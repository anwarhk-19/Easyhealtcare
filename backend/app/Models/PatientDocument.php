<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatientDocument extends BaseModel
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'patient_documents';

    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean', 'shared_with_patient_at' => 'datetime'];
    }

    protected $hidden = ['organization_id', 'file_path'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
