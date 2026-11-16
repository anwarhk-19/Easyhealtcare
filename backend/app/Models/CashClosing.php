<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;

class CashClosing extends BaseModel
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'cash_closings';

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
