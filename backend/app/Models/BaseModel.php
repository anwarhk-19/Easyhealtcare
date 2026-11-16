<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * All application models use ULID primary keys (no enumerable IDs in URLs/payloads).
 * organization_id is never mass-assignable; BelongsToTenant sets it from the TenantContext.
 */
abstract class BaseModel extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['organization_id'];

    /** Tenant-scoped models never accept organization_id from input; the TenantContext supplies it. */
    public function getGuarded(): array
    {
        $guarded = parent::getGuarded();
        if (in_array(BelongsToTenant::class, class_uses_recursive(static::class), true)) {
            $guarded[] = 'organization_id';
        }

        return $guarded;
    }
}
