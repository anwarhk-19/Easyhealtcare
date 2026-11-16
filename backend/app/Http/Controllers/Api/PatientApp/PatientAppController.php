<?php

namespace App\Http\Controllers\Api\PatientApp;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Consent;
use App\Models\DoctorProfile;
use App\Models\LabOrder;
use App\Models\Organization;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use App\Models\Payment;
use App\Models\Prescription;
use App\Services\AppointmentService;
use App\Services\AvailabilityService;
use App\Services\PatientPortalService;
use App\Services\PrescriptionService;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patient app API. Patients see only records the clinic has shared with them, from clinics they have linked (OTP-verified mobile + consent).
 */
class PatientAppController extends Controller
{
    public function __construct(private TenantContext $ctx, private PatientPortalService $portal) {}

    public function requestOtp(Request $request): JsonResponse
    {
        return $this->ok($this->portal->requestOtp($request->validate(['mobile' => ['required', 'string', 'regex:/^\+?[0-9 \-]{10,15}$/']])['mobile']));
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['mobile' => ['required', 'string'], 'code' => ['required', 'string', 'size:6']]);
        $account = $this->portal->verifyOtp($data['mobile'], $data['code']);

        return $this->ok(['token' => $account->createToken('patient-app', ['patient'], now()->addDays(30))->plainTextToken, 'account' => $account]);
    }

    public function me(Request $request): JsonResponse
    {
        $account = $request->user();

        return $this->ok(['account' => $account, 'clinics' => collect($this->portal->linkedRecords($account))->map(fn ($r) => ['organization' => $r['organization']->only(['id', 'name', 'slug']), 'records' => count($r['patient_ids'])])->values()]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $request->user()->update($request->validate(['name' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email']]));

        return $this->ok($request->user());
    }

    /** Clinic discovery: only active clinics that opted into public listing. */
    public function clinics(Request $request): JsonResponse
    {
        return $this->ctx->bypass(function () use ($request) {
            $q = Organization::with('specialities:id,code,name')->where('status', 'active')->where('settings->public_listing', true)
                ->when($request->query('q'), fn ($q, $t) => $q->where('name', 'like', "%$t%"))
                ->when($request->query('city'), fn ($q, $c) => $q->where('address->city', $c))
                ->when($request->query('speciality'), fn ($q, $s) => $q->whereHas('specialities', fn ($w) => $w->where('code', $s)));

            return $this->ok($q->limit(50)->get(['id', 'name', 'slug', 'address', 'phone', 'logo_path']));
        });
    }

    private function org(string $slug): Organization
    {
        return $this->ctx->bypass(fn () => Organization::where('slug', $slug)->where('status', 'active')->where('settings->public_listing', true)->firstOrFail());
    }

    public function clinic(string $slug): JsonResponse
    {
        $org = $this->org($slug);

        return $this->ctx->runAs($org, fn () => $this->ok([
            'clinic' => $org->only(['id', 'name', 'slug', 'address', 'phone', 'logo_path']),
            'branches' => Branch::where('is_active', true)->get(['id', 'name', 'address', 'phone', 'timezone']),
            'doctors' => DoctorProfile::with('user:id,name', 'specialities:id,code,name', 'branches:id,name')->where('is_active', true)->where('is_bookable_online', true)->get(['id', 'user_id', 'title', 'qualifications', 'experience_years', 'bio']),
        ]));
    }

    public function availability(Request $request, string $slug, string $doctorId, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date', 'after_or_equal:today'], 'branch_id' => ['required', 'string']]);

        return $this->ctx->runAs($this->org($slug), function () use ($data, $doctorId, $availability) {
            $doctor = DoctorProfile::where('is_bookable_online', true)->findOrFail($doctorId);

            return $this->ok(collect($availability->slots($doctor, Branch::findOrFail($data['branch_id']), $data['date']))->where('available', true)->values());
        });
    }

    public function link(Request $request, string $slug): JsonResponse
    {
        $profile = $request->validate(['first_name' => ['nullable', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'], 'gender' => ['nullable', 'in:male,female,other,unknown'], 'date_of_birth' => ['nullable', 'date', 'before:today'], 'consent' => ['accepted']]);
        $patients = $this->portal->link($request->user(), $this->org($slug), $profile);

        return $this->created(collect($patients)->map(fn ($p) => $p->only(['id', 'patient_code', 'full_name'])));
    }

    public function unlink(Request $request, string $slug): JsonResponse
    {
        $this->portal->revoke($request->user(), $this->org($slug));

        return response()->json(null, 204);
    }

    private function linkFor(PatientAccount $account, string $patientId): PatientAccountLink
    {
        return PatientAccountLink::where('patient_account_id', $account->id)->where('patient_id', $patientId)->whereNull('revoked_at')->firstOrFail();
    }

    public function book(Request $request, AppointmentService $appointments): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'string'], 'doctor_profile_id' => ['required', 'string'], 'branch_id' => ['required', 'string'], 'scheduled_start' => ['required', 'date', 'after:now']]);
        $link = $this->linkFor($request->user(), $data['patient_id']);
        $org = Organization::findOrFail($link->organization_id);

        return $this->ctx->runAs($org, function () use ($data, $appointments) {
            abort_unless(DoctorProfile::where('is_bookable_online', true)->whereKey($data['doctor_profile_id'])->exists(), 422, 'This doctor does not accept online bookings.');
            $a = $appointments->book($data + ['source' => 'patient_app', 'type' => 'online']);

            return $this->created($a->only(['id', 'scheduled_start', 'scheduled_end', 'status']));
        });
    }

    /** @return iterable<array{0: Organization, 1: array}> */
    private function eachLinkedOrg(PatientAccount $account): iterable
    {
        foreach ($this->portal->linkedRecords($account) as $r) {
            yield [$r['organization'], $r['patient_ids']];
        }
    }

    public function appointments(Request $request): JsonResponse
    {
        $out = collect();
        foreach ($this->eachLinkedOrg($request->user()) as [$org, $ids]) {
            $this->ctx->runAs($org, function () use ($ids, $org, $out) {
                Appointment::withoutGlobalScope('branch')->with('doctor.user:id,name', 'branch:id,name')->whereIn('patient_id', $ids)->latest('scheduled_start')->limit(50)->get()
                    ->each(fn ($a) => $out->push(['id' => $a->id, 'clinic' => $org->name, 'clinic_slug' => $org->slug, 'doctor' => $a->doctor->display_name, 'branch' => $a->branch->name, 'scheduled_start' => $a->scheduled_start, 'status' => $a->status]));
            });
        }

        return $this->ok($out->sortByDesc('scheduled_start')->values());
    }

    public function cancelAppointment(Request $request, string $id, AppointmentService $appointments): JsonResponse
    {
        foreach ($this->eachLinkedOrg($request->user()) as [$org, $ids]) {
            $done = $this->ctx->runAs($org, function () use ($id, $ids, $appointments) {
                $a = Appointment::withoutGlobalScope('branch')->whereIn('patient_id', $ids)->find($id);
                if (! $a) {
                    return null;
                }
                if ($a->scheduled_start->diffInMinutes(now(), false) > -60) {
                    throw new BusinessRuleException('TOO_LATE_TO_CANCEL', 'Please call the clinic to cancel within an hour of the appointment.');
                }

                return $appointments->cancel($a, 'Cancelled by patient');
            });
            if ($done) {
                return $this->ok(['id' => $done->id, 'status' => $done->status]);
            }
        }
        abort(404);
    }

    /** Shared prescriptions, released lab reports and payment receipts across linked clinics. */
    public function records(Request $request): JsonResponse
    {
        $out = ['prescriptions' => [], 'lab_reports' => [], 'receipts' => []];
        foreach ($this->eachLinkedOrg($request->user()) as [$org, $ids]) {
            $this->ctx->runAs($org, function () use ($ids, $org, &$out) {
                foreach (Prescription::withoutGlobalScope('branch')->with('doctor.user:id,name')->whereIn('patient_id', $ids)->whereNotNull('shared_with_patient_at')->latest()->get() as $rx) {
                    $out['prescriptions'][] = ['id' => $rx->id, 'clinic' => $org->name, 'doctor' => $rx->doctor->display_name, 'date' => $rx->signed_at];
                }
                foreach (LabOrder::withoutGlobalScope('branch')->with('items.test:id,name')->whereIn('patient_id', $ids)->whereNotNull('shared_with_patient_at')->latest()->get() as $l) {
                    $out['lab_reports'][] = ['id' => $l->id, 'clinic' => $org->name, 'order_number' => $l->order_number, 'tests' => $l->items->pluck('test.name'), 'released_at' => $l->released_at];
                }
                foreach (Payment::withoutGlobalScope('branch')->whereIn('patient_id', $ids)->where('status', 'success')->latest('received_at')->limit(50)->get() as $p) {
                    $out['receipts'][] = ['id' => $p->id, 'clinic' => $org->name, 'receipt_number' => $p->receipt_number, 'amount' => $p->amount, 'received_at' => $p->received_at];
                }
            });
        }

        return $this->ok($out);
    }

    public function prescriptionPdf(Request $request, string $id, PrescriptionService $rx)
    {
        foreach ($this->eachLinkedOrg($request->user()) as [$org, $ids]) {
            $res = $this->ctx->runAs($org, function () use ($id, $ids, $rx) {
                $p = Prescription::withoutGlobalScope('branch')->whereIn('patient_id', $ids)->whereNotNull('shared_with_patient_at')->find($id);

                return $p ? $rx->pdf($p)->stream('prescription.pdf') : null;
            });
            if ($res) {
                return $res;
            }
        }
        abort(404);
    }

    public function receiptPdf(Request $request, string $id)
    {
        foreach ($this->eachLinkedOrg($request->user()) as [$org, $ids]) {
            $res = $this->ctx->runAs($org, function () use ($id, $ids) {
                $p = Payment::withoutGlobalScope('branch')->with('patient', 'allocations.invoice', 'branch')->whereIn('patient_id', $ids)->find($id);

                return $p ? Pdf::loadView('pdf.receipt', ['payment' => $p, 'org' => $p->organization, 'branch' => $p->branch])->stream('receipt.pdf') : null;
            });
            if ($res) {
                return $res;
            }
        }
        abort(404);
    }

    /** Patient's consent centre across linked clinics. */
    public function consents(Request $request): JsonResponse
    {
        $out = [];
        foreach ($this->eachLinkedOrg($request->user()) as [$org, $ids]) {
            $this->ctx->runAs($org, function () use ($ids, $org, &$out) {
                $out[] = ['clinic' => $org->only(['name', 'slug']), 'consents' => Consent::whereIn('patient_id', $ids)->latest()->get(['purpose', 'status', 'granted_at', 'revoked_at', 'created_at'])];
            });
        }

        return $this->ok($out);
    }

    public function setConsent(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate(['purpose' => ['required', 'in:whatsapp,sms,email,ai_processing,clinical_photos'], 'status' => ['required', 'in:granted,revoked']]);
        $org = $this->org($slug);
        $ids = PatientAccountLink::where('patient_account_id', $request->user()->id)->where('organization_id', $org->id)->whereNull('revoked_at')->pluck('patient_id');
        abort_if($ids->isEmpty(), 404);
        $this->ctx->runAs($org, fn () => $ids->each(fn ($pid) => Consent::create(['patient_id' => $pid, 'purpose' => $data['purpose'], 'status' => $data['status'], 'method' => 'app', 'granted_at' => $data['status'] === 'granted' ? now() : null, 'revoked_at' => $data['status'] === 'revoked' ? now() : null])));

        return $this->ok($data);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $account = $request->user();
        foreach ($this->eachLinkedOrg($account) as [$org]) {
            $this->portal->revoke($account, $org);
        }
        $account->tokens()->delete();
        $account->update(['status' => 'deleted', 'name' => null, 'email' => null, 'mobile' => 'deleted-'.$account->id]);

        return $this->ok(['message' => 'Your app account is deleted. Clinics keep medical records as required by law.']);
    }
}
