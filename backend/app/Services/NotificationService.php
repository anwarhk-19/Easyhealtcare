<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationMessage;
use App\Models\NotificationTemplate;
use App\Models\Patient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Event → channel fan-out with consent checks, template rendering and idempotency.
 * Channels a patient has not consented to are recorded as skipped_no_consent (never silently sent).
 */
class NotificationService
{
    public const CONSENT_FOR_CHANNEL = ['whatsapp' => 'whatsapp', 'sms' => 'sms', 'email' => 'email'];

    public function __construct(private TenantContext $context) {}

    /** @return array<NotificationMessage> */
    public function send(string $event, ?Patient $patient, array $vars, ?Model $subject = null, ?array $channels = null, ?\DateTimeInterface $scheduledFor = null, string $idempotencySuffix = ''): array
    {
        if (! $patient || ! $this->context->check()) {
            return [];
        }
        $org = $this->context->organization();
        $channels ??= $org->setting('notification_channels', ['whatsapp', 'sms']);
        $vars += ['clinic_name' => $org->name, 'patient_name' => $patient->full_name];
        $messages = [];

        foreach ($channels as $channel) {
            $to = $channel === 'email' ? $patient->email : $patient->mobile;
            if (! $to) {
                continue;
            }
            $template = NotificationTemplate::where('event_key', $event)->where('channel', $channel)
                ->orderByRaw('organization_id is null')->first();
            if (! $template) {
                continue;
            }
            $consented = $patient->hasConsent(self::CONSENT_FOR_CHANNEL[$channel]);
            $key = implode(':', [$event, $subject?->getKey() ?? $patient->id, $channel, $idempotencySuffix]);

            try {
                $message = NotificationMessage::create([
                    'patient_id' => $patient->id,
                    'event_key' => $event,
                    'channel' => $channel,
                    'to_address' => $to,
                    'body' => $this->render($template->body, $vars),
                    'payload' => [
                        'provider_template_name' => $template->provider_template_name,
                        'dlt_template_id' => $template->dlt_template_id,
                        'template_params' => $vars,
                        'subject' => $template->subject ? $this->render($template->subject, $vars) : null,
                        'locale' => $template->locale,
                        'attachment_url' => $vars['link'] ?? null,
                    ],
                    'status' => $consented ? 'queued' : 'skipped_no_consent',
                    'idempotency_key' => $org->id.':'.$key,
                    'scheduled_for' => $scheduledFor,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue; // already sent for this event/subject/channel
            }

            if ($consented) {
                $job = SendNotificationJob::dispatch($message->id, $org->id);
                if ($scheduledFor) {
                    $job->delay($scheduledFor);
                }
            }
            $messages[] = $message;
        }

        return $messages;
    }

    public function render(string $body, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $body);
    }
}
