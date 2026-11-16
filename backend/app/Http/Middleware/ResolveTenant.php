<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the active organisation for the authenticated staff user.
 * The organisation comes from X-Organization-Id (if the user is an active member) or the user's current organisation.
 * A client can never select an organisation it has no active membership in.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Staff authentication required.']], 401);
        }

        $orgId = $request->header('X-Organization-Id') ?: $user->current_organization_id;

        $membership = $this->context->bypass(function () use ($user, $orgId) {
            $q = Membership::with(['roles.permissions', 'branches', 'user'])->where('user_id', $user->id)->where('status', 'active');

            return $orgId ? $q->where('organization_id', $orgId)->first() : $q->first();
        });

        if (! $membership) {
            return response()->json(['error' => ['code' => 'NO_ORGANIZATION', 'message' => 'You are not an active member of this organisation.']], 403);
        }

        $org = Organization::with('subscription.plan')->find($membership->organization_id);
        $this->context->set($org, $membership);

        // Impersonation tokens are tagged so every audit row records the platform user acting.
        $token = $user->currentAccessToken();
        if ($token && str_starts_with((string) $token->name, 'impersonation:')) {
            $request->attributes->set('impersonator_id', substr($token->name, strlen('impersonation:')));
        }

        try {
            return $next($request);
        } finally {
            if (! app()->runningUnitTests()) {
                $this->context->clear();
            }
        }
    }
}
