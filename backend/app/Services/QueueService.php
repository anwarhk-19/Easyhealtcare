<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Events\QueueUpdated;
use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\QueueToken;
use App\Support\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QueueService
{
    private const PRIORITY_ORDER = ['emergency' => 0, 'priority' => 1, 'normal' => 2];

    public function issueToken(Appointment $a, string $priority = 'normal'): QueueToken
    {
        if ($a->token()->exists()) {
            return $a->token;
        }
        $a->loadMissing('branch');
        $date = CarbonImmutable::now($a->branch->timezone ?: 'Asia/Kolkata')->toDateString();

        $token = DB::transaction(function () use ($a, $priority, $date) {
            $number = Sequence::next('token', $a->branch_id.':'.$a->doctor_profile_id, $date);

            return QueueToken::create([
                'branch_id' => $a->branch_id,
                'doctor_profile_id' => $a->doctor_profile_id,
                'appointment_id' => $a->id,
                'queue_date' => $date,
                'token_number' => $number,
                'token_label' => sprintf('%s-%03d', $a->type === 'walk_in' ? 'W' : 'A', $number),
                'priority' => $priority,
                'status' => 'waiting',
            ]);
        });
        $this->broadcast($token);

        return $token;
    }

    /** Ordered queue for a doctor/day: emergencies first, then priority, then token order. */
    public function queueFor(string $doctorId, string $branchId, string $date): Collection
    {
        return QueueToken::with(['appointment.patient:id,patient_code,first_name,last_name,full_name,gender,date_of_birth,age_years_at_registration,created_at'])
            ->where('doctor_profile_id', $doctorId)
            ->where('branch_id', $branchId)
            ->whereDate('queue_date', $date)
            ->get()
            ->sortBy(fn ($t) => [$this->statusRank($t->status), self::PRIORITY_ORDER[$t->priority] ?? 2, $t->token_number])
            ->values();
    }

    public function callNext(string $doctorId, string $branchId, string $date): ?QueueToken
    {
        $next = $this->queueFor($doctorId, $branchId, $date)->firstWhere('status', 'waiting');

        return $next ? $this->call($next) : null;
    }

    public function call(QueueToken $t): QueueToken
    {
        if (! in_array($t->status, ['waiting', 'called', 'skipped'], true)) {
            throw BusinessRuleException::conflict('INVALID_TRANSITION', 'Only waiting tokens can be called.');
        }
        $t->update(['status' => 'called', 'called_at' => now(), 'called_count' => $t->called_count + 1]);
        $this->broadcast($t);

        return $t;
    }

    public function skip(QueueToken $t): QueueToken
    {
        $t->update(['status' => 'skipped']);
        $this->broadcast($t);

        return $t;
    }

    public function requeue(QueueToken $t): QueueToken
    {
        $t->update(['status' => 'waiting']);
        $this->broadcast($t);

        return $t;
    }

    public function setPriority(QueueToken $t, string $priority): QueueToken
    {
        $t->update(['priority' => $priority]);
        $this->broadcast($t);

        return $t;
    }

    public function start(QueueToken $t, AppointmentService $appointments): QueueToken
    {
        if (! in_array($t->status, ['waiting', 'called'], true)) {
            throw BusinessRuleException::conflict('INVALID_TRANSITION', 'This token cannot be started.');
        }
        DB::transaction(function () use ($t, $appointments) {
            $t->update(['status' => 'in_consultation', 'started_at' => now()]);
            $appointments->transition($t->appointment, AppointmentStatus::InConsultation);
        });
        $this->broadcast($t);

        return $t;
    }

    public function complete(QueueToken $t, AppointmentService $appointments): QueueToken
    {
        if ($t->status !== 'in_consultation') {
            throw BusinessRuleException::conflict('INVALID_TRANSITION', 'Only tokens in consultation can be completed.');
        }
        DB::transaction(function () use ($t, $appointments) {
            $t->update(['status' => 'completed', 'completed_at' => now()]);
            $appointments->transition($t->appointment, AppointmentStatus::Completed);
        });
        $this->broadcast($t);

        return $t;
    }

    public function transfer(QueueToken $t, string $toDoctorId): QueueToken
    {
        if (! in_array($t->status, ['waiting', 'called', 'skipped'], true)) {
            throw BusinessRuleException::conflict('INVALID_TRANSITION', 'Only waiting tokens can be transferred.');
        }

        return DB::transaction(function () use ($t, $toDoctorId) {
            $appointment = $t->appointment;
            [$priority, $oldDoctor] = [$t->priority, $t->doctor_profile_id];
            $t->delete();
            // The patient is already in the clinic: the visit becomes a walk-in for the new doctor (frees the old slot).
            $appointment->update(['doctor_profile_id' => $toDoctorId, 'type' => 'walk_in']);
            $appointment->unsetRelation('token');
            $new = $this->issueToken($appointment, $priority);
            event(new QueueUpdated($t->organization_id, $t->branch_id, $oldDoctor));

            return $new;
        });
    }

    /** Estimated wait per waiting token, from today's average consultation duration (or the doctor's default). */
    public function estimates(string $doctorId, string $branchId, string $date, int $defaultMinutes = 15): array
    {
        $avg = Consultation::query()->where('doctor_profile_id', $doctorId)->whereDate('created_at', $date)
            ->whereNotNull('signed_at')->get(['created_at', 'signed_at'])
            ->avg(fn ($c) => max(1, $c->created_at->diffInMinutes($c->signed_at)));
        $per = (int) round($avg ?: $defaultMinutes);
        $position = 0;
        $out = [];
        foreach ($this->queueFor($doctorId, $branchId, $date) as $t) {
            if ($t->status === 'waiting') {
                $out[$t->id] = ['position' => ++$position, 'estimated_wait_minutes' => $position * $per];
            }
        }

        return ['average_consultation_minutes' => $per, 'tokens' => $out];
    }

    private function statusRank(string $status): int
    {
        return ['in_consultation' => 0, 'called' => 1, 'waiting' => 2, 'skipped' => 3, 'completed' => 4, 'transferred' => 5][$status] ?? 6;
    }

    private function broadcast(QueueToken $t): void
    {
        event(new QueueUpdated($t->organization_id, $t->branch_id, $t->doctor_profile_id));
    }
}
