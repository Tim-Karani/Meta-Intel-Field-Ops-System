<?php

namespace App\Support;

use RuntimeException;

/** A business-rule violation. Rendered as 422 (default) or 409, with a stable machine code. */
class DomainRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'rule_violation', public readonly int $status = 422, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public static function conflict(string $message, string $code = 'conflict', array $details = []): self
    {
        return new self($message, $code, 409, $details);
    }
}
