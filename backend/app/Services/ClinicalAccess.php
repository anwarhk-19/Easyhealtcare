<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Decides who may see clinical records (consultations, notes, prescriptions), beyond plain permissions:
 * - owner/admin only when the org enables `admins_can_view_clinical`
 * - doctors per `clinical_visibility` (all_doctors | treating_doctor_only)
 * - nurses get the clinical summary (allergies, conditions, vitals, latest Rx) via `patients.view_clinical_summary`.
 * Every clinical view is audit-logged.
 */
class ClinicalAccess
{
    public function __construct(private TenantContext $context) {}

    public function currentDoctor(): ?DoctorProfile
    {
        $userId = $this->context->user()?->id;

        return $userId ? DoctorProfile::where('user_id', $userId)->first() : null;
    }

    public function canViewFull(Patient $patient): bool
    {
        if (! $this->context->can('patients.view_clinical')) {
            return false;
        }
        $org = $this->context->organization();
        $doctor = $this->currentDoctor();

        if ($doctor) {
            if ($org->setting('clinical_visibility') === 'treating_doctor_only') {
                return Appointment::withoutGlobalScope('branch')->where('patient_id', $patient->id)->where('doctor_profile_id', $doctor->id)->exists()
                    || Consultation::withoutGlobalScope('branch')->where('patient_id', $patient->id)->where('doctor_profile_id', $doctor->id)->exists();
            }

            return true;
        }

        // Non-doctor holders (owner/admin) need the org-level switch.
        return (bool) $org->setting('admins_can_view_clinical');
    }

    public function authorizeFull(Patient $patient): void
    {
        if (! $this->canViewFull($patient)) {
            throw new AuthorizationException('You are not permitted to view this patient\'s clinical records.');
        }
        AuditLogger::log('clinical.viewed', $patient);
    }

    public function authorizeSummary(Patient $patient): void
    {
        if (! $this->canViewFull($patient) && ! $this->context->can('patients.view_clinical_summary')) {
            throw new AuthorizationException('You are not permitted to view this patient\'s clinical summary.');
        }
        AuditLogger::log('clinical.summary_viewed', $patient);
    }

    /** Doctors may only write consultations as themselves. */
    public function requireDoctor(): DoctorProfile
    {
        return $this->currentDoctor() ?? throw new BusinessRuleException('NOT_A_DOCTOR', 'Only doctors can perform this action.', 403);
    }
}
