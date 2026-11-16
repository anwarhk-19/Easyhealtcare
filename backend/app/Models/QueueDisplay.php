<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;

class QueueDisplay extends BaseModel
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'queue_displays';

    protected function casts(): array
    {
        return ['doctor_ids' => 'array'];
    }

    protected $hidden = ['organization_id', 'access_token'];
}
