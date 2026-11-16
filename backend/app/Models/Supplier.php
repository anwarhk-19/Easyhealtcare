<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class Supplier extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'suppliers';

    protected function casts(): array
    {
        return ['address' => 'array'];
    }
}
