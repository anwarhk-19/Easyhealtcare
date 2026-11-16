<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class PrescriptionItem extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'prescription_items';
}
