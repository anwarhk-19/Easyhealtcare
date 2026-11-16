<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;

class GoodsReceipt extends BaseModel
{
    use Auditable, BelongsToBranch, BelongsToTenant;

    protected $table = 'goods_receipts';

    protected function casts(): array
    {
        return ['received_on' => 'date'];
    }
}
