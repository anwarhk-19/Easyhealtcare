<?php

use App\Models\Appointment;
use App\Models\Organization;
use App\Services\AppointmentService;
use App\Services\InventoryService;
use App\Services\NotificationService;
use App\Services\SubscriptionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/** Appointment reminders at each configured offset; idempotency keys prevent duplicates across runs. */
Artisan::command('appointments:send-reminders', function (TenantContext $ctx, NotificationService $notifications, AppointmentService $appointments) {
    $count = 0;
    foreach ($ctx->bypass(fn () => Organization::where('status', 'active')->get()) as $org) {
        $ctx->runAs($org, function () use ($org, $notifications, $appointments, &$count) {
            foreach ($org->setting('reminder_offsets_minutes', [1440, 120]) as $offset) {
                $window = [now()->addMinutes($offset - 5), now()->addMinutes($offset + 5)];
                Appointment::withoutGlobalScope('branch')->with('patient')->whereIn('status', ['booked', 'confirmed'])->whereBetween('scheduled_start', $window)->get()
                    ->each(function ($a) use ($notifications, $appointments, $offset, &$count) {
                        $count += count($notifications->send('appointment.reminder', $a->patient, $appointments->notificationVars($a), $a, null, null, 'T-'.$offset));
                    });
            }
        });
    }
    $this->info("Queued {$count} reminders.");
})->purpose('Send appointment reminders');

Artisan::command('subscriptions:lifecycle', function (TenantContext $ctx, SubscriptionService $subs) {
    $this->info(json_encode($ctx->bypass(fn () => $subs->runLifecycle())));
})->purpose('Advance trial/renewal/suspension states');

Artisan::command('appointments:mark-no-shows', function (TenantContext $ctx) {
    $n = $ctx->bypass(fn () => Appointment::whereIn('status', ['booked', 'confirmed'])->where('scheduled_end', '<', now()->subHours(3))->get()
        ->each(fn ($a) => $a->update(['status' => 'no_show']))->count());
    $this->info("Marked {$n} no-shows.");
})->purpose('Mark stale appointments as no-show');

Artisan::command('inventory:alerts', function (TenantContext $ctx, InventoryService $inventory) {
    foreach ($ctx->bypass(fn () => Organization::where('status', 'active')->get()) as $org) {
        if (! $org->hasFeature('inventory')) {
            continue;
        }
        $ctx->runAs($org, function () use ($org, $inventory) {
            $low = $inventory->stock(null)->where('low_stock', true)->count();
            $expiring = $inventory->expiring(30)->count();
            if ($low || $expiring) {
                logger()->info('inventory alert', ['org' => $org->id, 'low_stock' => $low, 'expiring_30d' => $expiring]);
            }
        });
    }
})->purpose('Low-stock and near-expiry alerts');

Schedule::command('appointments:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('appointments:mark-no-shows')->hourly();
Schedule::command('subscriptions:lifecycle')->dailyAt('01:30');
Schedule::command('inventory:alerts')->dailyAt('02:30');
Schedule::command('sanctum:prune-expired --hours=24')->daily();
