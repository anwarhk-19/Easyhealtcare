<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class LabTest extends BaseModel
{
    use BelongsToTenant;

    public bool $includesPlatformRecords = true;

    protected $table = 'lab_tests';

    protected function casts(): array
    {
        return ['parameters' => 'array', 'is_active' => 'boolean'];
    }
}
