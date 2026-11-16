<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        if ($context->bypassing()) {
            return;
        }

        $column = $model->qualifyColumn('organization_id');
        $orgId = $context->id();

        if ($orgId === null) {
            throw new MissingTenantContext;
        }

        if (property_exists($model, 'includesPlatformRecords') && $model->includesPlatformRecords) {
            $builder->where(fn (Builder $q) => $q->where($column, $orgId)->orWhereNull($column));
        } else {
            $builder->where($column, $orgId);
        }
    }
}
