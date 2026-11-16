<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Invalidation hint only: clients refetch the queue over REST. No patient data is broadcast. */
class QueueUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $organizationId, public string $branchId, public string $doctorId) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("org.{$this->organizationId}.branch.{$this->branchId}.queue"),
            new PrivateChannel("org.{$this->organizationId}.doctor.{$this->doctorId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return ['doctor_id' => $this->doctorId];
    }
}
