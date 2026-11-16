<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\ConsultationAddendum;
use App\Models\ConsultationTemplate;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\Vital;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultationService
{
    public function __construct(private TenantContext $context, private NotificationService $notifications) {}

    public function start(DoctorProfile $doctor, ?Appointment $appointment, ?Patient $patient, ?string $branchId, ?string $templateId = null): Consultation
    {
        if ($appointment) {
            if ($appointment->consultation) {
                return $appointment->consultation;
            }
            if ($appointment->doctor_profile_id !== $doctor->id) {
                throw new BusinessRuleException('NOT_YOUR_APPOINTMENT', 'This appointment belongs to another doctor.', 403);
            }
            $patient = $appointment->patient;
            $branchId = $appointment->branch_id;
        }
        if (! $patient || ! $branchId) {
            throw new BusinessRuleException('PATIENT_REQUIRED', 'A patient and branch are required to start a consultation.');
        }

        $template = $templateId ? ConsultationTemplate::findOrFail($templateId) : $this->defaultTemplateFor($doctor);

        return Consultation::create([
            'branch_id' => $branchId,
            'patient_id' => $patient->id,
            'doctor_profile_id' => $doctor->id,
            'appointment_id' => $appointment?->id,
            'template_id' => $template?->id,
            'template_version' => $template?->version,
            'status' => 'draft',
        ]);
    }

    /** Org template for the doctor's primary speciality, else platform template, else the general template. */
    public function defaultTemplateFor(DoctorProfile $doctor): ?ConsultationTemplate
    {
        $doctor->loadMissing('specialities');
        $speciality = $doctor->specialities->firstWhere('pivot.is_primary', true) ?? $doctor->specialities->first();
        $base = ConsultationTemplate::where('is_active', true)->orderByRaw('organization_id is null')->orderByDesc('version');

        return ($speciality ? (clone $base)->where('speciality_id', $speciality->id)->first() : null)
            ?? (clone $base)->where('key', 'general')->first();
    }

    public function update(Consultation $c, array $data): Consultation
    {
        $this->assertEditable($c);
        $c->update(array_intersect_key($data, array_flip(['chief_complaint', 'notes', 'treatment_plan', 'follow_up_date', 'follow_up_notes'])));

        return $c;
    }

    public function saveSection(Consultation $c, string $key, array $data): void
    {
        $this->assertEditable($c);
        $this->validateSection($c, $key, $data);
        $c->sections()->updateOrCreate(['section_key' => $key], ['data' => $data]);
    }

    /** Validates section data against the template's field definitions (types, ranges, options, required). */
    public function validateSection(Consultation $c, string $key, array $data): void
    {
        $definition = $c->template?->definition ?? [];
        $section = collect($definition['sections'] ?? [])->firstWhere('key', $key);
        if (! $section) {
            throw ValidationException::withMessages(['section' => "Section '$key' is not part of this consultation template."]);
        }
        $errors = [];
        foreach ($section['fields'] ?? [] as $field) {
            $value = $data[$field['key']] ?? null;
            if (($field['required'] ?? false) && ($value === null || $value === '')) {
                $errors[$field['key']] = "{$field['label']} is required.";

                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            switch ($field['type'] ?? 'text') {
                case 'number':
                    if (! is_numeric($value)) {
                        $errors[$field['key']] = "{$field['label']} must be a number.";
                    } elseif ((isset($field['min']) && $value < $field['min']) || (isset($field['max']) && $value > $field['max'])) {
                        $errors[$field['key']] = "{$field['label']} must be between {$field['min']} and {$field['max']}.";
                    }
                    break;
                case 'select':
                    if (! in_array($value, $field['options'] ?? [], true)) {
                        $errors[$field['key']] = "{$field['label']} has an invalid option.";
                    }
                    break;
                case 'multiselect':
                    if (! is_array($value) || array_diff($value, $field['options'] ?? []) !== []) {
                        $errors[$field['key']] = "{$field['label']} has an invalid option.";
                    }
                    break;
                case 'boolean':
                    if (! is_bool($value)) {
                        $errors[$field['key']] = "{$field['label']} must be true or false.";
                    }
                    break;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function recordVitals(Patient $patient, array $data, ?Consultation $c = null, ?string $appointmentId = null): Vital
    {
        if ($c?->isLocked()) {
            throw BusinessRuleException::conflict('CONSULTATION_LOCKED', 'This consultation is signed and locked.');
        }
        if (! empty($data['height_cm']) && ! empty($data['weight_kg'])) {
            $m = $data['height_cm'] / 100;
            $data['bmi'] = round($data['weight_kg'] / ($m * $m), 1);
        }

        return Vital::create($data + [
            'patient_id' => $patient->id,
            'consultation_id' => $c?->id,
            'appointment_id' => $appointmentId ?? $c?->appointment_id,
            'recorded_by' => auth()->id(),
        ]);
    }

    public function setDiagnoses(Consultation $c, array $diagnoses): void
    {
        $this->assertEditable($c);
        DB::transaction(function () use ($c, $diagnoses) {
            $c->diagnoses()->delete();
            foreach ($diagnoses as $i => $d) {
                $c->diagnoses()->create([
                    'icd10_code' => $d['icd10_code'] ?? null,
                    'description' => $d['description'],
                    'type' => $d['type'] ?? 'provisional',
                    'is_primary' => $d['is_primary'] ?? $i === 0,
                ]);
            }
        });
    }

    public function sign(Consultation $c): Consultation
    {
        $this->assertEditable($c);

        DB::transaction(function () use ($c) {
            $c->update(['status' => 'signed', 'signed_at' => now(), 'signed_by' => auth()->id()]);
            if ($c->prescription && $c->prescription->status === 'draft' && $c->prescription->items()->exists()) {
                $c->prescription->update(['status' => 'signed', 'signed_at' => now()]);
            }
        });

        if ($c->follow_up_date) {
            $remindAt = $c->follow_up_date->copy()->subDay()->setTime(4, 30); // ~10:00 IST the day before
            $this->notifications->send('followup.reminder', $c->patient, [
                'doctor_name' => $c->doctor->display_name,
                'date' => $c->follow_up_date->format('d M Y'),
            ], $c, null, $remindAt->isFuture() ? $remindAt : null);
        }

        return $c->fresh();
    }

    public function addAddendum(Consultation $c, string $content): ConsultationAddendum
    {
        if (! $c->isLocked()) {
            throw BusinessRuleException::conflict('CONSULTATION_NOT_SIGNED', 'Edit the draft directly; addenda are for signed consultations.');
        }

        return $c->addenda()->create(['content' => $content, 'created_by' => auth()->id()]);
    }

    public function assertEditable(Consultation $c): void
    {
        if ($c->isLocked()) {
            throw BusinessRuleException::conflict('CONSULTATION_LOCKED', 'This consultation is signed and locked. Add an addendum instead.');
        }
        $doctor = app(ClinicalAccess::class)->currentDoctor();
        if (! $doctor || $doctor->id !== $c->doctor_profile_id) {
            throw new BusinessRuleException('NOT_YOUR_CONSULTATION', 'Only the treating doctor can edit this consultation.', 403);
        }
    }
}
