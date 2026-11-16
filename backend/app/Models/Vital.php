<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;

class Vital extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'vitals';
}
