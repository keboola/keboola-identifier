<?php

declare(strict_types=1);

namespace Keboola\Identifier\Tests;

use Keboola\Identifier\InvalidKidException;
use Keboola\Identifier\Kid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SharedVectorsTest extends TestCase
{
    #[DataProvider('validCases')]
    public function testValidIdentifier(array $case): void
    {
        $kid = Kid::parse($case['input']);
        self::assertTrue(Kid::validate($case['input']));
        self::assertSame($case['array'], $kid->toArray(), 'Stack, then decoded entities in canonical order');
        self::assertSame($case['canonical'], $kid->toString());
        self::assertSame($case['canonical'], (string) $kid);
        self::assertSame($case['canonical'], Kid::canonicalize($case['input']));
        self::assertSame($case['isCanonical'], Kid::isCanonical($case['input']));
        self::assertSame($case['array']['stack'], $kid->stack());

        $actual = $kid->entities();
        $expected = $case['entities'];
        ksort($actual);
        ksort($expected);
        self::assertSame($expected, $actual, 'Decoded entities');
        foreach (array_unique([...array_keys($case['array']), 'missing', 'constructor']) as $entity) {
            self::assertSame($case['entities'][$entity] ?? null, $kid->get($entity), 'get ' . $entity);
            self::assertSame(array_key_exists($entity, $case['entities']), $kid->has($entity), 'has ' . $entity);
            self::assertSame(in_array($entity, $case['wildcards'], true), $kid->isWildcard($entity), 'wildcard ' . $entity);
        }
        foreach (['maintainer' => 'maintainer', 'org' => 'organization', 'project' => 'project', 'branch' => 'branch'] as $entity => $method) {
            self::assertSame($case['entities'][$entity] ?? null, $kid->$method(), $method);
        }
        self::assertSame($case['branch'], $kid->branch());
        self::assertSame($case['isPattern'], $kid->isPattern());
        $roundTrip = Kid::parse($kid->toString());
        self::assertTrue($kid->equals($roundTrip));
        self::assertTrue($kid == $roundTrip, 'PHP structural equality');
        $array = $kid->toArray();
        unset($array['stack']);
        // A decoded literal `*` (%2A) becomes a wildcard in create(); that round trip is documented as lossy.
        $hasLiteralStar = array_diff(array_keys($array, '*', true), $case['wildcards']) !== [];
        self::assertSame(!$hasLiteralStar, $kid->equals(Kid::create($kid->stack(), $array)), 'toArray round trip');
        $copy = $kid->entities();
        $copy['config'] = 'changed';
        self::assertSame($case['canonical'], $kid->toString(), 'Defensive entities copy');
    }

    #[DataProvider('invalidCases')]
    public function testInvalidPredicates(array $case): void
    {
        self::assertFalse(Kid::validate($case['input']));
        self::assertFalse(Kid::isCanonical($case['input']));
    }

    #[DataProvider('invalidEntryPointCases')]
    public function testInvalidEntryPoint(string $method, array $case): void
    {
        try {
            Kid::$method($case['input']);
        } catch (InvalidKidException $error) {
            self::assertSame($case['code'], $error->code);
            self::assertSame($case['code'], $error->getCode());
            self::assertSame($case['segment'], $error->segment);
            return;
        }
        self::fail('Expected InvalidKidException from ' . $method);
    }

    #[DataProvider('createCases')]
    public function testCreate(array $case): void
    {
        $kid = Kid::create($case['stack'], $case['entities']);
        self::assertSame($case['canonical'], $kid->toString());
        self::assertTrue(Kid::isCanonical($kid->toString()));
    }

    #[DataProvider('invalidCreateCases')]
    public function testInvalidCreate(array $case): void
    {
        try {
            Kid::create($case['stack'], $case['entities']);
        } catch (InvalidKidException $error) {
            self::assertSame($case['code'], $error->code);
            self::assertSame($case['code'], $error->getCode());
            self::assertSame($case['segment'], $error->segment);
            return;
        }
        self::fail('Expected InvalidKidException from create');
    }

    public static function validCases(): iterable
    {
        yield from self::vectors('valid');
    }

    public static function invalidCases(): iterable
    {
        yield from self::vectors('invalid');
    }

    public static function invalidEntryPointCases(): iterable
    {
        foreach (self::vectors('invalid') as $label => [$case]) {
            foreach (['parse', 'canonicalize'] as $method) {
                yield $method . ' ' . $label => [$method, $case];
            }
        }
    }

    public static function createCases(): iterable
    {
        yield from self::vectors('create');
    }

    public static function invalidCreateCases(): iterable
    {
        foreach (self::vectors('createInvalid') as $label => [$case]) {
            if (isset($case['entitiesHex'])) {
                $case['entities'] = array_map(self::fixtureBytes(...), $case['entitiesHex']);
            }
            if (isset($case['segmentHex'])) {
                $case['segment'] = self::fixtureBytes($case['segmentHex']);
            }
            yield $label => [$case];
        }
    }

    private static function fixtureBytes(string $hex): string
    {
        if (strlen($hex) % 2 !== 0 || preg_match('/\A[0-9a-fA-F]*\z/', $hex) !== 1) {
            throw new \UnexpectedValueException('Invalid hexadecimal byte fixture');
        }
        return hex2bin($hex);
    }

    private static function vectors(string $group): iterable
    {
        $vectors = json_decode(file_get_contents(__DIR__ . '/../../spec/test-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($vectors[$group] as $index => $case) {
            $description = $case['note'] ?? $case['code'] ?? $case['canonical'];
            yield $group . ' #' . $index . ': ' . substr($description, 0, 100) => [$case];
        }
    }
}
