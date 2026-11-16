<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Consent;
use App\Models\DoctorFee;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Speciality;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\OnboardingService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = PlatformSeeder::class;

    protected function ctx(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** @return array{org: Organization, branch: Branch, owner: User} */
    protected function makeClinic(string $plan = 'business', array $overrides = []): array
    {
        $r = app(OnboardingService::class)->registerClinic($overrides + [
            'clinic_name' => 'Clinic '.Str::random(5),
            'owner_name' => 'Owner',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => 'Secret12345',
            'plan_code' => $plan,
        ]);
        $this->ctx()->clear();

        return ['org' => $r['organization'], 'branch' => $r['branch'], 'owner' => $r['user']];
    }

    protected function asTenant(Organization $org, callable $fn): mixed
    {
        return $this->ctx()->runAs($org, $fn);
    }

    protected function addStaff(Organization $org, Branch $branch, string $role, ?string $name = null): User
    {
        return $this->asTenant($org, function () use ($branch, $role, $name) {
            $user = User::create(['name' => $name ?? ucfirst($role), 'email' => Str::lower(Str::random(10)).'@example.test', 'password' => 'Secret12345', 'current_organization_id' => $branch->organization_id]);
            $m = Membership::create(['user_id' => $user->id, 'joined_at' => now()]);
            $m->roles()->sync(Role::whereNull('organization_id')->where('key', $role)->pluck('id'));
            $m->branches()->sync([$branch->id]);

            return $user;
        });
    }

    /** Doctor with Mon–Sun 09:00–17:00 schedule (15-min slots) and fees ₹500 new / ₹250 follow-up. */
    protected function addDoctor(Organization $org, Branch $branch, string $speciality = 'general'): DoctorProfile
    {
        $user = $this->addStaff($org, $branch, 'doctor', 'Doc '.Str::random(4));

        return $this->asTenant($org, function () use ($user, $branch, $speciality) {
            $d = DoctorProfile::create(['user_id' => $user->id, 'registration_number' => 'REG-1', 'consultation_duration_min' => 15]);
            $d->specialities()->sync([Speciality::where('code', $speciality)->value('id') => ['is_primary' => true]]);
            $d->branches()->sync([$branch->id]);
            foreach (range(0, 6) as $wd) {
                DoctorSchedule::create(['branch_id' => $branch->id, 'doctor_profile_id' => $d->id, 'weekday' => $wd, 'start_time' => '00:00', 'end_time' => '23:45', 'slot_minutes' => 15]);
            }
            DoctorFee::create(['doctor_profile_id' => $d->id, 'fee_type' => 'new', 'amount' => 50000]);
            DoctorFee::create(['doctor_profile_id' => $d->id, 'fee_type' => 'follow_up', 'amount' => 25000]);

            return $d->load('user');
        });
    }

    protected function addPatient(Organization $org, array $attrs = [], array $consents = ['data_processing', 'whatsapp', 'sms']): Patient
    {
        return $this->asTenant($org, function () use ($attrs, $consents) {
            $p = Patient::create($attrs + ['patient_code' => 'P-'.Str::upper(Str::random(6)), 'first_name' => 'Test', 'last_name' => 'Patient', 'gender' => 'female', 'date_of_birth' => '1990-05-05', 'mobile' => '+9199'.random_int(10000000, 99999999)]);
            foreach ($consents as $purpose) {
                Consent::create(['patient_id' => $p->id, 'purpose' => $purpose, 'status' => 'granted', 'granted_at' => now()]);
            }

            return $p;
        });
    }

    protected function actingAsStaff(User $user, ?Organization $org = null): static
    {
        Sanctum::actingAs($user);
        if ($org) {
            $this->withHeader('X-Organization-Id', $org->id);
        }

        return $this;
    }

    /** Next bookable slot start (UTC ISO) for a doctor at a branch, tomorrow. */
    protected function nextSlot(DoctorProfile $doctor, Branch $branch, int $index = 0): string
    {
        $date = now('Asia/Kolkata')->addDay()->toDateString();
        $slots = $this->asTenant(Organization::find($branch->organization_id), fn () => app(AvailabilityService::class)->slots($doctor, $branch, $date));

        return collect($slots)->where('available', true)->values()[$index]['start'];
    }
}
