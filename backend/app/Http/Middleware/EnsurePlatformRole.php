<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isPlatformStaff() || ($roles && ! in_array($user->platform_role, $roles, true))) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Platform staff access required.']], 403);
        }

        return $next($request);
    }
}
