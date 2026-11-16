<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class TaxRate extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'tax_rates';

    protected function casts(): array
    {
        return ['rate' => 'float', 'is_default' => 'boolean'];
    }
}
