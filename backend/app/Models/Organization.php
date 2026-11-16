<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends BaseModel
{
    use Auditable;

    protected $guarded = ['id'];

    protected $hidden = [];

    /** Defaults for organisation settings; stored overrides live in `settings` JSON. */
    public const DEFAULT_SETTINGS = [
        'patient_code_prefix' => 'P',
        'slot_minutes' => 15,
        'discount_approval_threshold_percent' => 10,
        'admins_can_view_clinical' => false,
        'clinical_visibility' => 'all_doctors', // all_doctors|treating_doctor_only
        'lab_tech_can_release' => true,
        'reminder_offsets_minutes' => [1440, 120],
        'notification_channels' => ['whatsapp', 'sms'],
        'follow_up_window_days' => 7,
        'ai_enabled' => false,
        'ai_phi_allowed' => false,
    ];

    protected function casts(): array
    {
        return ['address' => 'array', 'settings' => 'array', 'enabled_modules' => 'array', 'approved_at' => 'datetime'];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function specialities(): BelongsToMany
    {
        return $this->belongsToMany(Speciality::class, 'organization_speciality');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, self::DEFAULT_SETTINGS[$key] ?? $default);
    }

    public function allSettings(): array
    {
        return array_replace(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    /** A feature is available when the plan includes it and (for optional modules) the org has not switched it off. */
    public function hasFeature(string $feature): bool
    {
        $plan = $this->subscription?->plan;
        $planFeatures = array_merge($plan?->features ?? [], $this->subscription?->overrides['features'] ?? []);
        if (! in_array($feature, $planFeatures, true) && ! in_array('*', $planFeatures, true)) {
            return false;
        }
        $disabled = $this->enabled_modules['disabled'] ?? [];

        return ! in_array($feature, $disabled, true);
    }

    public function limit(string $key): ?int
    {
        $override = $this->subscription?->overrides['limits'][$key] ?? null;

        return $override !== null ? (int) $override : $this->subscription?->plan?->limit($key);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && (bool) $this->subscription?->isUsable();
    }
}
