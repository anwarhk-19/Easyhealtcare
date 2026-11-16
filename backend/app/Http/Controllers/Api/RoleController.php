<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    public function permissions(): JsonResponse
    {
        abort_unless($this->tenant()->can('staff.view'), 403);

        return $this->ok(Permission::orderBy('module')->orderBy('name')->get(['id', 'name', 'module'])->groupBy('module'));
    }

    public function index(): JsonResponse
    {
        abort_unless($this->tenant()->can('staff.view'), 403);

        return $this->ok(Role::with('permissions:id,name')->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $this->tenant()->id()))->orderBy('is_system', 'desc')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('roles.manage'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:200'], 'permissions' => ['required', 'array'], 'permissions.*' => ['exists:permissions,name']]);
        $this->assertGrantable($data['permissions']);
        $role = Role::create(['organization_id' => $this->tenant()->id(), 'key' => 'custom_'.Str::slug($data['name'], '_').'_'.Str::lower(Str::random(4)), 'name' => $data['name'], 'description' => $data['description'] ?? null]);
        $role->permissions()->sync(Permission::whereIn('name', $data['permissions'])->pluck('id'));

        return $this->created($role->load('permissions:id,name'));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('roles.manage'), 403);
        $role = Role::where('organization_id', $this->tenant()->id())->findOrFail($id); // system roles are not editable
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:200'], 'permissions' => ['sometimes', 'array'], 'permissions.*' => ['exists:permissions,name']]);
        if (isset($data['permissions'])) {
            $this->assertGrantable($data['permissions']);
            $role->permissions()->sync(Permission::whereIn('name', $data['permissions'])->pluck('id'));
        }
        $role->update(collect($data)->only(['name', 'description'])->all());

        return $this->ok($role->load('permissions:id,name'));
    }

    public function destroy(string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('roles.manage'), 403);
        $role = Role::where('organization_id', $this->tenant()->id())->findOrFail($id);
        abort_if($role->memberships()->exists(), 409, 'Role is assigned to staff.');
        $role->delete();

        return response()->json(null, 204);
    }

    private function assertGrantable(array $permissions): void
    {
        $missing = array_diff($permissions, $this->tenant()->permissions());
        abort_if($missing !== [], 403, 'You cannot grant permissions you do not hold: '.implode(', ', $missing));
    }
}
