<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBatch extends BaseModel
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'stock_batches';

    protected function casts(): array
    {
        return ['expiry_date' => 'date'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }
}
