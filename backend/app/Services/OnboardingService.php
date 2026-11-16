<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DoctorProfile;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Speciality;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Phone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnboardingService
{
    public function __construct(private TenantContext $context) {}

    /**
     * Self sign-up: creates the organisation, first branch, owner user + membership and a trial subscription.
     *
     * @param  array{clinic_name:string, owner_name:string, email:string, mobile?:string, password:string, plan_code?:string, speciality_codes?:array, owner_is_doctor?:bool, city?:string}  $data
     */
    public function registerClinic(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $plan = Plan::where('code', $data['plan_code'] ?? 'starter')->where('is_active', true)->firstOrFail();
            $autoApprove = (bool) (config('clinic.auto_approve_signups', true));

            $org = Organization::create([
                'name' => $data['clinic_name'],
                'slug' => $this->uniqueSlug($data['clinic_name']),
                'email' => $data['email'],
                'phone' => Phone::normalize($data['mobile'] ?? null),
                'status' => $autoApprove ? 'active' : 'pending',
                'approved_at' => $autoApprove ? now() : null,
                'address' => ['city' => $data['city'] ?? null],
            ]);

            Subscription::create([
                'organization_id' => $org->id,
                'plan_id' => $plan->id,
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays($plan->trial_days),
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($plan->trial_days),
            ]);

            $user = User::create([
                'name' => $data['owner_name'],
                'email' => strtolower($data['email']),
                'mobile' => Phone::normalize($data['mobile'] ?? null),
                'password' => $data['password'],
                'current_organization_id' => $org->id,
            ]);

            return $this->context->runAs($org, function () use ($org, $user, $data) {
                $branch = Branch::create(['name' => $data['branch_name'] ?? 'Main', 'code' => 'MAIN', 'address' => ['city' => $data['city'] ?? null], 'invoice_prefix' => 'INV']);

                $membership = Membership::create(['user_id' => $user->id, 'all_branches' => true, 'joined_at' => now()]);
                $roles = ['clinic_owner'];
                if (! empty($data['owner_is_doctor'])) {
                    $roles[] = 'doctor';
                }
                $membership->roles()->sync(Role::whereNull('organization_id')->whereIn('key', $roles)->pluck('id'));
                $membership->branches()->sync([$branch->id]);

                $specialityIds = Speciality::whereIn('code', $data['speciality_codes'] ?? ['general'])->pluck('id');
                $org->specialities()->sync($specialityIds);

                if (! empty($data['owner_is_doctor'])) {
                    $doctor = DoctorProfile::create(['user_id' => $user->id, 'registration_number' => $data['registration_number'] ?? null]);
                    $doctor->specialities()->sync($specialityIds->mapWithKeys(fn ($id, $i) => [$id => ['is_primary' => $i === 0]])->all());
                    $doctor->branches()->sync([$branch->id]);
                }

                return ['organization' => $org->fresh('subscription.plan'), 'branch' => $branch, 'user' => $user, 'membership' => $membership];
            });
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'clinic';
        $slug = $base;
        $i = 1;
        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
