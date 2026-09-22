<?php

declare(strict_types=1);

namespace Keboola\Identifier;

final readonly class Kid
{
    private const MAX_KID_BYTES = 2048;
    private const MAX_VALUE_BYTES = 255;
    private const HEAD = ['m', 'o', 'p', 'b'];
    private const UNENCODED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-';

    private function __construct(private string $canonical)
    {
    }

    /** @return array{stack: string, entities: array<string, string>} */
    public static function parse(string $text): array
    {
        if (!str_starts_with($text, 'KID:')) {
            self::fail('bad_prefix');
        }
        if (strlen($text) > self::MAX_KID_BYTES) {
            self::fail('too_long');
        }
        $parts = explode(':', $text);
        $stack = $parts[1];
        if (!self::validStack($stack)) {
            self::fail('bad_stack');
        }
        $entities = [];
        foreach (array_slice($parts, 2) as $segment) {
            if ($segment === '') {
                self::fail('empty_segment', '');
            }
            $hyphen = strpos($segment, '-');
            $entity = $hyphen === false ? '' : substr($segment, 0, $hyphen);
            if ($hyphen === false || preg_match('/^[a-z][a-z0-9]*$/D', $entity) !== 1) {
                self::fail('bad_segment', $segment);
            }
            if (array_key_exists($entity, $entities)) {
                self::fail('duplicate_entity', $segment);
            }
            $value = substr($segment, $hyphen + 1);
            if ($value === '' || !self::validShort($entity, $value)) {
                self::fail('bad_value', $segment);
            }
            if (!in_array($entity, self::HEAD, true) && $value !== '*' && preg_match('/^[A-Za-z0-9._%\-]+$/D', $value) !== 1) {
                self::fail('bad_value', $segment);
            }
            [, $raw] = self::decode($value, $segment);
            if (strlen($raw) > self::MAX_VALUE_BYTES) {
                self::fail('too_long', $segment);
            }
            $entities[$entity] = $value;
        }
        return ['stack' => $stack, 'entities' => $entities];
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

    public static function fromString(string $text): self
    {
        $parsed = self::parse($text);
        return new self(self::canonicalFromEncoded($parsed['stack'], $parsed['entities']));
    }

    /** @param array<array-key, mixed> $entities */
    public static function create(string $stack, array $entities): self
    {
        if (!self::validStack($stack)) {
            self::fail('bad_stack');
        }
        $encoded = [];
        foreach ($entities as $entity => $raw) {
            $segment = is_string($raw) ? sprintf('%s-%s', $entity, $raw) : null;
            if (!is_string($entity) || preg_match('/^[a-z][a-z0-9]*$/D', $entity) !== 1) {
                self::fail('bad_segment', $segment);
            }
            if (!is_string($raw)) {
                self::fail('bad_value');
            }
            if ($raw === '' || !self::validShort($entity, $raw)) {
                self::fail('bad_value', $segment);
            }
            if (preg_match('//u', $raw) !== 1) {
                self::fail('bad_utf8', $segment);
            }
            if (strlen($raw) > self::MAX_VALUE_BYTES) {
                self::fail('too_long', $segment);
            }
            $encoded[$entity] = $raw === '*' ? '*' : self::encode($raw);
        }
        $canonical = self::canonicalFromEncoded($stack, $encoded);
        if (strlen($canonical) > self::MAX_KID_BYTES) {
            self::fail('too_long');
        }
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
        return self::parse($this->canonical)['stack'];
    }

    public function get(string $entity): ?string
    {
        $value = self::parse($this->canonical)['entities'][$entity] ?? null;
        return $value === null ? null : self::decode($value, "{$entity}-{$value}")[0];
    }

    public function has(string $entity): bool
    {
        return array_key_exists($entity, self::parse($this->canonical)['entities']);
    }

    /** @return array<string, string> */
    public function entities(): array
    {
        $result = [];
        foreach (self::parse($this->canonical)['entities'] as $entity => $_) {
            $result[$entity] = $this->get($entity);
        }
        return $result;
    }

    public function maintainer(): ?string { return $this->get('m'); }
    public function organization(): ?string { return $this->get('o'); }
    public function project(): ?string { return $this->get('p'); }
    public function branch(): ?string { return $this->get('b'); }

    public function isWildcard(string $entity): bool
    {
        return (self::parse($this->canonical)['entities'][$entity] ?? null) === '*';
    }

    public function isPattern(): bool
    {
        $entities = self::parse($this->canonical)['entities'];
        return in_array('*', $entities, true) || ($entities['b'] ?? null) === 'dev';
    }

    public function equals(self $other): bool
    {
        return $this->canonical === $other->canonical;
    }

    public function toString(): string { return $this->canonical; }
    public function __toString(): string { return $this->canonical; }

    private static function validStack(string $stack): bool
    {
        return strlen($stack) <= 64 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $stack) === 1;
    }

    private static function validShort(string $entity, string $value): bool
    {
        if (in_array($entity, ['m', 'o', 'p'], true)) {
            return $value === '*' || preg_match('/^[0-9]+$/D', $value) === 1;
        }
        if ($entity === 'b') {
            return in_array($value, ['*', 'dev', 'default'], true) || preg_match('/^[0-9]+$/D', $value) === 1;
        }
        return true;
    }

    /** @return array{string, string} decoded text and raw bytes */
    private static function decode(string $value, string $segment): array
    {
        $raw = '';
        for ($i = 0, $length = strlen($value); $i < $length;) {
            if ($value[$i] === '%') {
                if ($i + 2 >= $length || preg_match('/^[0-9A-F]{2}$/D', substr($value, $i + 1, 2)) !== 1) {
                    self::fail('non_canonical_encoding', $segment);
                }
                $byte = hexdec(substr($value, $i + 1, 2));
                if (str_contains(self::UNENCODED, chr($byte))) {
                    self::fail('non_canonical_encoding', $segment);
                }
                $raw .= chr($byte);
                $i += 3;
            } else {
                $raw .= $value[$i];
                ++$i;
            }
        }
        if (preg_match('//u', $raw) !== 1) {
            self::fail('bad_utf8', $segment);
        }
        return [$raw, $raw];
    }

    private static function encode(string $raw): string
    {
        $result = '';
        for ($i = 0, $length = strlen($raw); $i < $length; ++$i) {
            $byte = ord($raw[$i]);
            $result .= str_contains(self::UNENCODED, $raw[$i]) ? $raw[$i] : sprintf('%%%02X', $byte);
        }
        return $result;
    }

    /** @param array<string, string> $entities */
    private static function canonicalFromEncoded(string $stack, array $entities): string
    {
        if (($entities['b'] ?? null) === 'default') {
            unset($entities['b']);
        }
        $ordered = [];
        foreach (self::HEAD as $entity) {
            if (array_key_exists($entity, $entities)) {
                $ordered[] = $entity;
            }
        }
        $rest = array_values(array_diff(array_keys($entities), self::HEAD));
        sort($rest, SORT_STRING);
        $segments = ['KID', $stack];
        foreach ([...$ordered, ...$rest] as $entity) {
            $segments[] = "{$entity}-{$entities[$entity]}";
        }
        return implode(':', $segments);
    }

    private static function fail(string $code, ?string $segment = null): never
    {
        throw new InvalidKidException($code, $segment);
    }
}
