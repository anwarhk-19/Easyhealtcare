<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class StaffInvitation extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'staff_invitations';

    protected function casts(): array
    {
        return ['role_keys' => 'array', 'branch_ids' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
