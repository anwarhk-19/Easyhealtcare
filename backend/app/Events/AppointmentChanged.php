<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class AppointmentChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $organizationId, public string $branchId, public string $appointmentId, public string $status) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("org.{$this->organizationId}.branch.{$this->branchId}.appointments")];
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->appointmentId, 'status' => $this->status];
    }
}
