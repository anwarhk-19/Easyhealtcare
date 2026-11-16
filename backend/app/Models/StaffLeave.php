<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class StaffLeave extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'staff_leaves';

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }
}
