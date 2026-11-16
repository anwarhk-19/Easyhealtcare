<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Patient;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function stock(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('inventory.view'), 403);
        $stock = $this->inventory->stock($request->query('branch_id'));
        if ($request->boolean('low_stock')) {
            $stock = $stock->where('low_stock', true)->values();
        }

        return $this->ok($stock);
    }

    public function batches(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('inventory.view'), 403);

        return $this->ok(StockBatch::with('item:id,name,sku')->where('qty_on_hand', '>', 0)->when($request->query('item_id'), fn ($q, $i) => $q->where('item_id', $i))->orderBy('expiry_date')->get());
    }

    public function expiring(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('inventory.view'), 403);

        return $this->ok($this->inventory->expiring((int) $request->query('days', 60), $request->query('branch_id')));
    }

    public function receive(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('inventory.purchase'), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'string'], 'supplier_id' => ['required', 'string'], 'supplier_invoice_ref' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.item_id' => ['required', 'string'], 'lines.*.batch_no' => ['required', 'string', 'max:32'],
            'lines.*.expiry_date' => ['nullable', 'date', 'after:today'], 'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.cost_price' => ['required', 'integer', 'min:0'], 'lines.*.mrp' => ['nullable', 'integer', 'min:0'],
        ]);
        $this->branchId($request);
        Supplier::findOrFail($data['supplier_id']);

        return $this->created($this->inventory->receive($data['branch_id'], $data['supplier_id'], $data['lines'], $data['supplier_invoice_ref'] ?? null));
    }

    public function receipts(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('inventory.view'), 403);

        return $this->paginated(GoodsReceipt::latest()->paginate($this->perPage($request)));
    }

    public function dispense(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('inventory.dispense'), 403);
        $data = $request->validate(['item_id' => ['required', 'string'], 'branch_id' => ['required', 'string'], 'quantity' => ['required', 'integer', 'min:1'], 'patient_id' => ['nullable', 'string']]);
        $this->branchId($request);
        $reference = ! empty($data['patient_id']) ? Patient::findOrFail($data['patient_id']) : null;

        return $this->created($this->inventory->dispense($data['item_id'], $data['branch_id'], $data['quantity'], $reference));
    }

    public function adjust(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('inventory.adjust'), 403);
        $data = $request->validate(['batch_id' => ['required', 'string'], 'delta' => ['required', 'integer', 'not_in:0'], 'reason' => ['required', 'string', 'max:120']]);

        return $this->created($this->inventory->adjust(StockBatch::findOrFail($data['batch_id']), $data['delta'], $data['reason']));
    }

    public function transfer(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('inventory.manage'), 403);
        $data = $request->validate(['item_id' => ['required', 'string'], 'from_branch_id' => ['required', 'string', 'different:to_branch_id'], 'to_branch_id' => ['required', 'string'], 'quantity' => ['required', 'integer', 'min:1']]);
        foreach ([$data['from_branch_id'], $data['to_branch_id']] as $b) {
            abort_unless($this->tenant()->canAccessBranch(Branch::findOrFail($b)->id), 403);
        }
        $this->inventory->transfer($data['item_id'], $data['from_branch_id'], $data['to_branch_id'], $data['quantity']);

        return $this->ok(['transferred' => $data['quantity']]);
    }

    public function movements(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('inventory.view'), 403);

        return $this->paginated(StockMovement::when($request->query('item_id'), fn ($q, $i) => $q->where('item_id', $i))->latest()->paginate($this->perPage($request)));
    }
}
