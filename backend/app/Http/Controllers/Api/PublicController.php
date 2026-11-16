<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\QueueDisplay;
use App\Models\QueueToken;
use App\Models\ShareLink;
use App\Services\BillingService;
use App\Services\DocumentService;
use App\Services\PrescriptionService;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Unauthenticated endpoints. Each one is keyed by an unguessable token and exposes the minimum data. */
class PublicController extends Controller
{
    /** Waiting-room TV board: token numbers and initials only, never full names. */
    public function display(string $token): JsonResponse
    {
        $ctx = $this->tenant();
        $display = $ctx->bypass(fn () => QueueDisplay::where('access_token', $token)->firstOrFail());
        $org = Organization::findOrFail($display->organization_id);

        return $ctx->runAs($org, function () use ($display) {
            $tz = $display->branch->timezone ?: 'Asia/Kolkata';
            $tokens = QueueToken::withoutGlobalScope('branch')->with('doctor.user:id,name', 'appointment.patient:id,first_name,last_name')
                ->where('branch_id', $display->branch_id)->whereDate('queue_date', CarbonImmutable::now($tz)->toDateString())
                ->when($display->doctor_ids, fn ($q, $ids) => $q->whereIn('doctor_profile_id', $ids))
                ->whereIn('status', ['waiting', 'called', 'in_consultation'])->orderBy('token_number')->get();

            return $this->ok([
                'branch' => $display->branch->name,
                'doctors' => $tokens->groupBy('doctor_profile_id')->map(fn ($list) => [
                    'doctor' => $list->first()->doctor->display_name,
                    'now_serving' => optional($list->firstWhere('status', 'in_consultation') ?? $list->firstWhere('status', 'called'))->token_label,
                    'next' => $list->where('status', 'waiting')->take(5)->map(fn ($t) => ['token' => $t->token_label, 'initials' => mb_substr($t->appointment->patient->first_name, 0, 1).mb_substr((string) $t->appointment->patient->last_name, 0, 1)])->values(),
                ])->values(),
                'updated_at' => now()->toIso8601String(),
            ]);
        });
    }

    /**
     * Secure share link. Requires the patient's year of birth or last 4 digits of mobile as a second factor.
     * Expired, revoked or over-used links return 410.
     */
    public function share(Request $request, string $token)
    {
        $ctx = $this->tenant();
        $link = $ctx->bypass(fn () => ShareLink::where('token_hash', hash('sha256', $token))->first());
        if (! $link || $link->revoked_at || $link->expires_at->isPast() || $link->view_count >= $link->max_views) {
            return response()->json(['error' => ['code' => 'LINK_EXPIRED', 'message' => 'This link has expired.']], 410);
        }
        $org = Organization::findOrFail($link->organization_id);

        return $ctx->runAs($org, function () use ($request, $link) {
            $patient = Patient::findOrFail($link->patient_id);
            $verifier = (string) $request->query('verify', '');
            $ok = ($patient->date_of_birth && $verifier === $patient->date_of_birth->format('Y'))
                || ($patient->mobile && strlen($verifier) === 4 && str_ends_with($patient->mobile, $verifier));
            if (! $ok) {
                return response()->json(['error' => ['code' => 'VERIFICATION_REQUIRED', 'message' => 'Enter your year of birth or the last 4 digits of your mobile number.']], 401);
            }
            $link->increment('view_count');
            AuditLogger::log('share_link.viewed', $link);

            return match ($link->shareable_type) {
                'Prescription' => app(PrescriptionService::class)->pdf(Prescription::withoutGlobalScope('branch')->findOrFail($link->shareable_id))->stream('prescription.pdf'),
                'LabOrder' => app(DocumentService::class)->download(LabOrder::withoutGlobalScope('branch')->with('report')->findOrFail($link->shareable_id)->report),
                'Invoice' => app(BillingService::class)->pdf(Invoice::withoutGlobalScope('branch')->findOrFail($link->shareable_id))->stream('invoice.pdf'),
                default => abort(404),
            };
        });
    }
}
