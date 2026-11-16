<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Service;
use App\Support\Money;
use App\Support\Sequence;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function __construct(private TenantContext $context, private NotificationService $notifications) {}

    public function createDraft(Patient $patient, string $branchId, array $items = [], ?Appointment $appointment = null): Invoice
    {
        return DB::transaction(function () use ($patient, $branchId, $items, $appointment) {
            $invoice = Invoice::create([
                'branch_id' => $branchId,
                'patient_id' => $patient->id,
                'appointment_id' => $appointment?->id,
                'consultation_id' => $appointment?->consultation?->id,
                'invoice_date' => now()->toDateString(),
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            return $items ? $this->setItems($invoice, $items) : $invoice;
        });
    }

    /** Pre-fills consultation fee (new vs follow-up rule) and any ordered lab tests. */
    public function fromAppointment(Appointment $a): Invoice
    {
        if ($existing = $a->invoice) {
            return $existing->load('items');
        }
        $a->loadMissing('doctor.fees', 'consultation', 'patient');
        $items = [];

        $windowDays = (int) $this->context->organization()->setting('follow_up_window_days', 7);
        $isFollowUp = Appointment::withoutGlobalScope('branch')->where('patient_id', $a->patient_id)
            ->where('doctor_profile_id', $a->doctor_profile_id)->where('id', '!=', $a->id)
            ->where('status', 'completed')->where('scheduled_start', '>=', $a->scheduled_start->copy()->subDays($windowDays))
            ->where('scheduled_start', '<', $a->scheduled_start)->exists();
        $fee = $a->doctor->feeFor($isFollowUp ? 'follow_up' : 'new', $a->branch_id) ?? $a->doctor->feeFor('new', $a->branch_id);
        if ($fee) {
            $items[] = ['item_type' => 'consultation', 'description' => ($isFollowUp ? 'Follow-up consultation' : 'Consultation').' — '.$a->doctor->display_name, 'quantity' => 1, 'unit_price' => $fee->amount, 'doctor_profile_id' => $a->doctor_profile_id];
        }
        if ($a->service_id && ($service = Service::with('taxRate', 'branchPrices')->find($a->service_id))) {
            $items[] = $this->serviceLine($service, $a->branch_id, $a->doctor_profile_id);
        }
        if ($a->consultation) {
            foreach (LabOrder::with('items.test')->where('consultation_id', $a->consultation->id)->whereNull('invoice_id')->get() as $order) {
                foreach ($order->items as $item) {
                    $items[] = ['item_type' => 'lab_test', 'item_id' => $item->lab_test_id, 'description' => 'Lab: '.$item->test->name, 'quantity' => 1, 'unit_price' => $item->test->price];
                }
            }
        }

        $invoice = $this->createDraft($a->patient, $a->branch_id, $items, $a);
        LabOrder::where('consultation_id', $a->consultation?->id)->whereNull('invoice_id')->update(['invoice_id' => $invoice->id]);

        return $invoice;
    }

    public function serviceLine(Service $s, ?string $branchId, ?string $doctorId = null): array
    {
        return ['item_type' => 'service', 'item_id' => $s->id, 'description' => $s->name, 'hsn_sac' => $s->hsn_sac, 'quantity' => 1, 'unit_price' => $s->priceFor($branchId), 'tax_rate' => $s->taxRate?->rate ?? 0, 'doctor_profile_id' => $doctorId];
    }

    /**
     * Replaces invoice lines and recalculates totals. Amounts are integer paise.
     * line: gross = qty × price; discount (flat paise | percent); tax on (gross − discount); total = taxable + tax.
     */
    public function setItems(Invoice $invoice, array $items): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw BusinessRuleException::conflict('INVOICE_NOT_DRAFT', 'Only draft invoices can be edited.');
        }

        return DB::transaction(function () use ($invoice, $items) {
            $invoice->items()->delete();
            $subtotal = $discount = $tax = 0;
            foreach ($items as $item) {
                $item = $this->resolveItem($item, $invoice->branch_id);
                $qty = (float) ($item['quantity'] ?? 1);
                $gross = (int) round($qty * (int) $item['unit_price']);
                $lineDiscount = match ($item['discount_type'] ?? null) {
                    'percent' => (int) round($gross * min(100, (float) $item['discount_value']) / 100),
                    'flat' => min($gross, (int) $item['discount_value']),
                    default => 0,
                };
                $rate = (float) ($item['tax_rate'] ?? 0);
                $lineTax = (int) round(($gross - $lineDiscount) * $rate / 100);
                $invoice->items()->create([
                    'item_type' => $item['item_type'] ?? 'custom',
                    'item_id' => $item['item_id'] ?? null,
                    'description' => $item['description'],
                    'hsn_sac' => $item['hsn_sac'] ?? null,
                    'quantity' => $qty,
                    'unit_price' => (int) $item['unit_price'],
                    'discount_type' => $item['discount_type'] ?? null,
                    'discount_value' => $item['discount_value'] ?? 0,
                    'discount_amount' => $lineDiscount,
                    'tax_rate' => $rate,
                    'tax_amount' => $lineTax,
                    'line_total' => $gross - $lineDiscount + $lineTax,
                    'doctor_profile_id' => $item['doctor_profile_id'] ?? null,
                ]);
                $subtotal += $gross;
                $discount += $lineDiscount;
                $tax += $lineTax;
            }
            $exact = $subtotal - $discount + $tax;
            $rounded = (int) (round($exact / 100) * 100); // round to nearest rupee
            $threshold = (float) $this->context->organization()->setting('discount_approval_threshold_percent', 10);
            $needsApproval = $subtotal > 0 && ($discount / $subtotal * 100) > $threshold && ! $this->context->can('discounts.approve');

            $invoice->update([
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'tax_total' => $tax,
                'round_off' => $rounded - $exact,
                'grand_total' => $rounded,
                'balance_due' => $rounded - $invoice->amount_paid,
                'discount_status' => $discount === 0 ? 'none' : ($needsApproval ? 'pending' : 'approved'),
                'discount_approved_by' => $discount > 0 && ! $needsApproval ? auth()->id() : null,
            ]);

            return $invoice->load('items');
        });
    }

    private function resolveItem(array $item, string $branchId): array
    {
        if (($item['item_type'] ?? null) === 'service' && ! empty($item['item_id']) && ! isset($item['unit_price'])) {
            $service = Service::with('taxRate', 'branchPrices')->findOrFail($item['item_id']);
            $item = array_merge($this->serviceLine($service, $branchId), array_filter($item, fn ($v) => $v !== null));
        }
        if (! isset($item['unit_price'], $item['description'])) {
            throw new BusinessRuleException('INVALID_LINE', 'Each line needs a description and unit price.');
        }

        return $item;
    }

    public function decideDiscount(Invoice $invoice, bool $approve): Invoice
    {
        if ($invoice->discount_status !== 'pending') {
            throw BusinessRuleException::conflict('NO_PENDING_DISCOUNT', 'There is no discount awaiting approval.');
        }
        if ($approve) {
            $invoice->update(['discount_status' => 'approved', 'discount_approved_by' => auth()->id()]);

            return $invoice;
        }
        // Rejected: strip discounts and recalculate.
        $items = $invoice->items->map(fn ($i) => $i->only(['item_type', 'item_id', 'description', 'hsn_sac', 'quantity', 'unit_price', 'tax_rate', 'doctor_profile_id']))->all();
        $this->setItems($invoice, $items);
        $invoice->update(['discount_status' => 'rejected']);

        return $invoice;
    }

    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw BusinessRuleException::conflict('INVOICE_NOT_DRAFT', 'This invoice has already been issued.');
        }
        if ($invoice->discount_status === 'pending') {
            throw BusinessRuleException::conflict('DISCOUNT_APPROVAL_PENDING', 'The discount must be approved before issuing.');
        }
        if (! $invoice->items()->exists()) {
            throw new BusinessRuleException('INVOICE_EMPTY', 'Add at least one line before issuing.');
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->loadMissing('branch');
            $fy = Sequence::financialYear($invoice->invoice_date);
            $n = Sequence::next('invoice', $invoice->branch_id, $fy);
            $invoice->update([
                'invoice_number' => sprintf('%s/%s/%s/%05d', $invoice->branch->invoice_prefix ?: 'INV', $invoice->branch->code, $fy, $n),
                'status' => $invoice->grand_total === 0 ? 'paid' : 'issued',
                'balance_due' => $invoice->grand_total - $invoice->amount_paid,
            ]);

            return $invoice;
        });
    }

    public function void(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status === 'void') {
            return $invoice;
        }
        if ($invoice->amount_paid > 0) {
            throw BusinessRuleException::conflict('INVOICE_HAS_PAYMENTS', 'Refund the payments on this invoice before voiding it.');
        }
        $invoice->update(['status' => 'void', 'void_reason' => $reason, 'balance_due' => 0]);

        return $invoice;
    }

    /**
     * Records a payment and allocates it to invoices; any unallocated remainder stays as patient advance credit.
     *
     * @param  array<int, array{invoice_id:string, amount:int}>  $allocations
     */
    public function recordPayment(Patient $patient, string $branchId, int $amount, string $method, array $allocations, ?string $reference = null, ?string $idempotencyKey = null): Payment
    {
        if ($idempotencyKey && ($existing = Payment::withoutGlobalScope('branch')->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing->load('allocations');
        }
        if ($amount <= 0) {
            throw new BusinessRuleException('INVALID_AMOUNT', 'Payment amount must be positive.');
        }
        if (array_sum(array_column($allocations, 'amount')) > $amount) {
            throw new BusinessRuleException('OVER_ALLOCATED', 'Allocations exceed the payment amount.');
        }

        try {
            $payment = DB::transaction(function () use ($patient, $branchId, $amount, $method, $allocations, $reference, $idempotencyKey) {
                $n = Sequence::next('receipt', $branchId, Sequence::financialYear());
                $payment = Payment::create([
                    'branch_id' => $branchId,
                    'patient_id' => $patient->id,
                    'receipt_number' => sprintf('RCT/%s/%06d', Sequence::financialYear(), $n),
                    'amount' => $amount,
                    'method' => $method,
                    'reference_no' => $reference,
                    'status' => 'success',
                    'received_by' => auth()->id(),
                    'received_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);
                foreach ($allocations as $alloc) {
                    $invoice = Invoice::whereKey($alloc['invoice_id'])->lockForUpdate()->firstOrFail();
                    if ($invoice->patient_id !== $patient->id || ! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
                        throw new BusinessRuleException('INVOICE_NOT_PAYABLE', "Invoice {$invoice->invoice_number} cannot receive payments.");
                    }
                    if ($alloc['amount'] > $invoice->balance_due) {
                        throw new BusinessRuleException('OVERPAYMENT', "Allocation exceeds the balance of {$invoice->invoice_number}.");
                    }
                    $payment->allocations()->create(['invoice_id' => $invoice->id, 'amount' => $alloc['amount']]);
                    $this->applyToInvoice($invoice, (int) $alloc['amount']);
                }

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey) {
                return Payment::withoutGlobalScope('branch')->where('idempotency_key', $idempotencyKey)->firstOrFail()->load('allocations');
            }
            throw $e;
        }

        $this->notifications->send('payment.received', $patient, ['amount' => Money::format($amount), 'receipt_number' => $payment->receipt_number], $payment);

        return $payment->load('allocations');
    }

    private function applyToInvoice(Invoice $invoice, int $delta): void
    {
        $paid = $invoice->amount_paid + $delta;
        $balance = $invoice->grand_total - $paid;
        $invoice->update([
            'amount_paid' => $paid,
            'balance_due' => $balance,
            'status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued'),
        ]);
    }

    public function patientBalance(Patient $patient): array
    {
        $outstanding = (int) Invoice::withoutGlobalScope('branch')->where('patient_id', $patient->id)->whereIn('status', ['issued', 'partially_paid'])->sum('balance_due');
        $paid = (int) Payment::withoutGlobalScope('branch')->where('patient_id', $patient->id)->where('status', 'success')->sum(DB::raw('amount - refunded_amount'));
        $allocated = (int) DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.patient_id', $patient->id)->where('payments.organization_id', $patient->organization_id)->sum('payment_allocations.amount');
        $refundedAllocated = (int) Refund::withoutGlobalScope('branch')->whereNotNull('invoice_id')->where('status', 'processed')->whereIn('payment_id', Payment::withoutGlobalScope('branch')->where('patient_id', $patient->id)->select('id'))->sum('amount');

        return ['outstanding' => $outstanding, 'advance_credit' => max(0, $paid - $allocated + $refundedAllocated)];
    }

    public function requestRefund(Payment $payment, int $amount, string $reason, ?string $invoiceId = null, ?string $method = null): Refund
    {
        $pending = (int) $payment->refunds()->whereIn('status', ['requested', 'approved'])->sum('amount');
        if ($amount <= 0 || $amount > $payment->amount - $payment->refunded_amount - $pending) {
            throw new BusinessRuleException('REFUND_EXCEEDS_PAYMENT', 'Refund amount exceeds the refundable balance of this payment.');
        }
        if ($invoiceId && ! $payment->allocations()->where('invoice_id', $invoiceId)->exists()) {
            throw new BusinessRuleException('INVOICE_NOT_ON_PAYMENT', 'This payment was not applied to that invoice.');
        }

        return Refund::create([
            'branch_id' => $payment->branch_id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'reason' => $reason,
            'method' => $method ?? $payment->method,
            'requested_by' => auth()->id(),
        ]);
    }

    public function decideRefund(Refund $refund, bool $approve): Refund
    {
        if ($refund->status !== 'requested') {
            throw BusinessRuleException::conflict('INVALID_TRANSITION', 'This refund has already been decided.');
        }
        if ($approve && $refund->requested_by === auth()->id() && ! $this->context->hasRole('clinic_owner')) {
            throw new BusinessRuleException('SELF_APPROVAL', 'A refund must be approved by someone other than the requester.', 403);
        }
        $refund->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => auth()->id()]);

        return $refund;
    }

    public function processRefund(Refund $refund): Refund
    {
        if ($refund->status !== 'approved') {
            throw BusinessRuleException::conflict('REFUND_NOT_APPROVED', 'Only approved refunds can be processed.');
        }

        return DB::transaction(function () use ($refund) {
            $payment = Payment::withoutGlobalScope('branch')->lockForUpdate()->findOrFail($refund->payment_id);
            $payment->update(['refunded_amount' => $payment->refunded_amount + $refund->amount]);
            if ($refund->invoice_id) {
                $invoice = Invoice::withoutGlobalScope('branch')->lockForUpdate()->findOrFail($refund->invoice_id);
                $this->applyToInvoice($invoice, -$refund->amount);
            }
            $n = Sequence::next('credit_note', $refund->branch_id, Sequence::financialYear());
            $refund->update(['status' => 'processed', 'processed_at' => now(), 'credit_note_number' => sprintf('CN/%s/%05d', Sequence::financialYear(), $n)]);

            return $refund;
        });
    }

    public function dailyCollection(string $branchId, string $date, ?string $userId = null): array
    {
        $q = Payment::where('branch_id', $branchId)->where('status', 'success')->whereDate('received_at', $date);
        if ($userId) {
            $q->where('received_by', $userId);
        }
        $byMethod = (clone $q)->selectRaw('method, count(*) as count, sum(amount) as total')->groupBy('method')->get();
        $refunds = Refund::where('branch_id', $branchId)->where('status', 'processed')->whereDate('processed_at', $date)
            ->when($userId, fn ($r) => $r->where('requested_by', $userId))->sum('amount');

        return [
            'date' => $date,
            'by_method' => $byMethod,
            'gross' => (int) $byMethod->sum('total'),
            'refunds' => (int) $refunds,
            'net' => (int) $byMethod->sum('total') - (int) $refunds,
            'cash_expected' => (int) ($byMethod->firstWhere('method', 'cash')?->total ?? 0),
        ];
    }

    public function pdf(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->load('items', 'patient', 'branch', 'allocations');

        return Pdf::loadView('pdf.invoice', ['invoice' => $invoice, 'org' => $invoice->organization, 'branch' => $invoice->branch]);
    }
}
