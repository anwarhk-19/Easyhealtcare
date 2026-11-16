<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\DoctorLeave;
use App\Models\DoctorProfile;
use App\Models\Holiday;
use Carbon\CarbonImmutable;

/** Computes bookable slots: schedule − holidays − leave − already-held slots − past times. */
class AvailabilityService
{
    /** @return array<int, array{start:string,end:string,available:bool,reason:?string}> */
    public function slots(DoctorProfile $doctor, Branch $branch, string $date): array
    {
        $tz = $branch->timezone ?: 'Asia/Kolkata';
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();

        $isHoliday = Holiday::whereDate('date', $day->toDateString())
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->exists();
        if ($isHoliday) {
            return [];
        }

        $schedules = $doctor->schedules()
            ->where('branch_id', $branch->id)
            ->where('weekday', $day->dayOfWeek)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day->toDateString()))
            ->orderBy('start_time')->get();

        if ($schedules->isEmpty()) {
            return [];
        }

        $dayStartUtc = $day->utc();
        $dayEndUtc = $day->endOfDay()->utc();

        $leaves = DoctorLeave::where('doctor_profile_id', $doctor->id)->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->where('starts_at', '<', $dayEndUtc)->where('ends_at', '>', $dayStartUtc)->get();

        $booked = Appointment::withoutGlobalScope('branch')
            ->where('doctor_profile_id', $doctor->id)
            ->whereNotNull('slot_lock')
            ->whereBetween('scheduled_start', [$dayStartUtc, $dayEndUtc])
            ->get(['scheduled_start', 'scheduled_end']);

        $now = CarbonImmutable::now();
        $slots = [];
        foreach ($schedules as $s) {
            $cursor = $day->setTimeFromTimeString($s->start_time);
            $end = $day->setTimeFromTimeString($s->end_time);
            $step = max(5, (int) $s->slot_minutes);
            while ($cursor->addMinutes($step) <= $end) {
                $slotEnd = $cursor->addMinutes($step);
                $startUtc = $cursor->utc();
                $endUtc = $slotEnd->utc();
                $reason = null;
                if ($startUtc <= $now) {
                    $reason = 'past';
                } elseif ($leaves->contains(fn ($l) => $l->starts_at < $endUtc && $l->ends_at > $startUtc)) {
                    $reason = 'leave';
                } elseif ($booked->contains(fn ($a) => $a->scheduled_start < $endUtc && $a->scheduled_end > $startUtc)) {
                    $reason = 'booked';
                }
                $slots[] = ['start' => $startUtc->toIso8601ZuluString(), 'end' => $endUtc->toIso8601ZuluString(), 'local_time' => $cursor->format('H:i'), 'available' => $reason === null, 'reason' => $reason];
                $cursor = $slotEnd;
            }
        }

        return $slots;
    }

    public function isAvailable(DoctorProfile $doctor, Branch $branch, CarbonImmutable $startUtc): ?array
    {
        $local = $startUtc->setTimezone($branch->timezone ?: 'Asia/Kolkata');
        foreach ($this->slots($doctor, $branch, $local->toDateString()) as $slot) {
            if ($slot['start'] === $startUtc->utc()->toIso8601ZuluString()) {
                return $slot;
            }
        }

        return null;
    }
}
