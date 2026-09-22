<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Keboola\Identifier\InvalidKidException;
use Keboola\Identifier\Kid;

$vectors = json_decode(file_get_contents(dirname(__DIR__, 2) . '/spec/test-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
$checks = 0;
$assert = static function (bool $condition, string $message = 'assertion failed') use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ($vectors['valid'] as $case) {
    $assert(Kid::parse($case['input']) === $case['parse'], $case['input'] . ' parse');
    $kid = Kid::fromString($case['input']);
    $assert($kid->toString() === $case['canonical'], $case['input'] . ' canonical');
    $assert($kid->entities() === $case['entities'], $case['input'] . ' entities');
    $assert($kid->branch() === $case['branch'], $case['input'] . ' branch');
    $assert($kid->isPattern() === $case['isPattern'], $case['input'] . ' pattern');
    $actualWildcards = array_values(array_filter(array_keys($case['entities']), $kid->isWildcard(...)));
    $assert($actualWildcards === $case['wildcards'], $case['input'] . ' wildcards');
    $assert(Kid::validate($case['input']));
    $assert(Kid::isCanonical($case['input']) === $case['isCanonical']);
}
foreach ($vectors['invalid'] as $case) {
    $assert(!Kid::validate($case['input']));
    $assert(!Kid::isCanonical($case['input']));
    try {
        Kid::fromString($case['input']);
        $assert(false, 'expected exception');
    } catch (InvalidKidException $error) {
        $assert($error->code === $case['code'], $case['input'] . ' code');
        $assert($error->segment === $case['segment'], $case['input'] . ' segment');
    }
}
foreach ($vectors['create'] as $case) {
    $assert(Kid::create($case['stack'], $case['entities'])->toString() === $case['canonical'], $case['canonical']);
}
foreach ($vectors['createInvalid'] as $case) {
    if (in_array('php', $case['skip'] ?? [], true)) continue;
    try {
        Kid::create($case['stack'], $case['entities']);
        $assert(false, 'expected create exception');
    } catch (InvalidKidException $error) {
        $assert($error->code === $case['code'], 'create code');
        $assert($error->segment === $case['segment'], 'create segment');
    }
}
$assert(Kid::fromString('KID:s:p-1:o-2')->equals(Kid::fromString('KID:s:o-2:p-1')));
fwrite(STDOUT, "OK ({$checks} checks)\n");
