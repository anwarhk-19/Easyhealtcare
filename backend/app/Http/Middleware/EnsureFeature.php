<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeature
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $this->context->organization()?->hasFeature($feature)) {
            return response()->json(['error' => ['code' => 'FEATURE_NOT_IN_PLAN', 'message' => "The '{$feature}' module is not enabled for this clinic."]], 402);
        }

        return $next($request);
    }
}
