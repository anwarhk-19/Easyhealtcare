<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientAccountLink extends BaseModel
{
    protected $table = 'patient_account_links';

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
