<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;

class PatientAllergy extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'patient_allergies';
}
