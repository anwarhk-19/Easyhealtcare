<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends BaseModel
{
    protected $table = 'subscription_invoices';

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'due_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
