<?php

declare(strict_types=1);

namespace Keboola\Identifier;

/** Stable string code in errorCode and the offending segment; null denotes a global error. */
final class InvalidKidException extends \InvalidArgumentException
{
    public function __construct(public readonly string $errorCode, public readonly ?string $segment = null)
    {
        // getCode() stays the integer 0 so the exception can be re-wrapped under strict_types.
        parent::__construct('Invalid KID: ' . $errorCode);
    }
}
