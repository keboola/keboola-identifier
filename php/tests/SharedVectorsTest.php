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
        $kid = Kid::fromString($case['input']);
        self::assertTrue(Kid::validate($case['input']));
        self::assertSame($case['parse'], Kid::parse($case['input']), 'Encoded values and input order');
        self::assertSame($case['canonical'], $kid->toString());
        self::assertSame($case['canonical'], (string) $kid);
        self::assertSame($case['canonical'], Kid::canonicalize($case['input']));
        self::assertSame($case['isCanonical'], Kid::isCanonical($case['input']));
        self::assertSame($case['parse']['stack'], $kid->stack());

        $actual = $kid->entities();
        $expected = $case['entities'];
        ksort($actual);
        ksort($expected);
        self::assertSame($expected, $actual, 'Decoded entities');
        foreach (array_unique([...array_keys($case['parse']['entities']), 'missing', 'constructor']) as $entity) {
            self::assertSame($case['entities'][$entity] ?? null, $kid->get($entity), 'get ' . $entity);
            self::assertSame(array_key_exists($entity, $case['entities']), $kid->has($entity), 'has ' . $entity);
            self::assertSame(in_array($entity, $case['wildcards'], true), $kid->isWildcard($entity), 'wildcard ' . $entity);
        }
        foreach (['maintainer' => 'maintainer', 'org' => 'organization', 'project' => 'project', 'branch' => 'branch'] as $entity => $method) {
            self::assertSame($case['entities'][$entity] ?? null, $kid->$method(), $method);
        }
        self::assertSame($case['branch'], $kid->branch());
        self::assertSame($case['isPattern'], $kid->isPattern());
        $roundTrip = Kid::fromString($kid->toString());
        self::assertTrue($kid->equals($roundTrip));
        self::assertTrue($kid == $roundTrip, 'PHP structural equality');
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
            foreach (['parse', 'fromString', 'canonicalize'] as $method) {
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
        yield from self::vectors('createInvalid');
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
