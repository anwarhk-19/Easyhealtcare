<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\LabTest;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LabInventoryTest extends TestCase
{
    public function test_lab_order_results_flags_release_and_share(): void
    {
        $c = $this->makeClinic();
        $doctor = $this->addDoctor($c['org'], $c['branch']);
        $patient = $this->addPatient($c['org']);
        $cbc = $this->asTenant($c['org'], fn () => LabTest::where('code', 'CBC')->first());

        $this->actingAsStaff($doctor->user);
        $order = $this->postJson('/api/v1/lab-orders', ['patient_id' => $patient->id, 'branch_id' => $c['branch']->id, 'test_ids' => [$cbc->id], 'priority' => 'urgent'])->assertCreated()->json('data');

        $this->actingAsStaff($tech = $this->addStaff($c['org'], $c['branch'], 'lab_technician'));
        $this->getJson('/api/v1/lab-orders')->assertJsonPath('data.0.id', $order['id']);
        $item = $order['items'][0]['id'];
        $this->postJson("/api/v1/lab-orders/{$order['id']}/items/{$item}/collect-sample")->assertOk();
        $this->postJson("/api/v1/lab-orders/{$order['id']}/release")->assertStatus(409);
        $res = $this->putJson("/api/v1/lab-orders/{$order['id']}/items/{$item}/results", ['values' => ['hb' => 6.5, 'wbc' => 12.1, 'plt' => 250]])->assertOk()->json('data.results');
        $this->assertSame(['critical', 'high', 'normal'], array_column($res, 'flag'));
        $this->getJson("/api/v1/lab-orders/{$order['id']}")->assertJsonPath('data.status', 'report_ready');
        $this->get("/api/v1/lab-orders/{$order['id']}/pdf")->assertOk();
        $this->postJson("/api/v1/lab-orders/{$order['id']}/report", ['file' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf')])->assertOk();
        $this->postJson("/api/v1/lab-orders/{$order['id']}/release")->assertOk()->assertJsonPath('data.status', 'released');
        $this->postJson("/api/v1/lab-orders/{$order['id']}/share")->assertOk();
        $this->putJson("/api/v1/lab-orders/{$order['id']}/items/{$item}/results", ['values' => ['hb' => 9]])->assertStatus(409);

        $this->actingAsStaff($this->addStaff($c['org'], $c['branch'], 'receptionist'));
        $this->putJson("/api/v1/lab-orders/{$order['id']}/items/{$item}/results", ['values' => ['hb' => 9]])->assertForbidden();
    }

    public function test_inventory_fefo_expiry_and_ledger(): void
    {
        $c = $this->makeClinic('professional');
        $this->actingAsStaff($c['owner']);
        [$item, $supplier] = $this->asTenant($c['org'], fn () => [InventoryItem::create(['sku' => 'PCM', 'name' => 'Paracetamol 650', 'reorder_level' => 50]), Supplier::create(['name' => 'Pharma Dist'])]);

        $this->postJson('/api/v1/inventory/goods-receipts', ['branch_id' => $c['branch']->id, 'supplier_id' => $supplier->id, 'lines' => [
            ['item_id' => $item->id, 'batch_no' => 'LATE', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 100, 'cost_price' => 150],
            ['item_id' => $item->id, 'batch_no' => 'SOON', 'expiry_date' => now()->addMonth()->toDateString(), 'quantity' => 30, 'cost_price' => 150],
        ]])->assertCreated();
        $moves = $this->postJson('/api/v1/inventory/dispense', ['item_id' => $item->id, 'branch_id' => $c['branch']->id, 'quantity' => 40])->assertCreated()->json('data');
        $this->assertSame([-30, -10], array_column($moves, 'quantity'), 'earliest expiry first');
        $this->postJson('/api/v1/inventory/dispense', ['item_id' => $item->id, 'branch_id' => $c['branch']->id, 'quantity' => 1000])->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $stock = collect($this->getJson('/api/v1/inventory/stock')->json('data'))->firstWhere('id', $item->id);
        $this->assertSame(90, $stock['qty_on_hand']);
        $this->assertSame(90, $this->asTenant($c['org'], fn () => (int) StockMovement::where('item_id', $item->id)->sum('quantity')), 'ledger reconciles with on-hand');
        $this->getJson('/api/v1/inventory/expiring?days=45')->assertJsonCount(0, 'data');

        $batch = $this->asTenant($c['org'], fn () => StockBatch::where('batch_no', 'LATE')->first());
        $this->postJson('/api/v1/inventory/adjustments', ['batch_id' => $batch->id, 'delta' => -100, 'reason' => 'count'])->assertStatus(422);
        $this->postJson('/api/v1/inventory/adjustments', ['batch_id' => $batch->id, 'delta' => -5, 'reason' => 'damaged'])->assertCreated();
    }

    public function test_inventory_is_plan_gated_and_can_be_switched_off(): void
    {
        $starter = $this->makeClinic('starter');
        $this->actingAsStaff($starter['owner']);
        $this->getJson('/api/v1/inventory/stock')->assertStatus(402);

        $pro = $this->makeClinic('professional');
        $this->actingAsStaff($pro['owner']);
        $this->getJson('/api/v1/inventory/stock')->assertOk();
        $this->patchJson('/api/v1/organization/modules/inventory', ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson('/api/v1/inventory/stock')->assertStatus(402);
    }
}
