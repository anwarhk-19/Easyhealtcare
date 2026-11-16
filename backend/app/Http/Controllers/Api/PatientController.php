<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatientRequest;
use App\Models\Appointment;
use App\Models\Consent;
use App\Models\Consultation;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Prescription;
use App\Models\Vital;
use App\Services\BillingService;
use App\Services\ClinicalAccess;
use App\Services\DocumentService;
use App\Support\Audit\AuditLogger;
use App\Support\Phone;
use App\Support\Sequence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Patient::query()->with('allergies:id,patient_id,allergen,severity');
        if ($term = trim((string) $request->query('q'))) {
            $q->search($term);
        }

        return $this->paginated($q->orderByDesc('created_at')->paginate($this->perPage($request)));
    }

    public function duplicatesCheck(Request $request): JsonResponse
    {
        $data = $request->validate(['mobile' => ['nullable', 'string'], 'first_name' => ['nullable', 'string'], 'date_of_birth' => ['nullable', 'date']]);

        return $this->ok($this->findDuplicates($data));
    }

    private function findDuplicates(array $data)
    {
        $mobile = Phone::normalize($data['mobile'] ?? null);
        if (! $mobile) {
            return collect();
        }

        return Patient::where('mobile', $mobile)->get(['id', 'patient_code', 'first_name', 'last_name', 'full_name', 'gender', 'date_of_birth', 'mobile'])
            ->filter(fn ($p) => empty($data['first_name']) || similar_text(strtolower($p->first_name), strtolower($data['first_name'])) >= min(3, strlen($data['first_name'])))
            ->values();
    }

    public function store(StorePatientRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (empty($data['allow_duplicate']) && ($dupes = $this->findDuplicates($data))->isNotEmpty()) {
            throw new BusinessRuleException('POSSIBLE_DUPLICATE', 'A patient with this mobile and a similar name already exists.', 409, ['matches' => $dupes]);
        }

        $patient = DB::transaction(function () use ($data, $request) {
            $org = $this->tenant()->organization();
            $year = now()->format('Y');
            $patient = Patient::create($this->attributes($data) + [
                'patient_code' => sprintf('%s-%s-%05d', $org->setting('patient_code_prefix', 'P'), $year, Sequence::next('patient', '', $year)),
                'registered_branch_id' => $this->branchId($request),
                'created_by' => auth()->id(),
            ]);
            foreach ($data['consents'] ?? [] as $purpose) {
                Consent::create(['patient_id' => $patient->id, 'purpose' => $purpose, 'status' => 'granted', 'method' => 'in_person', 'granted_at' => now(), 'recorded_by' => auth()->id()]);
            }

            return $patient;
        });

        return $this->created($patient);
    }

    private function attributes(array $data): array
    {
        $attrs = collect($data)->except(['age_years', 'consents', 'allow_duplicate'])->all();
        foreach (['mobile', 'alt_mobile'] as $k) {
            if (array_key_exists($k, $attrs)) {
                $attrs[$k] = Phone::normalize($attrs[$k]);
            }
        }
        if (isset($data['age_years']) && empty($data['date_of_birth'])) {
            $attrs['age_years_at_registration'] = $data['age_years'];
        }
        if (! empty($attrs['guardian_patient_id'])) {
            Patient::findOrFail($attrs['guardian_patient_id']);
        }

        return $attrs;
    }

    public function show(string $id): JsonResponse
    {
        $p = Patient::with(['allergies', 'conditions', 'medications', 'guardian:id,patient_code,full_name'])->findOrFail($id);

        return $this->ok($p->makeVisible('abha_number'));
    }

    public function update(StorePatientRequest $request, string $id): JsonResponse
    {
        $p = Patient::findOrFail($id);
        $p->update($this->attributes($request->validated()));

        return $this->ok($p->fresh());
    }

    /** Moves all records from source into target and soft-deletes the source. */
    public function merge(Request $request, string $id): JsonResponse
    {
        $target = Patient::findOrFail($id);
        $source = Patient::findOrFail($request->validate(['source_patient_id' => ['required', 'string', 'different:'.$id]])['source_patient_id']);
        DB::transaction(function () use ($target, $source) {
            foreach (['appointments', 'consultations', 'vitals', 'prescriptions', 'invoices', 'payments', 'lab_orders', 'patient_documents', 'patient_allergies', 'patient_conditions', 'patient_medications', 'consents', 'waitlist_entries', 'dental_tooth_records', 'treatment_plans', 'share_links', 'notification_messages'] as $table) {
                DB::table($table)->where('organization_id', $target->organization_id)->where('patient_id', $source->id)->update(['patient_id' => $target->id]);
            }
            $source->update(['merged_into_id' => $target->id]);
            $source->delete();
        });
        AuditLogger::log('patient.merged', $target, ['source' => $source->id], ['target' => $target->id]);

        return $this->ok($target->fresh());
    }

    /** Timeline: visits, prescriptions, labs, invoices. Clinical content requires clinical access. */
    public function timeline(string $id, ClinicalAccess $access): JsonResponse
    {
        $p = Patient::findOrFail($id);
        $clinical = $access->canViewFull($p);
        if ($clinical) {
            AuditLogger::log('clinical.viewed', $p);
        }
        $events = collect();
        foreach (Appointment::withoutGlobalScope('branch')->with('doctor.user:id,name')->where('patient_id', $p->id)->latest('scheduled_start')->limit(100)->get() as $a) {
            $events->push(['type' => 'appointment', 'id' => $a->id, 'at' => $a->scheduled_start, 'status' => $a->status, 'doctor' => $a->doctor->display_name, 'branch_id' => $a->branch_id]);
        }
        if ($clinical) {
            foreach (Consultation::withoutGlobalScope('branch')->with('diagnoses', 'doctor.user:id,name')->where('patient_id', $p->id)->latest()->limit(100)->get() as $c) {
                $events->push(['type' => 'consultation', 'id' => $c->id, 'at' => $c->created_at, 'status' => $c->status, 'doctor' => $c->doctor->display_name, 'chief_complaint' => $c->chief_complaint, 'diagnoses' => $c->diagnoses->pluck('description')]);
            }
        }
        foreach (LabOrder::withoutGlobalScope('branch')->with('items.test:id,name')->where('patient_id', $p->id)->latest()->limit(50)->get() as $l) {
            $events->push(['type' => 'lab_order', 'id' => $l->id, 'at' => $l->created_at, 'status' => $l->status, 'tests' => $l->items->pluck('test.name')]);
        }
        if ($this->tenant()->can('invoices.view')) {
            foreach (Invoice::withoutGlobalScope('branch')->where('patient_id', $p->id)->where('status', '!=', 'draft')->latest()->limit(50)->get() as $i) {
                $events->push(['type' => 'invoice', 'id' => $i->id, 'at' => $i->created_at, 'status' => $i->status, 'number' => $i->invoice_number, 'total' => $i->grand_total, 'balance_due' => $i->balance_due]);
            }
        }

        return $this->ok(['clinical_access' => $clinical, 'events' => $events->sortByDesc('at')->values()]);
    }

    /** Allergies, conditions, medications, recent vitals and latest Rx — for doctors and nurses. */
    public function clinicalSummary(string $id, ClinicalAccess $access): JsonResponse
    {
        $p = Patient::with('allergies', 'conditions', 'medications')->findOrFail($id);
        $access->authorizeSummary($p);
        $rx = Prescription::withoutGlobalScope('branch')->with('items')->where('patient_id', $p->id)->where('status', 'signed')->latest('signed_at')->first();

        return $this->ok([
            'allergies' => $p->allergies, 'conditions' => $p->conditions, 'medications' => $p->medications,
            'vitals' => Vital::where('patient_id', $p->id)->latest()->limit(10)->get(),
            'latest_prescription' => $rx,
        ]);
    }

    public function consultations(string $id, ClinicalAccess $access): JsonResponse
    {
        $p = Patient::findOrFail($id);
        $access->authorizeFull($p);

        return $this->ok(Consultation::withoutGlobalScope('branch')->with(['diagnoses', 'prescription.items', 'doctor.user:id,name', 'vitals', 'sections', 'addenda'])->where('patient_id', $p->id)->latest()->limit(50)->get());
    }

    public function vitals(string $id, ClinicalAccess $access): JsonResponse
    {
        $p = Patient::findOrFail($id);
        $access->authorizeSummary($p);

        return $this->ok(Vital::where('patient_id', $p->id)->orderBy('created_at')->limit(200)->get());
    }

    /** Nested medical-history lists: allergies | conditions | medications. */
    public function addHistory(Request $request, string $id, string $kind): JsonResponse
    {
        $p = Patient::findOrFail($id);
        $rules = match ($kind) {
            'allergies' => ['allergen' => ['required', 'string', 'max:120'], 'allergen_type' => ['required', Rule::in(['drug', 'food', 'environmental'])], 'reaction' => ['nullable', 'string', 'max:200'], 'severity' => ['required', Rule::in(['mild', 'moderate', 'severe'])]],
            'conditions' => ['name' => ['required', 'string', 'max:160'], 'type' => ['nullable', Rule::in(['chronic', 'past_illness', 'surgery', 'family', 'social'])], 'icd10_code' => ['nullable', 'string', 'max:16'], 'status' => ['nullable', Rule::in(['active', 'resolved'])], 'since_date' => ['nullable', 'date']],
            'medications' => ['name' => ['required', 'string', 'max:160'], 'dosage' => ['nullable', 'string', 'max:120'], 'is_active' => ['boolean']],
            default => abort(404),
        };

        return $this->created($p->{$kind}()->create($request->validate($rules)));
    }

    public function removeHistory(string $id, string $kind, string $itemId): JsonResponse
    {
        abort_unless(in_array($kind, ['allergies', 'conditions', 'medications'], true), 404);
        Patient::findOrFail($id)->{$kind}()->findOrFail($itemId)->delete();

        return response()->json(null, 204);
    }

    public function documents(string $id): JsonResponse
    {
        $p = Patient::findOrFail($id);
        $canSensitive = app(ClinicalAccess::class)->canViewFull($p);

        return $this->ok($p->documents()->when(! $canSensitive, fn ($q) => $q->where('is_sensitive', false))->latest()->get());
    }

    public function uploadDocument(Request $request, string $id, DocumentService $documents): JsonResponse
    {
        $p = Patient::findOrFail($id);
        $data = $request->validate(['file' => ['required', 'file', 'max:20480'], 'category' => ['required', Rule::in(['report', 'prescription_external', 'imaging', 'clinical_photo', 'consent', 'id_proof', 'other'])], 'title' => ['required', 'string', 'max:160'], 'consultation_id' => ['nullable', 'string']]);
        if ($data['category'] === 'clinical_photo' && ! $p->hasConsent('clinical_photos')) {
            throw new BusinessRuleException('CONSENT_REQUIRED', 'Record the patient\'s consent for clinical photographs first.', 422);
        }

        return $this->created($documents->store($p, $request->file('file'), $data['category'], $data['title'], $this->branchId($request, false), ['consultation_id' => $data['consultation_id'] ?? null]));
    }

    public function downloadDocument(string $documentId, DocumentService $documents)
    {
        $doc = PatientDocument::findOrFail($documentId);
        if ($doc->is_sensitive) {
            app(ClinicalAccess::class)->authorizeFull($doc->patient);
        }
        AuditLogger::log('document.downloaded', $doc);

        return $documents->download($doc);
    }

    public function consents(string $id): JsonResponse
    {
        return $this->ok(Patient::findOrFail($id)->consents()->latest()->get());
    }

    public function recordConsent(Request $request, string $id): JsonResponse
    {
        $p = Patient::findOrFail($id);
        $data = $request->validate(['purpose' => ['required', Rule::in(['data_processing', 'whatsapp', 'sms', 'email', 'clinical_photos', 'ai_processing', 'share_with_patient_app'])], 'status' => ['required', Rule::in(['granted', 'revoked'])], 'method' => ['nullable', Rule::in(['in_person', 'in_person_signed', 'otp', 'app'])], 'evidence_document_id' => ['nullable', 'string']]);

        return $this->created(Consent::create([
            'patient_id' => $p->id, 'purpose' => $data['purpose'], 'status' => $data['status'], 'method' => $data['method'] ?? 'in_person',
            'granted_at' => $data['status'] === 'granted' ? now() : null, 'revoked_at' => $data['status'] === 'revoked' ? now() : null, 'recorded_by' => auth()->id(),
        ]));
    }

    public function balance(string $id, BillingService $billing): JsonResponse
    {
        return $this->ok($billing->patientBalance(Patient::findOrFail($id)));
    }

    public function appointments(string $id): JsonResponse
    {
        return $this->ok(Appointment::withoutGlobalScope('branch')->with('doctor.user:id,name', 'token')->where('patient_id', Patient::findOrFail($id)->id)->latest('scheduled_start')->limit(100)->get());
    }

    /** DPDP data-principal request: machine-readable export of the patient's record in this clinic. */
    public function export(string $id, ClinicalAccess $access): JsonResponse
    {
        $p = Patient::with('allergies', 'conditions', 'medications', 'consents')->findOrFail($id);
        $access->authorizeFull($p);
        AuditLogger::log('patient.exported', $p);

        return $this->ok([
            'patient' => $p->makeVisible('abha_number'),
            'appointments' => Appointment::withoutGlobalScope('branch')->where('patient_id', $p->id)->get(),
            'consultations' => Consultation::withoutGlobalScope('branch')->with('diagnoses', 'sections', 'vitals', 'addenda', 'prescription.items')->where('patient_id', $p->id)->get(),
            'lab_orders' => LabOrder::withoutGlobalScope('branch')->with('items')->where('patient_id', $p->id)->get(),
            'invoices' => Invoice::withoutGlobalScope('branch')->with('items')->where('patient_id', $p->id)->get(),
            'documents' => $p->documents()->get(['id', 'title', 'category', 'created_at']),
            'exported_at' => now(),
        ]);
    }
}
