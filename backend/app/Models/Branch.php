<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends BaseModel
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'branches';

    protected function casts(): array
    {
        return ['address' => 'array', 'is_active' => 'boolean'];
    }
}
