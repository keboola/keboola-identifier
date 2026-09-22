# keboola/identifier

Immutable PHP 8.2+ implementation of the Keboola Identifier 1.0 draft, using the
full hierarchy entity names `maintainer`, `org`, `project`, `branch`. This explicitly
revises the entity naming from PR #1. Runtime requires PHP and its bundled PCRE
extension, with no third-party runtime dependencies or build step.

## Installation

The Composer package lives in the `php/` subdirectory. To consume a local checkout,
add a path repository in your application's `composer.json`:

```json
{
  "repositories": [{"type": "path", "url": "../keboola-identifier/php"}],
  "require": {"keboola/identifier": "@dev"}
}
```

Run `composer update keboola/identifier`, then load `vendor/autoload.php`.
Once the package subdirectory is published to a Composer registry, consumers can
use `composer require keboola/identifier`. Publication is a separate release step;
the monorepo root is not a Composer package. Metadata declares `proprietary` because
the repository does not supply an open-source license grant.

## Create and read

```php
require 'vendor/autoload.php';

use Keboola\Identifier\Kid;

$kid = Kid::create('kbc-eu-central-1', [
    'config' => 'my config',
    'project' => '9876',
    'branch' => 'default',
]);
echo $kid; // KID:kbc-eu-central-1:project-9876:config-my%20config
$kid->stack();        // 'kbc-eu-central-1'
$kid->project();      // '9876', always a string
$kid->branch();       // null (default branch)
$kid->get('config');  // 'my config'
$kid->has('config');  // true
$kid->get('missing'); // null
```

`create()` accepts decoded **string values only**; integers, booleans, null,
arrays and stringable objects raise `bad_value`. It validates in map iteration order.
Raw strings must be valid UTF-8; bytes, including leading U+FEFF, NUL and control
characters, are preserved without trimming or Unicode normalization.
Numeric IDs retain their leading zeros.

The stack must also be a string, even when the caller does not enable
`strict_types`. Other stack types raise `bad_stack` without conversion, before
entity validation. The runtime `mixed` parameter prevents PHP's implicit scalar
coercion; it does not broaden the accepted input types.

## Parse, validate and canonicalize

```php
use Keboola\Identifier\Kid;

$text = 'KID:s:config-my%20config:branch-default:project-1';
Kid::validate($text);      // true
Kid::isCanonical($text);   // false; also false for invalid text
Kid::canonicalize($text);  // 'KID:s:project-1:config-my%20config'
Kid::parse($text);         // ['stack' => 's', 'entities' => [
                          //   'config' => 'my%20config', 'branch' => 'default', 'project' => '1']]
$kid = Kid::fromString($text);
$kid->entities();          // ['project' => '1', 'config' => 'my config']
$kid->equals(Kid::fromString('KID:s:project-1:config-my%20config')); // true
```

`parse()` performs full validation but retains encoded values, input order and
`branch-default`. `fromString()` checks the 2048-byte **input** limit before parsing;
`create()` checks the canonical **output** after removing `branch-default`.
Both enforce the 255-byte decoded value limit. Lowercase hex, escapes for unencoded
bytes, raw spaces, raw `+` and raw `~` are rejected, not repaired.

## Wildcards and immutable objects

```php
$literal = Kid::fromString('KID:s:config-%2A');
$literal->get('config');        // '*'
$literal->isWildcard('config'); // false
$literal->isPattern();         // false
$pattern = Kid::create('s', ['config' => '*']);
$pattern->isWildcard('config'); // true
$pattern->isPattern();         // true
$dev = Kid::fromString('KID:s:branch-dev');
$dev->isPattern();             // true
$dev->isWildcard('branch');     // false
```

`create()` cannot represent a whole literal `*`; read `%2A` using `fromString()`.
A star within a longer raw string, such as `a*b`, is encoded normally.
`entities()` is a decoded copy and therefore loses the whole-star distinction:
recreating a literal-star identifier from that map turns it into a wildcard.
Use `Kid::fromString($kid->toString())` for a lossless round trip.

Objects are final and readonly, with only the canonical string as instance state.
`equals()` and PHP `==` compare canonical representations. There are no mutators;
map iteration order is not an API guarantee except for `parse()`.

## Public API

- Static methods on `Kid`: `validate(string): bool`, `parse(string): array`,
  `fromString(string): Kid`, `create(mixed, array): Kid` (stack must
  be a string), `canonicalize(string): string`, `isCanonical(string): bool`.
- Accessors: `stack(): string`, `maintainer(): ?string`, `organization(): ?string`,
  `project(): ?string`, `branch(): ?string`, `get(string): ?string`,
  `has(string): bool`, `entities(): array`.
- Inspection: `isWildcard(string): bool`, `isPattern(): bool`, `equals(Kid): bool`.
- Serialization: `toString(): string`, `__toString(): string`.
- Entity names are strings such as `'project'`, `'branch'`, `'bucket'` and `'config'`.
  Any syntactically valid lowercase entity name is accepted, including `constructor`.

The PHP API intentionally omits the snapshot's `Entity` constants class. `Kid`
combines static service operations (validation, parsing and creation) with immutable
identifier instances (accessors, comparison and serialization).
The four hierarchy entities sort first as `maintainer`, `org`, `project`, `branch`,
followed by other entities in ASCII order. `organization()` reads `'org'`.
Only these named entities receive the hierarchy constraints and branch semantics;
there is no automatic aliasing of unknown entity names.

## Errors

```php
use Keboola\Identifier\InvalidKidException;

try {
    Kid::fromString('KID:s:project-abc');
} catch (InvalidKidException $error) {
    echo $error->code;    // bad_value (also returned by getCode())
    echo $error->segment; // project-abc
}
```

Codes, in text validation order: `bad_prefix`, `too_long` (whole input),
`bad_stack`, then per segment `empty_segment`, `bad_segment`, `duplicate_entity`,
`bad_value`, `non_canonical_encoding`, `bad_utf8`, `too_long` (decoded value).
The first failure wins. `segment` is null for global errors and non-string values,
`''` for an empty segment, otherwise the offending segment. Creation errors carry
the raw value in the segment. Creation checks raw length before UTF-8 validity.
Exception messages are informational, not stable API.

## Development and distribution

From `php/` in a full repository checkout:

```bash
composer install
composer validate --strict
composer lint
composer test
composer test-package
php examples/usage.php
composer archive --format=tar --dir=/path/to/output
```

Tests use `../spec/test-vectors.json`. `test-package` builds an archive, verifies
its contents and installs the extracted package into an isolated Composer consumer
to exercise the public autoloaded API. Temporary files are removed afterwards.
It requires Composer on PATH and PHP's Phar extension (development only).
Development tests, lockfile and vendor files are excluded from archives.

The [repository specification](https://github.com/keboola/keboola-identifier/blob/reimplementation/php-pr1/SPEC.md)
defines the complete syntax. Validation is offline; object existence, parent
relationships and pattern matching are outside this library. Producers should omit
the default branch rather than use its numeric ID, and prefer minimal project/object
chains for comparable stored identities.
