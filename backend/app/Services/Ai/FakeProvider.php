<?php

namespace App\Services\Ai;

/** Deterministic provider for local development and tests; never calls an external service. */
class FakeProvider implements AiProvider
{
    public function complete(string $system, string $user, int $maxTokens = 2000): AiResult
    {
        return new AiResult('[draft] '.mb_substr(preg_replace('/\s+/', ' ', $user), 0, 200), 'fake', 'fake-model', mb_strlen($user), 20);
    }
}
