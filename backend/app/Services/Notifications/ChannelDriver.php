<?php

namespace App\Services\Notifications;

use App\Models\NotificationMessage;

interface ChannelDriver
{
    /** Sends the message and returns the provider message id. Throws on failure. */
    public function send(NotificationMessage $message): ?string;
}
