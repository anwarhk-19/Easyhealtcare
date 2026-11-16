<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class TreatmentPlanItem extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'treatment_plan_items';

    protected function casts(): array
    {
        return ['session_data' => 'array', 'completed_at' => 'datetime'];
    }
}
