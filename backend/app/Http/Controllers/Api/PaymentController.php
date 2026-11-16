<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashClosing;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\BillingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private BillingService $billing) {}

    public function index(Request $request): JsonResponse
    {
        $q = Payment::with('patient:id,patient_code,first_name,last_name,full_name', 'allocations.invoice:id,invoice_number')
            ->when($request->query('patient_id'), fn ($q, $p) => $q->where('patient_id', $p))
            ->when($request->query('date'), fn ($q, $d) => $q->whereDate('received_at', $d))
            ->when($request->query('method'), fn ($q, $m) => $q->where('method', $m));

        return $this->paginated($q->latest('received_at')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => ['required', 'string'], 'branch_id' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(['cash', 'upi', 'card', 'bank_transfer', 'wallet', 'online'])],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'allocations' => ['present', 'array'], 'allocations.*.invoice_id' => ['required', 'string'], 'allocations.*.amount' => ['required', 'integer', 'min:1'],
        ]);
        $this->branchId($request);
        $payment = $this->billing->recordPayment(Patient::findOrFail($data['patient_id']), $data['branch_id'], $data['amount'], $data['method'], $data['allocations'], $data['reference_no'] ?? null, $request->header('Idempotency-Key'));

        return $this->created($payment);
    }

    public function receipt(string $id)
    {
        $payment = Payment::with('patient', 'allocations.invoice', 'branch')->findOrFail($id);

        return Pdf::loadView('pdf.receipt', ['payment' => $payment, 'org' => $payment->organization, 'branch' => $payment->branch])->stream(str_replace('/', '-', $payment->receipt_number).'.pdf');
    }

    public function refunds(Request $request): JsonResponse
    {
        return $this->paginated(Refund::with('payment:id,receipt_number,patient_id,amount,method')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->paginate($this->perPage($request)));
    }

    public function requestRefund(Request $request, string $paymentId): JsonResponse
    {
        abort_unless($this->tenant()->can('refunds.request'), 403);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:200'], 'invoice_id' => ['nullable', 'string'], 'method' => ['nullable', Rule::in(['cash', 'upi', 'card', 'bank_transfer', 'wallet', 'online'])]]);

        return $this->created($this->billing->requestRefund(Payment::findOrFail($paymentId), $data['amount'], $data['reason'], $data['invoice_id'] ?? null, $data['method'] ?? null));
    }

    public function decideRefund(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('refunds.approve'), 403);

        return $this->ok($this->billing->decideRefund(Refund::findOrFail($id), $request->validate(['approve' => ['required', 'boolean']])['approve']));
    }

    public function processRefund(string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('refunds.approve'), 403);

        return $this->ok($this->billing->processRefund(Refund::findOrFail($id)));
    }

    public function dailyCollection(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $date = $request->query('date', now()->toDateString());
        // Receptionists see only their own collection; finance roles see the whole branch.
        $userId = $this->tenant()->can('reports.financial') ? $request->query('user_id') : auth()->id();

        return $this->ok($this->billing->dailyCollection($branchId, $date, $userId));
    }

    public function closeCash(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'string'], 'counted_cash' => ['required', 'integer', 'min:0'], 'notes' => ['nullable', 'string', 'max:200']]);
        $this->branchId($request);
        $expected = $this->billing->dailyCollection($data['branch_id'], now()->toDateString(), auth()->id())['cash_expected'];

        return $this->created(CashClosing::updateOrCreate(
            ['branch_id' => $data['branch_id'], 'user_id' => auth()->id(), 'date' => now()->toDateString()],
            ['expected_cash' => $expected, 'counted_cash' => $data['counted_cash'], 'difference' => $data['counted_cash'] - $expected, 'notes' => $data['notes'] ?? null],
        ));
    }
}
