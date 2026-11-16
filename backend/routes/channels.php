<?php

use App\Models\DoctorProfile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Broadcast;

// Private channels are authorised against the active membership; payloads are invalidation hints only.
Broadcast::channel('org.{orgId}.branch.{branchId}.queue', function ($user, string $orgId, string $branchId) {
    $ctx = app(TenantContext::class);

    return $ctx->id() === $orgId && $ctx->canAccessBranch($branchId) && $ctx->can('queue.view');
});

Broadcast::channel('org.{orgId}.branch.{branchId}.appointments', function ($user, string $orgId, string $branchId) {
    $ctx = app(TenantContext::class);

    return $ctx->id() === $orgId && $ctx->canAccessBranch($branchId) && $ctx->can('appointments.view');
});

Broadcast::channel('org.{orgId}.doctor.{doctorId}', function ($user, string $orgId, string $doctorId) {
    $ctx = app(TenantContext::class);

    return $ctx->id() === $orgId && DoctorProfile::whereKey($doctorId)->where('user_id', $user->id)->exists();
});

Broadcast::channel('org.{orgId}.user.{userId}', fn ($user, string $orgId, string $userId) => app(TenantContext::class)->id() === $orgId && $user->id === $userId);
