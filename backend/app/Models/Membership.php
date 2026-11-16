<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Membership extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['all_branches' => 'boolean', 'joined_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withoutGlobalScopes();
    }

    /** @return array<string> */
    public function permissionNames(): array
    {
        $this->loadMissing('roles.permissions');

        return $this->roles->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique()->values()->all();
    }
}
