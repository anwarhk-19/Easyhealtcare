<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('notifications.view'), 403);
        $q = NotificationMessage::query()->when($request->query('patient_id'), fn ($q, $p) => $q->where('patient_id', $p))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest();

        return $this->paginated($q->paginate($this->perPage($request)));
    }

    public function retry(string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('notifications.manage'), 403);
        $m = NotificationMessage::findOrFail($id);
        abort_unless($m->status === 'failed', 409, 'Only failed messages can be retried.');
        $m->update(['status' => 'queued', 'error' => null]);
        SendNotificationJob::dispatch($m->id, $m->organization_id);

        return $this->ok($m);
    }

    /** WhatsApp Cloud API webhook verification handshake. */
    public function verifyWhatsapp(Request $request)
    {
        abort_unless($request->query('hub_verify_token') === config('services.whatsapp.webhook_verify_token') && config('services.whatsapp.webhook_verify_token'), 403);

        return response($request->query('hub_challenge'));
    }

    /** Delivery status callbacks. Signature is verified with the app secret (X-Hub-Signature-256). */
    public function whatsappWebhook(Request $request, TenantContext $ctx): JsonResponse
    {
        $secret = config('services.whatsapp.app_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $secret);
        abort_unless($secret && hash_equals($expected, (string) $request->header('X-Hub-Signature-256')), 401);

        $statuses = collect($request->input('entry', []))->flatMap(fn ($e) => $e['changes'] ?? [])->flatMap(fn ($c) => $c['value']['statuses'] ?? []);
        foreach ($statuses as $s) {
            $ctx->bypass(fn () => NotificationMessage::where('provider_message_id', $s['id'] ?? '')->update([
                'status' => match ($s['status'] ?? '') {
                    'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed', default => 'sent'
                },
                'error' => isset($s['errors']) ? substr(json_encode($s['errors']), 0, 250) : null,
            ]));
        }

        return response()->json(['ok' => true]);
    }
}
