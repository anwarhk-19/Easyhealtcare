<?php

namespace App\Services\Notifications;

use App\Models\NotificationMessage;
use Illuminate\Support\Facades\Mail;

class MailDriver implements ChannelDriver
{
    public function send(NotificationMessage $message): ?string
    {
        Mail::raw($message->body, function ($m) use ($message) {
            $m->to($message->to_address)->subject($message->payload['subject'] ?? 'Notification from your clinic');
        });

        return null;
    }
}
