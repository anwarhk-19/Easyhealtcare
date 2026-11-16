<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class UserNotified implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $organizationId, public string $userId, public string $type) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("org.{$this->organizationId}.user.{$this->userId}")];
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type];
    }
}
