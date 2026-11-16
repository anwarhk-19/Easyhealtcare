<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends BaseModel
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $hidden = ['organization_id', 'abha_number'];

    protected $appends = ['age'];

    protected array $auditRedact = ['abha_number'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'address' => 'array',
            'emergency_contact' => 'array',
            'abha_number' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Patient $p) {
            $p->full_name = trim($p->first_name.' '.$p->last_name);
        });
    }

    public function getAgeAttribute(): ?int
    {
        if ($this->date_of_birth) {
            return $this->date_of_birth->age;
        }
        if ($this->age_years_at_registration !== null && $this->created_at) {
            return $this->age_years_at_registration + (int) $this->created_at->diffInYears(now());
        }

        return $this->age_years_at_registration;
    }

    public function scopeSearch(Builder $q, string $term): Builder
    {
        $term = trim($term);
        $digits = preg_replace('/\D+/', '', $term);

        return $q->where(function (Builder $w) use ($term, $digits) {
            $w->where('patient_code', $term)
                ->orWhere('full_name', 'like', $term.'%')
                ->orWhere('last_name', 'like', $term.'%');
            if (strlen($digits) >= 4) {
                $w->orWhere('mobile', 'like', '%'.$digits.'%');
            }
        });
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(PatientCondition::class);
    }

    public function medications(): HasMany
    {
        return $this->hasMany(PatientMedication::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PatientDocument::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'guardian_patient_id');
    }

    public function hasConsent(string $purpose): bool
    {
        $latest = $this->consents()->where('purpose', $purpose)->latest('created_at')->latest('id')->first();

        return $latest !== null && $latest->status === 'granted';
    }
}
