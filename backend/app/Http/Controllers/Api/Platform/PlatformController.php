<?php

namespace App\Http\Controllers\Api\Platform;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ConsultationTemplate;
use App\Models\DoctorProfile;
use App\Models\GlobalSetting;
use App\Models\ImpersonationSession;
use App\Models\Membership;
use App\Models\NotificationMessage;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\Speciality;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Platform plane. Platform staff manage clinics, plans and reference data.
 * They never read tenant clinical data from these endpoints; support access goes through audited impersonation.
 */
class PlatformController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required'], 'code' => ['nullable', 'string', 'size:6']]);
        $user = User::where('email', strtolower($data['email']))->whereNotNull('platform_role')->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new BusinessRuleException('INVALID_CREDENTIALS', 'These credentials do not match our records.', 401);
        }
        if (app()->isProduction() && (! $user->hasTwoFactor() || ! Totp::verify($user->two_factor_secret, (string) ($data['code'] ?? '')))) {
            throw new BusinessRuleException('TWO_FACTOR_REQUIRED', 'Platform staff must sign in with two-factor authentication.', 401);
        }
        AuditLogger::log('platform.login', $user);

        return $this->ok(['token' => $user->createToken('platform', ['platform'], now()->addHours(8))->plainTextToken, 'user' => $user]);
    }

    public function organizations(Request $request): JsonResponse
    {
        return $this->ctx->bypass(function () use ($request) {
            $q = Organization::with('subscription.plan:id,code,name')->withCount(['branches', 'memberships'])
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('slug', 'like', "%$t%")->orWhere('email', 'like', "%$t%")))
                ->latest();

            return $this->paginated($q->paginate($this->perPage($request)));
        });
    }

    public function organization(string $id): JsonResponse
    {
        return $this->ctx->bypass(function () use ($id) {
            $org = Organization::with(['subscription.plan', 'specialities', 'branches:id,organization_id,name,code,is_active'])->withCount('memberships')->findOrFail($id);

            return $this->ok([
                'organization' => $org,
                'usage' => [
                    'patients' => Patient::where('organization_id', $id)->count(),
                    'appointments_30d' => Appointment::where('organization_id', $id)->where('created_at', '>=', now()->subDays(30))->count(),
                    'doctors' => DoctorProfile::where('organization_id', $id)->where('is_active', true)->count(),
                    'messages_30d' => NotificationMessage::where('organization_id', $id)->where('created_at', '>=', now()->subDays(30))->count(),
                ],
                'owners' => Membership::with('user:id,name,email,mobile')->where('organization_id', $id)->whereHas('roles', fn ($q) => $q->where('key', 'clinic_owner'))->get(),
                'invoices' => SubscriptionInvoice::where('organization_id', $id)->latest()->limit(12)->get(),
            ]);
        });
    }

    public function setStatus(Request $request, string $id, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['approve', 'suspend', 'reactivate'], true), 404);
        abort_unless($request->user()->platform_role === 'super_admin', 403);
        $reason = $request->validate(['reason' => [$action === 'suspend' ? 'required' : 'nullable', 'string', 'max:200']])['reason'] ?? null;
        $org = Organization::findOrFail($id);
        match ($action) {
            'approve' => $org->update(['status' => 'active', 'approved_at' => now()]),
            'suspend' => $org->update(['status' => 'suspended', 'suspended_reason' => $reason]),
            'reactivate' => $org->update(['status' => 'active', 'suspended_reason' => null]),
        };
        if ($action === 'suspend') {
            // Revoke all staff tokens of the suspended clinic.
            $userIds = $this->ctx->bypass(fn () => Membership::where('organization_id', $org->id)->pluck('user_id'));
            PersonalAccessToken::whereIn('tokenable_id', $userIds)->where('tokenable_type', User::class)->where('name', 'not like', 'platform%')->delete();
        }
        AuditLogger::log('platform.org.'.$action, $org, null, ['reason' => $reason]);

        return $this->ok($org);
    }

    public function updateSubscription(Request $request, string $id, SubscriptionService $subscriptions): JsonResponse
    {
        abort_unless(in_array($request->user()->platform_role, ['super_admin', 'billing'], true), 403);
        $data = $request->validate(['action' => ['required', Rule::in(['extend_trial', 'change_plan', 'set_overrides', 'mark_invoice_paid'])], 'days' => ['nullable', 'integer', 'min:1', 'max:365'], 'plan_code' => ['nullable', 'exists:plans,code'], 'billing_cycle' => ['nullable', Rule::in(['monthly', 'annual'])], 'overrides' => ['nullable', 'array'], 'invoice_id' => ['nullable', 'string'], 'reference' => ['nullable', 'string']]);
        $org = Organization::with('subscription')->findOrFail($id);
        $sub = $org->subscription;
        match ($data['action']) {
            'extend_trial' => $sub->update(['status' => 'trialing', 'trial_ends_at' => ($sub->trial_ends_at?->isFuture() ? $sub->trial_ends_at : now())->addDays($data['days'] ?? 14)]),
            'change_plan' => $subscriptions->changePlan($org, Plan::where('code', $data['plan_code'])->firstOrFail(), $data['billing_cycle'] ?? 'monthly'),
            'set_overrides' => $sub->update(['overrides' => $data['overrides'] ?? []]),
            'mark_invoice_paid' => $subscriptions->markInvoicePaid(SubscriptionInvoice::where('organization_id', $org->id)->findOrFail($data['invoice_id']), $data['reference'] ?? 'manual'),
        };
        AuditLogger::log('platform.subscription.'.$data['action'], $org, null, collect($data)->except('action')->all());

        return $this->ok($org->fresh('subscription.plan'));
    }

    public function plans(): JsonResponse
    {
        return $this->ok(Plan::orderBy('sort')->get());
    }

    public function savePlan(Request $request, ?string $id = null): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'super_admin', 403);
        $data = $request->validate([
            'code' => [$id ? 'sometimes' : 'required', 'alpha_dash', 'max:32', Rule::unique('plans')->ignore($id)], 'name' => [$id ? 'sometimes' : 'required', 'string', 'max:80'],
            'description' => ['nullable', 'string'], 'price_monthly' => ['sometimes', 'integer', 'min:0'], 'price_annual' => ['sometimes', 'integer', 'min:0'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'], 'limits' => ['nullable', 'array'], 'features' => ['nullable', 'array'],
            'is_public' => ['boolean'], 'is_active' => ['boolean'], 'sort' => ['integer'],
        ]);
        $plan = $id ? tap(Plan::findOrFail($id))->update($data) : Plan::create($data);
        AuditLogger::log('platform.plan.saved', $plan);

        return $this->ok($plan, $id ? 200 : 201);
    }

    public function specialities(): JsonResponse
    {
        return $this->ok(Speciality::orderBy('name')->get());
    }

    public function saveSpeciality(Request $request, ?string $id = null): JsonResponse
    {
        abort_unless(in_array($request->user()->platform_role, ['super_admin', 'support'], true), 403);
        $data = $request->validate(['code' => [$id ? 'sometimes' : 'required', 'alpha_dash', Rule::unique('specialities')->ignore($id)], 'name' => [$id ? 'sometimes' : 'required', 'string'], 'description' => ['nullable', 'string'], 'module_key' => ['nullable', 'string'], 'is_active' => ['boolean']]);

        return $this->ok($id ? tap(Speciality::findOrFail($id))->update($data) : Speciality::create($data), $id ? 200 : 201);
    }

    /** New speciality templates are data, not code: no core change is needed to add a speciality. */
    public function saveTemplate(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->platform_role, ['super_admin', 'support'], true), 403);
        $data = $request->validate(['key' => ['required', 'string', 'max:64'], 'name' => ['required', 'string'], 'speciality_id' => ['nullable', 'exists:specialities,id'], 'definition' => ['required', 'array'], 'definition.sections' => ['required', 'array', 'min:1']]);

        return $this->ctx->bypass(function () use ($data) {
            $version = (int) ConsultationTemplate::whereNull('organization_id')->where('key', $data['key'])->max('version') + 1;
            ConsultationTemplate::whereNull('organization_id')->where('key', $data['key'])->update(['is_active' => false]);

            return $this->created(ConsultationTemplate::create($data + ['version' => $version, 'is_default' => true]));
        });
    }

    public function metrics(): JsonResponse
    {
        return $this->ctx->bypass(function () {
            $active = Subscription::with('plan')->whereIn('status', ['active', 'past_due', 'grace'])->get();
            $mrr = (int) $active->sum(fn ($s) => $s->billing_cycle === 'annual' ? intdiv((int) $s->plan->price_annual, 12) : (int) $s->plan->price_monthly);
            $cancelled30 = Subscription::where('status', 'cancelled')->where('cancelled_at', '>=', now()->subDays(30))->count();

            return $this->ok([
                'organizations' => Organization::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
                'subscriptions' => Subscription::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
                'mrr' => $mrr,
                'arr' => $mrr * 12,
                'trials_ending_7d' => Subscription::where('status', 'trialing')->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count(),
                'churn_30d_percent' => $active->count() ? round($cancelled30 / max(1, $active->count() + $cancelled30) * 100, 1) : 0,
                'revenue_collected_30d' => (int) SubscriptionInvoice::where('status', 'paid')->where('paid_at', '>=', now()->subDays(30))->sum('total'),
                'by_plan' => $active->groupBy('plan.code')->map->count(),
                'signups_30d' => Organization::where('created_at', '>=', now()->subDays(30))->count(),
            ]);
        });
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'super_admin', 403);

        return $this->paginated(AuditLog::where('event', 'like', 'platform.%')->when($request->query('organization_id'), fn ($q, $o) => $q->where('organization_id', $o))->latest('created_at')->paginate($this->perPage($request)));
    }

    public function settings(): JsonResponse
    {
        return $this->ok(GlobalSetting::all()->pluck('value', 'key'));
    }

    public function saveSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'super_admin', 403);
        foreach ($request->validate(['settings' => ['required', 'array']])['settings'] as $key => $value) {
            GlobalSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return $this->settings();
    }

    /** Time-boxed, reason-logged support session as a tenant user. The org owner can see it in their audit log. */
    public function impersonate(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->platform_role, ['super_admin', 'support'], true), 403);
        $data = $request->validate(['organization_id' => ['required', 'string'], 'user_id' => ['required', 'string'], 'reason' => ['required', 'string', 'min:10', 'max:255'], 'ticket_ref' => ['nullable', 'string', 'max:64']]);
        $member = $this->ctx->bypass(fn () => Membership::where('organization_id', $data['organization_id'])->where('user_id', $data['user_id'])->where('status', 'active')->exists());
        abort_unless($member, 422, 'That user is not an active member of the organisation.');
        $minutes = config('clinic.impersonation_minutes', 60);
        $session = ImpersonationSession::create($data + ['platform_user_id' => $request->user()->id, 'expires_at' => now()->addMinutes($minutes)]);
        $target = User::findOrFail($data['user_id']);
        $target->forceFill(['current_organization_id' => $data['organization_id']])->save();
        $token = $target->createToken('impersonation:'.$request->user()->id, ['*'], now()->addMinutes($minutes))->plainTextToken;
        $this->ctx->runAs(Organization::findOrFail($data['organization_id']), fn () => AuditLogger::log('platform.impersonation.started', $session, null, ['reason' => $data['reason'], 'by' => $request->user()->email]));

        return $this->created(['token' => $token, 'expires_at' => $session->expires_at, 'session_id' => $session->id]);
    }

    public function endImpersonation(Request $request, string $id): JsonResponse
    {
        $session = ImpersonationSession::where('platform_user_id', $request->user()->id)->findOrFail($id);
        $session->update(['ended_at' => now()]);
        PersonalAccessToken::where('tokenable_id', $session->user_id)->where('name', 'impersonation:'.$request->user()->id)->delete();

        return response()->json(null, 204);
    }
}
