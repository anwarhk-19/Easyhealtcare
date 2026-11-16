<?php

namespace Tests\Feature;

use App\Models\Medicine;
use Tests\TestCase;

class ConsultationFlowTest extends TestCase
{
    public function test_full_walk_in_to_signed_prescription_flow(): void
    {
        $c = $this->makeClinic();
        $doctor = $this->addDoctor($c['org'], $c['branch']);
        $patient = $this->addPatient($c['org']);
        $this->asTenant($c['org'], fn () => $patient->allergies()->create(['allergen' => 'Penicillin', 'allergen_type' => 'drug', 'severity' => 'severe']));
        $nurse = $this->addStaff($c['org'], $c['branch'], 'nurse');
        $reception = $this->addStaff($c['org'], $c['branch'], 'receptionist');

        $this->actingAsStaff($reception);
        $appt = $this->postJson('/api/v1/appointments/walk-in', ['patient_id' => $patient->id, 'doctor_profile_id' => $doctor->id, 'branch_id' => $c['branch']->id])->json('data');

        $this->actingAsStaff($nurse);
        $bmi = $this->postJson("/api/v1/appointments/{$appt['id']}/vitals", ['height_cm' => 160, 'weight_kg' => 64, 'bp_systolic' => 130, 'bp_diastolic' => 85, 'pulse_bpm' => 78])
            ->assertCreated()->json('data.bmi');
        $this->assertEquals(25.0, $bmi);
        $this->getJson("/api/v1/patients/{$patient->id}/clinical-summary")->assertOk()->assertJsonPath('data.allergies.0.allergen', 'Penicillin');
        $this->getJson("/api/v1/patients/{$patient->id}/consultations")->assertForbidden();
        $this->postJson('/api/v1/consultations', ['appointment_id' => $appt['id']])->assertForbidden();

        $this->actingAsStaff($doctor->user);
        $this->postJson("/api/v1/queue/{$appt['token']['id']}/start")->assertOk();
        $consult = $this->postJson('/api/v1/consultations', ['appointment_id' => $appt['id']])->assertCreated()->json('data');
        $this->assertSame('general', $consult['template']['key']);

        $this->patchJson("/api/v1/consultations/{$consult['id']}", ['chief_complaint' => 'Fever and sore throat for 3 days', 'notes' => 'Private note', 'follow_up_date' => now()->addDays(5)->toDateString()])->assertOk();
        $this->putJson("/api/v1/consultations/{$consult['id']}/sections/complaints", ['data' => ['complaints' => ['fever', 'sore throat'], 'duration' => '3 days']])->assertOk();
        $this->putJson("/api/v1/consultations/{$consult['id']}/sections/not_in_template", ['data' => []])->assertStatus(422);
        $this->putJson("/api/v1/consultations/{$consult['id']}/diagnoses", ['diagnoses' => [['description' => 'Acute pharyngitis', 'icd10_code' => 'J02.9']]])->assertOk();

        $rx = $this->putJson("/api/v1/consultations/{$consult['id']}/prescription", ['items' => [
            ['medicine_name' => 'Amoxicillin 500', 'frequency' => '1-0-1', 'duration_value' => 5, 'timing' => 'after_food'],
            ['medicine_name' => 'Paracetamol 650', 'frequency' => '1-1-1', 'duration_value' => 3],
        ], 'advice' => 'Warm saline gargles'])->assertOk()->json('data');
        $this->assertSame(10, $rx['prescription']['items'][0]['quantity']);
        $this->assertSame([], $rx['warnings'], 'brand name does not mention penicillin');

        $medicineId = $this->asTenant($c['org'], fn () => Medicine::where('brand_name', 'Mox')->value('id'));
        $warn = $this->postJson('/api/v1/prescriptions/check-allergies', ['patient_id' => $patient->id, 'items' => [['medicine_id' => $medicineId]]])->assertOk()->json('data');
        $this->assertSame('Penicillin', $warn[0]['allergen'], 'drug class match triggers advisory warning');

        $this->postJson("/api/v1/consultations/{$consult['id']}/sign")->assertOk()->assertJsonPath('data.status', 'signed')->assertJsonPath('data.prescription.status', 'signed');
        $this->patchJson("/api/v1/consultations/{$consult['id']}", ['notes' => 'Changed'])->assertStatus(409)->assertJsonPath('error.code', 'CONSULTATION_LOCKED');
        $this->postJson("/api/v1/consultations/{$consult['id']}/addenda", ['content' => 'Throat swab negative'])->assertCreated();

        $this->get("/api/v1/prescriptions/{$rx['prescription']['id']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $share = $this->postJson("/api/v1/prescriptions/{$rx['prescription']['id']}/share", ['channels' => ['whatsapp']])->assertOk()->json('data.url');
        $path = parse_url($share, PHP_URL_PATH);
        $this->get($path)->assertStatus(401);
        $this->get($path.'?verify=1990')->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->postJson("/api/v1/queue/{$appt['token']['id']}/complete")->assertOk();

        // Notes are encrypted at rest and redacted in the audit trail.
        $raw = \DB::table('consultations')->where('id', $consult['id'])->value('notes');
        $this->assertNotSame('Private note', $raw);
        $this->assertStringNotContainsString('Private note', (string) \DB::table('audit_logs')->pluck('new_values')->implode(' '));
        $this->assertTrue(\DB::table('audit_logs')->where('event', 'clinical.viewed')->exists());
    }

    public function test_owner_cannot_read_clinical_notes_unless_org_allows(): void
    {
        $c = $this->makeClinic();
        $patient = $this->addPatient($c['org']);
        $this->actingAsStaff($c['owner']);
        $this->getJson("/api/v1/patients/{$patient->id}/consultations")->assertForbidden();
        $this->patchJson('/api/v1/organization/settings', ['admins_can_view_clinical' => true])->assertOk();
        $this->getJson("/api/v1/patients/{$patient->id}/consultations")->assertOk();
    }

    public function test_another_doctor_cannot_edit_a_consultation(): void
    {
        $c = $this->makeClinic();
        $d1 = $this->addDoctor($c['org'], $c['branch']);
        $d2 = $this->addDoctor($c['org'], $c['branch']);
        $patient = $this->addPatient($c['org']);
        $this->actingAsStaff($d1->user);
        $id = $this->postJson('/api/v1/consultations', ['patient_id' => $patient->id, 'branch_id' => $c['branch']->id])->assertCreated()->json('data.id');
        $this->actingAsStaff($d2->user);
        $this->patchJson("/api/v1/consultations/{$id}", ['chief_complaint' => 'x'])->assertForbidden()->assertJsonPath('error.code', 'NOT_YOUR_CONSULTATION');
    }

    public function test_speciality_template_validation_and_dental_chart(): void
    {
        $c = $this->makeClinic('business');
        $dentist = $this->addDoctor($c['org'], $c['branch'], 'dental');
        $patient = $this->addPatient($c['org']);
        $this->actingAsStaff($dentist->user);
        $consult = $this->postJson('/api/v1/consultations', ['patient_id' => $patient->id, 'branch_id' => $c['branch']->id])->json('data');
        $this->assertSame('dental', $consult['template']['key']);
        $this->putJson("/api/v1/consultations/{$consult['id']}/sections/oral_exam", ['data' => ['oral_hygiene' => 'Excellent']])->assertStatus(422)->assertJsonPath('error.fields.oral_hygiene.0', 'Oral hygiene has an invalid option.');
        $this->putJson("/api/v1/consultations/{$consult['id']}/sections/oral_exam", ['data' => ['oral_hygiene' => 'Fair']])->assertOk();

        $this->postJson("/api/v1/patients/{$patient->id}/dental-chart", ['consultation_id' => $consult['id'], 'entries' => [['tooth_number' => 36, 'surfaces' => 'O,D', 'condition' => 'caries', 'status' => 'existing'], ['tooth_number' => 99, 'condition' => 'caries']]])->assertStatus(422);
        $this->postJson("/api/v1/patients/{$patient->id}/dental-chart", ['consultation_id' => $consult['id'], 'entries' => [['tooth_number' => 36, 'surfaces' => 'O,D', 'condition' => 'caries']]])->assertCreated();
        $this->getJson("/api/v1/patients/{$patient->id}/dental-chart")->assertOk()->assertJsonPath('data.current.36.condition', 'caries');

        $plan = $this->postJson("/api/v1/patients/{$patient->id}/treatment-plans", ['module' => 'physiotherapy', 'name' => 'Knee rehab', 'total_sessions' => 3])->assertCreated()->json('data');
        $this->postJson("/api/v1/treatment-plans/{$plan['id']}/items/{$plan['items'][0]['id']}/complete", ['session_data' => ['pain_before' => 7, 'pain_after' => 5]])->assertOk();
        $this->getJson("/api/v1/treatment-plans/{$plan['id']}/progress")->assertJsonPath('data.completed', 1)->assertJsonPath('data.pain_trend.0.before', 7);
    }

    public function test_speciality_modules_are_plan_gated(): void
    {
        $c = $this->makeClinic('starter');
        $patient = $this->addPatient($c['org']);
        $doctor = $this->addDoctor($c['org'], $c['branch'], 'dental');
        $this->actingAsStaff($doctor->user);
        $this->getJson("/api/v1/patients/{$patient->id}/dental-chart")->assertStatus(402)->assertJsonPath('error.code', 'FEATURE_NOT_IN_PLAN');
    }
}
