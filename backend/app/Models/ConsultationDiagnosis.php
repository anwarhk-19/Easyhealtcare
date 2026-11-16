<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class ConsultationDiagnosis extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'consultation_diagnoses';

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
