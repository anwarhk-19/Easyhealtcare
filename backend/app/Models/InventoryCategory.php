<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class InventoryCategory extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'inventory_categories';
}
