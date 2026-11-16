<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Role;
use App\Models\StaffInvitation;
use App\Services\SubscriptionService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('staff.view'), 403);
        $q = Membership::with(['user:id,name,email,mobile,status,last_login_at', 'roles:id,key,name', 'branches:id,name'])->latest('created_at');
        if ($ids = $this->tenant()->branchIds()) {
            $q->where(fn ($w) => $w->where('all_branches', true)->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $ids)));
        }

        return $this->paginated($q->paginate($this->perPage($request)));
    }

    public function invitations(): JsonResponse
    {
        abort_unless($this->tenant()->can('staff.view'), 403);

        return $this->ok(StaffInvitation::whereNull('accepted_at')->latest()->get()->makeHidden('token_hash'));
    }

    public function invite(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        abort_unless($this->tenant()->can('staff.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required_without:mobile', 'nullable', 'email'],
            'mobile' => ['required_without:email', 'nullable', 'string', 'max:20'],
            'role_keys' => ['required', 'array', 'min:1'], 'role_keys.*' => ['string'],
            'branch_ids' => ['nullable', 'array'], 'branch_ids.*' => ['string'],
        ]);
        $subscriptions->assertWithinLimit($this->tenant()->organization(), 'staff');
        $this->assertAssignable($data['role_keys']);
        foreach ($data['branch_ids'] ?? [] as $b) {
            abort_unless($this->tenant()->canAccessBranch(Branch::findOrFail($b)->id), 403);
        }
        if ($this->tenant()->branchIds() !== null && empty($data['branch_ids'])) {
            throw new BusinessRuleException('BRANCH_REQUIRED', 'Select at least one branch for this staff member.');
        }
        $token = Str::random(48);
        $invite = StaffInvitation::create([
            'name' => $data['name'], 'email' => isset($data['email']) ? strtolower($data['email']) : null, 'mobile' => Phone::normalize($data['mobile'] ?? null),
            'role_keys' => $data['role_keys'], 'branch_ids' => $data['branch_ids'] ?? null,
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'invited_by' => auth()->id(),
        ]);
        $url = rtrim(config('app.frontend_url', config('app.url')), '/').'/accept-invite/'.$token;
        if ($invite->email) {
            Mail::raw("You have been invited to join {$this->tenant()->organization()->name} on EasyHealthcare.\n\nAccept: {$url}\n\nThis link expires in 7 days.", fn ($m) => $m->to($invite->email)->subject('You are invited to EasyHealthcare'));
        }

        return $this->created(['invitation' => $invite->makeHidden('token_hash'), 'accept_url' => $url]);
    }

    public function revokeInvitation(string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('staff.manage'), 403);
        StaffInvitation::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('staff.manage'), 403);
        $membership = Membership::with('roles')->findOrFail($id);
        $data = $request->validate([
            'role_keys' => ['sometimes', 'array', 'min:1'], 'branch_ids' => ['sometimes', 'array'], 'all_branches' => ['sometimes', 'boolean'],
            'designation' => ['nullable', 'string', 'max:80'], 'employee_code' => ['nullable', 'string', 'max:32'], 'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ]);
        if ($membership->roles->contains('key', 'clinic_owner') && ! $this->tenant()->hasRole('clinic_owner')) {
            abort(403, 'Only an owner can change another owner.');
        }
        if (isset($data['role_keys'])) {
            $this->assertAssignable($data['role_keys']);
            if ($membership->roles->contains('key', 'clinic_owner') && ! in_array('clinic_owner', $data['role_keys'], true) && $this->ownerCount() <= 1) {
                throw new BusinessRuleException('LAST_OWNER', 'The organisation must keep at least one owner.');
            }
            $membership->roles()->sync($this->roleIds($data['role_keys']));
        }
        if (isset($data['branch_ids'])) {
            $membership->branches()->sync(Branch::whereIn('id', $data['branch_ids'])->pluck('id'));
        }
        if (($data['status'] ?? null) === 'disabled') {
            if ($membership->user_id === auth()->id()) {
                throw new BusinessRuleException('CANNOT_DISABLE_SELF', 'You cannot deactivate yourself.');
            }
            if ($membership->roles->contains('key', 'clinic_owner') && $this->ownerCount() <= 1) {
                throw new BusinessRuleException('LAST_OWNER', 'The organisation must keep at least one owner.');
            }
        }
        if (isset($data['all_branches']) && $data['all_branches'] && $this->tenant()->branchIds() !== null) {
            abort(403, 'You cannot grant access to all branches.');
        }
        $membership->update(collect($data)->only(['all_branches', 'designation', 'employee_code', 'status'])->all());
        if (($data['status'] ?? null) === 'disabled') {
            $membership->user->tokens()->delete();
        }

        return $this->ok($membership->fresh(['user', 'roles', 'branches']));
    }

    private function ownerCount(): int
    {
        return Membership::where('status', 'active')->whereHas('roles', fn ($q) => $q->where('key', 'clinic_owner'))->count();
    }

    private function roleIds(array $keys)
    {
        return Role::whereIn('key', $keys)->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $this->tenant()->id()))->pluck('id');
    }

    /** Prevent privilege escalation: you can only grant roles whose permissions you already hold. */
    private function assertAssignable(array $keys): void
    {
        $roles = Role::with('permissions')->whereIn('key', $keys)->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $this->tenant()->id()))->get();
        if ($roles->count() !== count(array_unique($keys))) {
            throw new BusinessRuleException('UNKNOWN_ROLE', 'One or more roles do not exist.');
        }
        if ($roles->contains('key', 'clinic_owner') && ! $this->tenant()->hasRole('clinic_owner')) {
            abort(403, 'Only an owner can grant the owner role.');
        }
        $mine = $this->tenant()->permissions();
        foreach ($roles as $role) {
            $missing = array_diff($role->permissions->pluck('name')->all(), $mine);
            // Clinical permissions are exempt: an admin may onboard a doctor without being one.
            $missing = array_diff($missing, ['consultations.write', 'vitals.record', 'prescriptions.write', 'speciality.write', 'doctors.manage_own', 'queue.call', 'lab.order', 'lab.worklist', 'lab.results.enter', 'lab.results.release', 'patients.view_clinical_summary', 'attendance.self']);
            if ($missing) {
                abort(403, "You cannot grant the role '{$role->name}'.");
            }
        }
    }
}
