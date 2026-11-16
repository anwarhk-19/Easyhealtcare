<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Icd10Code;
use App\Models\Patient;
use App\Services\ClinicalAccess;
use App\Services\ConsultationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsultationController extends Controller
{
    public function __construct(private ConsultationService $consultations, private ClinicalAccess $access) {}

    private function find(string $id): Consultation
    {
        $c = Consultation::with(['patient.allergies', 'patient.conditions', 'patient.medications', 'doctor.user:id,name', 'template', 'sections', 'diagnoses', 'vitals', 'addenda', 'prescription.items', 'labOrders.items.test:id,name'])->findOrFail($id);
        $this->access->authorizeFull($c->patient);

        return $c;
    }

    public function index(Request $request): JsonResponse
    {
        $doctor = $this->access->currentDoctor();
        $q = Consultation::with('patient:id,patient_code,first_name,last_name,full_name', 'diagnoses:id,consultation_id,description')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('follow_up_due'), fn ($q) => $q->whereNotNull('follow_up_date')->whereDate('follow_up_date', '<=', now()->addDays(7)));
        if ($doctor) {
            $q->where('doctor_profile_id', $doctor->id);
        } else {
            abort_unless($this->tenant()->organization()->setting('admins_can_view_clinical'), 403);
        }

        return $this->paginated($q->latest()->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['appointment_id' => ['required_without:patient_id', 'nullable', 'string'], 'patient_id' => ['required_without:appointment_id', 'nullable', 'string'], 'template_id' => ['nullable', 'string']]);
        $doctor = $this->access->requireDoctor();
        $appointment = ! empty($data['appointment_id']) ? Appointment::with('consultation', 'patient')->findOrFail($data['appointment_id']) : null;
        $patient = ! empty($data['patient_id']) ? Patient::findOrFail($data['patient_id']) : null;
        $c = $this->consultations->start($doctor, $appointment, $patient, $this->branchId($request), $data['template_id'] ?? null);

        return $this->created($this->find($c->id));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->find($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['chief_complaint' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:20000'], 'treatment_plan' => ['nullable', 'string', 'max:5000'], 'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'], 'follow_up_notes' => ['nullable', 'string', 'max:255']]);
        $this->consultations->update($this->find($id), $data);

        return $this->ok($this->find($id));
    }

    public function saveSection(Request $request, string $id, string $key): JsonResponse
    {
        $data = $request->validate(['data' => ['present', 'array']])['data'];
        $this->consultations->saveSection($this->find($id), $key, $data);

        return $this->ok($this->find($id)->sections);
    }

    public function vitals(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('vitals.record'), 403);
        $c = Consultation::with('patient')->findOrFail($id);

        return $this->created($this->consultations->recordVitals($c->patient, $this->vitalRules($request), $c));
    }

    /** Nurse triage: vitals against an appointment before the consultation starts. */
    public function appointmentVitals(Request $request, string $appointmentId): JsonResponse
    {
        abort_unless($this->tenant()->can('vitals.record'), 403);
        $a = Appointment::with('patient')->findOrFail($appointmentId);

        return $this->created($this->consultations->recordVitals($a->patient, $this->vitalRules($request), $a->consultation, $a->id));
    }

    private function vitalRules(Request $request): array
    {
        return $request->validate([
            'height_cm' => ['nullable', 'numeric', 'between:20,250'], 'weight_kg' => ['nullable', 'numeric', 'between:0.5,350'],
            'temperature_c' => ['nullable', 'numeric', 'between:30,45'], 'pulse_bpm' => ['nullable', 'integer', 'between:20,250'],
            'resp_rate' => ['nullable', 'integer', 'between:4,80'], 'bp_systolic' => ['nullable', 'integer', 'between:50,260'],
            'bp_diastolic' => ['nullable', 'integer', 'between:30,160'], 'spo2' => ['nullable', 'integer', 'between:50,100'],
            'blood_sugar' => ['nullable', 'integer', 'between:20,800'], 'pain_score' => ['nullable', 'integer', 'between:0,10'],
        ]);
    }

    public function diagnoses(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['diagnoses' => ['present', 'array'], 'diagnoses.*.description' => ['required', 'string', 'max:255'], 'diagnoses.*.icd10_code' => ['nullable', 'string', 'exists:icd10_codes,code'], 'diagnoses.*.type' => ['nullable', Rule::in(['provisional', 'final', 'differential'])], 'diagnoses.*.is_primary' => ['boolean']]);
        $c = $this->find($id);
        $this->consultations->setDiagnoses($c, $data['diagnoses']);

        return $this->ok($c->diagnoses()->get());
    }

    public function sign(string $id): JsonResponse
    {
        $this->consultations->sign($this->find($id));

        return $this->ok($this->find($id));
    }

    public function addendum(Request $request, string $id): JsonResponse
    {
        $content = $request->validate(['content' => ['required', 'string', 'max:5000']])['content'];
        $c = $this->find($id);
        abort_unless($this->access->currentDoctor()?->id === $c->doctor_profile_id, 403, 'Only the treating doctor can add an addendum.');

        return $this->created($this->consultations->addAddendum($c, $content));
    }

    public function icd10(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        return $this->ok(Icd10Code::query()->when($q, fn ($w) => $w->where('code', 'like', $q.'%')->orWhere('description', 'like', '%'.$q.'%'))->orderBy('code')->limit(20)->get());
    }
}
