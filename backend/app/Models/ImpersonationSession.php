<?php

namespace App\Models;

class ImpersonationSession extends BaseModel
{
    protected $table = 'impersonation_sessions';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
