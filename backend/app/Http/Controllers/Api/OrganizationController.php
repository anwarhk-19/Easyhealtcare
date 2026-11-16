<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Speciality;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function show(): JsonResponse
    {
        abort_unless($this->tenant()->can('organization.view'), 403);

        return $this->ok($this->tenant()->organization()->load('specialities'));
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('organization.update'), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'], 'legal_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:20'],
            'gstin' => ['nullable', 'string', 'size:15'], 'registration_no' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'array'], 'timezone' => ['sometimes', 'timezone'],
        ]);
        $org = $this->tenant()->organization();
        $org->update($data);

        return $this->ok($org);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('organization.update'), 403);
        $request->validate(['logo' => ['required', 'image', 'max:1024']]);
        $org = $this->tenant()->organization();
        $path = $request->file('logo')->store('orgs/'.$org->id.'/branding', 'public');
        $org->update(['logo_path' => Storage::disk('public')->url($path)]);

        return $this->ok(['logo_path' => $org->logo_path]);
    }

    public function settings(): JsonResponse
    {
        abort_unless($this->tenant()->can('organization.view'), 403);

        return $this->ok($this->tenant()->organization()->allSettings());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('organization.update'), 403);
        $data = $request->validate([
            'patient_code_prefix' => ['sometimes', 'string', 'alpha_num', 'max:6'],
            'slot_minutes' => ['sometimes', 'integer', 'in:5,10,15,20,30,45,60'],
            'discount_approval_threshold_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'admins_can_view_clinical' => ['sometimes', 'boolean'],
            'clinical_visibility' => ['sometimes', Rule::in(['all_doctors', 'treating_doctor_only'])],
            'lab_tech_can_release' => ['sometimes', 'boolean'],
            'reminder_offsets_minutes' => ['sometimes', 'array'], 'reminder_offsets_minutes.*' => ['integer', 'min:15', 'max:10080'],
            'notification_channels' => ['sometimes', 'array'], 'notification_channels.*' => [Rule::in(['whatsapp', 'sms', 'email'])],
            'follow_up_window_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'public_listing' => ['sometimes', 'boolean'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_phi_allowed' => ['sometimes', 'boolean'],
        ]);
        if ((array_key_exists('ai_enabled', $data) || array_key_exists('ai_phi_allowed', $data)) && ! $this->tenant()->can('ai.manage')) {
            abort(403, 'Only the clinic owner can change AI settings.');
        }
        $org = $this->tenant()->organization();
        $org->update(['settings' => array_replace($org->settings ?? [], $data)]);

        return $this->ok($org->allSettings());
    }

    public function updateSpecialities(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('organization.update'), 403);
        $data = $request->validate(['codes' => ['required', 'array', 'min:1'], 'codes.*' => ['exists:specialities,code']]);
        $org = $this->tenant()->organization();
        $org->specialities()->sync(Speciality::whereIn('code', $data['codes'])->pluck('id'));

        return $this->ok($org->specialities()->get());
    }

    /** Optional modules can be switched off even when the plan includes them. */
    public function toggleModule(Request $request, string $key): JsonResponse
    {
        abort_unless($this->tenant()->can('organization.update'), 403);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $org = $this->tenant()->organization();
        $disabled = collect($org->enabled_modules['disabled'] ?? []);
        $disabled = $enabled ? $disabled->reject(fn ($k) => $k === $key) : $disabled->push($key)->unique();
        $org->update(['enabled_modules' => ['disabled' => $disabled->values()->all()]]);

        return $this->ok(['key' => $key, 'enabled' => $org->fresh()->hasFeature($key)]);
    }

    public function subscription(): JsonResponse
    {
        abort_unless($this->tenant()->can('subscription.view'), 403);
        $org = $this->tenant()->organization();

        return $this->ok([
            'subscription' => $org->subscription?->load('plan'),
            'invoices' => SubscriptionInvoice::where('organization_id', $org->id)->latest()->limit(24)->get(),
            'available_plans' => Plan::where('is_active', true)->where('is_public', true)->orderBy('sort')->get(),
        ]);
    }

    public function changePlan(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        abort_unless($this->tenant()->can('subscription.manage'), 403);
        $data = $request->validate(['plan_code' => ['required', 'exists:plans,code'], 'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])]]);
        $sub = $subscriptions->changePlan($this->tenant()->organization(), Plan::where('code', $data['plan_code'])->firstOrFail(), $data['billing_cycle']);

        return $this->ok($sub->load('plan'));
    }
}
