<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;

class Consent extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'consents';

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
