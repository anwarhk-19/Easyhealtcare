<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\DentalToothRecord;
use App\Models\EyeExamination;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Services\ClinicalAccess;
use App\Services\ConsultationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Speciality modules (dental chart, treatment plans / physio sessions, eye exams). Gated by the `specialities` plan feature. */
class SpecialityController extends Controller
{
    public const FDI_TEETH = [11, 12, 13, 14, 15, 16, 17, 18, 21, 22, 23, 24, 25, 26, 27, 28, 31, 32, 33, 34, 35, 36, 37, 38, 41, 42, 43, 44, 45, 46, 47, 48, 51, 52, 53, 54, 55, 61, 62, 63, 64, 65, 71, 72, 73, 74, 75, 81, 82, 83, 84, 85];

    public function __construct(private ClinicalAccess $access, private ConsultationService $consultations) {}

    private function patient(string $id): Patient
    {
        $p = Patient::findOrFail($id);
        $this->access->authorizeFull($p);

        return $p;
    }

    public function dentalChart(string $patientId): JsonResponse
    {
        $p = $this->patient($patientId);
        $records = DentalToothRecord::where('patient_id', $p->id)->orderBy('tooth_number')->latest()->get();

        return $this->ok(['teeth' => self::FDI_TEETH, 'records' => $records, 'current' => $records->groupBy('tooth_number')->map->first()]);
    }

    public function recordTooth(Request $request, string $patientId): JsonResponse
    {
        abort_unless($this->tenant()->can('speciality.write'), 403);
        $p = $this->patient($patientId);
        $data = $request->validate([
            'consultation_id' => ['nullable', 'string'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.tooth_number' => ['required', 'integer', Rule::in(self::FDI_TEETH)],
            'entries.*.surfaces' => ['nullable', 'string', 'regex:/^[MODBLI,]{1,16}$/'],
            'entries.*.condition' => ['required', Rule::in(['healthy', 'caries', 'missing', 'filled', 'rct', 'crown', 'bridge', 'implant', 'fractured', 'mobile', 'impacted', 'extraction_advised'])],
            'entries.*.procedure' => ['nullable', 'string', 'max:120'],
            'entries.*.status' => ['nullable', Rule::in(['existing', 'planned', 'done'])],
            'entries.*.notes' => ['nullable', 'string', 'max:255'],
        ]);
        if (! empty($data['consultation_id'])) {
            $this->consultations->assertEditable(Consultation::where('patient_id', $p->id)->findOrFail($data['consultation_id']));
        }
        $created = collect($data['entries'])->map(fn ($e) => DentalToothRecord::create($e + ['patient_id' => $p->id, 'consultation_id' => $data['consultation_id'] ?? null]));

        return $this->created($created);
    }

    public function treatmentPlans(string $patientId): JsonResponse
    {
        $p = $this->patient($patientId);

        return $this->ok(TreatmentPlan::with('items')->where('patient_id', $p->id)->latest()->get());
    }

    public function createPlan(Request $request, string $patientId): JsonResponse
    {
        abort_unless($this->tenant()->can('speciality.write'), 403);
        $p = $this->patient($patientId);
        $doctor = $this->access->requireDoctor();
        $data = $request->validate([
            'module' => ['required', Rule::in(['dental', 'physiotherapy', 'dermatology'])], 'name' => ['required', 'string', 'max:160'], 'condition' => ['nullable', 'string', 'max:160'],
            'total_sessions' => ['nullable', 'integer', 'min:1', 'max:200'],
            'items' => ['nullable', 'array'], 'items.*.description' => ['required', 'string', 'max:200'], 'items.*.sitting_no' => ['nullable', 'integer', 'min:1'],
            'items.*.tooth_number' => ['nullable', 'integer', Rule::in(self::FDI_TEETH)], 'items.*.service_id' => ['nullable', 'string'], 'items.*.estimated_cost' => ['nullable', 'integer', 'min:0'],
        ]);
        $plan = DB::transaction(function () use ($data, $p, $doctor) {
            $items = $data['items'] ?? [];
            if (! $items && ! empty($data['total_sessions'])) {
                $items = array_map(fn ($n) => ['description' => "Session {$n}", 'sitting_no' => $n], range(1, $data['total_sessions']));
            }
            $plan = TreatmentPlan::create(collect($data)->except('items')->all() + ['patient_id' => $p->id, 'doctor_profile_id' => $doctor->id, 'estimated_total' => array_sum(array_column($items, 'estimated_cost'))]);
            foreach ($items as $i) {
                $plan->items()->create($i + ['sitting_no' => $i['sitting_no'] ?? 1]);
            }

            return $plan;
        });

        return $this->created($plan->load('items'));
    }

    /** Completes a plan item (dental sitting / physio session) and records session data. */
    public function completeItem(Request $request, string $planId, string $itemId): JsonResponse
    {
        abort_unless($this->tenant()->can('speciality.write'), 403);
        $plan = TreatmentPlan::findOrFail($planId);
        $this->patient($plan->patient_id);
        $item = TreatmentPlanItem::where('treatment_plan_id', $plan->id)->findOrFail($itemId);
        $data = $request->validate([
            'consultation_id' => ['nullable', 'string'],
            'session_data' => ['nullable', 'array'],
            'session_data.pain_before' => ['nullable', 'integer', 'between:0,10'], 'session_data.pain_after' => ['nullable', 'integer', 'between:0,10'],
            'session_data.modalities' => ['nullable', 'array'], 'session_data.exercises' => ['nullable', 'array'], 'session_data.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($item->status === 'done') {
            throw BusinessRuleException::conflict('ALREADY_COMPLETED', 'This item is already completed.');
        }
        $item->update(['status' => 'done', 'completed_at' => now(), 'session_data' => $data['session_data'] ?? null, 'completed_consultation_id' => $data['consultation_id'] ?? null]);
        if ($plan->items()->where('status', '!=', 'done')->doesntExist()) {
            $plan->update(['status' => 'completed']);
        }

        return $this->ok($plan->fresh('items'));
    }

    /** Progress over sessions (e.g. physio pain scores). */
    public function planProgress(string $planId): JsonResponse
    {
        $plan = TreatmentPlan::with('items')->findOrFail($planId);
        $this->patient($plan->patient_id);
        $done = $plan->items->where('status', 'done');

        return $this->ok([
            'completed' => $done->count(), 'total' => $plan->items->count(),
            'percent' => $plan->items->count() ? round($done->count() / $plan->items->count() * 100) : 0,
            'pain_trend' => $done->map(fn ($i) => ['session' => $i->sitting_no, 'before' => $i->session_data['pain_before'] ?? null, 'after' => $i->session_data['pain_after'] ?? null])->values(),
        ]);
    }

    public function eyeExam(string $consultationId): JsonResponse
    {
        $c = Consultation::with('patient')->findOrFail($consultationId);
        $this->access->authorizeFull($c->patient);

        return $this->ok(EyeExamination::where('consultation_id', $c->id)->first());
    }

    public function saveEyeExam(Request $request, string $consultationId): JsonResponse
    {
        abort_unless($this->tenant()->can('speciality.write'), 403);
        $c = Consultation::with('patient')->findOrFail($consultationId);
        $this->consultations->assertEditable($c);
        $va = ['nullable', 'string', 'regex:/^(6\/(5|6|9|12|18|24|36|60)|CF|HM|PL|NPL|[0-9.]{1,4})$/'];
        $axis = ['nullable', 'integer', 'between:0,180'];
        $dpt = ['nullable', 'numeric', 'between:-30,30'];
        $data = $request->validate([
            'va_re_unaided' => $va, 'va_le_unaided' => $va, 'va_re_aided' => $va, 'va_le_aided' => $va,
            'iop_re' => ['nullable', 'integer', 'between:2,80'], 'iop_le' => ['nullable', 'integer', 'between:2,80'],
            'refraction' => ['nullable', 'array'],
            'refraction.re.sph' => $dpt, 'refraction.re.cyl' => $dpt, 'refraction.re.axis' => $axis, 'refraction.re.add' => ['nullable', 'numeric', 'between:0,4'],
            'refraction.le.sph' => $dpt, 'refraction.le.cyl' => $dpt, 'refraction.le.axis' => $axis, 'refraction.le.add' => ['nullable', 'numeric', 'between:0,4'],
            'findings' => ['nullable', 'array'],
        ]);

        return $this->ok(EyeExamination::updateOrCreate(['consultation_id' => $c->id], $data));
    }
}
