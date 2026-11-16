<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'inventory_items';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'tax_rate' => 'float'];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class, 'item_id');
    }
}
