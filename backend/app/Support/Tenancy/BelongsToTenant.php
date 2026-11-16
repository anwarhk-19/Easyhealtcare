<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds automatic organisation scoping and assignment.
 * Models that also expose platform-wide rows (organization_id NULL) set `public bool $includesPlatformRecords = true`.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model->organization_id !== null) {
                return;
            }
            $context = app(TenantContext::class);
            if ($context->check()) {
                $model->organization_id = $context->id();
            } elseif (! ($model->includesPlatformRecords ?? false) || ! $context->bypassing()) {
                throw new MissingTenantContext;
            }
        });

        // Guard against a record being moved to another tenant.
        static::updating(function ($model) {
            if ($model->isDirty('organization_id') && $model->getOriginal('organization_id') !== null) {
                throw new \LogicException('organization_id is immutable.');
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
