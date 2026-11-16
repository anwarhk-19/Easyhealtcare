<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class ConsultationSection extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'consultation_sections';

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
