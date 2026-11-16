<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class OperatingHour extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'operating_hours';
}
