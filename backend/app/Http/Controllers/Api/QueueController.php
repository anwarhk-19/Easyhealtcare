<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DoctorProfile;
use App\Models\QueueToken;
use App\Services\AppointmentService;
use App\Services\ClinicalAccess;
use App\Services\QueueService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QueueController extends Controller
{
    public function __construct(private QueueService $queue, private AppointmentService $appointments) {}

    private function today(?string $branchId): string
    {
        $tz = $branchId ? (Branch::find($branchId)?->timezone ?? 'Asia/Kolkata') : 'Asia/Kolkata';

        return CarbonImmutable::now($tz)->toDateString();
    }

    /** Reception board: every doctor's queue at a branch. */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $date = $request->query('date', $this->today($branchId));
        $doctors = DoctorProfile::with('user:id,name')->where('is_active', true)->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))->get();

        return $this->ok([
            'date' => $date,
            'branch_id' => $branchId,
            'doctors' => $doctors->map(fn ($d) => [
                'doctor_id' => $d->id,
                'doctor' => $d->display_name,
                'tokens' => $this->queue->queueFor($d->id, $branchId, $date),
                'estimates' => $this->queue->estimates($d->id, $branchId, $date, $d->consultation_duration_min),
            ])->values(),
        ]);
    }

    public function my(Request $request, ClinicalAccess $access): JsonResponse
    {
        $doctor = $access->requireDoctor();
        $branchId = $this->branchId($request);
        $date = $request->query('date', $this->today($branchId));

        return $this->ok(['date' => $date, 'doctor_id' => $doctor->id, 'tokens' => $this->queue->queueFor($doctor->id, $branchId, $date), 'estimates' => $this->queue->estimates($doctor->id, $branchId, $date, $doctor->consultation_duration_min)]);
    }

    public function callNext(Request $request, ClinicalAccess $access): JsonResponse
    {
        $data = $request->validate(['doctor_id' => ['nullable', 'string'], 'branch_id' => ['required', 'string']]);
        $doctorId = $data['doctor_id'] ?? $access->requireDoctor()->id;
        $this->branchId($request);

        return $this->ok($this->queue->callNext($doctorId, $data['branch_id'], $this->today($data['branch_id'])));
    }

    private function token(string $id): QueueToken
    {
        return QueueToken::with('appointment')->findOrFail($id);
    }

    public function call(string $id): JsonResponse
    {
        return $this->ok($this->queue->call($this->token($id)));
    }

    public function skip(string $id): JsonResponse
    {
        return $this->ok($this->queue->skip($this->token($id)));
    }

    public function requeue(string $id): JsonResponse
    {
        return $this->ok($this->queue->requeue($this->token($id)));
    }

    public function start(string $id): JsonResponse
    {
        return $this->ok($this->queue->start($this->token($id), $this->appointments));
    }

    public function complete(string $id): JsonResponse
    {
        return $this->ok($this->queue->complete($this->token($id), $this->appointments));
    }

    public function transfer(Request $request, string $id): JsonResponse
    {
        $to = DoctorProfile::with('branches')->findOrFail($request->validate(['doctor_id' => ['required', 'string']])['doctor_id']);
        $token = $this->token($id);
        abort_unless($to->branches->contains('id', $token->branch_id), 422, 'That doctor does not consult at this branch.');

        return $this->ok($this->queue->transfer($token, $to->id));
    }

    public function priority(Request $request, string $id): JsonResponse
    {
        $p = $request->validate(['priority' => ['required', Rule::in(['normal', 'priority', 'emergency'])]])['priority'];

        return $this->ok($this->queue->setPriority($this->token($id), $p));
    }
}
