<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class InvoiceItem extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'invoice_items';

    protected function casts(): array
    {
        return ['quantity' => 'float', 'discount_value' => 'float', 'tax_rate' => 'float'];
    }
}
