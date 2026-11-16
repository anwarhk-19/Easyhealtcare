<?php

namespace App\Services\Notifications;

use App\Models\NotificationMessage;
use Illuminate\Support\Facades\Http;

/** DLT-compliant SMS via MSG91 Flow API (template id = DLT-registered template). */
class Msg91SmsDriver implements ChannelDriver
{
    public function send(NotificationMessage $message): ?string
    {
        $config = config('services.msg91');
        $response = Http::withHeaders(['authkey' => $config['auth_key']])
            ->post('https://control.msg91.com/api/v5/flow/', [
                'template_id' => $message->payload['dlt_template_id'] ?? $config['default_template_id'],
                'short_url' => 0,
                'recipients' => [array_merge(['mobiles' => ltrim($message->to_address, '+')], $message->payload['template_params'] ?? [])],
            ])->throw();

        return $response->json('request_id');
    }
}
