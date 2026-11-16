<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class DoctorFee extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'doctor_fees';

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
