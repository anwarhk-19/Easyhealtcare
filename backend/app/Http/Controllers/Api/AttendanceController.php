<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\StaffLeave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $manage = $this->tenant()->can('attendance.manage');
        $q = AttendanceRecord::with('branch:id,name')->when(! $manage, fn ($q) => $q->where('user_id', auth()->id()))
            ->when($request->query('date'), fn ($q, $d) => $q->whereDate('date', $d))->when($request->query('user_id'), fn ($q, $u) => $q->where('user_id', $u))->latest('date');

        return $this->paginated($q->paginate($this->perPage($request)));
    }

    public function clockIn(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('attendance.self'), 403);
        $branchId = $this->branchId($request);
        $record = AttendanceRecord::firstOrCreate(['user_id' => auth()->id(), 'date' => now()->toDateString()], ['branch_id' => $branchId, 'clock_in' => now(), 'shift' => $request->input('shift')]);

        return $this->ok($record);
    }

    public function clockOut(): JsonResponse
    {
        $record = AttendanceRecord::where('user_id', auth()->id())->whereDate('date', now()->toDateString())->first();
        if (! $record) {
            throw new BusinessRuleException('NOT_CLOCKED_IN', 'You have not clocked in today.');
        }
        $record->update(['clock_out' => now()]);

        return $this->ok($record);
    }

    public function leaves(Request $request): JsonResponse
    {
        $manage = $this->tenant()->can('attendance.manage');

        return $this->ok(StaffLeave::when(! $manage, fn ($q) => $q->where('user_id', auth()->id()))->latest('from_date')->limit(200)->get());
    }

    public function requestLeave(Request $request): JsonResponse
    {
        $data = $request->validate(['from_date' => ['required', 'date', 'after_or_equal:today'], 'to_date' => ['required', 'date', 'after_or_equal:from_date'], 'reason' => ['nullable', 'string', 'max:200']]);

        return $this->created(StaffLeave::create($data + ['user_id' => auth()->id()]));
    }

    public function decideLeave(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tenant()->can('attendance.manage'), 403);
        $leave = StaffLeave::findOrFail($id);
        $approve = $request->validate(['approve' => ['required', 'boolean']])['approve'];
        $leave->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => auth()->id()]);

        return $this->ok($leave);
    }
}
