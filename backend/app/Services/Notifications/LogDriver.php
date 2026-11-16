<?php

namespace App\Services\Notifications;

use App\Models\NotificationMessage;
use Illuminate\Support\Facades\Log;

/** Default driver for local/test environments: records the message without contacting a provider. */
class LogDriver implements ChannelDriver
{
    public function send(NotificationMessage $message): ?string
    {
        Log::info('notification', ['channel' => $message->channel, 'to' => substr($message->to_address, 0, 5).'***', 'event' => $message->event_key]);

        return 'log-'.$message->id;
    }
}
