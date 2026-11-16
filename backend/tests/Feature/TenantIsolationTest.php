<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\DoctorSchedule;
use App\Models\Invoice;
use App\Models\Patient;
use App\Services\AppointmentService;
use App\Services\BillingService;
use App\Support\Tenancy\MissingTenantContext;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/** The most important suite: one clinic must never see or touch another clinic's data. */
class TenantIsolationTest extends TestCase
{
    private array $a;

    private array $b;

    private Patient $patientB;

    private Appointment $apptB;

    private Invoice $invoiceB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->makeClinic();
        $this->b = $this->makeClinic();
        $doctorB = $this->addDoctor($this->b['org'], $this->b['branch']);
        $this->patientB = $this->addPatient($this->b['org'], ['first_name' => 'Secret', 'mobile' => '+919111111111']);
        $slot = $this->nextSlot($doctorB, $this->b['branch']);
        [$this->apptB, $this->invoiceB] = $this->asTenant($this->b['org'], function () use ($doctorB, $slot) {
            $appt = app(AppointmentService::class)->book(['patient_id' => $this->patientB->id, 'doctor_profile_id' => $doctorB->id, 'branch_id' => $this->b['branch']->id, 'scheduled_start' => $slot]);
            $inv = app(BillingService::class)->createDraft($this->patientB, $this->b['branch']->id, [['description' => 'X', 'unit_price' => 1000]]);

            return [$appt, $inv];
        });
        $this->ctx()->clear();
    }

    public function test_lists_never_include_other_tenants_records(): void
    {
        $this->addPatient($this->a['org'], ['first_name' => 'Mine']);
        $this->actingAsStaff($this->a['owner']);

        $patients = $this->getJson('/api/v1/patients')->assertOk()->json('data');
        $this->assertCount(1, $patients);
        $this->assertSame('Mine', $patients[0]['first_name']);
        $this->getJson('/api/v1/patients?q=Secret')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/patients?q=9111111111')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/doctors')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_direct_access_to_other_tenants_ids_returns_404(): void
    {
        $this->actingAsStaff($this->a['owner']);
        foreach ([
            ['GET', "/api/v1/patients/{$this->patientB->id}"],
            ['GET', "/api/v1/patients/{$this->patientB->id}/timeline"],
            ['PATCH', "/api/v1/patients/{$this->patientB->id}", ['first_name' => 'Hacked']],
            ['GET', "/api/v1/appointments/{$this->apptB->id}"],
            ['POST', "/api/v1/appointments/{$this->apptB->id}/cancel", ['reason' => 'x']],
            ['GET', "/api/v1/invoices/{$this->invoiceB->id}"],
            ['PUT', "/api/v1/invoices/{$this->invoiceB->id}/items", ['items' => []]],
            ['GET', "/api/v1/branches/{$this->b['branch']->id}"],
        ] as $req) {
            $this->json($req[0], $req[1], $req[2] ?? [])->assertNotFound();
        }
        $this->assertSame('Secret', $this->asTenant($this->b['org'], fn () => $this->patientB->fresh()->first_name));
    }

    public function test_cannot_create_records_referencing_another_tenant(): void
    {
        $doctorA = $this->addDoctor($this->a['org'], $this->a['branch']);
        $this->actingAsStaff($this->a['owner']);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $this->patientB->id, 'doctor_profile_id' => $doctorA->id,
            'branch_id' => $this->a['branch']->id, 'scheduled_start' => $this->nextSlot($doctorA, $this->a['branch']),
        ])->assertNotFound();

        $this->postJson('/api/v1/invoices', ['patient_id' => $this->patientB->id, 'branch_id' => $this->a['branch']->id, 'items' => []])->assertNotFound();
    }

    public function test_database_composite_foreign_key_blocks_cross_tenant_links(): void
    {
        $doctorA = $this->addDoctor($this->a['org'], $this->a['branch']);
        $this->expectException(QueryException::class);
        $this->asTenant($this->a['org'], fn () => Appointment::create([
            'branch_id' => $this->a['branch']->id, 'patient_id' => $this->patientB->id, 'doctor_profile_id' => $doctorA->id,
            'scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addMinutes(15), 'status' => 'booked',
        ]));
    }

    public function test_cannot_select_an_organisation_without_membership(): void
    {
        $this->actingAsStaff($this->a['owner'], $this->b['org']);
        $this->getJson('/api/v1/patients')->assertForbidden()->assertJsonPath('error.code', 'NO_ORGANIZATION');
    }

    public function test_tenant_queries_fail_closed_without_context(): void
    {
        $this->expectException(MissingTenantContext::class);
        Patient::count();
    }

    public function test_organization_id_cannot_be_mass_assigned_or_changed(): void
    {
        $p = $this->addPatient($this->a['org']);
        $this->asTenant($this->a['org'], function () use ($p) {
            $p->update(['organization_id' => $this->b['org']->id, 'first_name' => 'Still mine']);
            $this->assertSame($this->a['org']->id, $p->fresh()->organization_id);
        });
        $this->expectException(\LogicException::class);
        $this->asTenant($this->a['org'], fn () => $p->forceFill(['organization_id' => $this->b['org']->id])->save());
    }

    public function test_branch_scoped_staff_only_see_their_branch(): void
    {
        $branch2 = $this->asTenant($this->a['org'], fn () => Branch::create(['name' => 'Second', 'code' => 'B2']));
        $doctor = $this->addDoctor($this->a['org'], $this->a['branch']);
        $this->asTenant($this->a['org'], function () use ($doctor, $branch2) {
            $doctor->branches()->attach($branch2->id);
            DoctorSchedule::create(['branch_id' => $branch2->id, 'doctor_profile_id' => $doctor->id, 'weekday' => now('Asia/Kolkata')->addDay()->dayOfWeek, 'start_time' => '00:00', 'end_time' => '23:45', 'slot_minutes' => 15]);
        });
        $patient = $this->addPatient($this->a['org']);
        $this->asTenant($this->a['org'], fn () => app(AppointmentService::class)->book(['patient_id' => $patient->id, 'doctor_profile_id' => $doctor->id, 'branch_id' => $branch2->id, 'scheduled_start' => $this->nextSlot($doctor, $branch2)]));
        $this->ctx()->clear();

        $reception = $this->addStaff($this->a['org'], $this->a['branch'], 'receptionist');
        $this->actingAsStaff($reception);
        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/queue?branch_id='.$branch2->id)->assertForbidden();

        $this->actingAsStaff($this->a['owner']);
        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(1, 'data');
    }
}
