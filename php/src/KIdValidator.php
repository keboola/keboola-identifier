<?php

declare(strict_types=1);

namespace Keboola\Identifier;

/** Immutable, syntactically validated KID 1.0 identity or pattern. */
final readonly class KIdValidator implements \Stringable
{
    private const HEAD = ['m', 'o', 'p', 'b'];
    private const UNENCODED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-';

    // Single instance state makes PHP == agree with canonical equality (§9.1).
    private function __construct(private string $canonical)
    {
    }

    public static function validate(string $text): bool
    {
        try {
            self::parse($text);
            return true;
        } catch (InvalidKidException) {
            return false;
        }
    }

    /** @return array{stack: string, entities: array<string, string>} Encoded values, in input order. */
    public static function parse(string $text): array
    {
        if (!str_starts_with($text, 'KID:')) {
            throw new InvalidKidException('bad_prefix');
        }
        self::checkTotalLength($text);
        $parts = explode(':', substr($text, 4));
        $stack = array_shift($parts);
        self::checkStack($stack);
        $entities = [];
        foreach ($parts as $segment) {
            if ($segment === '') {
                throw new InvalidKidException('empty_segment', $segment);
            }
            $pair = explode('-', $segment, 2);
            if (count($pair) !== 2) {
                throw new InvalidKidException('bad_segment', $segment);
            }
            [$entity, $value] = $pair;
            self::checkEntity($entity, $segment);
            if (array_key_exists($entity, $entities)) {
                throw new InvalidKidException('duplicate_entity', $segment);
            }
            self::checkValue($entity, $value, $segment);
            // Structure is checked for the whole value before examining any escapes.
            if ($value !== '*' && preg_match('/\A[A-Za-z0-9._%\-]+\z/', $value) !== 1) {
                throw new InvalidKidException('bad_value', $segment);
            }
            $raw = self::decode($value, $segment);
            self::checkUtf8($raw, $segment);
            self::checkValueLength($raw, $segment);
            $entities[$entity] = $value;
        }
        return ['stack' => $stack, 'entities' => $entities];
    }

    public static function fromString(string $text): self
    {
        $parsed = self::parse($text);
        return new self(self::serialize($parsed['stack'], $parsed['entities']));
    }

    /** @param array<string, string> $entities Decoded values; a whole '*' means wildcard. */
    public static function create(mixed $stack, array $entities): self
    {
        // A string parameter would coerce scalars in callers without strict_types.
        if (!is_string($stack)) {
            throw new InvalidKidException('bad_stack');
        }
        self::checkStack($stack);
        $encoded = [];
        foreach ($entities as $entity => $value) {
            $segment = is_string($value) ? $entity . '-' . $value : null;
            self::checkEntity($entity, $segment);
            if (!is_string($value)) {
                throw new InvalidKidException('bad_value');
            }
            self::checkValue($entity, $value, $segment);
            self::checkValueLength($value, $segment);
            self::checkUtf8($value, $segment);
            $encoded[$entity] = $value === '*' ? '*' : self::encode($value);
        }
        $canonical = self::serialize($stack, $encoded);
        self::checkTotalLength($canonical);
        return new self($canonical);
    }

    public static function canonicalize(string $text): string
    {
        return self::fromString($text)->toString();
    }

    public static function isCanonical(string $text): bool
    {
        try {
            return self::canonicalize($text) === $text;
        } catch (InvalidKidException) {
            return false;
        }
    }

    public function stack(): string
    {
        return explode(':', $this->canonical, 3)[1];
    }

    public function maintainer(): ?string
    {
        return $this->get(KIdEntity::MAINTAINER);
    }

    public function organization(): ?string
    {
        return $this->get(KIdEntity::ORGANIZATION);
    }

    public function project(): ?string
    {
        return $this->get(KIdEntity::PROJECT);
    }

    public function branch(): ?string
    {
        return $this->get(KIdEntity::BRANCH);
    }

    public function get(string $entity): ?string
    {
        $value = $this->encodedEntities()[$entity] ?? null;
        return $value === null ? null : self::decode($value, null);
    }

    public function has(string $entity): bool
    {
        return array_key_exists($entity, $this->encodedEntities());
    }

    /** @return array<string, string> Decoded copy; literal and wildcard stars are indistinguishable. */
    public function entities(): array
    {
        return array_map(static fn(string $value): string => self::decode($value, null), $this->encodedEntities());
    }

    public function isWildcard(string $entity): bool
    {
        return ($this->encodedEntities()[$entity] ?? null) === '*';
    }

    public function isPattern(): bool
    {
        $entities = $this->encodedEntities();
        return ($entities['b'] ?? null) === 'dev' || in_array('*', $entities, true);
    }

    public function equals(self $other): bool
    {
        return $this->canonical === $other->canonical;
    }

    public function toString(): string
    {
        return $this->canonical;
    }

    public function __toString(): string
    {
        return $this->canonical;
    }

    /** @return array<string, string> */
    private function encodedEntities(): array
    {
        $entities = [];
        foreach (array_slice(explode(':', $this->canonical), 2) as $segment) {
            [$entity, $value] = explode('-', $segment, 2);
            $entities[$entity] = $value;
        }
        return $entities;
    }

    /** @param array<string, string> $entities */
    private static function serialize(string $stack, array $entities): string
    {
        if (($entities['b'] ?? null) === 'default') {
            unset($entities['b']);
        }
        $text = 'KID:' . $stack;
        foreach (self::HEAD as $entity) {
            if (isset($entities[$entity])) {
                $text .= ':' . $entity . '-' . $entities[$entity];
                unset($entities[$entity]);
            }
        }
        ksort($entities, SORT_STRING);
        foreach ($entities as $entity => $value) {
            $text .= ':' . $entity . '-' . $value;
        }
        return $text;
    }

    private static function checkStack(string $stack): void
    {
        if (strlen($stack) > 64 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $stack) !== 1) {
            throw new InvalidKidException('bad_stack');
        }
    }

    private static function checkEntity(string|int $entity, ?string $segment): void
    {
        if (!is_string($entity) || preg_match('/\A[a-z][a-z0-9]*\z/', $entity) !== 1) {
            throw new InvalidKidException('bad_segment', $segment);
        }
    }

    private static function checkValue(string $entity, string $value, string $segment): void
    {
        if ($value === '' || (in_array($entity, self::HEAD, true)
            && $value !== '*' && preg_match('/\A[0-9]+\z/', $value) !== 1
            && !($entity === 'b' && in_array($value, ['dev', 'default'], true)))) {
            throw new InvalidKidException('bad_value', $segment);
        }
    }

    private static function checkTotalLength(string $text): void
    {
        if (strlen($text) > 2048) {
            throw new InvalidKidException('too_long');
        }
    }

    private static function checkValueLength(string $raw, string $segment): void
    {
        if (strlen($raw) > 255) {
            throw new InvalidKidException('too_long', $segment);
        }
    }

    private static function checkUtf8(string $raw, string $segment): void
    {
        // Validate bytes without normalization or stripping a leading BOM.
        if (preg_match('//u', $raw) !== 1) {
            throw new InvalidKidException('bad_utf8', $segment);
        }
    }

    private static function encode(string $raw): string
    {
        $encoded = '';
        for ($i = 0, $length = strlen($raw); $i < $length; $i++) {
            $byte = $raw[$i];
            $encoded .= str_contains(self::UNENCODED, $byte) ? $byte : sprintf('%%%02X', ord($byte));
        }
        return $encoded;
    }

    private static function decode(string $encoded, ?string $segment): string
    {
        $raw = '';
        for ($i = 0, $length = strlen($encoded); $i < $length; $i++) {
            if ($encoded[$i] !== '%') {
                $raw .= $encoded[$i];
                continue;
            }
            $hex = substr($encoded, $i + 1, 2);
            if (preg_match('/\A[0-9A-F]{2}\z/', $hex) !== 1) {
                throw new InvalidKidException('non_canonical_encoding', $segment);
            }
            $byte = chr(hexdec($hex));
            if (str_contains(self::UNENCODED, $byte)) {
                throw new InvalidKidException('non_canonical_encoding', $segment);
            }
            $raw .= $byte;
            $i += 2;
        }
        return $raw;
    }
}
