<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Services\BillingService;
use App\Services\NotificationService;
use App\Services\ShareLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(private BillingService $billing) {}

    private function find(string $id): Invoice
    {
        return Invoice::with('items', 'patient:id,patient_code,first_name,last_name,full_name,mobile', 'allocations')->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $q = Invoice::with('patient:id,patient_code,first_name,last_name,full_name')
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', (array) $s))
            ->when($request->query('patient_id'), fn ($q, $p) => $q->where('patient_id', $p))
            ->when($request->query('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->when($request->query('discount_status'), fn ($q, $s) => $q->where('discount_status', $s));

        return $this->paginated($q->latest('invoice_date')->latest('created_at')->paginate($this->perPage($request)));
    }

    private function itemRules(): array
    {
        return [
            'items' => ['present', 'array', 'max:100'],
            'items.*.item_type' => ['nullable', Rule::in(['service', 'inventory_item', 'lab_test', 'consultation', 'custom'])],
            'items.*.item_id' => ['nullable', 'string'],
            'items.*.description' => ['required_without:items.*.item_id', 'nullable', 'string', 'max:255'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:16'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.01', 'max:10000'],
            'items.*.unit_price' => ['required_without:items.*.item_id', 'nullable', 'integer', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(['flat', 'percent'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:28'],
            'items.*.doctor_profile_id' => ['nullable', 'string'],
        ];
    }

    private function guardDiscounts(array $items): void
    {
        if (collect($items)->contains(fn ($i) => ! empty($i['discount_type']) && (float) ($i['discount_value'] ?? 0) > 0)) {
            abort_unless($this->tenant()->can('discounts.apply'), 403, 'You are not allowed to apply discounts.');
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'string'], 'branch_id' => ['required', 'string']] + $this->itemRules());
        $this->branchId($request);
        $this->guardDiscounts($data['items']);

        return $this->created($this->billing->createDraft(Patient::findOrFail($data['patient_id']), $data['branch_id'], $data['items'])->load('items'));
    }

    public function fromAppointment(string $appointmentId): JsonResponse
    {
        return $this->created($this->billing->fromAppointment(Appointment::findOrFail($appointmentId))->load('items'));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->find($id));
    }

    public function setItems(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->itemRules());
        $this->guardDiscounts($data['items']);

        return $this->ok($this->billing->setItems($this->find($id), $data['items']));
    }

    public function issue(string $id): JsonResponse
    {
        return $this->ok($this->billing->issue($this->find($id)));
    }

    public function void(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('invoices.void'), 403);

        return $this->ok($this->billing->void($this->find($id), $request->validate(['reason' => ['required', 'string', 'max:200']])['reason']));
    }

    public function decideDiscount(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('discounts.approve'), 403);
        $approve = $request->validate(['approve' => ['required', 'boolean']])['approve'];

        return $this->ok($this->billing->decideDiscount($this->find($id), $approve)->fresh('items'));
    }

    public function pdf(string $id)
    {
        $invoice = $this->find($id);
        abort_if($invoice->status === 'draft', 409, 'Issue the invoice before printing.');

        return $this->billing->pdf($invoice)->stream(str_replace('/', '-', $invoice->invoice_number).'.pdf');
    }

    public function share(Request $request, string $id, ShareLinkService $links, NotificationService $notifications): JsonResponse
    {
        $invoice = $this->find($id);
        abort_if($invoice->status === 'draft', 409, 'Issue the invoice before sharing.');
        $share = $links->create($invoice, $invoice->patient_id);
        $notifications->send('invoice.shared', $invoice->patient, ['invoice_number' => $invoice->invoice_number, 'link' => $share['url']], $invoice, $request->input('channels'), null, (string) now()->timestamp);

        return $this->ok(['url' => $share['url'], 'expires_at' => $share['link']->expires_at]);
    }
}
