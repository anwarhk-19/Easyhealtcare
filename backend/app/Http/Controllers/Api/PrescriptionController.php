<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Prescription;
use App\Services\ClinicalAccess;
use App\Services\PrescriptionService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrescriptionController extends Controller
{
    public function __construct(private PrescriptionService $rx, private ClinicalAccess $access) {}

    public function show(string $consultationId): JsonResponse
    {
        $c = Consultation::with('patient', 'prescription.items')->findOrFail($consultationId);
        $this->access->authorizeFull($c->patient);

        return $this->ok($c->prescription);
    }

    public function save(Request $request, string $consultationId): JsonResponse
    {
        $data = $request->validate([
            'items' => ['present', 'array', 'max:30'],
            'items.*.medicine_id' => ['nullable', 'string', 'exists:medicines,id'],
            'items.*.medicine_name' => ['required_without:items.*.medicine_id', 'nullable', 'string', 'max:160'],
            'items.*.strength' => ['nullable', 'string', 'max:64'], 'items.*.form' => ['nullable', 'string', 'max:32'],
            'items.*.dose' => ['nullable', 'string', 'max:32'],
            'items.*.frequency' => ['required', 'string', 'max:32'],
            'items.*.duration_value' => ['nullable', 'integer', 'min:1', 'max:365'],
            'items.*.duration_unit' => ['nullable', Rule::in(['days', 'weeks', 'months'])],
            'items.*.route' => ['nullable', 'string', 'max:32'],
            'items.*.timing' => ['nullable', Rule::in(['before_food', 'after_food', 'with_food', 'empty_stomach', 'bedtime', 'any'])],
            'items.*.instructions' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'advice' => ['nullable', 'string', 'max:3000'],
        ]);
        $c = Consultation::with('patient', 'prescription')->findOrFail($consultationId);

        return $this->ok($this->rx->save($c, $data['items'], $data['advice'] ?? null));
    }

    public function checkAllergies(Request $request): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'string'], 'items' => ['required', 'array'], 'items.*.medicine_id' => ['nullable', 'string'], 'items.*.medicine_name' => ['nullable', 'string']]);

        return $this->ok($this->rx->allergyWarnings(Patient::findOrFail($data['patient_id']), $data['items']));
    }

    /** Copies the lines of a previous prescription for the same patient into this draft. */
    public function copyFrom(string $consultationId, string $prescriptionId): JsonResponse
    {
        $c = Consultation::with('patient', 'prescription')->findOrFail($consultationId);
        $previous = Prescription::withoutGlobalScope('branch')->with('items')->where('patient_id', $c->patient_id)->findOrFail($prescriptionId);
        $items = $previous->items->map(fn ($i) => $i->only(['medicine_id', 'medicine_name', 'strength', 'form', 'dose', 'frequency', 'duration_value', 'duration_unit', 'route', 'timing', 'instructions']))->all();

        return $this->ok($this->rx->save($c, $items, $previous->advice));
    }

    public function pdf(string $id)
    {
        abort_unless($this->tenant()->can('prescriptions.print'), 403);
        $rx = Prescription::findOrFail($id);
        AuditLogger::log('prescription.printed', $rx);

        return $this->rx->pdf($rx)->stream('prescription-'.$rx->id.'.pdf');
    }

    public function share(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('prescriptions.share'), 403);
        $data = $request->validate(['channels' => ['nullable', 'array'], 'channels.*' => [Rule::in(['whatsapp', 'sms', 'email'])], 'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:720']]);

        return $this->ok($this->rx->share(Prescription::with('patient', 'doctor.user')->findOrFail($id), $data['channels'] ?? ['whatsapp'], $data['expires_in_hours'] ?? 72));
    }
}
