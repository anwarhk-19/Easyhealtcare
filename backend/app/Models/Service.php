<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends BaseModel
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'services';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price' => 'integer'];
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function branchPrices(): HasMany
    {
        return $this->hasMany(ServiceBranchPrice::class);
    }

    public function priceFor(?string $branchId): int
    {
        return $branchId ? ($this->branchPrices->firstWhere('branch_id', $branchId)?->price ?? $this->price) : $this->price;
    }
}
