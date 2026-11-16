<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends BaseModel
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'departments';
}
