<?php

use App\Console\OpenApiCommand;
use App\Exceptions\BusinessRuleException;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureOrganizationActive;
use App\Http\Middleware\EnsurePatientAccount;
use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Tenancy\MissingTenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([OpenApiCommand::class])
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['auth:sanctum', 'tenant']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'org.active' => EnsureOrganizationActive::class,
            'feature' => EnsureFeature::class,
            'platform' => EnsurePlatformRole::class,
            'patient' => EnsurePatientAccount::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        // Tenant context must exist before route-model binding resolves tenant-scoped models.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $error = fn (string $code, string $message, int $status, array $extra = []) => response()->json(['error' => ['code' => $code, 'message' => $message] + $extra], $status);

        $exceptions->render(function (BusinessRuleException $e) use ($error) {
            return $error($e->errorCode, $e->getMessage(), $e->status, $e->context ? ['context' => $e->context] : []);
        });
        $exceptions->render(function (ValidationException $e, Request $r) use ($error) {
            return $r->is('api/*') ? $error('VALIDATION_FAILED', $e->getMessage(), 422, ['fields' => $e->errors()]) : null;
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($error) {
            return $r->is('api/*') ? $error('UNAUTHENTICATED', 'Authentication required.', 401) : null;
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $r) use ($error) {
            return $r->is('api/*') ? $error('FORBIDDEN', $e->getMessage() ?: 'This action is not allowed.', 403) : null;
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $r) use ($error) {
            return $r->is('api/*') ? $error('NOT_FOUND', 'The requested resource was not found.', 404) : null;
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $r) use ($error) {
            return $r->is('api/*') ? $error('RATE_LIMITED', 'Too many requests. Please slow down.', 429) : null;
        });
        $exceptions->render(function (MissingTenantContext $e, Request $r) use ($error) {
            report($e);

            return $r->is('api/*') ? $error('TENANT_CONTEXT_MISSING', 'Organisation context is missing.', 500) : null;
        });
    })->create();
