<?php

namespace Database\Seeders;

use App\Models\Consent;
use App\Models\DoctorFee;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Service;
use App\Models\Speciality;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\OnboardingService;
use App\Support\Sequence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/** Demo multi-doctor clinic with synthetic data only (never real patient data). Password for all users: "password". */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'owner@demo.clinic')->exists()) {
            return;
        }
        $result = app(OnboardingService::class)->registerClinic([
            'clinic_name' => 'Demo Multi-Speciality Clinic', 'owner_name' => 'Asha Menon', 'email' => 'owner@demo.clinic',
            'mobile' => '9800000001', 'password' => 'password', 'plan_code' => 'business', 'speciality_codes' => ['general', 'dental', 'ophthalmology', 'physiotherapy'], 'city' => 'Kochi',
        ]);
        $org = $result['organization'];
        $org->subscription->update(['status' => 'active', 'trial_ends_at' => null, 'current_period_end' => now()->addYear()]);
        $org->update(['settings' => ['ai_enabled' => true, 'public_listing' => true]]);
        $branch = $result['branch'];

        app(TenantContext::class)->runAs($org, function () use ($branch) {
            $gst18 = TaxRate::create(['name' => 'GST 18%', 'rate' => 18]);
            TaxRate::create(['name' => 'Exempt', 'rate' => 0, 'is_default' => true]);
            Service::create(['code' => 'CONSULT', 'name' => 'General consultation', 'type' => 'consultation', 'price' => 50000]);
            Service::create(['code' => 'DRESS', 'name' => 'Wound dressing', 'type' => 'procedure', 'price' => 30000]);
            Service::create(['code' => 'SCALING', 'name' => 'Dental scaling', 'type' => 'procedure', 'price' => 150000, 'speciality_id' => Speciality::where('code', 'dental')->value('id')]);
            Service::create(['code' => 'PEEL', 'name' => 'Chemical peel (cosmetic)', 'type' => 'procedure', 'price' => 250000, 'tax_rate_id' => $gst18->id]);

            $staff = [
                ['Dr. Rahul Nair', 'doctor1@demo.clinic', '9800000002', ['doctor'], 'general', 50000],
                ['Dr. Priya Shah', 'doctor2@demo.clinic', '9800000003', ['doctor'], 'dental', 60000],
                ['Dr. Kiran Rao', 'doctor3@demo.clinic', '9800000004', ['doctor'], 'ophthalmology', 70000],
                ['Meera Joseph', 'reception@demo.clinic', '9800000005', ['receptionist'], null, null],
                ['Anil Kumar', 'nurse@demo.clinic', '9800000006', ['nurse'], null, null],
                ['Sara Thomas', 'lab@demo.clinic', '9800000007', ['lab_technician'], null, null],
                ['Vivek Iyer', 'accounts@demo.clinic', '9800000008', ['accountant'], null, null],
            ];
            foreach ($staff as [$name, $email, $mobile, $roles, $speciality, $fee]) {
                $user = User::create(['name' => preg_replace('/^Dr\. /', '', $name), 'email' => $email, 'mobile' => '+91'.$mobile, 'password' => 'password', 'current_organization_id' => $branch->organization_id]);
                $m = Membership::create(['user_id' => $user->id, 'joined_at' => now()]);
                $m->roles()->sync(Role::whereNull('organization_id')->whereIn('key', $roles)->pluck('id'));
                $m->branches()->sync([$branch->id]);
                if ($speciality) {
                    $doctor = DoctorProfile::create(['user_id' => $user->id, 'qualifications' => ['MBBS'], 'registration_council' => 'Kerala Medical Council', 'registration_number' => 'KMC-'.random_int(10000, 99999), 'consultation_duration_min' => 15]);
                    $doctor->specialities()->sync([Speciality::where('code', $speciality)->value('id') => ['is_primary' => true]]);
                    $doctor->branches()->sync([$branch->id]);
                    DoctorFee::create(['doctor_profile_id' => $doctor->id, 'fee_type' => 'new', 'amount' => $fee]);
                    DoctorFee::create(['doctor_profile_id' => $doctor->id, 'fee_type' => 'follow_up', 'amount' => (int) ($fee / 2)]);
                    foreach (range(1, 6) as $weekday) {
                        DoctorSchedule::create(['branch_id' => $branch->id, 'doctor_profile_id' => $doctor->id, 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '13:00', 'slot_minutes' => 15]);
                        DoctorSchedule::create(['branch_id' => $branch->id, 'doctor_profile_id' => $doctor->id, 'weekday' => $weekday, 'start_time' => '16:00', 'end_time' => '20:00', 'slot_minutes' => 15]);
                    }
                }
            }

            $names = [['Arjun', 'Pillai', 'male', '1985-04-12'], ['Fatima', 'Sheikh', 'female', '1992-09-03'], ['Joseph', 'Mathew', 'male', '1958-01-25'], ['Lakshmi', 'Iyer', 'female', '2018-06-30'], ['Rohan', 'Das', 'male', '2001-11-14']];
            foreach ($names as $i => [$first, $last, $gender, $dob]) {
                $p = Patient::create([
                    'patient_code' => 'P-'.now()->format('Y').'-'.str_pad((string) Sequence::next('patient', '', now()->format('Y')), 5, '0', STR_PAD_LEFT),
                    'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'date_of_birth' => $dob, 'mobile' => '+9197000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'registered_branch_id' => $branch->id, 'blood_group' => ['O+', 'B+', 'A+', 'AB+', 'O-'][$i],
                ]);
                foreach (['data_processing', 'whatsapp', 'sms'] as $purpose) {
                    Consent::create(['patient_id' => $p->id, 'purpose' => $purpose, 'status' => 'granted', 'granted_at' => now()]);
                }
                if ($i === 0) {
                    $p->allergies()->create(['allergen' => 'Penicillin', 'allergen_type' => 'drug', 'severity' => 'severe', 'reaction' => 'Rash, breathlessness']);
                    $p->conditions()->create(['name' => 'Type 2 diabetes mellitus', 'icd10_code' => 'E11.9']);
                }
            }
        });
    }
}
