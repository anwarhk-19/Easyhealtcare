<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends BaseModel
{
    use Auditable, BelongsToBranch, BelongsToTenant;

    protected $hidden = ['organization_id', 'slot_lock'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'confirmed_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'consult_started_at' => 'datetime',
            'consult_ended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // DB-enforced double-booking guard: the unique slot_lock is only set while the slot is held.
        static::saving(function (Appointment $a) {
            $status = $a->status instanceof AppointmentStatus ? $a->status : AppointmentStatus::from($a->status);
            $a->slot_lock = ($a->type !== 'walk_in' && $status->holdsSlot())
                ? $a->doctor_profile_id.'|'.$a->scheduled_start->utc()->format('YmdHi')
                : null;
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_profile_id');
    }

    public function token(): HasOne
    {
        return $this->hasOne(QueueToken::class);
    }

    public function consultation(): HasOne
    {
        return $this->hasOne(Consultation::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->where('status', '!=', 'void');
    }

    public function history(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class)->orderBy('created_at');
    }
}
