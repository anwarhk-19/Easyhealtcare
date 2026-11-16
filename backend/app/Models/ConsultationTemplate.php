<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationTemplate extends BaseModel
{
    use BelongsToTenant;

    public bool $includesPlatformRecords = true;

    protected $table = 'consultation_templates';

    protected function casts(): array
    {
        return ['definition' => 'array', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function speciality(): BelongsTo
    {
        return $this->belongsTo(Speciality::class);
    }
}
