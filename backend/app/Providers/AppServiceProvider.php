<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\FakeProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->bind(AiProvider::class, fn () => match (config('clinic.ai_provider')) {
            'anthropic' => new AnthropicProvider,
            default => new FakeProvider,
        });
    }

    public function boot(): void
    {
        // Permission strings ("module.action") are evaluated against the active membership.
        Gate::before(function ($user, string $ability) {
            if ($user instanceof User && str_contains($ability, '.')) {
                return app(TenantContext::class)->can($ability) ?: null;
            }

            return null;
        });

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()), Limit::perMinute(20)->by($r->ip())]);
        RateLimiter::for('otp', fn (Request $r) => [Limit::perMinutes(10, 3)->by((string) $r->input('mobile')), Limit::perMinute(10)->by($r->ip())]);
        RateLimiter::for('exports', fn (Request $r) => Limit::perHour(10)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
    }
}
