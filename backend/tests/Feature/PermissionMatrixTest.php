<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Role;
use App\Support\Rbac\PermissionCatalog;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    public function test_seeded_roles_match_the_catalogue(): void
    {
        foreach (PermissionCatalog::roles() as $key => $def) {
            $seeded = Role::with('permissions')->whereNull('organization_id')->where('key', $key)->firstOrFail()->permissions->pluck('name')->sort()->values()->all();
            $expected = collect($def['permissions'])->unique()->sort()->values()->all();
            $this->assertSame($expected, $seeded, "role $key");
        }
    }

    /** @return array<string, array{string, string, string, int}> role, method, uri template, expected status */
    public static function matrix(): array
    {
        return [
            'receptionist cannot write consultations' => ['receptionist', 'POST', '/api/v1/consultations', 403],
            'nurse cannot bill' => ['nurse', 'POST', '/api/v1/invoices', 403],
            'lab tech cannot view invoices' => ['lab_technician', 'GET', '/api/v1/invoices', 403],
            'accountant cannot book appointments' => ['accountant', 'POST', '/api/v1/appointments', 403],
            'doctor cannot manage staff' => ['doctor', 'POST', '/api/v1/staff/invitations', 403],
            'receptionist cannot see audit log' => ['receptionist', 'GET', '/api/v1/audit-logs', 403],
            'nurse cannot see financial dashboard' => ['nurse', 'GET', '/api/v1/dashboard', 403],
            'accountant can see dashboard' => ['accountant', 'GET', '/api/v1/dashboard', 200],
            'receptionist can list patients' => ['receptionist', 'GET', '/api/v1/patients', 200],
            'lab tech can open worklist' => ['lab_technician', 'GET', '/api/v1/lab-orders', 200],
            'branch manager cannot change subscription' => ['branch_manager', 'POST', '/api/v1/subscription/change-plan', 403],
            'admin cannot toggle AI' => ['clinic_admin', 'PATCH', '/api/v1/organization/settings', 403],
        ];
    }

    /** @dataProvider matrix */
    public function test_role_permissions(string $role, string $method, string $uri, int $status): void
    {
        $c = $this->makeClinic();
        $this->actingAsStaff($this->addStaff($c['org'], $c['branch'], $role));
        $body = $uri === '/api/v1/organization/settings' ? ['ai_enabled' => true] : [];
        $this->assertSame($status, $this->json($method, $uri, $body)->status());
    }

    public function test_no_privilege_escalation_when_inviting_or_creating_roles(): void
    {
        $c = $this->makeClinic();
        $manager = $this->addStaff($c['org'], $c['branch'], 'branch_manager');
        $this->actingAsStaff($manager);
        $this->postJson('/api/v1/staff/invitations', ['name' => 'X', 'email' => 'x@example.test', 'role_keys' => ['clinic_admin'], 'branch_ids' => [$c['branch']->id]])->assertForbidden();
        $this->postJson('/api/v1/staff/invitations', ['name' => 'R', 'email' => 'r@example.test', 'role_keys' => ['receptionist'], 'branch_ids' => [$c['branch']->id]])->assertCreated();

        $this->actingAsStaff($c['owner']);
        $this->postJson('/api/v1/roles', ['name' => 'Front desk lite', 'permissions' => ['patients.view', 'appointments.view']])->assertCreated();
        $admin = $this->addStaff($c['org'], $c['branch'], 'clinic_admin');
        $this->actingAsStaff($admin);
        $this->postJson('/api/v1/roles', ['name' => 'Sneaky', 'permissions' => ['subscription.manage']])->assertForbidden();
    }

    public function test_invitation_acceptance_and_last_owner_protection(): void
    {
        $c = $this->makeClinic();
        $this->actingAsStaff($c['owner']);
        $url = $this->postJson('/api/v1/staff/invitations', ['name' => 'Nurse N', 'email' => 'nn@example.test', 'role_keys' => ['nurse']])->assertCreated()->json('data.accept_url');
        $token = basename($url);
        $this->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'Password1234'])->assertOk();
        $this->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'Password1234'])->assertStatus(410);

        $ownerMembership = $this->asTenant($c['org'], fn () => Membership::where('user_id', $c['owner']->id)->first());
        $this->patchJson("/api/v1/staff/{$ownerMembership->id}", ['role_keys' => ['clinic_admin']])->assertStatus(422)->assertJsonPath('error.code', 'LAST_OWNER');
    }
}
