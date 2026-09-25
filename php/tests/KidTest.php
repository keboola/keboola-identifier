<?php

// Deliberately use PHP's default coercive mode to catch accidental scalar conversion.

namespace Keboola\Identifier\Tests;

use Keboola\Identifier\InvalidKidException;
use Keboola\Identifier\Kid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Stringable;

final class KidTest extends TestCase
{
    #[DataProvider('nonStringValues')]
    public function testRejectsNonStringStackWithoutStrictTypes(mixed $stack): void
    {
        $this->assertInvalid(static fn() => Kid::create($stack, []), 'bad_stack', null);
    }

    #[DataProvider('nonStringValues')]
    public function testRejectsNonStringEntityValues(mixed $value): void
    {
        $this->assertInvalid(static fn() => Kid::create('s', ['config' => $value]), 'bad_value', null);
    }

    public static function nonStringValues(): iterable
    {
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'float' => [1.5];
        yield 'null' => [null];
        yield 'array' => [[]];
        yield 'object' => [new stdClass()];
        yield 'stringable object' => [new class implements Stringable {
            public function __toString(): string
            {
                return 's';
            }
        }];
    }

    public function testIntegerValuesAreConvertedToDecimalStrings(): void
    {
        $kid = Kid::create('s', ['project' => 9876, 'org' => 0, 'config' => 42]);
        self::assertSame('KID:s:config-42:org-0:project-9876', $kid->toString());
        self::assertSame('9876', $kid->project());
        self::assertTrue($kid->equals(Kid::create('s', ['project' => '9876', 'org' => '0', 'config' => '42'])));
        // A negative integer becomes a string with a minus sign and is validated as such.
        $this->assertInvalid(static fn() => Kid::create('s', ['project' => -1]), 'bad_value', 'project--1');
        self::assertSame('KID:s:config--1', Kid::create('s', ['config' => -1])->toString());
    }

    public function testWeakModeRejectsNonStringStackAndKeepsErrorPrecedence(): void
    {
        self::assertSame('KID:123', (string) Kid::create('123', []));
        $this->assertInvalid(static fn() => Kid::create(123, ['Bad' => null]), 'bad_stack', null);
        $this->assertInvalid(static fn() => Kid::create('s', ['project' => 1.5]), 'bad_value', null);
    }

    #[DataProvider('phpErrorCases')]
    public function testPhpErrorsAndPrecedence(string $method, array $arguments, string $code, ?string $segment): void
    {
        $this->assertInvalid(static fn() => Kid::$method(...$arguments), $code, $segment);
    }

    public static function phpErrorCases(): iterable
    {
        yield 'numeric entity key' => ['create', ['s', [12 => 'x']], 'bad_segment', '12-x'];
        foreach (['invalid byte' => "\xFF", 'overlong' => "\xC0\xAF", 'surrogate' => "\xED\xA0\x80", 'out of range' => "\xF4\x90\x80\x80"] as $label => $raw) {
            yield $label => ['create', ['s', ['config' => $raw]], 'bad_utf8', 'config-' . $raw];
        }
        $raw = str_repeat("\xFF", 256);
        yield 'create length before UTF-8' => ['create', ['s', ['config' => $raw]], 'too_long', 'config-' . $raw];
        $segment = 'config-%FF' . str_repeat('x', 256);
        yield 'parse UTF-8 before value length' => ['parse', ['KID:s:' . $segment], 'bad_utf8', $segment];
        yield 'prefix before total length' => ['parse', ['kid:' . str_repeat('x', 2048)], 'bad_prefix', null];
        yield 'encoding before UTF-8' => ['parse', ['KID:s:config-%FF%2a'], 'non_canonical_encoding', 'config-%FF%2a'];
        yield 'structure before encoding' => ['parse', ['KID:s:config-%2a!'], 'bad_value', 'config-%2a!'];
        yield 'duplicate before value' => ['parse', ['KID:s:project-1:project-abc'], 'duplicate_entity', 'project-abc'];
        yield 'stack before entity' => ['create', ['S', ['Bad' => null]], 'bad_stack', null];
        yield 'entity before value type' => ['create', ['s', ['Bad' => null]], 'bad_segment', null];
        yield 'first entity before next entity' => ['create', ['s', ['project' => 'abc', 'Bad' => 'x']], 'bad_value', 'project-abc'];
    }

    public function testBranchDevIsPatternButNotWildcard(): void
    {
        $kid = Kid::parse('KID:s:branch-dev');
        self::assertTrue($kid->isPattern());
        self::assertFalse($kid->isWildcard('branch'));
    }

    public function testDefaultBranchFallsBackToDefaultInEveryReader(): void
    {
        $kid = Kid::parse('KID:s:project-1:branch-default:config-my%20config');
        self::assertSame('KID:s:config-my%20config:project-1', $kid->toString());
        self::assertSame(
            ['stack' => 's', 'branch' => 'default', 'config' => 'my config', 'project' => '1'],
            $kid->toArray(),
        );
        self::assertSame(['branch' => 'default', 'config' => 'my config', 'project' => '1'], $kid->entities());
        self::assertSame('default', $kid->branch());
        self::assertSame('default', $kid->get('branch'));
        self::assertTrue($kid->has('branch'));
        self::assertFalse($kid->isWildcard('branch'));
        self::assertFalse($kid->isPattern());
        self::assertTrue($kid->equals(Kid::parse('KID:s:config-my%20config:project-1')));
        self::assertTrue($kid->equals(Kid::create('s', $kid->entities())));
    }

    public function testExplicitBranchIsNotReplacedByFallback(): void
    {
        self::assertSame('555', Kid::parse('KID:s:branch-555')->branch());
        self::assertSame('dev', Kid::parse('KID:s:branch-dev')->toArray()['branch']);
        self::assertSame('*', Kid::parse('KID:s:branch-*')->get('branch'));
    }

    public function testSerializationRoundTripsThroughCanonicalForm(): void
    {
        $kid = Kid::parse('KID:s:project-1:branch-default:config-my%20config');
        $copy = unserialize(serialize($kid));
        self::assertInstanceOf(Kid::class, $copy);
        self::assertTrue($kid->equals($copy));
        self::assertSame('KID:s:config-my%20config:project-1', $copy->toString());
    }

    public function testUnserializeRejectsForgedPayload(): void
    {
        $this->expectException(InvalidKidException::class);
        unserialize('O:22:"Keboola\\Identifier\\Kid":1:{s:3:"kid";s:9:"KID:A::::";}');
    }

    public function testUnserializeRejectsLegacyPropertyPayload(): void
    {
        // The former private-property layout must not bypass validation either.
        $this->expectException(InvalidKidException::class);
        unserialize('O:22:"Keboola\\Identifier\\Kid":1:{s:9:"canonical";s:9:"KID:A::::";}');
    }

    public function testExceptionCanBeRewrappedUnderStrictTypes(): void
    {
        try {
            Kid::parse('KID:s:project-abc');
            self::fail('Expected InvalidKidException');
        } catch (InvalidKidException $error) {
            $wrapped = new \RuntimeException($error->getMessage(), $error->getCode(), $error);
            self::assertSame($error, $wrapped->getPrevious());
            self::assertSame('bad_value', $error->errorCode);
            self::assertSame('project-abc', $error->segment);
            self::assertSame('Invalid KID: bad_value', $error->getMessage());
        }
    }

    public function testJsonEncodesAsCanonicalString(): void
    {
        $kid = Kid::parse('KID:s:project-1:branch-default:config-my%20config');
        self::assertSame('"KID:s:config-my%20config:project-1"', json_encode($kid));
        self::assertSame('{"kid":"KID:s:config-my%20config:project-1"}', json_encode(['kid' => $kid]));
    }

    public function testUnicodeIsNotNormalized(): void
    {
        self::assertFalse(Kid::create('s', ['config' => 'é'])->equals(Kid::create('s', ['config' => "e\u{0301}"])));
    }

    public function testDecodedLiteralStarRoundTripIsLossy(): void
    {
        $literal = Kid::parse('KID:s:config-%2A');
        $wildcard = Kid::create('s', $literal->entities());
        self::assertFalse($literal->equals($wildcard));
        self::assertFalse($literal == $wildcard);
        self::assertTrue($wildcard->isWildcard('config'));
    }

    public function testDifferentStacksAreNotEqual(): void
    {
        self::assertFalse(Kid::parse('KID:s')->equals(Kid::parse('KID:t')));
    }

    public function testNumericValuesAreNotNormalized(): void
    {
        self::assertSame('001', Kid::create('s', ['project' => '001'])->project());
    }

    public function testInstanceStateIsImmutableAndCanonicalOnly(): void
    {
        $class = new ReflectionClass(Kid::class);
        self::assertTrue($class->isReadOnly());
        self::assertCount(1, $class->getProperties());
    }

    #[DataProvider('entityPermutations')]
    public function testEntityOrderDoesNotAffectIdentity(array $entities): void
    {
        $canonical = 'KID:s:alpha-x:branch-4:maintainer-1:org-2:project-3:zeta-y';
        $text = 'KID:s';
        foreach ($entities as $name => $value) {
            $text .= ':' . $name . '-' . $value;
        }
        self::assertSame($canonical, Kid::parse($text)->toString());
        $sorted = $entities;
        ksort($sorted, SORT_STRING);
        self::assertSame(['stack' => 's'] + $sorted, Kid::parse($text)->toArray(), 'Stack first, then canonical entity order');
        self::assertSame($canonical, Kid::create('s', $entities)->toString());
        self::assertTrue(Kid::parse($text)->equals(Kid::parse($canonical)));
    }

    public static function entityPermutations(): iterable
    {
        $entities = ['maintainer' => '1', 'org' => '2', 'project' => '3', 'branch' => '4', 'alpha' => 'x', 'zeta' => 'y'];
        foreach (self::permutations($entities) as $permutation) {
            yield implode(', ', array_keys($permutation)) => [$permutation];
        }
    }

    private static function permutations(array $entities): iterable
    {
        if ($entities === []) {
            yield [];
            return;
        }
        foreach ($entities as $name => $value) {
            $rest = $entities;
            unset($rest[$name]);
            foreach (self::permutations($rest) as $tail) {
                yield [$name => $value] + $tail;
            }
        }
    }

    #[DataProvider('asciiBytes')]
    public function testAsciiEncoding(string $raw, string $encoded): void
    {
        $text = 'KID:s:config-x' . $encoded;
        self::assertSame($text, (string) Kid::create('s', ['config' => 'x' . $raw]));
        self::assertSame('x' . $raw, Kid::parse($text)->get('config'));
    }

    public static function asciiBytes(): iterable
    {
        // Independent oracle for every ASCII byte, including NUL and controls.
        for ($byte = 0; $byte < 128; $byte++) {
            $raw = chr($byte);
            $unencoded = ($byte >= 65 && $byte <= 90) || ($byte >= 97 && $byte <= 122)
                || ($byte >= 48 && $byte <= 57) || in_array($byte, [45, 46, 95], true);
            $hex = strtoupper(str_pad(dechex($byte), 2, '0', STR_PAD_LEFT));
            yield '0x' . $hex => [$raw, $unencoded ? $raw : '%' . $hex];
        }
    }

    private function assertInvalid(callable $operation, string $code, ?string $segment): void
    {
        try {
            $operation();
        } catch (InvalidKidException $error) {
            self::assertSame($code, $error->errorCode);
            self::assertSame(0, $error->getCode());
            self::assertSame($segment, $error->segment);
            return;
        }
        self::fail('Expected InvalidKidException with code ' . $code);
    }
}
