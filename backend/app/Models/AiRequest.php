<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class AiRequest extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'ai_requests';

    protected function casts(): array
    {
        return ['prompt' => 'encrypted', 'response' => 'encrypted', 'contains_phi' => 'boolean', 'accepted_at' => 'datetime'];
    }

    protected $hidden = ['organization_id', 'prompt'];
}
