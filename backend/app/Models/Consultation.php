<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Consultation extends BaseModel
{
    use Auditable, BelongsToBranch, BelongsToTenant;

    protected array $auditRedact = ['notes'];

    protected function casts(): array
    {
        return ['notes' => 'encrypted', 'follow_up_date' => 'date', 'signed_at' => 'datetime'];
    }

    public function isLocked(): bool
    {
        return $this->status === 'signed';
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_profile_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ConsultationTemplate::class, 'template_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ConsultationSection::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(ConsultationDiagnosis::class);
    }

    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class);
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(ConsultationAddendum::class);
    }

    public function prescription(): HasOne
    {
        return $this->hasOne(Prescription::class);
    }

    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class);
    }
}
