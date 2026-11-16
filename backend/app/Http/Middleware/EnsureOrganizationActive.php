<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Suspended/expired organisations become read-only (exports still allowed) until reactivated. */
class EnsureOrganizationActive
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $org = $this->context->organization();
        if ($org && ! $org->isActive() && ! $request->isMethodSafe() && ! $request->is('api/v1/reports/*/export', 'api/v1/subscription/*', 'api/v1/auth/*')) {
            return response()->json(['error' => [
                'code' => 'ORG_SUSPENDED',
                'message' => $org->status === 'pending' ? 'Your clinic is awaiting approval.' : 'Your subscription is inactive. The account is read-only until it is renewed.',
            ]], 423);
        }

        return $next($request);
    }
}
