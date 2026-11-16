<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends BaseModel
{
    protected $table = 'subscriptions';

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'cancelled_at' => 'datetime', 'overrides' => 'array'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['trialing', 'active', 'past_due', 'grace'], true)
            && ($this->status !== 'trialing' || $this->trial_ends_at === null || $this->trial_ends_at->isFuture());
    }
}
