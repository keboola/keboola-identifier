<?php

declare(strict_types=1);

namespace Keboola\Identifier;

use InvalidArgumentException;

final class InvalidKidException extends InvalidArgumentException
{
    public readonly ?string $segment;
    private readonly string $kidCode;

    public function __construct(string $code, ?string $segment = null)
    {
        $this->kidCode = $code;
        $this->segment = $segment;
        parent::__construct(sprintf('Invalid KID: %s%s', $code, $segment === null ? '' : " ({$segment})"));
    }

    /** Exposes the specified machine-readable `$exception->code` despite Exception's protected integer property. */
    public function __get(string $name): mixed
    {
        if ($name === 'code') {
            return $this->kidCode;
        }
        trigger_error(sprintf('Undefined property: %s::$%s', self::class, $name), E_USER_WARNING);
        return null;
    }

    public function errorCode(): string
    {
        return $this->kidCode;
    }
}
