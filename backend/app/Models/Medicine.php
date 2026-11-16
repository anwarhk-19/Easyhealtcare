<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class Medicine extends BaseModel
{
    use BelongsToTenant;

    public bool $includesPlatformRecords = true;

    protected $table = 'medicines';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
