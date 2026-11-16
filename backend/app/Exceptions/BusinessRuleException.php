<?php

namespace App\Exceptions;

use RuntimeException;

/** A business-rule violation rendered as {"error":{"code","message"}} with a meaningful HTTP status. */
class BusinessRuleException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message, 409);
    }
}
