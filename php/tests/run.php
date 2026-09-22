<?php

declare(strict_types=1);

use Keboola\Identifier\KIdEntity;
use Keboola\Identifier\KIdValidator;
use Keboola\Identifier\InvalidKidException;

require __DIR__ . '/../vendor/autoload.php';

$checks = 0;
function same(mixed $expected, mixed $actual, string $context): void
{
    global $checks;
    $checks++;
    if ($expected !== $actual) {
        throw new RuntimeException($context . '\nExpected: ' . var_export($expected, true)
            . '\nActual: ' . var_export($actual, true));
    }
}

function fails(callable $operation, string $code, ?string $segment): void
{
    try {
        $operation();
    } catch (InvalidKidException $error) {
        same($code, $error->code, 'error code');
        same($code, $error->getCode(), 'exception getCode');
        same($segment, $error->segment, 'offending segment');
        return;
    }
    throw new RuntimeException('Expected ' . $code);
}

$vectors = json_decode(file_get_contents(__DIR__ . '/../../spec/test-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($vectors['valid'] as $index => $v) {
    $kid = KIdValidator::fromString($v['input']);
    same(true, KIdValidator::validate($v['input']), "valid[$index] KIdValidator");
    same($v['parse'], KIdValidator::parse($v['input']), "valid[$index] parse including order");
    same($v['canonical'], $kid->toString(), 'canonical');
    same($v['canonical'], (string) $kid, 'string cast');
    same($v['canonical'], KIdValidator::canonicalize($v['input']), 'canonicalize');
    same($v['isCanonical'], KIdValidator::isCanonical($v['input']), 'isCanonical');
    same($v['parse']['stack'], $kid->stack(), 'stack');
    $actual = $kid->entities();
    $expected = $v['entities'];
    ksort($actual);
    ksort($expected);
    same($expected, $actual, 'decoded entities');
    foreach (array_unique([...array_keys($v['parse']['entities']), 'missing', 'constructor']) as $entity) {
        same($v['entities'][$entity] ?? null, $kid->get($entity), 'get ' . $entity);
        same(array_key_exists($entity, $v['entities']), $kid->has($entity), 'has ' . $entity);
        same(in_array($entity, $v['wildcards'], true), $kid->isWildcard($entity), 'wildcard ' . $entity);
    }
    foreach (['m' => 'maintainer', 'o' => 'organization', 'p' => 'project', 'b' => 'branch'] as $entity => $method) {
        same($v['entities'][$entity] ?? null, $kid->$method(), $method);
    }
    same($v['branch'], $kid->branch(), 'branch vector');
    same($v['isPattern'], $kid->isPattern(), 'pattern');
    $roundTrip = KIdValidator::fromString($kid->toString());
    same(true, $kid->equals($roundTrip), 'lossless round trip');
    same(true, $kid == $roundTrip, 'PHP structural equality');
    $copy = $kid->entities();
    $copy['config'] = 'changed';
    same($v['canonical'], $kid->toString(), 'defensive entities copy');
}
foreach ($vectors['invalid'] as $v) {
    same(false, KIdValidator::validate($v['input']), 'invalid KIdValidator');
    same(false, KIdValidator::isCanonical($v['input']), 'invalid isCanonical');
    foreach (['parse', 'fromString', 'canonicalize'] as $method) {
        fails(static fn() => KIdValidator::$method($v['input']), $v['code'], $v['segment']);
    }
}
foreach ($vectors['create'] as $v) {
    $kid = KIdValidator::create($v['stack'], $v['entities']);
    same($v['canonical'], $kid->toString(), 'create');
    same(true, KIdValidator::isCanonical($kid->toString()), 'create emits valid canonical text');
}
foreach ($vectors['createInvalid'] as $v) {
    fails(static fn() => KIdValidator::create($v['stack'], $v['entities']), $v['code'], $v['segment']);
}

// PHP-specific types and byte strings cannot all be represented in JSON.
$weakCreate = require __DIR__ . '/weak-types.php';
foreach ([123, true, false, 1.5, null, [], new stdClass(), new class implements Stringable {
    public function __toString(): string
    {
        return 's';
    }
}] as $stack) {
    fails(static fn() => KIdValidator::create($stack, []), 'bad_stack', null);
    fails(static fn() => $weakCreate($stack, []), 'bad_stack', null);
}
same('KID:123', (string) $weakCreate('123', []), 'string stack remains valid in weak mode');
fails(static fn() => $weakCreate(123, ['Bad' => null]), 'bad_stack', null);
fails(static fn() => $weakCreate('s', ['p' => 123]), 'bad_value', null);
foreach ([42, 1.5, false, null, [], new stdClass(), new class implements Stringable {
    public function __toString(): string
    {
        return 'x';
    }
}] as $value) {
    fails(static fn() => KIdValidator::create('s', ['config' => $value]), 'bad_value', null);
}
fails(static fn() => KIdValidator::create('s', [12 => 'x']), 'bad_segment', '12-x');
foreach (["\xFF", "\xC0\xAF", "\xED\xA0\x80", "\xF4\x90\x80\x80"] as $raw) {
    fails(static fn() => KIdValidator::create('s', ['config' => $raw]), 'bad_utf8', 'config-' . $raw);
}
fails(static fn() => KIdValidator::create('s', ['config' => str_repeat("\xFF", 256)]), 'too_long', 'config-' . str_repeat("\xFF", 256));
fails(static fn() => KIdValidator::fromString('KID:s:config-%FF' . str_repeat('x', 256)), 'bad_utf8', 'config-%FF' . str_repeat('x', 256));
fails(static fn() => KIdValidator::fromString('kid:' . str_repeat('x', 2048)), 'bad_prefix', null);
fails(static fn() => KIdValidator::fromString('KID:s:config-%FF%2a'), 'non_canonical_encoding', 'config-%FF%2a');
fails(static fn() => KIdValidator::fromString('KID:s:config-%2a!'), 'bad_value', 'config-%2a!');
fails(static fn() => KIdValidator::fromString('KID:s:p-1:p-abc'), 'duplicate_entity', 'p-abc');
fails(static fn() => KIdValidator::create('S', ['Bad' => null]), 'bad_stack', null);
fails(static fn() => KIdValidator::create('s', ['Bad' => null]), 'bad_segment', null);
fails(static fn() => KIdValidator::create('s', ['p' => 'abc', 'Bad' => 'x']), 'bad_value', 'p-abc');
same(true, KIdValidator::fromString('KID:s:b-dev')->isPattern(), 'dev alone is a pattern');
same(false, KIdValidator::fromString('KID:s:b-dev')->isWildcard('b'), 'dev is not wildcard');
same(false, KIdValidator::create('s', ['config' => 'é'])->equals(KIdValidator::create('s', ['config' => "e\u{0301}"])), 'no normalization');
$literal = KIdValidator::fromString('KID:s:config-%2A');
$wildcard = KIdValidator::create('s', $literal->entities());
same(false, $literal->equals($wildcard), 'literal vs wildcard equality');
same(false, $literal == $wildcard, 'literal vs wildcard PHP equality');
same(true, $wildcard->isWildcard('config'), 'documented lossy entities round trip');
same(false, KIdValidator::fromString('KID:s')->equals(KIdValidator::fromString('KID:t')), 'different stacks');
same('001', KIdValidator::create('s', ['p' => '001'])->project(), 'no numeric normalization');

// Exhaustive ASCII byte table, including NUL and controls, with an independent oracle.
for ($byte = 0; $byte < 128; $byte++) {
    $raw = chr($byte);
    $unencoded = ($byte >= 65 && $byte <= 90) || ($byte >= 97 && $byte <= 122)
        || ($byte >= 48 && $byte <= 57) || in_array($byte, [45, 46, 95], true);
    $encoded = $unencoded ? $raw : '%' . strtoupper(str_pad(dechex($byte), 2, '0', STR_PAD_LEFT));
    $text = 'KID:s:config-x' . $encoded;
    same($text, (string) KIdValidator::create('s', ['config' => 'x' . $raw]), 'ASCII encode ' . $byte);
    same('x' . $raw, KIdValidator::fromString($text)->get('config'), 'ASCII decode ' . $byte);
}
$catalog = json_decode(file_get_contents(__DIR__ . '/../../spec/entities.json'), true, 512, JSON_THROW_ON_ERROR);
$constants = array_values((new ReflectionClass(KIdEntity::class))->getConstants());
$names = array_keys($catalog['entities']);
sort($constants);
sort($names);
same($names, $constants, 'all catalog constants');
same(true, (new ReflectionClass(KIdValidator::class))->isReadOnly(), 'immutable class');
same(1, count((new ReflectionClass(KIdValidator::class))->getProperties()), 'single canonical state');
echo "OK: $checks checks; " . count($vectors['valid']) . ' valid, ' . count($vectors['invalid'])
    . ' invalid, ' . count($vectors['create']) . ' create, ' . count($vectors['createInvalid']) . " createInvalid vectors\n";
