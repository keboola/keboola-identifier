<?php

declare(strict_types=1);

use Keboola\Identifier\Kid;
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
    $kid = Kid::fromString($v['input']);
    same(true, Kid::validate($v['input']), "valid[$index] validate");
    same($v['parse'], Kid::parse($v['input']), "valid[$index] parse including order");
    same($v['canonical'], $kid->toString(), 'canonical');
    same($v['canonical'], (string) $kid, 'string cast');
    same($v['canonical'], Kid::canonicalize($v['input']), 'canonicalize');
    same($v['isCanonical'], Kid::isCanonical($v['input']), 'isCanonical');
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
    foreach (['maintainer' => 'maintainer', 'org' => 'organization', 'project' => 'project', 'branch' => 'branch'] as $entity => $method) {
        same($v['entities'][$entity] ?? null, $kid->$method(), $method);
    }
    same($v['branch'], $kid->branch(), 'branch vector');
    same($v['isPattern'], $kid->isPattern(), 'pattern');
    $roundTrip = Kid::fromString($kid->toString());
    same(true, $kid->equals($roundTrip), 'lossless round trip');
    same(true, $kid == $roundTrip, 'PHP structural equality');
    $copy = $kid->entities();
    $copy['config'] = 'changed';
    same($v['canonical'], $kid->toString(), 'defensive entities copy');
}
foreach ($vectors['invalid'] as $v) {
    same(false, Kid::validate($v['input']), 'invalid validate');
    same(false, Kid::isCanonical($v['input']), 'invalid isCanonical');
    foreach (['parse', 'fromString', 'canonicalize'] as $method) {
        fails(static fn() => Kid::$method($v['input']), $v['code'], $v['segment']);
    }
}
foreach ($vectors['create'] as $v) {
    $kid = Kid::create($v['stack'], $v['entities']);
    same($v['canonical'], $kid->toString(), 'create');
    same(true, Kid::isCanonical($kid->toString()), 'create emits valid canonical text');
}
foreach ($vectors['createInvalid'] as $v) {
    fails(static fn() => Kid::create($v['stack'], $v['entities']), $v['code'], $v['segment']);
}

// PHP-specific types and byte strings cannot all be represented in JSON.
$weakCreate = require __DIR__ . '/weak-types.php';
foreach ([123, true, false, 1.5, null, [], new stdClass(), new class implements Stringable {
    public function __toString(): string
    {
        return 's';
    }
}] as $stack) {
    fails(static fn() => Kid::create($stack, []), 'bad_stack', null);
    fails(static fn() => $weakCreate($stack, []), 'bad_stack', null);
}
same('KID:123', (string) $weakCreate('123', []), 'string stack remains valid in weak mode');
fails(static fn() => $weakCreate(123, ['Bad' => null]), 'bad_stack', null);
fails(static fn() => $weakCreate('s', ['project' => 123]), 'bad_value', null);
foreach ([42, 1.5, false, null, [], new stdClass(), new class implements Stringable {
    public function __toString(): string
    {
        return 'x';
    }
}] as $value) {
    fails(static fn() => Kid::create('s', ['config' => $value]), 'bad_value', null);
}
fails(static fn() => Kid::create('s', [12 => 'x']), 'bad_segment', '12-x');
foreach (["\xFF", "\xC0\xAF", "\xED\xA0\x80", "\xF4\x90\x80\x80"] as $raw) {
    fails(static fn() => Kid::create('s', ['config' => $raw]), 'bad_utf8', 'config-' . $raw);
}
fails(static fn() => Kid::create('s', ['config' => str_repeat("\xFF", 256)]), 'too_long', 'config-' . str_repeat("\xFF", 256));
fails(static fn() => Kid::fromString('KID:s:config-%FF' . str_repeat('x', 256)), 'bad_utf8', 'config-%FF' . str_repeat('x', 256));
fails(static fn() => Kid::fromString('kid:' . str_repeat('x', 2048)), 'bad_prefix', null);
fails(static fn() => Kid::fromString('KID:s:config-%FF%2a'), 'non_canonical_encoding', 'config-%FF%2a');
fails(static fn() => Kid::fromString('KID:s:config-%2a!'), 'bad_value', 'config-%2a!');
fails(static fn() => Kid::fromString('KID:s:project-1:project-abc'), 'duplicate_entity', 'project-abc');
fails(static fn() => Kid::create('S', ['Bad' => null]), 'bad_stack', null);
fails(static fn() => Kid::create('s', ['Bad' => null]), 'bad_segment', null);
fails(static fn() => Kid::create('s', ['project' => 'abc', 'Bad' => 'x']), 'bad_value', 'project-abc');
same(true, Kid::fromString('KID:s:branch-dev')->isPattern(), 'dev alone is a pattern');
same(false, Kid::fromString('KID:s:branch-dev')->isWildcard('branch'), 'dev is not wildcard');
same(false, Kid::create('s', ['config' => 'é'])->equals(Kid::create('s', ['config' => "e\u{0301}"])), 'no normalization');
$literal = Kid::fromString('KID:s:config-%2A');
$wildcard = Kid::create('s', $literal->entities());
same(false, $literal->equals($wildcard), 'literal vs wildcard equality');
same(false, $literal == $wildcard, 'literal vs wildcard PHP equality');
same(true, $wildcard->isWildcard('config'), 'documented lossy entities round trip');
same(false, Kid::fromString('KID:s')->equals(Kid::fromString('KID:t')), 'different stacks');
same('001', Kid::create('s', ['project' => '001'])->project(), 'no numeric normalization');

// Exhaustive ASCII byte table, including NUL and controls, with an independent oracle.
for ($byte = 0; $byte < 128; $byte++) {
    $raw = chr($byte);
    $unencoded = ($byte >= 65 && $byte <= 90) || ($byte >= 97 && $byte <= 122)
        || ($byte >= 48 && $byte <= 57) || in_array($byte, [45, 46, 95], true);
    $encoded = $unencoded ? $raw : '%' . strtoupper(str_pad(dechex($byte), 2, '0', STR_PAD_LEFT));
    $text = 'KID:s:config-x' . $encoded;
    same($text, (string) Kid::create('s', ['config' => 'x' . $raw]), 'ASCII encode ' . $byte);
    same('x' . $raw, Kid::fromString($text)->get('config'), 'ASCII decode ' . $byte);
}
same(true, (new ReflectionClass(Kid::class))->isReadOnly(), 'immutable class');
same(1, count((new ReflectionClass(Kid::class))->getProperties()), 'single canonical state');
echo "OK: $checks checks; " . count($vectors['valid']) . ' valid, ' . count($vectors['invalid'])
    . ' invalid, ' . count($vectors['create']) . ' create, ' . count($vectors['createInvalid']) . " createInvalid vectors\n";
