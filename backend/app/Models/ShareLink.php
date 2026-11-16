<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class ShareLink extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'share_links';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected $hidden = ['organization_id', 'token_hash'];
}
