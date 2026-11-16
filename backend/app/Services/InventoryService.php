<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Support\Sequence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Batch/expiry-aware stock ledger. qty_on_hand changes only together with a stock_movements row. */
class InventoryService
{
    /** @param array<int, array{item_id:string,batch_no:string,expiry_date?:string,quantity:int,cost_price:int,mrp?:int}> $lines */
    public function receive(string $branchId, string $supplierId, array $lines, ?string $supplierInvoiceRef = null): GoodsReceipt
    {
        return DB::transaction(function () use ($branchId, $supplierId, $lines, $supplierInvoiceRef) {
            $n = Sequence::next('grn', $branchId, Sequence::financialYear());
            $grn = GoodsReceipt::create([
                'branch_id' => $branchId,
                'supplier_id' => $supplierId,
                'number' => sprintf('GRN/%s/%05d', Sequence::financialYear(), $n),
                'supplier_invoice_ref' => $supplierInvoiceRef,
                'received_on' => now()->toDateString(),
                'total' => array_sum(array_map(fn ($l) => $l['quantity'] * $l['cost_price'], $lines)),
                'created_by' => auth()->id(),
            ]);
            foreach ($lines as $line) {
                InventoryItem::findOrFail($line['item_id']);
                $batch = StockBatch::firstOrCreate(
                    ['branch_id' => $branchId, 'item_id' => $line['item_id'], 'batch_no' => $line['batch_no']],
                    ['expiry_date' => $line['expiry_date'] ?? null, 'cost_price' => $line['cost_price'], 'mrp' => $line['mrp'] ?? 0, 'qty_on_hand' => 0],
                );
                $this->move($batch, 'purchase', (int) $line['quantity'], $grn);
            }

            return $grn;
        });
    }

    /** First-expiry-first-out across non-expired batches. */
    public function dispense(string $itemId, string $branchId, int $quantity, ?object $reference = null, string $type = 'dispense'): Collection
    {
        if ($quantity <= 0) {
            throw new BusinessRuleException('INVALID_QUANTITY', 'Quantity must be positive.');
        }

        return DB::transaction(function () use ($itemId, $branchId, $quantity, $reference, $type) {
            $batches = StockBatch::where('item_id', $itemId)->where('branch_id', $branchId)->where('qty_on_hand', '>', 0)
                ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))
                ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->lockForUpdate()->get();
            if ($batches->sum('qty_on_hand') < $quantity) {
                throw new BusinessRuleException('INSUFFICIENT_STOCK', 'Not enough unexpired stock for this item.');
            }
            $moves = collect();
            $remaining = $quantity;
            foreach ($batches as $batch) {
                $take = min($remaining, $batch->qty_on_hand);
                $moves->push($this->move($batch, $type, -$take, $reference));
                $remaining -= $take;
                if ($remaining === 0) {
                    break;
                }
            }

            return $moves;
        });
    }

    public function adjust(StockBatch $batch, int $delta, string $reason): StockMovement
    {
        if ($batch->qty_on_hand + $delta < 0) {
            throw new BusinessRuleException('NEGATIVE_STOCK', 'Adjustment would make stock negative.');
        }

        return DB::transaction(fn () => $this->move($batch, $delta < 0 && $reason === 'expired' ? 'expired' : 'adjustment', $delta, null, $reason));
    }

    public function transfer(string $itemId, string $fromBranchId, string $toBranchId, int $quantity): void
    {
        DB::transaction(function () use ($itemId, $fromBranchId, $toBranchId, $quantity) {
            foreach ($this->dispense($itemId, $fromBranchId, $quantity, null, 'transfer_out') as $out) {
                $source = StockBatch::withoutGlobalScope('branch')->findOrFail($out->batch_id);
                $target = StockBatch::withoutGlobalScope('branch')->firstOrCreate(
                    ['branch_id' => $toBranchId, 'item_id' => $itemId, 'batch_no' => $source->batch_no],
                    ['expiry_date' => $source->expiry_date, 'cost_price' => $source->cost_price, 'mrp' => $source->mrp, 'qty_on_hand' => 0],
                );
                $this->move($target, 'transfer_in', -$out->quantity, $out);
            }
        });
    }

    public function stock(?string $branchId): Collection
    {
        $items = InventoryItem::where('is_active', true)->orderBy('name')->get();
        $totals = StockBatch::query()->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('item_id, sum(qty_on_hand) as qty, min(case when qty_on_hand > 0 then expiry_date end) as next_expiry')->groupBy('item_id')->get()->keyBy('item_id');

        return $items->map(fn ($i) => [
            'id' => $i->id, 'sku' => $i->sku, 'name' => $i->name, 'unit' => $i->unit, 'reorder_level' => $i->reorder_level,
            'qty_on_hand' => (int) ($totals[$i->id]->qty ?? 0),
            'next_expiry' => $totals[$i->id]->next_expiry ?? null,
            'low_stock' => (int) ($totals[$i->id]->qty ?? 0) <= $i->reorder_level,
        ]);
    }

    public function expiring(int $days, ?string $branchId = null): Collection
    {
        return StockBatch::with('item:id,name,sku')->where('qty_on_hand', '>', 0)->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderBy('expiry_date')->get();
    }

    private function move(StockBatch $batch, string $type, int $quantity, ?object $reference = null, ?string $reason = null): StockMovement
    {
        $batch->update(['qty_on_hand' => $batch->qty_on_hand + $quantity]);

        return StockMovement::create([
            'branch_id' => $batch->branch_id,
            'item_id' => $batch->item_id,
            'batch_id' => $batch->id,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => $reference ? class_basename($reference) : null,
            'reference_id' => $reference?->id ?? null,
            'reason' => $reason,
            'created_by' => auth()->id(),
        ]);
    }
}
