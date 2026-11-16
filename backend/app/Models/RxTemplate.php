<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class RxTemplate extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'rx_templates';

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
