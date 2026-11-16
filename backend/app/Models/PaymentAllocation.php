<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'payment_allocations';

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
