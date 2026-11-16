<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DoctorProfile extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $hidden = ['organization_id', 'signature_path'];

    protected $appends = ['display_name'];

    protected function casts(): array
    {
        return ['qualifications' => 'array', 'is_bookable_online' => 'boolean', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specialities(): BelongsToMany
    {
        return $this->belongsToMany(Speciality::class, 'doctor_speciality')->withPivot('is_primary');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_doctor_profile');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(DoctorFee::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(DoctorLeave::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->title.' '.($this->user?->name ?? ''));
    }

    /** Branch-specific fee overrides the org-wide fee for the doctor. */
    public function feeFor(string $type, ?string $branchId): ?DoctorFee
    {
        $fees = $this->fees;

        return $fees->first(fn ($f) => $f->fee_type === $type && $f->branch_id === $branchId)
            ?? $fees->first(fn ($f) => $f->fee_type === $type && $f->branch_id === null);
    }
}
