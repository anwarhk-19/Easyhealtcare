<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class Export extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'exports';

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
