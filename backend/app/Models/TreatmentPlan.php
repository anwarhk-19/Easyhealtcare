<?php

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentPlan extends BaseModel
{
    use Auditable, BelongsToTenant;

    protected $table = 'treatment_plans';

    public function items(): HasMany
    {
        return $this->hasMany(TreatmentPlanItem::class)->orderBy('sitting_no');
    }
}
