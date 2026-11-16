<?php

namespace App\Models;

class Plan extends BaseModel
{
    protected $table = 'plans';

    protected function casts(): array
    {
        return ['limits' => 'array', 'features' => 'array', 'is_public' => 'boolean', 'is_active' => 'boolean'];
    }

    public function limit(string $key): ?int
    {
        return isset($this->limits[$key]) ? (int) $this->limits[$key] : null;
    }
}
