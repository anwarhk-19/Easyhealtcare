<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends BaseModel
{
    use BelongsToBranch, BelongsToTenant;

    protected $table = 'waitlist_entries';

    protected function casts(): array
    {
        return ['preferred_date' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
