<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Refund;
use Carbon\CarbonImmutable;

/**
 * Aggregates for dashboards and reports. All queries go through tenant (and branch) scopes.
 * Returns aggregated, non-identifying numbers only — safe to pass to the analytics AI module.
 */
class ReportService
{
    public function range(?string $from, ?string $to): array
    {
        $from = $from ? CarbonImmutable::parse($from)->startOfDay() : CarbonImmutable::now()->startOfMonth();
        $to = $to ? CarbonImmutable::parse($to)->endOfDay() : CarbonImmutable::now()->endOfDay();

        return [$from, $to];
    }

    public function dashboard(?string $from, ?string $to, ?string $branchId = null): array
    {
        [$from, $to] = $this->range($from, $to);
        $today = CarbonImmutable::now();
        $appts = Appointment::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $byStatus = (clone $appts)->whereBetween('scheduled_start', [$from, $to])
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'patients' => [
                'total' => Patient::count(),
                'new' => Patient::whereBetween('created_at', [$from, $to])->count(),
            ],
            'today' => [
                'appointments' => (clone $appts)->whereBetween('scheduled_start', [$today->startOfDay(), $today->endOfDay()])->count(),
                'completed' => (clone $appts)->whereBetween('scheduled_start', [$today->startOfDay(), $today->endOfDay()])->where('status', 'completed')->count(),
                'waiting' => (clone $appts)->whereBetween('scheduled_start', [$today->startOfDay(), $today->endOfDay()])->whereIn('status', ['checked_in', 'waiting'])->count(),
                'revenue' => $this->collections($today->startOfDay(), $today->endOfDay(), $branchId),
            ],
            'appointments' => [
                'by_status' => $byStatus,
                'total' => (int) $byStatus->sum(),
                'pending' => (int) (($byStatus['booked'] ?? 0) + ($byStatus['confirmed'] ?? 0)),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
                'no_show' => (int) ($byStatus['no_show'] ?? 0),
            ],
            'consultations_completed' => Consultation::where('status', 'signed')->whereBetween('signed_at', [$from, $to])
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->count(),
            'revenue' => [
                'collected' => $this->collections($from, $to, $branchId),
                'billed' => (int) Invoice::whereNotIn('status', ['draft', 'void'])->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('grand_total'),
                'outstanding' => (int) Invoice::whereIn('status', ['issued', 'partially_paid'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('balance_due'),
                'daily' => $this->dailyRevenue($from, $to, $branchId),
            ],
            'doctors' => $this->doctorPerformance($from, $to, $branchId),
            'services' => $this->serviceRevenue($from, $to, $branchId),
            'branches' => $branchId ? [] : $this->branchPerformance($from, $to),
            'visit_trend' => $this->visitTrend($from, $to, $branchId),
        ];
    }

    public function collections(\DateTimeInterface $from, \DateTimeInterface $to, ?string $branchId): int
    {
        $paid = (int) Payment::where('status', 'success')->whereBetween('received_at', [$from, $to])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('amount');
        $refunded = (int) Refund::where('status', 'processed')->whereBetween('processed_at', [$from, $to])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('amount');

        return $paid - $refunded;
    }

    public function dailyRevenue(\DateTimeInterface $from, \DateTimeInterface $to, ?string $branchId): array
    {
        return Payment::where('status', 'success')->whereBetween('received_at', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('date(received_at) as day, sum(amount) as total, count(*) as payments')->groupBy('day')->orderBy('day')
            ->get()->map(fn ($r) => ['date' => $r->day, 'total' => (int) $r->total, 'payments' => (int) $r->payments])->all();
    }

    public function doctorPerformance(\DateTimeInterface $from, \DateTimeInterface $to, ?string $branchId): array
    {
        $consults = Appointment::whereBetween('scheduled_start', [$from, $to])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw("doctor_profile_id, count(*) as total, sum(case when status = 'completed' then 1 else 0 end) as completed, sum(case when status = 'no_show' then 1 else 0 end) as no_show, sum(case when status = 'cancelled' then 1 else 0 end) as cancelled")
            ->groupBy('doctor_profile_id')->get()->keyBy('doctor_profile_id');
        $revenue = InvoiceItem::query()->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotIn('invoices.status', ['draft', 'void'])->whereBetween('invoices.invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
            ->whereNotNull('invoice_items.doctor_profile_id')
            ->selectRaw('invoice_items.doctor_profile_id, sum(invoice_items.line_total) as revenue')->groupBy('invoice_items.doctor_profile_id')->pluck('revenue', 'doctor_profile_id');
        $names = DoctorProfile::with('user:id,name')->get()->keyBy('id');

        return $names->map(fn ($d) => [
            'doctor_id' => $d->id,
            'name' => $d->display_name,
            'appointments' => (int) ($consults[$d->id]->total ?? 0),
            'completed' => (int) ($consults[$d->id]->completed ?? 0),
            'no_show' => (int) ($consults[$d->id]->no_show ?? 0),
            'cancelled' => (int) ($consults[$d->id]->cancelled ?? 0),
            'revenue' => (int) ($revenue[$d->id] ?? 0),
        ])->values()->all();
    }

    public function serviceRevenue(\DateTimeInterface $from, \DateTimeInterface $to, ?string $branchId): array
    {
        return InvoiceItem::query()->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotIn('invoices.status', ['draft', 'void'])->whereBetween('invoices.invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
            ->selectRaw('invoice_items.item_type, invoice_items.description, sum(invoice_items.quantity) as qty, sum(invoice_items.line_total) as revenue')
            ->groupBy('invoice_items.item_type', 'invoice_items.description')->orderByDesc('revenue')->limit(50)->get()
            ->map(fn ($r) => ['type' => $r->item_type, 'description' => $r->description, 'quantity' => (float) $r->qty, 'revenue' => (int) $r->revenue])->all();
    }

    public function branchPerformance(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return Branch::all()->map(fn ($b) => [
            'branch_id' => $b->id,
            'name' => $b->name,
            'appointments' => Appointment::where('branch_id', $b->id)->whereBetween('scheduled_start', [$from, $to])->count(),
            'collected' => $this->collections($from, $to, $b->id),
        ])->all();
    }

    public function visitTrend(\DateTimeInterface $from, \DateTimeInterface $to, ?string $branchId): array
    {
        return Appointment::whereBetween('scheduled_start', [$from, $to])->where('status', 'completed')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('date(scheduled_start) as day, count(*) as visits')->groupBy('day')->orderBy('day')
            ->get()->map(fn ($r) => ['date' => $r->day, 'visits' => (int) $r->visits])->all();
    }

    public function gstSummary(?string $from, ?string $to, ?string $branchId): array
    {
        [$from, $to] = $this->range($from, $to);

        return InvoiceItem::query()->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotIn('invoices.status', ['draft', 'void'])->whereBetween('invoices.invoice_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('invoices.branch_id', $branchId))
            ->selectRaw('invoice_items.tax_rate, sum(invoice_items.line_total - invoice_items.tax_amount) as taxable, sum(invoice_items.tax_amount) as tax')
            ->groupBy('invoice_items.tax_rate')->get()
            ->map(fn ($r) => ['rate' => (float) $r->tax_rate, 'taxable_value' => (int) $r->taxable, 'cgst' => intdiv((int) $r->tax, 2), 'sgst' => (int) $r->tax - intdiv((int) $r->tax, 2), 'total_tax' => (int) $r->tax])->all();
    }

    /** Flat rows for CSV export. */
    public function rows(string $report, ?string $from, ?string $to, ?string $branchId): array
    {
        [$f, $t] = $this->range($from, $to);

        return match ($report) {
            'appointments' => Appointment::with('patient:id,patient_code,first_name,last_name,full_name', 'doctor.user:id,name')
                ->whereBetween('scheduled_start', [$f, $t])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('scheduled_start')->get()
                ->map(fn ($a) => ['date' => $a->scheduled_start->toDateTimeString(), 'patient_code' => $a->patient->patient_code, 'patient' => $a->patient->full_name, 'doctor' => $a->doctor->display_name, 'type' => $a->type, 'status' => $a->status->value])->all(),
            'collections' => Payment::with('patient:id,patient_code,first_name,last_name,full_name')->whereBetween('received_at', [$f, $t])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('received_at')->get()
                ->map(fn ($p) => ['received_at' => $p->received_at->toDateTimeString(), 'receipt' => $p->receipt_number, 'patient_code' => $p->patient->patient_code, 'method' => $p->method, 'amount' => $p->amount / 100, 'refunded' => $p->refunded_amount / 100])->all(),
            'outstanding' => Invoice::with('patient:id,patient_code,first_name,last_name,full_name')->whereIn('status', ['issued', 'partially_paid'])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('invoice_date')->get()
                ->map(fn ($i) => ['invoice' => $i->invoice_number, 'date' => $i->invoice_date->toDateString(), 'patient_code' => $i->patient->patient_code, 'total' => $i->grand_total / 100, 'balance_due' => $i->balance_due / 100])->all(),
            'doctors' => $this->doctorPerformance($f, $t, $branchId),
            'services' => $this->serviceRevenue($f, $t, $branchId),
            'gst' => $this->gstSummary($from, $to, $branchId),
            default => throw new BusinessRuleException('UNKNOWN_REPORT', 'Unknown report.'),
        };
    }
}
