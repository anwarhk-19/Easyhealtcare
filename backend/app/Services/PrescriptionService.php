<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Consultation;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Prescription;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class PrescriptionService
{
    public function __construct(private ConsultationService $consultations, private ShareLinkService $links, private NotificationService $notifications) {}

    /** Replaces the Rx lines on a draft consultation and returns advisory allergy warnings. */
    public function save(Consultation $c, array $items, ?string $advice): array
    {
        $this->consultations->assertEditable($c);

        $rx = DB::transaction(function () use ($c, $items, $advice) {
            $rx = $c->prescription ?? Prescription::create([
                'branch_id' => $c->branch_id,
                'consultation_id' => $c->id,
                'patient_id' => $c->patient_id,
                'doctor_profile_id' => $c->doctor_profile_id,
                'status' => 'draft',
            ]);
            $rx->update(['advice' => $advice]);
            $rx->items()->delete();
            foreach (array_values($items) as $i => $item) {
                $medicine = ! empty($item['medicine_id']) ? Medicine::find($item['medicine_id']) : null;
                $rx->items()->create([
                    'medicine_id' => $medicine?->id,
                    'medicine_name' => $item['medicine_name'] ?? trim(($medicine?->brand_name ?: $medicine?->generic_name).' '),
                    'strength' => $item['strength'] ?? $medicine?->strength,
                    'form' => $item['form'] ?? $medicine?->form,
                    'dose' => $item['dose'] ?? null,
                    'frequency' => $item['frequency'],
                    'duration_value' => $item['duration_value'] ?? null,
                    'duration_unit' => $item['duration_unit'] ?? 'days',
                    'route' => $item['route'] ?? null,
                    'timing' => $item['timing'] ?? 'after_food',
                    'instructions' => $item['instructions'] ?? null,
                    'quantity' => $item['quantity'] ?? $this->estimateQuantity($item),
                    'sort' => $i,
                ]);
            }

            return $rx;
        });

        return ['prescription' => $rx->load('items'), 'warnings' => $this->allergyWarnings($c->patient, $items)];
    }

    /**
     * Advisory only (string/class match against recorded drug allergies). It never blocks the doctor's decision.
     *
     * @return array<int, array{medicine:string, allergen:string, severity:string}>
     */
    public function allergyWarnings(Patient $patient, array $items): array
    {
        $allergies = $patient->allergies()->where('allergen_type', 'drug')->get();
        $warnings = [];
        foreach ($items as $item) {
            $medicine = ! empty($item['medicine_id']) ? Medicine::find($item['medicine_id']) : null;
            $haystack = strtolower(implode(' ', array_filter([$item['medicine_name'] ?? null, $medicine?->generic_name, $medicine?->brand_name, $medicine?->drug_class])));
            foreach ($allergies as $a) {
                if ($haystack !== '' && str_contains($haystack, strtolower($a->allergen))) {
                    $warnings[] = ['medicine' => $item['medicine_name'] ?? $medicine?->brand_name ?? $medicine?->generic_name, 'allergen' => $a->allergen, 'severity' => $a->severity];
                }
            }
        }

        return $warnings;
    }

    /** 1-0-1 style frequency × duration in days. */
    private function estimateQuantity(array $item): ?int
    {
        if (empty($item['duration_value']) || ! preg_match('/^\d(-\d){2,3}$/', $item['frequency'] ?? '')) {
            return null;
        }
        $perDay = array_sum(array_map('intval', explode('-', $item['frequency'])));
        $days = $item['duration_value'] * match ($item['duration_unit'] ?? 'days') {
            'weeks' => 7, 'months' => 30, default => 1
        };

        return $perDay * $days;
    }

    public function pdf(Prescription $rx): \Barryvdh\DomPDF\PDF
    {
        $rx->load('items', 'patient', 'doctor.user', 'doctor.specialities', 'consultation.diagnoses', 'consultation.vitals', 'branch');

        return Pdf::loadView('pdf.prescription', ['rx' => $rx, 'org' => $rx->organization, 'branch' => $rx->branch]);
    }

    public function share(Prescription $rx, array $channels, int $hours = 72): array
    {
        if ($rx->status !== 'signed') {
            throw BusinessRuleException::conflict('PRESCRIPTION_NOT_SIGNED', 'Only signed prescriptions can be shared.');
        }
        $share = $this->links->create($rx, $rx->patient_id, $hours);
        $rx->update(['shared_with_patient_at' => now()]);
        $this->notifications->send('prescription.shared', $rx->patient, [
            'doctor_name' => $rx->doctor->display_name,
            'link' => $share['url'],
        ], $rx, $channels, null, (string) now()->timestamp);

        return ['url' => $share['url'], 'expires_at' => $share['link']->expires_at];
    }
}
