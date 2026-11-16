<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\DoctorProfile;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Str;

class SubscriptionService
{
    /** Throws PLAN_LIMIT_REACHED (402) when adding one more of $resource would exceed the plan. */
    public function assertWithinLimit(Organization $org, string $resource): void
    {
        $limit = $org->limit('max_'.$resource);
        if ($limit === null) {
            return;
        }
        $current = match ($resource) {
            'doctors' => DoctorProfile::where('is_active', true)->count(),
            'branches' => $org->branches()->count(),
            'staff' => Membership::where('status', 'active')->count(),
            default => 0,
        };
        if ($current >= $limit) {
            throw new BusinessRuleException('PLAN_LIMIT_REACHED', "Your plan allows up to {$limit} {$resource}. Upgrade to add more.", 402);
        }
    }

    public function changePlan(Organization $org, Plan $plan, string $cycle = 'monthly'): Subscription
    {
        $current = $org->subscription;
        $current?->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $start = now();
        $end = $cycle === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonth();
        $sub = Subscription::create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => $cycle,
            'current_period_start' => $start,
            'current_period_end' => $end,
        ]);
        $this->invoice($sub);

        return $sub;
    }

    public function invoice(Subscription $sub): SubscriptionInvoice
    {
        $plan = $sub->plan;
        $subtotal = $sub->billing_cycle === 'annual' ? $plan->price_annual : $plan->price_monthly;
        $tax = (int) round($subtotal * 0.18); // 18% GST on SaaS

        return SubscriptionInvoice::create([
            'organization_id' => $sub->organization_id,
            'subscription_id' => $sub->id,
            'number' => 'SUB-'.now()->format('Ym').'-'.strtoupper(substr((string) Str::ulid(), -6)),
            'period_start' => $sub->current_period_start,
            'period_end' => $sub->current_period_end,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
            'status' => $subtotal === 0 ? 'paid' : 'due',
            'due_at' => now()->addDays(7),
            'paid_at' => $subtotal === 0 ? now() : null,
        ]);
    }

    /** Daily lifecycle: trials end, unpaid renewals move to past_due → grace → suspended. */
    public function runLifecycle(): array
    {
        $changed = ['expired_trials' => 0, 'past_due' => 0, 'suspended' => 0, 'renewed' => 0];
        foreach (Subscription::with('plan')->whereIn('status', ['trialing', 'active', 'past_due', 'grace'])->get() as $sub) {
            if ($sub->status === 'trialing' && $sub->trial_ends_at?->isPast()) {
                $sub->update(['status' => 'suspended']);
                $changed['expired_trials']++;
            } elseif ($sub->status === 'active' && $sub->current_period_end?->isPast()) {
                $unpaid = SubscriptionInvoice::where('subscription_id', $sub->id)->where('status', 'due')->exists();
                if ($unpaid) {
                    $sub->update(['status' => 'past_due']);
                    $changed['past_due']++;
                } else {
                    $start = $sub->current_period_end;
                    $sub->update(['current_period_start' => $start, 'current_period_end' => $sub->billing_cycle === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonth()]);
                    $this->invoice($sub->fresh());
                    $changed['renewed']++;
                }
            } elseif ($sub->status === 'past_due' && $sub->current_period_end?->copy()->addDays(7)->isPast()) {
                $sub->update(['status' => 'grace']);
            } elseif ($sub->status === 'grace' && $sub->current_period_end?->copy()->addDays(14)->isPast()) {
                $sub->update(['status' => 'suspended']);
                $changed['suspended']++;
            }
        }

        return $changed;
    }

    public function markInvoicePaid(SubscriptionInvoice $invoice, string $reference): void
    {
        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'payment_reference' => $reference]);
        $sub = $invoice->subscription;
        if (in_array($sub->status, ['past_due', 'grace', 'suspended'], true)) {
            $sub->update(['status' => 'active']);
        }
    }
}
