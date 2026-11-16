<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;

class EyeExamination extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'eye_examinations';

    protected function casts(): array
    {
        return ['refraction' => 'array', 'findings' => 'array'];
    }
}
