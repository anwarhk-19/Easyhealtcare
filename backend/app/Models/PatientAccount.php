<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/** A patient's own login for the patient app. Separate from staff users and never holds tenant permissions. */
class PatientAccount extends Authenticatable
{
    use HasApiTokens, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mobile_verified_at' => 'datetime'];
    }

    public function links(): HasMany
    {
        return $this->hasMany(PatientAccountLink::class)->whereNull('revoked_at');
    }
}
