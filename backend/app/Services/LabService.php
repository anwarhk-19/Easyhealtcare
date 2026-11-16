<?php

namespace App\Services;

use App\Events\UserNotified;
use App\Exceptions\BusinessRuleException;
use App\Models\DoctorProfile;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Support\Sequence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class LabService
{
    public function __construct(private TenantContext $context, private DocumentService $documents, private NotificationService $notifications) {}

    public function order(Patient $patient, string $branchId, array $testIds, ?string $consultationId = null, ?string $doctorId = null, string $priority = 'routine', ?string $notes = null): LabOrder
    {
        $tests = LabTest::whereIn('id', $testIds)->where('is_active', true)->get();
        if ($tests->count() !== count(array_unique($testIds))) {
            throw new BusinessRuleException('UNKNOWN_TEST', 'One or more tests are not in the catalogue.');
        }

        return DB::transaction(function () use ($patient, $branchId, $tests, $consultationId, $doctorId, $priority, $notes) {
            $n = Sequence::next('lab_order', '', Sequence::financialYear());
            $order = LabOrder::create([
                'branch_id' => $branchId,
                'patient_id' => $patient->id,
                'consultation_id' => $consultationId,
                'doctor_profile_id' => $doctorId,
                'order_number' => sprintf('LAB/%s/%05d', Sequence::financialYear(), $n),
                'priority' => $priority,
                'notes' => $notes,
                'ordered_by' => auth()->id(),
            ]);
            foreach ($tests as $test) {
                $order->items()->create(['lab_test_id' => $test->id]);
            }

            return $order->load('items.test');
        });
    }

    public function collectSample(LabOrderItem $item, ?string $barcode = null): LabOrderItem
    {
        $this->assertOpen($item->lab_order_id);
        $item->update(['status' => 'sample_collected', 'sample_collected_at' => now(), 'sample_barcode' => $barcode ?? strtoupper(substr($item->id, -10))]);
        $this->syncOrderStatus($item->lab_order_id);

        return $item;
    }

    /** Stores structured results and flags them against the reference range. */
    public function enterResults(LabOrderItem $item, array $values): LabOrderItem
    {
        $this->assertOpen($item->lab_order_id);
        $params = collect($item->test->parameters ?? [])->keyBy('key');
        $results = [];
        foreach ($values as $key => $value) {
            $p = $params->get($key);
            $flag = 'normal';
            if ($p && is_numeric($value)) {
                if (isset($p['critical_low']) && $value < $p['critical_low'] || isset($p['critical_high']) && $value > $p['critical_high']) {
                    $flag = 'critical';
                } elseif (isset($p['low']) && $value < $p['low']) {
                    $flag = 'low';
                } elseif (isset($p['high']) && $value > $p['high']) {
                    $flag = 'high';
                }
            }
            $results[] = ['key' => $key, 'name' => $p['name'] ?? $key, 'value' => $value, 'unit' => $p['unit'] ?? null, 'range' => isset($p['low'], $p['high']) ? "{$p['low']}–{$p['high']}" : null, 'flag' => $flag];
        }
        $item->update(['results' => $results, 'status' => 'completed', 'result_entered_by' => auth()->id()]);
        $this->syncOrderStatus($item->lab_order_id);

        return $item;
    }

    public function attachReport(LabOrder $order, UploadedFile $file): LabOrder
    {
        $this->assertOpen($order->id);
        $doc = $this->documents->store($order->patient, $file, 'report', 'Lab report '.$order->order_number, $order->branch_id, ['lab_order_id' => $order->id]);
        $order->update(['report_document_id' => $doc->id, 'status' => 'report_ready']);

        return $order;
    }

    public function release(LabOrder $order): LabOrder
    {
        if (! in_array($order->status, ['report_ready'], true)) {
            throw BusinessRuleException::conflict('REPORT_NOT_READY', 'All results or a report must be available before release.');
        }
        $isDoctor = DoctorProfile::where('user_id', auth()->id())->exists();
        if (! $isDoctor && ! $this->context->organization()->setting('lab_tech_can_release')) {
            throw new BusinessRuleException('DOCTOR_VERIFICATION_REQUIRED', 'A doctor must verify this report before release.', 403);
        }
        $order->update(['status' => 'released', 'released_at' => now(), 'verified_by' => auth()->id()]);

        if ($order->doctor_profile_id && ($doctor = DoctorProfile::find($order->doctor_profile_id))) {
            event(new UserNotified($order->organization_id, $doctor->user_id, 'lab_report_ready'));
        }

        return $order;
    }

    public function share(LabOrder $order, array $channels, ShareLinkService $links): array
    {
        if ($order->status !== 'released') {
            throw BusinessRuleException::conflict('REPORT_NOT_RELEASED', 'Release the report before sharing it.');
        }
        $share = $links->create($order, $order->patient_id);
        $order->update(['shared_with_patient_at' => now()]);
        PatientDocument::whereKey($order->report_document_id)->update(['shared_with_patient_at' => now()]);
        $this->notifications->send('lab.report_ready', $order->patient, ['link' => $share['url'], 'order_number' => $order->order_number], $order, $channels);

        return ['url' => $share['url'], 'expires_at' => $share['link']->expires_at];
    }

    public function cancel(LabOrder $order): LabOrder
    {
        $this->assertOpen($order->id);
        $order->update(['status' => 'cancelled']);

        return $order;
    }

    private function assertOpen(string $orderId): void
    {
        $order = LabOrder::findOrFail($orderId);
        if (in_array($order->status, ['released', 'cancelled'], true)) {
            throw BusinessRuleException::conflict('LAB_ORDER_CLOSED', 'This lab order is closed.');
        }
    }

    private function syncOrderStatus(string $orderId): void
    {
        $order = LabOrder::with('items')->findOrFail($orderId);
        $statuses = $order->items->pluck('status');
        $status = match (true) {
            $statuses->every(fn ($s) => $s === 'completed') => 'report_ready',
            $statuses->contains('completed') || $statuses->contains('sample_collected') => $statuses->contains('completed') ? 'in_progress' : 'sample_collected',
            default => 'ordered',
        };
        $order->update(['status' => $status]);
    }
}
