<?php

namespace App\Services\Ai;

final class AiResult
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly ?string $model = null,
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly bool $refused = false,
    ) {}
}
