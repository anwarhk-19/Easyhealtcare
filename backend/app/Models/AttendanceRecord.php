<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;

class AttendanceRecord extends BaseModel
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'attendance_records';

    protected function casts(): array
    {
        return ['date' => 'date', 'clock_in' => 'datetime', 'clock_out' => 'datetime'];
    }
}
