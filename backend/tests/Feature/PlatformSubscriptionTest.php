<?php

namespace Tests\Feature;

use App\Models\Speciality;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Totp;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformSubscriptionTest extends TestCase
{
    private function superAdmin(): User
    {
        $user = User::create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret12345']);
        $user->forceFill(['platform_role' => 'super_admin'])->save();

        return $user;
    }

    public function test_self_signup_creates_trial_clinic_and_owner_can_sign_in(): void
    {
        $res = $this->postJson('/api/v1/auth/register-clinic', ['clinic_name' => 'Sunrise Dental', 'owner_name' => 'Dr Anu', 'email' => 'anu@sunrise.test', 'password' => 'Secret12345', 'owner_is_doctor' => true, 'speciality_codes' => ['dental']])->assertCreated();
        $this->assertSame('trialing', $res->json('data.organization.subscription.status'));
        $this->postJson('/api/v1/auth/login', ['email' => 'anu@sunrise.test', 'password' => 'wrong'])->assertStatus(401);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'anu@sunrise.test', 'password' => 'Secret12345'])->assertOk()->json('data.token');
        $me = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['clinic_owner', 'doctor'], $me['roles']);
        $this->assertNotNull($me['doctor_profile_id']);
        $this->postJson('/api/v1/auth/register-clinic', ['clinic_name' => 'Dup', 'owner_name' => 'x', 'email' => 'anu@sunrise.test', 'password' => 'Secret12345'])->assertStatus(422);
    }

    public function test_two_factor_login(): void
    {
        $c = $this->makeClinic();
        $this->actingAsStaff($c['owner']);
        $secret = $this->postJson('/api/v1/auth/two-factor')->json('data.secret');
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($secret)])->assertOk();
        $this->app['auth']->forgetGuards();

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $c['owner']->email, 'password' => 'Secret12345'])->assertOk()->assertJsonPath('data.two_factor_required', true)->json('data.challenge');
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(401);
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => $challenge, 'code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_plan_limits_are_enforced(): void
    {
        $c = $this->makeClinic('starter');
        $this->addDoctor($c['org'], $c['branch']);
        $this->actingAsStaff($c['owner']);
        $spec = Speciality::first()->id;
        $this->postJson('/api/v1/doctors', ['name' => 'Second', 'email' => 'second@example.test', 'password' => 'Secret12345', 'speciality_ids' => [$spec], 'branch_ids' => [$c['branch']->id]])
            ->assertStatus(402)->assertJsonPath('error.code', 'PLAN_LIMIT_REACHED');
        $this->postJson('/api/v1/branches', ['name' => 'Two', 'code' => 'TWO'])->assertStatus(402);
    }

    public function test_super_admin_suspends_clinic_which_becomes_read_only(): void
    {
        $c = $this->makeClinic();
        Sanctum::actingAs($this->superAdmin());
        $this->getJson('/api/platform/v1/organizations')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/platform/v1/organizations/{$c['org']->id}/suspend", ['reason' => 'Non-payment'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->getJson('/api/platform/v1/metrics')->assertOk()->assertJsonPath('data.organizations.suspended', 1);

        $this->actingAsStaff($c['owner']);
        $this->getJson('/api/v1/patients')->assertOk();
        $this->postJson('/api/v1/patients', ['first_name' => 'A', 'gender' => 'male'])->assertStatus(423)->assertJsonPath('error.code', 'ORG_SUSPENDED');
    }

    public function test_clinic_users_cannot_reach_platform_api(): void
    {
        $c = $this->makeClinic();
        $this->actingAsStaff($c['owner']);
        $this->getJson('/api/platform/v1/organizations')->assertForbidden();
    }

    public function test_trial_expiry_lifecycle_suspends_subscription(): void
    {
        $c = $this->makeClinic();
        Subscription::where('organization_id', $c['org']->id)->update(['trial_ends_at' => now()->subDay()]);
        $this->artisan('subscriptions:lifecycle')->assertSuccessful();
        $this->assertSame('suspended', Subscription::where('organization_id', $c['org']->id)->value('status'));
    }

    public function test_impersonation_is_time_boxed_and_audited(): void
    {
        $c = $this->makeClinic();
        Sanctum::actingAs($this->superAdmin());
        $res = $this->postJson('/api/platform/v1/impersonations', ['organization_id' => $c['org']->id, 'user_id' => $c['owner']->id, 'reason' => 'Ticket 123: cannot print invoices'])->assertCreated()->json('data');
        $this->app['auth']->forgetGuards();
        $this->withToken($res['token'])->getJson('/api/v1/auth/me')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'platform.impersonation.started', 'organization_id' => $c['org']->id]);
    }
}
