<?php

namespace Tests\Feature;

use App\Models\Organization;
use Tests\TestCase;

class PatientAppTest extends TestCase
{
    public function test_patient_logs_in_links_clinic_books_and_sees_only_shared_records(): void
    {
        $c = $this->makeClinic();
        Organization::whereKey($c['org']->id)->update(['settings' => json_encode(['public_listing' => true])]);
        $doctor = $this->addDoctor($c['org'], $c['branch']);
        $patient = $this->addPatient($c['org'], ['mobile' => '+919888877777']);

        $this->getJson('/api/patient/v1/clinics')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/patient/v1/auth/otp/request', ['mobile' => '98888 77777'])->assertOk();
        $this->postJson('/api/patient/v1/auth/otp/verify', ['mobile' => '9888877777', 'code' => '000000'])->assertStatus(401);
        $token = $this->postJson('/api/patient/v1/auth/otp/verify', ['mobile' => '9888877777', 'code' => '123456'])->assertOk()->json('data.token');

        $slug = $c['org']->slug;
        $this->withToken($token)->postJson("/api/patient/v1/clinics/{$slug}/link", ['consent' => true])->assertCreated()->assertJsonPath('data.0.id', $patient->id);
        $date = now('Asia/Kolkata')->addDay()->toDateString();
        $slot = $this->getJson("/api/patient/v1/clinics/{$slug}/doctors/{$doctor->id}/availability?date={$date}&branch_id={$c['branch']->id}")->assertOk()->json('data.0.start');
        $this->withToken($token)->postJson('/api/patient/v1/appointments', ['patient_id' => $patient->id, 'doctor_profile_id' => $doctor->id, 'branch_id' => $c['branch']->id, 'scheduled_start' => $slot])->assertCreated();
        $this->withToken($token)->getJson('/api/patient/v1/appointments')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson('/api/patient/v1/records')->assertOk()->assertJsonCount(0, 'data.prescriptions');

        // Staff tokens are not patient tokens and vice versa.
        $this->withToken($token)->getJson('/api/v1/patients')->assertStatus(401);

        $other = $this->addPatient($c['org']);
        $this->withToken($token)->postJson('/api/patient/v1/appointments', ['patient_id' => $other->id, 'doctor_profile_id' => $doctor->id, 'branch_id' => $c['branch']->id, 'scheduled_start' => $slot])->assertNotFound();

        $this->withToken($token)->deleteJson("/api/patient/v1/clinics/{$slug}/link")->assertNoContent();
        $this->withToken($token)->getJson('/api/patient/v1/appointments')->assertJsonCount(0, 'data');
    }

    public function test_unlisted_clinics_are_not_discoverable(): void
    {
        $c = $this->makeClinic();
        $this->getJson('/api/patient/v1/clinics')->assertJsonCount(0, 'data');
        $this->getJson("/api/patient/v1/clinics/{$c['org']->slug}")->assertNotFound();
    }
}
