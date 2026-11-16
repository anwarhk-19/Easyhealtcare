<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Sequence;
use App\Support\Totp;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_money_uses_indian_digit_grouping(): void
    {
        $this->assertSame('₹1,23,45,678.90', Money::format(1234567890));
        $this->assertSame('₹500.00', Money::format(50000));
        $this->assertSame('-₹1,000.05', Money::format(-100005));
    }

    public function test_phone_normalisation(): void
    {
        $this->assertSame('+919876543210', Phone::normalize('98765 43210'));
        $this->assertSame('+919876543210', Phone::normalize('09876543210'));
        $this->assertSame('+447700900123', Phone::normalize('+44 7700 900123'));
        $this->assertNull(Phone::normalize(''));
    }

    public function test_totp_matches_rfc6238_reference_vector(): void
    {
        // RFC 6238 SHA1 secret "12345678901234567890" in base32.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertSame('081804', Totp::code($secret, 1111111109));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret)));
    }

    public function test_indian_financial_year(): void
    {
        $this->assertSame('2026-27', Sequence::financialYear(Carbon::parse('2026-04-01')));
        $this->assertSame('2025-26', Sequence::financialYear(Carbon::parse('2026-03-31')));
    }

    public function test_appointment_state_machine(): void
    {
        $this->assertTrue(AppointmentStatus::Booked->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertTrue(AppointmentStatus::Waiting->canTransitionTo(AppointmentStatus::InConsultation));
        $this->assertFalse(AppointmentStatus::Completed->canTransitionTo(AppointmentStatus::Cancelled));
        $this->assertFalse(AppointmentStatus::Booked->canTransitionTo(AppointmentStatus::Completed));
        $this->assertTrue(AppointmentStatus::Confirmed->holdsSlot());
        $this->assertFalse(AppointmentStatus::Cancelled->holdsSlot());
    }
}
