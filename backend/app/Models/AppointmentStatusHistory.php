<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class AppointmentStatusHistory extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'appointment_status_history';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public const UPDATED_AT = null;
}
