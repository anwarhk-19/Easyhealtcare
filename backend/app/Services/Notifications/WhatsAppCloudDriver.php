<?php

namespace App\Services\Notifications;

use App\Models\NotificationMessage;
use Illuminate\Support\Facades\Http;

/** Meta WhatsApp Cloud API. Uses approved templates (required outside the 24h customer-service window). */
class WhatsAppCloudDriver implements ChannelDriver
{
    public function send(NotificationMessage $message): ?string
    {
        $config = config('services.whatsapp');
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($message->to_address, '+'),
        ];
        $template = $message->payload['provider_template_name'] ?? null;
        if ($template) {
            $payload['type'] = 'template';
            $payload['template'] = [
                'name' => $template,
                'language' => ['code' => $message->payload['locale'] ?? 'en'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($message->payload['template_params'] ?? [])),
                ]],
            ];
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['body' => $message->body];
        }

        $response = Http::withToken($config['token'])
            ->post(rtrim($config['base_url'], '/').'/'.$config['phone_number_id'].'/messages', $payload)
            ->throw();

        return $response->json('messages.0.id');
    }
}
