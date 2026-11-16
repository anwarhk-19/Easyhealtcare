<?php

namespace Tests\Feature;

use App\Models\Appointment;
use Tests\TestCase;

class BillingTest extends TestCase
{
    private array $c;

    private $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->makeClinic();
        $this->patient = $this->addPatient($this->c['org']);
    }

    private function draft(array $items)
    {
        return $this->postJson('/api/v1/invoices', ['patient_id' => $this->patient->id, 'branch_id' => $this->c['branch']->id, 'items' => $items]);
    }

    public function test_gst_discount_rounding_and_numbering(): void
    {
        $this->actingAsStaff($this->addStaff($this->c['org'], $this->c['branch'], 'accountant'));
        $inv = $this->draft([
            ['description' => 'Consultation', 'unit_price' => 50000],
            ['description' => 'Cosmetic peel', 'unit_price' => 199999, 'tax_rate' => 18, 'discount_type' => 'flat', 'discount_value' => 9999],
        ])->assertCreated()->json('data');
        $this->assertSame(249999, $inv['subtotal']);
        $this->assertSame(9999, $inv['discount_total']);
        $this->assertSame(34200, $inv['tax_total']);          // 18% of 1,90,000
        $this->assertSame(274200, $inv['grand_total']);       // 50,000 + 1,90,000 + 34,200 — already whole rupees
        $issued = $this->postJson("/api/v1/invoices/{$inv['id']}/issue")->assertOk()->json('data');
        $this->assertMatchesRegularExpression('#^INV/MAIN/\d{4}-\d{2}/00001$#', $issued['invoice_number']);
        $this->putJson("/api/v1/invoices/{$inv['id']}/items", ['items' => []])->assertStatus(409);
        $this->get("/api/v1/invoices/{$inv['id']}/pdf")->assertOk();
    }

    public function test_large_discount_by_receptionist_needs_approval(): void
    {
        $reception = $this->addStaff($this->c['org'], $this->c['branch'], 'receptionist');
        $this->actingAsStaff($reception);
        $id = $this->draft([['description' => 'Procedure', 'unit_price' => 100000, 'discount_type' => 'percent', 'discount_value' => 25]])->json('data.id');
        $this->getJson("/api/v1/invoices/{$id}")->assertJsonPath('data.discount_status', 'pending');
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertStatus(409)->assertJsonPath('error.code', 'DISCOUNT_APPROVAL_PENDING');
        $this->postJson("/api/v1/invoices/{$id}/discount-decision", ['approve' => true])->assertForbidden();

        $this->actingAsStaff($this->addStaff($this->c['org'], $this->c['branch'], 'accountant'));
        $this->postJson("/api/v1/invoices/{$id}/discount-decision", ['approve' => false])->assertOk()->assertJsonPath('data.discount_total', 0)->assertJsonPath('data.grand_total', 100000);

        $this->actingAsStaff($this->addStaff($this->c['org'], $this->c['branch'], 'nurse'));
        $this->draft([['description' => 'x', 'unit_price' => 100]])->assertForbidden();
    }

    public function test_partial_payments_refunds_and_void_rules(): void
    {
        $this->actingAsStaff($this->addStaff($this->c['org'], $this->c['branch'], 'receptionist'));
        $id = $this->draft([['description' => 'Procedure', 'unit_price' => 100000]])->json('data.id');
        $this->postJson("/api/v1/invoices/{$id}/issue")->assertOk();

        $pay = fn ($amount, $alloc, $key = null) => $this->postJson('/api/v1/payments', ['patient_id' => $this->patient->id, 'branch_id' => $this->c['branch']->id, 'amount' => $amount, 'method' => 'upi', 'allocations' => [['invoice_id' => $id, 'amount' => $alloc]]], $key ? ['Idempotency-Key' => $key] : []);
        $p1 = $pay(40000, 40000, 'k1')->assertCreated()->json('data');
        $this->assertSame($p1['id'], $pay(40000, 40000, 'k1')->json('data.id'), 'idempotent retry returns the same payment');
        $this->getJson("/api/v1/invoices/{$id}")->assertJsonPath('data.status', 'partially_paid')->assertJsonPath('data.balance_due', 60000);
        $pay(70000, 70000)->assertStatus(422)->assertJsonPath('error.code', 'OVERPAYMENT');
        $pay(80000, 60000)->assertCreated(); // ₹200 advance credit
        $this->getJson("/api/v1/invoices/{$id}")->assertJsonPath('data.status', 'paid');
        $this->getJson("/api/v1/patients/{$this->patient->id}/balance")->assertJsonPath('data.outstanding', 0)->assertJsonPath('data.advance_credit', 20000);

        $this->postJson("/api/v1/invoices/{$id}/void", ['reason' => 'x'])->assertForbidden();
        $refund = $this->postJson("/api/v1/payments/{$p1['id']}/refunds", ['amount' => 40000, 'reason' => 'Procedure not done', 'invoice_id' => $id])->assertCreated()->json('data');
        $this->postJson("/api/v1/payments/{$p1['id']}/refunds", ['amount' => 1, 'reason' => 'again'])->assertStatus(422)->assertJsonPath('error.code', 'REFUND_EXCEEDS_PAYMENT');

        $acct = $this->addStaff($this->c['org'], $this->c['branch'], 'accountant');
        $this->actingAsStaff($acct);
        $this->postJson("/api/v1/refunds/{$refund['id']}/decide", ['approve' => true])->assertOk();
        $this->postJson("/api/v1/refunds/{$refund['id']}/process")->assertOk()->assertJsonPath('data.status', 'processed');
        $this->getJson("/api/v1/invoices/{$id}")->assertJsonPath('data.status', 'partially_paid')->assertJsonPath('data.balance_due', 40000);
        $this->postJson("/api/v1/invoices/{$id}/void", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'INVOICE_HAS_PAYMENTS');

        $daily = $this->getJson('/api/v1/collections/daily?branch_id='.$this->c['branch']->id)->assertOk()->json('data');
        $this->assertSame(120000, $daily['gross']);
        $this->assertSame(80000, $daily['net']);
        $this->get('/api/v1/payments/'.$p1['id'].'/receipt')->assertOk();
    }

    public function test_invoice_from_appointment_applies_follow_up_fee(): void
    {
        $doctor = $this->addDoctor($this->c['org'], $this->c['branch']);
        $this->asTenant($this->c['org'], function () use ($doctor) {
            $past = now()->subDays(3);
            Appointment::create(['branch_id' => $this->c['branch']->id, 'patient_id' => $this->patient->id, 'doctor_profile_id' => $doctor->id, 'type' => 'walk_in', 'scheduled_start' => $past, 'scheduled_end' => $past->copy()->addMinutes(15), 'status' => 'completed']);
        });
        $this->actingAsStaff($this->addStaff($this->c['org'], $this->c['branch'], 'receptionist'));
        $appt = $this->postJson('/api/v1/appointments/walk-in', ['patient_id' => $this->patient->id, 'doctor_profile_id' => $doctor->id, 'branch_id' => $this->c['branch']->id])->json('data');
        $inv = $this->postJson("/api/v1/invoices/from-appointment/{$appt['id']}")->assertCreated()->json('data');
        $this->assertSame(25000, $inv['grand_total']);
        $this->assertStringStartsWith('Follow-up consultation', $inv['items'][0]['description']);
        $this->assertSame($inv['id'], $this->postJson("/api/v1/invoices/from-appointment/{$appt['id']}")->json('data.id'), 'no duplicate invoice');
    }
}
