<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Consent;
use App\Models\Organization;
use App\Models\OtpCode;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use App\Support\Phone;
use App\Support\Sequence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

class PatientPortalService
{
    public function __construct(private TenantContext $context) {}

    /** Issues a 6-digit OTP. The code is only hashed at rest; delivery is via the SMS driver. */
    public function requestOtp(string $mobile): array
    {
        $mobile = Phone::normalize($mobile);
        $code = app()->environment('production') ? (string) random_int(100000, 999999) : '123456';
        OtpCode::where('identifier', $mobile)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        OtpCode::create(['identifier' => $mobile, 'purpose' => 'patient_login', 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(5)]);
        logger()->info('patient OTP issued', ['to' => substr($mobile, 0, 6).'****']);

        return ['mobile' => $mobile, 'expires_in' => 300];
    }

    public function verifyOtp(string $mobile, string $code): PatientAccount
    {
        $mobile = Phone::normalize($mobile);
        $otp = OtpCode::where('identifier', $mobile)->whereNull('consumed_at')->latest()->first();
        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= 5) {
            throw new BusinessRuleException('OTP_EXPIRED', 'This code has expired. Request a new one.', 401);
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            throw new BusinessRuleException('OTP_INVALID', 'Incorrect code.', 401);
        }
        $otp->update(['consumed_at' => now()]);

        return PatientAccount::firstOrCreate(['mobile' => $mobile], ['mobile_verified_at' => now()]);
    }

    /**
     * Links the account to the clinic's records with the same verified mobile, recording consent.
     * If the clinic has no record yet, a new patient record is registered there (patient-initiated, consented).
     */
    public function link(PatientAccount $account, Organization $org, array $profile = []): array
    {
        return $this->context->runAs($org, function () use ($account, $org, $profile) {
            $patients = Patient::where('mobile', $account->mobile)->get();
            if ($patients->isEmpty()) {
                if (empty($profile['first_name'])) {
                    throw new BusinessRuleException('PROFILE_REQUIRED', 'Your details are needed to register with this clinic.');
                }
                $patients = collect([Patient::create([
                    'patient_code' => $org->setting('patient_code_prefix', 'P').'-'.now()->format('Y').'-'.str_pad((string) Sequence::next('patient', '', now()->format('Y')), 5, '0', STR_PAD_LEFT),
                    'first_name' => $profile['first_name'],
                    'last_name' => $profile['last_name'] ?? null,
                    'gender' => $profile['gender'] ?? null,
                    'date_of_birth' => $profile['date_of_birth'] ?? null,
                    'mobile' => $account->mobile,
                ])]);
            }
            foreach ($patients as $p) {
                PatientAccountLink::updateOrCreate(
                    ['organization_id' => $org->id, 'patient_id' => $p->id],
                    ['patient_account_id' => $account->id, 'revoked_at' => null],
                );
                $p->update(['patient_account_id' => $account->id]);
                foreach (['share_with_patient_app', 'data_processing'] as $purpose) {
                    Consent::create(['patient_id' => $p->id, 'purpose' => $purpose, 'status' => 'granted', 'method' => 'otp', 'granted_at' => now()]);
                }
            }

            return $patients->all();
        });
    }

    public function revoke(PatientAccount $account, Organization $org): void
    {
        $this->context->runAs($org, function () use ($account, $org) {
            foreach (PatientAccountLink::where('patient_account_id', $account->id)->where('organization_id', $org->id)->whereNull('revoked_at')->get() as $link) {
                $link->update(['revoked_at' => now()]);
                Consent::create(['patient_id' => $link->patient_id, 'purpose' => 'share_with_patient_app', 'status' => 'revoked', 'method' => 'app', 'revoked_at' => now()]);
            }
        });
    }

    /** @return array<string, array{organization: Organization, patient_ids: array}> */
    public function linkedRecords(PatientAccount $account): array
    {
        $out = [];
        foreach ($account->links()->with('organization')->get() as $link) {
            $out[$link->organization_id]['organization'] = $link->organization;
            $out[$link->organization_id]['patient_ids'][] = $link->patient_id;
        }

        return $out;
    }
}
