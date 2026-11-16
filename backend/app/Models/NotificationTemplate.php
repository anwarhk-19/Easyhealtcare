<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;

class NotificationTemplate extends BaseModel
{
    use BelongsToTenant;

    public bool $includesPlatformRecords = true;

    protected $table = 'notification_templates';
}
