<?php

namespace App\Services\Ai;

interface AiProvider
{
    public function complete(string $system, string $user, int $maxTokens = 2000): AiResult;
}
