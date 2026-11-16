<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Events\AppointmentChanged;
use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use App\Models\Branch;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AppointmentService
{
    public function __construct(
        private AvailabilityService $availability,
        private QueueService $queue,
        private NotificationService $notifications,
    ) {}

    /** @param array{patient_id:string, doctor_profile_id:string, branch_id:string, scheduled_start:string, service_id?:string, type?:string, source?:string, notes?:string, override_schedule?:bool} $data */
    public function book(array $data): Appointment
    {
        $patient = Patient::findOrFail($data['patient_id']);
        $doctor = DoctorProfile::with('branches')->findOrFail($data['doctor_profile_id']);
        $branch = Branch::findOrFail($data['branch_id']);
        $this->assertDoctorAtBranch($doctor, $branch);

        $start = CarbonImmutable::parse($data['scheduled_start'])->utc()->second(0);
        $slot = $this->availability->isAvailable($doctor, $branch, $start);

        if (empty($data['override_schedule'])) {
            if (! $slot || ! $slot['available']) {
                throw BusinessRuleException::conflict('SLOT_UNAVAILABLE', $slot ? "This slot is not available ({$slot['reason']})." : 'The doctor does not consult at this time.');
            }
            $end = CarbonImmutable::parse($slot['end']);
        } else {
            if ($start->isPast()) {
                throw new BusinessRuleException('SLOT_IN_PAST', 'Cannot book an appointment in the past.');
            }
            $end = $start->addMinutes($doctor->consultation_duration_min);
        }

        try {
            $appointment = DB::transaction(function () use ($data, $patient, $doctor, $branch, $start, $end) {
                $a = Appointment::create([
                    'branch_id' => $branch->id,
                    'patient_id' => $patient->id,
                    'doctor_profile_id' => $doctor->id,
                    'service_id' => $data['service_id'] ?? null,
                    'speciality_id' => $data['speciality_id'] ?? null,
                    'type' => $data['type'] ?? 'scheduled',
                    'source' => $data['source'] ?? 'reception',
                    'scheduled_start' => $start,
                    'scheduled_end' => $end,
                    'status' => AppointmentStatus::Booked,
                    'notes' => $data['notes'] ?? null,
                    'rescheduled_from_id' => $data['rescheduled_from_id'] ?? null,
                    'created_by' => auth()->user() instanceof User ? auth()->id() : null,
                ]);
                $this->history($a, null, AppointmentStatus::Booked);

                return $a;
            });
        } catch (UniqueConstraintViolationException) {
            throw BusinessRuleException::conflict('SLOT_UNAVAILABLE', 'This slot was just booked by someone else.');
        }

        $this->broadcast($appointment);
        $this->notifications->send('appointment.booked', $patient, $this->notificationVars($appointment), $appointment);

        return $appointment;
    }

    public function walkIn(array $data): Appointment
    {
        $patient = Patient::findOrFail($data['patient_id']);
        $doctor = DoctorProfile::with('branches')->findOrFail($data['doctor_profile_id']);
        $branch = Branch::findOrFail($data['branch_id']);
        $this->assertDoctorAtBranch($doctor, $branch);

        $appointment = DB::transaction(function () use ($data, $patient, $doctor, $branch) {
            $now = CarbonImmutable::now()->second(0);
            $a = Appointment::create([
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'doctor_profile_id' => $doctor->id,
                'service_id' => $data['service_id'] ?? null,
                'type' => 'walk_in',
                'source' => 'reception',
                'scheduled_start' => $now,
                'scheduled_end' => $now->addMinutes($doctor->consultation_duration_min),
                'status' => AppointmentStatus::CheckedIn,
                'checked_in_at' => now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->user() instanceof User ? auth()->id() : null,
            ]);
            $this->history($a, null, AppointmentStatus::CheckedIn, 'walk-in');
            $this->queue->issueToken($a, $data['priority'] ?? 'normal');
            $this->transition($a, AppointmentStatus::Waiting);

            return $a;
        });

        return $appointment->load('token');
    }

    public function checkIn(Appointment $a, string $priority = 'normal'): Appointment
    {
        return DB::transaction(function () use ($a, $priority) {
            $this->transition($a, AppointmentStatus::CheckedIn);
            $this->queue->issueToken($a, $priority);
            $this->transition($a, AppointmentStatus::Waiting);

            return $a->load('token');
        });
    }

    public function confirm(Appointment $a): Appointment
    {
        $this->transition($a, AppointmentStatus::Confirmed);
        $this->notifications->send('appointment.confirmed', $a->patient, $this->notificationVars($a), $a);

        return $a;
    }

    public function cancel(Appointment $a, string $reason, bool $notify = true): Appointment
    {
        $this->transition($a, AppointmentStatus::Cancelled, $reason);
        if ($a->token && ! in_array($a->token->status, ['completed'], true)) {
            $a->token->update(['status' => 'skipped']);
        }
        if ($notify) {
            $this->notifications->send('appointment.cancelled', $a->patient, $this->notificationVars($a) + ['reason' => $reason], $a);
        }
        $this->offerToWaitlist($a);

        return $a;
    }

    public function noShow(Appointment $a): Appointment
    {
        return $this->transition($a, AppointmentStatus::NoShow);
    }

    public function reschedule(Appointment $a, string $newStart, ?string $doctorId = null): Appointment
    {
        if (! $a->status->holdsSlot() || in_array($a->status, [AppointmentStatus::InConsultation], true)) {
            throw BusinessRuleException::conflict('INVALID_TRANSITION', 'Only upcoming appointments can be rescheduled.');
        }

        return DB::transaction(function () use ($a, $newStart, $doctorId) {
            $this->transition($a, AppointmentStatus::Cancelled, 'rescheduled');

            $new = $this->book([
                'patient_id' => $a->patient_id,
                'doctor_profile_id' => $doctorId ?? $a->doctor_profile_id,
                'branch_id' => $a->branch_id,
                'service_id' => $a->service_id,
                'scheduled_start' => $newStart,
                'type' => $a->type,
                'source' => $a->source,
                'notes' => $a->notes,
                'rescheduled_from_id' => $a->id,
            ]);

            return $new;
        });
    }

    public function transition(Appointment $a, AppointmentStatus $to, ?string $reason = null): Appointment
    {
        $from = $a->status;
        if ($from === $to) {
            return $a;
        }
        if (! $from->canTransitionTo($to)) {
            throw BusinessRuleException::conflict('INVALID_TRANSITION', "Cannot move an appointment from {$from->value} to {$to->value}.");
        }

        $attrs = ['status' => $to];
        match ($to) {
            AppointmentStatus::Confirmed => $attrs['confirmed_at'] = now(),
            AppointmentStatus::CheckedIn => $attrs['checked_in_at'] = now(),
            AppointmentStatus::InConsultation => $attrs['consult_started_at'] = now(),
            AppointmentStatus::Completed => $attrs['consult_ended_at'] = now(),
            AppointmentStatus::Cancelled => $attrs += ['cancelled_at' => now(), 'cancel_reason' => $reason],
            default => null,
        };
        $a->update($attrs);
        $this->history($a, $from, $to, $reason);
        $this->broadcast($a);

        return $a;
    }

    private function offerToWaitlist(Appointment $a): void
    {
        $entry = WaitlistEntry::where('status', 'waiting')
            ->where('branch_id', $a->branch_id)
            ->whereDate('preferred_date', $a->scheduled_start->toDateString())
            ->where(fn ($q) => $q->whereNull('doctor_profile_id')->orWhere('doctor_profile_id', $a->doctor_profile_id))
            ->oldest()->first();
        if ($entry) {
            $entry->update(['status' => 'offered']);
            $this->notifications->send('waitlist.slot_available', $entry->patient, ['date' => $a->scheduled_start->toDateString()], $entry);
        }
    }

    private function assertDoctorAtBranch(DoctorProfile $doctor, Branch $branch): void
    {
        if (! $doctor->is_active || ! $doctor->branches->contains('id', $branch->id)) {
            throw new BusinessRuleException('DOCTOR_NOT_AT_BRANCH', 'This doctor does not consult at the selected branch.');
        }
    }

    private function history(Appointment $a, ?AppointmentStatus $from, AppointmentStatus $to, ?string $reason = null): void
    {
        AppointmentStatusHistory::create([
            'appointment_id' => $a->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by' => auth()->id(),
            'reason' => $reason,
        ]);
    }

    private function broadcast(Appointment $a): void
    {
        event(new AppointmentChanged($a->organization_id, $a->branch_id, $a->id, $a->status->value));
    }

    public function notificationVars(Appointment $a): array
    {
        $a->loadMissing('doctor.user', 'branch', 'patient');
        $local = $a->scheduled_start->copy()->setTimezone($a->branch->timezone ?: 'Asia/Kolkata');

        return [
            'patient_name' => $a->patient->full_name,
            'doctor_name' => $a->doctor->display_name,
            'clinic_name' => $a->organization?->name ?? '',
            'branch_name' => $a->branch->name,
            'date' => $local->format('d M Y'),
            'time' => $local->format('h:i A'),
        ];
    }
}
