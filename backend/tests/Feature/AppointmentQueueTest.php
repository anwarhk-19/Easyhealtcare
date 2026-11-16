<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DoctorLeave;
use App\Models\Holiday;
use App\Models\NotificationMessage;
use App\Models\WaitlistEntry;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\TestCase;

class AppointmentQueueTest extends TestCase
{
    private array $c;

    private $doctor;

    private $patient;

    private $reception;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->makeClinic();
        $this->doctor = $this->addDoctor($this->c['org'], $this->c['branch']);
        $this->patient = $this->addPatient($this->c['org']);
        $this->reception = $this->addStaff($this->c['org'], $this->c['branch'], 'receptionist');
        $this->actingAsStaff($this->reception);
    }

    private function book(string $start, array $extra = [])
    {
        return $this->postJson('/api/v1/appointments', $extra + ['patient_id' => $this->patient->id, 'doctor_profile_id' => $this->doctor->id, 'branch_id' => $this->c['branch']->id, 'scheduled_start' => $start]);
    }

    public function test_availability_excludes_booked_slots_and_double_booking_is_rejected(): void
    {
        $slot = $this->nextSlot($this->doctor, $this->c['branch']);
        $this->book($slot)->assertCreated()->assertJsonPath('data.status', 'booked');
        $this->book($slot)->assertStatus(409)->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');

        $date = now('Asia/Kolkata')->addDay()->toDateString();
        $slots = collect($this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?date={$date}&branch_id={$this->c['branch']->id}")->assertOk()->json('data'));
        $this->assertSame('booked', $slots->firstWhere('start', $slot)['reason']);
    }

    public function test_database_unique_slot_lock_is_the_last_line_of_defence(): void
    {
        $start = now()->addDays(2)->startOfHour();
        $attrs = ['branch_id' => $this->c['branch']->id, 'patient_id' => $this->patient->id, 'doctor_profile_id' => $this->doctor->id, 'scheduled_start' => $start, 'scheduled_end' => $start->copy()->addMinutes(15), 'status' => 'booked'];
        $this->asTenant($this->c['org'], fn () => Appointment::create($attrs));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->asTenant($this->c['org'], fn () => Appointment::create($attrs));
    }

    public function test_cancel_frees_slot_and_offers_waitlist(): void
    {
        $slot = $this->nextSlot($this->doctor, $this->c['branch']);
        $id = $this->book($slot)->json('data.id');
        $other = $this->addPatient($this->c['org']);
        $this->postJson('/api/v1/waitlist', ['patient_id' => $other->id, 'branch_id' => $this->c['branch']->id, 'doctor_profile_id' => $this->doctor->id, 'preferred_date' => Carbon::parse($slot)->toDateString()])->assertCreated();

        $this->postJson("/api/v1/appointments/{$id}/cancel", ['reason' => 'Patient travelling'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('offered', $this->asTenant($this->c['org'], fn () => WaitlistEntry::first()->status));
        $this->book($slot)->assertCreated(); // slot is free again
        $this->postJson("/api/v1/appointments/{$id}/confirm")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION');
    }

    public function test_reschedule_moves_the_booking_and_keeps_history(): void
    {
        $id = $this->book($this->nextSlot($this->doctor, $this->c['branch']))->json('data.id');
        $new = $this->nextSlot($this->doctor, $this->c['branch'], 3);
        $res = $this->postJson("/api/v1/appointments/{$id}/reschedule", ['scheduled_start' => $new])->assertOk();
        $this->assertSame($id, $res->json('data.rescheduled_from_id'));
        $this->getJson("/api/v1/appointments/{$id}")->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'rescheduled');
    }

    public function test_leave_and_holidays_block_slots(): void
    {
        $date = now('Asia/Kolkata')->addDay();
        $this->asTenant($this->c['org'], fn () => DoctorLeave::create(['doctor_profile_id' => $this->doctor->id, 'starts_at' => $date->copy()->startOfDay()->utc(), 'ends_at' => $date->copy()->setTime(12, 0)->utc()]));
        $slots = collect($this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?date={$date->toDateString()}&branch_id={$this->c['branch']->id}")->json('data'));
        $this->assertSame('leave', $slots->firstWhere('local_time', '10:00')['reason']);
        $this->assertTrue($slots->firstWhere('local_time', '14:00')['available']);

        $this->asTenant($this->c['org'], fn () => Holiday::create(['date' => $date->toDateString(), 'name' => 'Onam']));
        $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?date={$date->toDateString()}&branch_id={$this->c['branch']->id}")->assertJsonCount(0, 'data');
    }

    public function test_walk_in_queue_priority_and_lifecycle(): void
    {
        $w1 = $this->postJson('/api/v1/appointments/walk-in', ['patient_id' => $this->patient->id, 'doctor_profile_id' => $this->doctor->id, 'branch_id' => $this->c['branch']->id])->assertCreated();
        $this->assertSame('waiting', $w1->json('data.status'));
        $this->assertSame('W-001', $w1->json('data.token.token_label'));

        $p2 = $this->addPatient($this->c['org']);
        $w2 = $this->postJson('/api/v1/appointments/walk-in', ['patient_id' => $p2->id, 'doctor_profile_id' => $this->doctor->id, 'branch_id' => $this->c['branch']->id, 'priority' => 'emergency'])->json('data');
        $this->assertSame(2, $w2['token']['token_number']);

        $board = $this->getJson('/api/v1/queue?branch_id='.$this->c['branch']->id)->assertOk()->json('data.doctors.0');
        $this->assertSame('W-002', $board['tokens'][0]['token_label'], 'emergency jumps the queue');
        $this->assertCount(2, $board['estimates']['tokens']);

        $this->actingAsStaff($this->doctor->user);
        $called = $this->postJson('/api/v1/queue/call-next', ['branch_id' => $this->c['branch']->id])->assertOk()->json('data');
        $this->assertSame($w2['token']['id'], $called['id']);
        $this->postJson("/api/v1/queue/{$called['id']}/start")->assertOk();
        $this->getJson("/api/v1/appointments/{$w2['id']}")->assertJsonPath('data.status', 'in_consultation');
        $this->postJson("/api/v1/queue/{$called['id']}/complete")->assertOk();
        $this->getJson("/api/v1/appointments/{$w2['id']}")->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/v1/queue/{$called['id']}/complete")->assertStatus(409);
    }

    public function test_check_in_scheduled_appointment_issues_token_and_display_hides_names(): void
    {
        $id = $this->book($this->nextSlot($this->doctor, $this->c['branch']))->json('data.id');
        $this->postJson("/api/v1/appointments/{$id}/check-in")->assertOk()->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.token.token_label', 'A-001');

        $owner = $this->c['owner'];
        $this->actingAsStaff($owner);
        $url = $this->postJson('/api/v1/queue-displays', ['name' => 'Lobby', 'branch_id' => $this->c['branch']->id])->assertCreated()->json('data.display_url');
        $board = $this->getJson(parse_url($url, PHP_URL_PATH))->assertOk()->json('data');
        $this->assertSame('TP', $board['doctors'][0]['next'][0]['initials']);
        $this->assertStringNotContainsString('Patient', json_encode($board));
    }

    public function test_booking_sends_consented_notifications_only(): void
    {
        $this->book($this->nextSlot($this->doctor, $this->c['branch']))->assertCreated();
        $statuses = $this->asTenant($this->c['org'], fn () => NotificationMessage::where('event_key', 'appointment.booked')->pluck('status', 'channel'));
        $this->assertSame('sent', $statuses['whatsapp']);
        $this->assertSame('sent', $statuses['sms']);

        $noConsent = $this->addPatient($this->c['org'], [], ['data_processing']);
        $this->postJson('/api/v1/appointments', ['patient_id' => $noConsent->id, 'doctor_profile_id' => $this->doctor->id, 'branch_id' => $this->c['branch']->id, 'scheduled_start' => $this->nextSlot($this->doctor, $this->c['branch'], 1)])->assertCreated();
        $this->assertSame(['skipped_no_consent'], $this->asTenant($this->c['org'], fn () => NotificationMessage::where('patient_id', $noConsent->id)->pluck('status')->unique()->values()->all()));
    }

    public function test_reminder_command_is_idempotent(): void
    {
        $start = now()->addMinutes(120)->startOfMinute();
        $this->asTenant($this->c['org'], fn () => Appointment::create(['branch_id' => $this->c['branch']->id, 'patient_id' => $this->patient->id, 'doctor_profile_id' => $this->doctor->id, 'scheduled_start' => $start, 'scheduled_end' => $start->copy()->addMinutes(15), 'status' => 'confirmed']));
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->assertSame(2, $this->asTenant($this->c['org'], fn () => NotificationMessage::where('event_key', 'appointment.reminder')->count()));
    }
}
