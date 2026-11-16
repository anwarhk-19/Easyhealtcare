<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Booked = 'booked';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Waiting = 'waiting';
    case InConsultation = 'in_consultation';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /** Statuses that hold the doctor's slot. */
    public function holdsSlot(): bool
    {
        return in_array($this, [self::Booked, self::Confirmed, self::CheckedIn, self::Waiting, self::InConsultation], true);
    }

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Booked => [self::Confirmed, self::CheckedIn, self::Cancelled, self::NoShow],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::Waiting, self::InConsultation, self::Cancelled],
            self::Waiting => [self::InConsultation, self::Cancelled, self::NoShow],
            self::InConsultation => [self::Completed],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
