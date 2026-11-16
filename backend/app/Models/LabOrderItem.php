<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabOrderItem extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'lab_order_items';

    protected function casts(): array
    {
        return ['results' => 'array', 'sample_collected_at' => 'datetime'];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id');
    }
}
