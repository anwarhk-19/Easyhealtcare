<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\Patient;
use App\Services\ClinicalAccess;
use App\Services\LabService;
use App\Services\ShareLinkService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabController extends Controller
{
    public function __construct(private LabService $lab) {}

    private function find(string $id): LabOrder
    {
        return LabOrder::with('items.test', 'patient:id,patient_code,first_name,last_name,full_name,gender,date_of_birth,age_years_at_registration,created_at,mobile', 'report')->findOrFail($id);
    }

    /** Worklist for technicians: open orders by priority then age. */
    public function index(Request $request): JsonResponse
    {
        $q = LabOrder::with('items.test:id,name,code', 'patient:id,patient_code,first_name,last_name,full_name')
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', (array) $s), fn ($q) => $q->whereNotIn('status', ['released', 'cancelled']))
            ->when($request->query('patient_id'), fn ($q, $p) => $q->where('patient_id', $p))
            ->orderByRaw("case when priority = 'urgent' then 0 else 1 end")->oldest();

        return $this->paginated($q->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'string'], 'branch_id' => ['required', 'string'], 'test_ids' => ['required', 'array', 'min:1'], 'test_ids.*' => ['string'], 'consultation_id' => ['nullable', 'string'], 'priority' => ['nullable', Rule::in(['routine', 'urgent'])], 'notes' => ['nullable', 'string', 'max:200']]);
        $this->branchId($request);
        $doctor = app(ClinicalAccess::class)->currentDoctor();
        if (! empty($data['consultation_id'])) {
            Consultation::where('patient_id', $data['patient_id'])->findOrFail($data['consultation_id']);
        }

        return $this->created($this->lab->order(Patient::findOrFail($data['patient_id']), $data['branch_id'], $data['test_ids'], $data['consultation_id'] ?? null, $doctor?->id, $data['priority'] ?? 'routine', $data['notes'] ?? null));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->find($id));
    }

    private function item(string $orderId, string $itemId): LabOrderItem
    {
        return LabOrderItem::with('test')->where('lab_order_id', LabOrder::findOrFail($orderId)->id)->findOrFail($itemId);
    }

    public function collectSample(Request $request, string $id, string $itemId): JsonResponse
    {
        return $this->ok($this->lab->collectSample($this->item($id, $itemId), $request->input('barcode')));
    }

    public function results(Request $request, string $id, string $itemId): JsonResponse
    {
        $values = $request->validate(['values' => ['required', 'array', 'min:1']])['values'];

        return $this->ok($this->lab->enterResults($this->item($id, $itemId), $values));
    }

    public function uploadReport(Request $request, string $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480']]);

        return $this->ok($this->lab->attachReport($this->find($id), $request->file('file')));
    }

    public function release(string $id): JsonResponse
    {
        return $this->ok($this->lab->release($this->find($id)));
    }

    public function share(Request $request, string $id, ShareLinkService $links): JsonResponse
    {
        $channels = $request->validate(['channels' => ['nullable', 'array'], 'channels.*' => [Rule::in(['whatsapp', 'sms', 'email'])]])['channels'] ?? ['whatsapp'];

        return $this->ok($this->lab->share($this->find($id), $channels, $links));
    }

    public function cancel(string $id): JsonResponse
    {
        return $this->ok($this->lab->cancel($this->find($id)));
    }

    /** Printable report from structured results (when no external PDF was uploaded). */
    public function pdf(string $id)
    {
        $order = $this->find($id);
        abort_unless(in_array($order->status, ['report_ready', 'released'], true), 409, 'Results are not complete.');

        return Pdf::loadView('pdf.lab-report', ['order' => $order, 'org' => $order->organization, 'branch' => $order->branch])->stream(str_replace('/', '-', $order->order_number).'.pdf');
    }
}
