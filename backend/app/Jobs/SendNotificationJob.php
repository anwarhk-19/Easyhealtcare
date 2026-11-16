<?php

namespace App\Jobs;

use App\Models\NotificationMessage;
use App\Models\Organization;
use App\Services\Notifications\ChannelDriver;
use App\Services\Notifications\LogDriver;
use App\Services\Notifications\MailDriver;
use App\Services\Notifications\Msg91SmsDriver;
use App\Services\Notifications\WhatsAppCloudDriver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public string $messageId, public string $organizationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $context): void
    {
        $org = Organization::findOrFail($this->organizationId);
        $context->runAs($org, function () {
            $message = NotificationMessage::find($this->messageId);
            if (! $message || $message->status !== 'queued') {
                return;
            }
            try {
                $providerId = self::driver($message->channel)->send($message);
                $message->update(['status' => 'sent', 'provider_message_id' => $providerId, 'sent_at' => now(), 'error' => null]);
            } catch (\Throwable $e) {
                $message->update(['error' => substr($e->getMessage(), 0, 250)]);
                if ($this->attempts() >= $this->tries) {
                    $message->update(['status' => 'failed']);

                    return;
                }
                throw $e;
            }
        });
    }

    public static function driver(string $channel): ChannelDriver
    {
        $driver = config("clinic.notification_drivers.$channel", 'log');

        return match ($driver) {
            'whatsapp_cloud' => app(WhatsAppCloudDriver::class),
            'msg91' => app(Msg91SmsDriver::class),
            'mail' => app(MailDriver::class),
            default => app(LogDriver::class),
        };
    }
}
