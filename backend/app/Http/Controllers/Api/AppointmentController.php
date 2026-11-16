<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookAppointmentRequest;
use App\Models\Appointment;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\WaitlistEntry;
use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $appointments) {}

    private function find(string $id): Appointment
    {
        return Appointment::with(['patient', 'doctor.user:id,name', 'branch:id,name,timezone', 'token'])->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $q = Appointment::with(['patient:id,patient_code,first_name,last_name,full_name,mobile,gender,date_of_birth,age_years_at_registration,created_at', 'doctor.user:id,name', 'token:id,appointment_id,token_label,status,priority']);
        if ($date = $request->query('date')) {
            $tz = $this->tenant()->organization()->timezone;
            $day = CarbonImmutable::parse($date, $tz);
            $q->whereBetween('scheduled_start', [$day->startOfDay()->utc(), $day->endOfDay()->utc()]);
        }
        if ($from = $request->query('from')) {
            $q->where('scheduled_start', '>=', CarbonImmutable::parse($from)->utc());
        }
        if ($to = $request->query('to')) {
            $q->where('scheduled_start', '<=', CarbonImmutable::parse($to)->utc());
        }
        foreach (['doctor_profile_id' => 'doctor_id', 'branch_id' => 'branch_id', 'status' => 'status', 'patient_id' => 'patient_id'] as $col => $param) {
            if ($v = $request->query($param)) {
                is_array($v) ? $q->whereIn($col, $v) : $q->where($col, $v);
            }
        }

        // Doctors without appointments.view_all-style permissions still see their own list via doctor filter.
        return $this->paginated($q->orderBy('scheduled_start')->paginate($this->perPage($request)));
    }

    public function store(BookAppointmentRequest $request): JsonResponse
    {
        $this->branchId($request);

        return $this->created($this->find($this->appointments->book($request->validated())->id));
    }

    public function walkIn(Request $request): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'string'], 'doctor_profile_id' => ['required', 'string'], 'branch_id' => ['required', 'string'], 'service_id' => ['nullable', 'string'], 'priority' => ['nullable', Rule::in(['normal', 'priority', 'emergency'])], 'notes' => ['nullable', 'string', 'max:500']]);
        $this->branchId($request);

        return $this->created($this->find($this->appointments->walkIn($data)->id));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->find($id)->load('history'));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $a = $this->find($id);
        $a->update($request->validate(['notes' => ['nullable', 'string', 'max:500'], 'service_id' => ['nullable', 'string']]));

        return $this->ok($a);
    }

    public function confirm(string $id): JsonResponse
    {
        return $this->ok($this->appointments->confirm($this->find($id)));
    }

    public function checkIn(Request $request, string $id): JsonResponse
    {
        $priority = $request->validate(['priority' => ['nullable', Rule::in(['normal', 'priority', 'emergency'])]])['priority'] ?? 'normal';

        return $this->ok($this->appointments->checkIn($this->find($id), $priority));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'notify' => ['boolean']]);

        return $this->ok($this->appointments->cancel($this->find($id), $data['reason'], $data['notify'] ?? true));
    }

    public function noShow(string $id): JsonResponse
    {
        return $this->ok($this->appointments->noShow($this->find($id)));
    }

    public function reschedule(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['scheduled_start' => ['required', 'date'], 'doctor_profile_id' => ['nullable', 'string']]);
        $new = $this->appointments->reschedule($this->find($id), $data['scheduled_start'], $data['doctor_profile_id'] ?? null);

        return $this->ok($this->find($new->id));
    }

    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'branch_id' => ['nullable', 'string'], 'doctor_id' => ['nullable', 'string']]);
        $tz = $this->tenant()->organization()->timezone;
        $appts = Appointment::with('patient:id,patient_code,first_name,last_name,full_name', 'doctor.user:id,name')
            ->whereBetween('scheduled_start', [CarbonImmutable::parse($data['from'], $tz)->startOfDay()->utc(), CarbonImmutable::parse($data['to'], $tz)->endOfDay()->utc()])
            ->when($data['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($data['doctor_id'] ?? null, fn ($q, $d) => $q->where('doctor_profile_id', $d))
            ->where('status', '!=', 'cancelled')->orderBy('scheduled_start')->get();

        return $this->ok($appts->groupBy('doctor_profile_id')->map(fn ($list, $doctorId) => ['doctor_id' => $doctorId, 'doctor' => $list->first()->doctor->display_name, 'appointments' => $list->values()])->values());
    }

    public function waitlist(Request $request): JsonResponse
    {
        return $this->ok(WaitlistEntry::with('patient:id,patient_code,first_name,last_name,full_name,mobile')
            ->whereIn('status', ['waiting', 'offered'])->when($request->query('date'), fn ($q, $d) => $q->whereDate('preferred_date', $d))->orderBy('preferred_date')->get());
    }

    public function addToWaitlist(Request $request): JsonResponse
    {
        $data = $request->validate(['patient_id' => ['required', 'string'], 'branch_id' => ['required', 'string'], 'doctor_profile_id' => ['nullable', 'string'], 'preferred_date' => ['required', 'date', 'after_or_equal:today'], 'notes' => ['nullable', 'string', 'max:200']]);
        Patient::findOrFail($data['patient_id']);
        if ($data['doctor_profile_id'] ?? null) {
            DoctorProfile::findOrFail($data['doctor_profile_id']);
        }
        $this->branchId($request);

        return $this->created(WaitlistEntry::create($data));
    }

    public function removeFromWaitlist(string $id): JsonResponse
    {
        WaitlistEntry::findOrFail($id)->update(['status' => 'expired']);

        return response()->json(null, 204);
    }
}
