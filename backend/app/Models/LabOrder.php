<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabOrder extends BaseModel
{
    use Auditable, BelongsToBranch, BelongsToTenant;

    protected function casts(): array
    {
        return ['released_at' => 'datetime', 'shared_with_patient_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabOrderItem::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(PatientDocument::class, 'report_document_id');
    }
}
