<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;

class StockMovement extends BaseModel
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'stock_movements';
}
