<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogger
{
    public static function log(string $event, ?Model $subject = null, ?array $old = null, ?array $new = null): void
    {
        $context = app(TenantContext::class);
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        AuditLog::query()->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $subject?->getAttribute('organization_id') ?? $context->id(),
            'branch_id' => $subject?->getAttribute('branch_id'),
            'user_id' => auth()->id(),
            'impersonator_id' => $request?->attributes->get('impersonator_id'),
            'event' => $event,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ? json_encode($old) : null,
            'new_values' => $new ? json_encode($new) : null,
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 250) ?: null,
            'created_at' => now(),
        ]);
    }
}
