<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class DoctorSchedule extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'doctor_schedules';

    protected function casts(): array
    {
        return ['allow_walk_in' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];
    }
}
