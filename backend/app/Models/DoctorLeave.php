<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;

class DoctorLeave extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'doctor_leaves';

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
