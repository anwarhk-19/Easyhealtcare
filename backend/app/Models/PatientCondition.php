<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;

class PatientCondition extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'patient_conditions';

    protected function casts(): array
    {
        return ['since_date' => 'date'];
    }
}
