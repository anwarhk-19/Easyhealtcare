<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class PatientMedication extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'patient_medications';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
