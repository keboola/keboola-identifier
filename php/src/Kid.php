<?php

declare(strict_types=1);

namespace Keboola\Identifier;

/** Immutable, syntactically validated KID 1.0 identity or pattern. */
final readonly class Kid implements \Stringable, \JsonSerializable
{
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

    /** Full validation of text input; returns the canonical identity. */
    public static function parse(string $text): self
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
        return new self(self::serialize($stack, $entities));
    }

    /** @param array<string, string|int> $entities Decoded values; a whole '*' means wildcard. */
    public static function create(mixed $stack, array $entities): self
    {
        // A string parameter would coerce scalars in callers without strict_types.
        if (!is_string($stack)) {
            throw new InvalidKidException('bad_stack');
        }
        self::checkStack($stack);
        $encoded = [];
        foreach ($entities as $entity => $value) {
            // Integers become their decimal string form; other non-string types are rejected.
            if (is_int($value)) {
                $value = (string) $value;
            }
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
        return self::parse($text)->toString();
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
        return $this->get('maintainer');
    }

    public function organization(): ?string
    {
        return $this->get('org');
    }

    public function project(): ?string
    {
        return $this->get('project');
    }

    /** Never null: an absent branch means the default branch (§4.2). */
    public function branch(): string
    {
        return $this->get('branch') ?? 'default';
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

    /** @return array<string, string> Stack first, then decoded entities in canonical order. */
    public function toArray(): array
    {
        return ['stack' => $this->stack()] + $this->entities();
    }

    public function isWildcard(string $entity): bool
    {
        return ($this->encodedEntities()[$entity] ?? null) === '*';
    }

    public function isPattern(): bool
    {
        $entities = $this->encodedEntities();
        return ($entities['branch'] ?? null) === 'dev' || in_array('*', $entities, true);
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

    public function jsonSerialize(): string
    {
        return $this->canonical;
    }

    /** @return array{kid: string} */
    public function __serialize(): array
    {
        return ['kid' => $this->canonical];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        // Re-validate so a forged or stale payload cannot produce an unchecked instance.
        $text = $data['kid'] ?? null;
        if (!is_string($text)) {
            throw new InvalidKidException('bad_prefix');
        }
        $this->canonical = self::parse($text)->canonical;
    }

    /** @return array<string, string> Canonical order; an absent branch reads as `default` (§4.2). */
    private function encodedEntities(): array
    {
        $entities = [];
        foreach (array_slice(explode(':', $this->canonical), 2) as $segment) {
            [$entity, $value] = explode('-', $segment, 2);
            $entities[$entity] = $value;
        }
        if (!array_key_exists('branch', $entities)) {
            $entities['branch'] = 'default';
            ksort($entities, SORT_STRING);
        }
        return $entities;
    }

    /** @param array<string, string> $entities */
    private static function serialize(string $stack, array $entities): string
    {
        if (($entities['branch'] ?? null) === 'default') {
            unset($entities['branch']);
        }
        $text = 'KID:' . $stack;
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
        if (!is_string($entity) || $entity === 'stack' || preg_match('/\A[a-z][a-z0-9]*\z/', $entity) !== 1) {
            throw new InvalidKidException('bad_segment', $segment);
        }
    }

    private static function checkValue(string $entity, string $value, string $segment): void
    {
        $valid = match ($entity) {
            // No leading zeros: `project-007` would be a second identity for project 7 (§4.1).
            'maintainer', 'org', 'project' => preg_match('/\A(?:0|[1-9][0-9]*|\*)\z/', $value) === 1,
            'branch' => preg_match('/\A(?:0|[1-9][0-9]*|dev|default|\*)\z/', $value) === 1,
            default => $value !== '',
        };
        if (!$valid) {
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
