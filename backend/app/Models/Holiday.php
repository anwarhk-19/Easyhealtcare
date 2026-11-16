<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class Holiday extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'holidays';

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
