<?php

declare(strict_types=1);

namespace Keboola\Identifier;

/** Offline syntax validation using the same full parser as Kid::fromString(). */
final class KIdValidator
{
    private function __construct()
    {
    }

    public static function validate(string $text): bool
    {
        try {
            Kid::parse($text);
            return true;
        } catch (InvalidKidException) {
            return false;
        }
    }
}
