<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;

class DentalToothRecord extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'dental_tooth_records';
}
