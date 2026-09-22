<?php

declare(strict_types=1);

namespace Keboola\Identifier;

/** Stable string code and offending segment; null denotes a global error. */
final class InvalidKidException extends \InvalidArgumentException
{
    /** @var string */
    public $code;

    public function __construct(string $code, public readonly ?string $segment = null)
    {
        parent::__construct('Invalid KID: ' . $code);
        // Exception's inherited code is untyped and getCode() also returns this string.
        $this->code = $code;
    }
}
