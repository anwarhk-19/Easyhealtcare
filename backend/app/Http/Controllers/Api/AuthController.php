<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterClinicRequest;
use App\Models\Branch;
use App\Models\DoctorProfile;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\OnboardingService;
use App\Support\Audit\AuditLogger;
use App\Support\Phone;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function registerClinic(RegisterClinicRequest $request, OnboardingService $onboarding): JsonResponse
    {
        $result = $onboarding->registerClinic($request->validated());
        $token = $result['user']->createToken('web', ['*'], now()->addHours(12))->plainTextToken;

        return $this->created(['token' => $token, 'organization' => $result['organization'], 'branch' => $result['branch'], 'user' => $result['user']]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string'], 'password' => ['required', 'string'], 'device_name' => ['nullable', 'string', 'max:60']]);
        $identifier = $data['email'];
        $user = str_contains($identifier, '@')
            ? User::where('email', strtolower($identifier))->first()
            : User::where('mobile', Phone::normalize($identifier))->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || $user->status !== 'active') {
            AuditLogger::log('login.failed', null, null, ['identifier' => substr($identifier, 0, 3).'***']);
            throw new BusinessRuleException('INVALID_CREDENTIALS', 'These credentials do not match our records.', 401);
        }

        if ($user->hasTwoFactor()) {
            $challenge = Str::random(40);
            Cache::put('2fa:'.$challenge, $user->id, now()->addMinutes(5));

            return $this->ok(['two_factor_required' => true, 'challenge' => $challenge]);
        }

        return $this->ok($this->issueToken($user, $data['device_name'] ?? 'web'));
    }

    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string'], 'code' => ['required', 'string', 'size:6']]);
        $userId = Cache::get('2fa:'.$data['challenge']);
        $user = $userId ? User::find($userId) : null;
        if (! $user || ! Totp::verify($user->two_factor_secret, $data['code'])) {
            throw new BusinessRuleException('INVALID_2FA_CODE', 'The authentication code is invalid.', 401);
        }
        Cache::forget('2fa:'.$data['challenge']);

        return $this->ok($this->issueToken($user, 'web'));
    }

    private function issueToken(User $user, string $device): array
    {
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('login', $user);

        return ['token' => $user->createToken($device, ['*'], now()->addHours(12))->plainTextToken, 'user' => $user];
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }

    public function sessions(Request $request): JsonResponse
    {
        return $this->ok($request->user()->tokens()->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']));
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $request->user()->tokens()->whereKey($id)->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        $ctx = $this->tenant();
        $org = $ctx->organization();
        $user = $request->user();
        $memberships = $ctx->bypass(fn () => Membership::with('organization:id,name,slug,status')->where('user_id', $user->id)->where('status', 'active')->get());

        return $this->ok([
            'user' => $user,
            'organization' => $org->only(['id', 'name', 'slug', 'status', 'logo_path', 'timezone', 'currency']),
            'subscription' => ['plan' => $org->subscription?->plan?->only(['code', 'name', 'features', 'limits']), 'status' => $org->subscription?->status, 'trial_ends_at' => $org->subscription?->trial_ends_at],
            'roles' => $ctx->membership()->roles->pluck('key'),
            'permissions' => $ctx->permissions(),
            'branch_ids' => $ctx->branchIds(),
            'branches' => Branch::where('is_active', true)->when($ctx->branchIds() !== null, fn ($q) => $q->whereIn('id', $ctx->branchIds()))->get(['id', 'name', 'code', 'timezone']),
            'doctor_profile_id' => DoctorProfile::where('user_id', $user->id)->value('id'),
            'settings' => $org->allSettings(),
            'memberships' => $memberships->map(fn ($m) => ['organization_id' => $m->organization_id, 'name' => $m->organization?->name]),
        ]);
    }

    public function switchOrganization(Request $request): JsonResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string']]);
        $member = $this->tenant()->bypass(fn () => Membership::where('user_id', $request->user()->id)->where('organization_id', $data['organization_id'])->where('status', 'active')->exists());
        abort_unless($member, 403, 'You are not a member of that organisation.');
        $request->user()->forceFill(['current_organization_id' => $data['organization_id']])->save();

        return $this->ok(['organization_id' => $data['organization_id']]);
    }

    public function enableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return $this->ok(['secret' => $secret, 'otpauth_url' => Totp::uri($secret, $user->email ?? $user->mobile)]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $user = $request->user();
        if (! $user->two_factor_secret || ! Totp::verify($user->two_factor_secret, $data['code'])) {
            throw new BusinessRuleException('INVALID_2FA_CODE', 'The authentication code is invalid.');
        }
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        AuditLogger::log('2fa.enabled', $user);

        return $this->ok(['enabled' => true]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:sanctum']]);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        AuditLogger::log('2fa.disabled', $request->user());

        return $this->ok(['enabled' => false]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same response whether or not the account exists (no user enumeration).
        return $this->ok(['message' => 'If an account exists for this email, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete(); // sign out everywhere
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw new BusinessRuleException('INVALID_RESET_TOKEN', __($status));
        }

        return $this->ok(['message' => 'Password reset. Please sign in.']);
    }

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['password' => ['required', PasswordRule::min(10)->letters()->numbers()]]);

        return $this->tenant()->bypass(function () use ($token, $data) {
            $invite = StaffInvitation::where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->first();
            if (! $invite || $invite->expires_at->isPast()) {
                throw new BusinessRuleException('INVITATION_INVALID', 'This invitation is invalid or has expired.', 410);
            }
            $org = Organization::findOrFail($invite->organization_id);

            return $this->tenant()->runAs($org, function () use ($invite, $data, $org) {
                $user = User::where('email', $invite->email)->when(! $invite->email, fn ($q) => $q->orWhere('mobile', $invite->mobile))->first()
                    ?? User::create(['name' => $invite->name, 'email' => $invite->email, 'mobile' => $invite->mobile, 'password' => $data['password'], 'current_organization_id' => $org->id]);
                $membership = Membership::firstOrCreate(['user_id' => $user->id], ['status' => 'active', 'joined_at' => now(), 'all_branches' => empty($invite->branch_ids)]);
                $membership->update(['status' => 'active']);
                $membership->roles()->syncWithoutDetaching(Role::whereIn('key', $invite->role_keys)->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $org->id))->pluck('id'));
                if ($invite->branch_ids) {
                    $membership->branches()->syncWithoutDetaching($invite->branch_ids);
                }
                $invite->update(['accepted_at' => now()]);

                return $this->ok(['token' => $user->createToken('web', ['*'], now()->addHours(12))->plainTextToken, 'user' => $user]);
            });
        });
    }
}
