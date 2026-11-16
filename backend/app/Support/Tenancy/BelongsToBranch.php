<?php

namespace App\Support\Tenancy;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Restricts queries to the branches the current membership is assigned to. */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            $context = app(TenantContext::class);
            if ($context->bypassing()) {
                return;
            }
            $ids = $context->branchIds();
            if ($ids !== null) {
                $builder->whereIn($builder->getModel()->qualifyColumn('branch_id'), $ids);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
