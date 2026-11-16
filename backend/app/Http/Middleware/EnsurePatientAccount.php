<?php

namespace App\Http\Middleware;

use App\Models\PatientAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePatientAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof PatientAccount) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Patient app authentication required.']], 403);
        }

        return $next($request);
    }
}
