<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\DoctorFee;
use App\Models\DoctorLeave;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\ReportService;
use App\Services\SubscriptionService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('doctors.view'), 403);
        $q = DoctorProfile::with(['user:id,name,email,mobile', 'specialities:id,code,name', 'branches:id,name'])->where('is_active', true);
        if ($b = $request->query('branch_id')) {
            $q->whereHas('branches', fn ($w) => $w->where('branches.id', $b));
        }
        if ($s = $request->query('speciality')) {
            $q->whereHas('specialities', fn ($w) => $w->where('code', $s)->orWhere('specialities.id', $s));
        }

        return $this->ok($q->get());
    }

    /** Creates a doctor: either for an existing staff member (user_id) or a new user. */
    public function store(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        abort_unless($this->tenant()->can('doctors.manage'), 403);
        $data = $request->validate([
            'user_id' => ['nullable', 'string'],
            'name' => ['required_without:user_id', 'string', 'max:120'],
            'email' => ['required_without:user_id', 'nullable', 'email', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'password' => ['required_without:user_id', 'nullable', 'string', 'min:10'],
            'title' => ['nullable', 'string', 'max:16'], 'qualifications' => ['nullable', 'array'],
            'registration_council' => ['nullable', 'string', 'max:120'], 'registration_number' => ['nullable', 'string', 'max:64'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:70'], 'bio' => ['nullable', 'string', 'max:2000'],
            'consultation_duration_min' => ['nullable', 'integer', 'min:5', 'max:180'],
            'speciality_ids' => ['required', 'array', 'min:1'], 'speciality_ids.*' => ['exists:specialities,id'],
            'branch_ids' => ['required', 'array', 'min:1'], 'branch_ids.*' => ['string'],
        ]);
        $subscriptions->assertWithinLimit($this->tenant()->organization(), 'doctors');

        $doctor = DB::transaction(function () use ($data) {
            if (! empty($data['user_id'])) {
                $membership = Membership::where('user_id', $data['user_id'])->firstOrFail();
                $user = $membership->user;
            } else {
                $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'mobile' => Phone::normalize($data['mobile'] ?? null), 'password' => $data['password'], 'current_organization_id' => $this->tenant()->id()]);
                $membership = Membership::create(['user_id' => $user->id, 'joined_at' => now()]);
            }
            $membership->roles()->syncWithoutDetaching(Role::whereNull('organization_id')->where('key', 'doctor')->pluck('id'));
            $branchIds = Branch::whereIn('id', $data['branch_ids'])->pluck('id');
            $membership->branches()->syncWithoutDetaching($branchIds);

            $doctor = DoctorProfile::create(collect($data)->only(['title', 'qualifications', 'registration_council', 'registration_number', 'experience_years', 'bio', 'consultation_duration_min'])->filter(fn ($v) => $v !== null)->all() + ['user_id' => $user->id]);
            $doctor->specialities()->sync(collect($data['speciality_ids'])->mapWithKeys(fn ($id, $i) => [$id => ['is_primary' => $i === 0]])->all());
            $doctor->branches()->sync($branchIds);

            return $doctor;
        });

        return $this->created($doctor->load('user', 'specialities', 'branches'));
    }

    public function show(string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('doctors.view'), 403);

        return $this->ok(DoctorProfile::with(['user:id,name,email,mobile', 'specialities', 'branches:id,name', 'fees', 'schedules', 'leaves' => fn ($q) => $q->where('ends_at', '>=', now())])->findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $doctor = DoctorProfile::findOrFail($id);
        $this->authorizeManage($doctor);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:16'], 'qualifications' => ['nullable', 'array'],
            'registration_council' => ['nullable', 'string'], 'registration_number' => ['nullable', 'string'],
            'experience_years' => ['nullable', 'integer'], 'bio' => ['nullable', 'string', 'max:2000'],
            'consultation_duration_min' => ['sometimes', 'integer', 'min:5', 'max:180'], 'is_bookable_online' => ['boolean'], 'is_active' => ['boolean'],
            'speciality_ids' => ['sometimes', 'array', 'min:1'], 'speciality_ids.*' => ['exists:specialities,id'],
            'branch_ids' => ['sometimes', 'array', 'min:1'],
        ]);
        $doctor->update(collect($data)->except(['speciality_ids', 'branch_ids'])->all());
        if (isset($data['speciality_ids'])) {
            $doctor->specialities()->sync(collect($data['speciality_ids'])->mapWithKeys(fn ($sid, $i) => [$sid => ['is_primary' => $i === 0]])->all());
        }
        if (isset($data['branch_ids']) && $this->tenant()->can('doctors.manage')) {
            $doctor->branches()->sync(Branch::whereIn('id', $data['branch_ids'])->pluck('id'));
        }

        return $this->show($id);
    }

    public function uploadSignature(Request $request, string $id): JsonResponse
    {
        $doctor = DoctorProfile::findOrFail($id);
        $this->authorizeManage($doctor);
        $request->validate(['signature' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:512']]);
        $path = $request->file('signature')->store('orgs/'.$doctor->organization_id.'/signatures', config('clinic.documents_disk'));
        $doctor->update(['signature_path' => $path]);

        return $this->ok(['uploaded' => true]);
    }

    public function setSchedules(Request $request, string $id): JsonResponse
    {
        $doctor = DoctorProfile::with('branches')->findOrFail($id);
        $this->authorizeManage($doctor);
        $data = $request->validate([
            'schedules' => ['present', 'array'],
            'schedules.*.branch_id' => ['required', 'string'],
            'schedules.*.weekday' => ['required', 'integer', 'between:0,6'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i'],
            'schedules.*.slot_minutes' => ['nullable', 'integer', 'min:5', 'max:120'],
            'schedules.*.max_patients' => ['nullable', 'integer', 'min:1'],
            'schedules.*.allow_walk_in' => ['nullable', 'boolean'],
        ]);
        foreach ($data['schedules'] as $s) {
            abort_unless($doctor->branches->contains('id', $s['branch_id']), 422, 'Doctor is not assigned to one of the branches.');
            abort_if($s['end_time'] <= $s['start_time'], 422, 'End time must be after start time.');
        }
        DB::transaction(function () use ($doctor, $data) {
            $doctor->schedules()->delete();
            foreach ($data['schedules'] as $s) {
                DoctorSchedule::create($s + ['doctor_profile_id' => $doctor->id, 'slot_minutes' => $s['slot_minutes'] ?? $doctor->consultation_duration_min]);
            }
        });

        return $this->ok($doctor->schedules()->orderBy('weekday')->orderBy('start_time')->get());
    }

    public function setFees(Request $request, string $id): JsonResponse
    {
        $doctor = DoctorProfile::findOrFail($id);
        abort_unless($this->tenant()->can('doctors.manage'), 403);
        $data = $request->validate(['fees' => ['present', 'array'], 'fees.*.fee_type' => ['required', Rule::in(['new', 'follow_up', 'emergency', 'online'])], 'fees.*.amount' => ['required', 'integer', 'min:0'], 'fees.*.branch_id' => ['nullable', 'string'], 'fees.*.follow_up_window_days' => ['nullable', 'integer', 'min:0', 'max:90']]);
        DB::transaction(function () use ($doctor, $data) {
            $doctor->fees()->delete();
            foreach ($data['fees'] as $f) {
                DoctorFee::create($f + ['doctor_profile_id' => $doctor->id]);
            }
        });

        return $this->ok($doctor->fees()->get());
    }

    public function leaves(string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('doctors.view'), 403);

        return $this->ok(DoctorLeave::where('doctor_profile_id', DoctorProfile::findOrFail($id)->id)->orderByDesc('starts_at')->get());
    }

    /** Adds leave and returns the appointments that now need rescheduling. */
    public function addLeave(Request $request, string $id): JsonResponse
    {
        $doctor = DoctorProfile::findOrFail($id);
        $this->authorizeManage($doctor);
        $data = $request->validate(['starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'branch_id' => ['nullable', 'string'], 'reason' => ['nullable', 'string', 'max:200']]);
        $leave = DoctorLeave::create($data + ['doctor_profile_id' => $doctor->id, 'created_by' => auth()->id()]);

        return $this->created(['leave' => $leave, 'affected_appointments' => $this->affected($leave)]);
    }

    public function deleteLeave(string $id, string $leaveId): JsonResponse
    {
        $doctor = DoctorProfile::findOrFail($id);
        $this->authorizeManage($doctor);
        DoctorLeave::where('doctor_profile_id', $doctor->id)->findOrFail($leaveId)->delete();

        return response()->json(null, 204);
    }

    public function affectedAppointments(string $id, string $leaveId): JsonResponse
    {
        abort_unless($this->tenant()->can('appointments.view'), 403);

        return $this->ok($this->affected(DoctorLeave::where('doctor_profile_id', $id)->findOrFail($leaveId)));
    }

    private function affected(DoctorLeave $leave)
    {
        return Appointment::with('patient:id,patient_code,first_name,last_name,full_name,mobile')
            ->where('doctor_profile_id', $leave->doctor_profile_id)->whereNotNull('slot_lock')
            ->where('scheduled_start', '<', $leave->ends_at)->where('scheduled_end', '>', $leave->starts_at)
            ->when($leave->branch_id, fn ($q) => $q->where('branch_id', $leave->branch_id))->orderBy('scheduled_start')->get();
    }

    public function availability(Request $request, string $id, AvailabilityService $availability): JsonResponse
    {
        abort_unless($this->tenant()->can('appointments.view'), 403);
        $data = $request->validate(['date' => ['required', 'date'], 'branch_id' => ['required', 'string']]);
        $doctor = DoctorProfile::findOrFail($id);

        return $this->ok($availability->slots($doctor, Branch::findOrFail($data['branch_id']), $data['date']));
    }

    public function stats(Request $request, string $id, ReportService $reports): JsonResponse
    {
        $doctor = DoctorProfile::findOrFail($id);
        $own = $doctor->user_id === auth()->id();
        abort_unless($own || $this->tenant()->can('reports.operational'), 403);
        [$from, $to] = $reports->range($request->query('from'), $request->query('to'));
        $row = collect($reports->doctorPerformance($from, $to, $request->query('branch_id')))->firstWhere('doctor_id', $doctor->id);

        return $this->ok($row + ['from' => $from->toDateString(), 'to' => $to->toDateString()]);
    }

    private function authorizeManage(DoctorProfile $doctor): void
    {
        $self = $doctor->user_id === auth()->id() && $this->tenant()->can('doctors.manage_own');
        abort_unless($self || $this->tenant()->can('doctors.manage'), 403);
    }
}
