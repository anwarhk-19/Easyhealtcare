<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class NotificationMessage extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'notification_messages';

    protected function casts(): array
    {
        return ['payload' => 'array', 'scheduled_for' => 'datetime', 'sent_at' => 'datetime'];
    }
}
