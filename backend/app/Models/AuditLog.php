<?php

namespace App\Models;

class AuditLog extends BaseModel
{
    protected $table = 'audit_logs';

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    public const UPDATED_AT = null;

    protected $hidden = [];
}
