<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class ServiceBranchPrice extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'service_branch_prices';
}
