# keboola/identifier

Immutable PHP 8.2+ implementation of Keboola Identifier 1.0, based on PR #1 commit
`1f6eed74c198460f8160daa425b6e3e680a88a06`. Runtime requires PHP and its bundled PCRE
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

use Keboola\Identifier\KIdEntity;
use Keboola\Identifier\Kid;

$kid = Kid::create('kbc-eu-central-1', [
    KIdEntity::CONFIG => 'my config',
    KIdEntity::PROJECT => '9876',
    KIdEntity::BRANCH => 'default',
]);
echo $kid; // KID:kbc-eu-central-1:p-9876:config-my%20config
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
use Keboola\Identifier\KIdValidator;

$text = 'KID:s:config-my%20config:b-default:p-1';
KIdValidator::validate($text); // true
Kid::isCanonical($text);   // false; also false for invalid text
Kid::canonicalize($text);  // 'KID:s:p-1:config-my%20config'
Kid::parse($text);         // ['stack' => 's', 'entities' => [
                          //   'config' => 'my%20config', 'b' => 'default', 'p' => '1']]
$kid = Kid::fromString($text);
$kid->entities();          // ['p' => '1', 'config' => 'my config']
$kid->equals(Kid::fromString('KID:s:p-1:config-my%20config')); // true
```

`parse()` performs full validation but retains encoded values, input order and
`b-default`. `fromString()` checks the 2048-byte **input** limit before parsing;
`create()` checks the canonical **output** after removing `b-default`.
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
$dev = Kid::fromString('KID:s:b-dev');
$dev->isPattern();             // true
$dev->isWildcard('b');          // false
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

- `KIdValidator::validate(string): bool` performs full syntax validation using the
  parser. `Kid::validate()` delegates to it for compatibility with the frozen API.
- Static: `validate(string): bool`, `parse(string): array`, `fromString(string): Kid`,
  `create(mixed, array): Kid` (stack must be a string), `canonicalize(string): string`, `isCanonical(string): bool`.
- Accessors: `stack(): string`, `maintainer(): ?string`, `organization(): ?string`,
  `project(): ?string`, `branch(): ?string`, `get(string): ?string`,
  `has(string): bool`, `entities(): array`.
- Inspection: `isWildcard(string): bool`, `isPattern(): bool`, `equals(Kid): bool`.
- Serialization: `toString(): string`, `__toString(): string`.
- `KIdEntity` constants: `MAINTAINER`, `ORGANIZATION`, `PROJECT`, `BRANCH`, `BUCKET`,
  `TABLE`, `COMPONENT`, `CONFIG`, `ROW`, `WORKSPACE`, `USER`, `APP`, `STREAM`.
  Any syntactically valid lowercase entity name is accepted, including `constructor`.

The PHP naming refinement intentionally replaces the snapshot's generic `Entity`
class with `KIdEntity` and adds `KIdValidator`. Use these exact class names in imports
for PSR-4 autoloading on case-sensitive filesystems. `Kid` remains the immutable
identifier value object; the format, entity values and validation rules are unchanged.

## Errors

```php
use Keboola\Identifier\InvalidKidException;

try {
    Kid::fromString('KID:s:p-abc');
} catch (InvalidKidException $error) {
    echo $error->code;    // bad_value (also returned by getCode())
    echo $error->segment; // p-abc
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

The [repository specification](https://github.com/keboola/keboola-identifier/blob/1f6eed74c198460f8160daa425b6e3e680a88a06/SPEC.md)
defines the complete syntax. Validation is offline; object existence, parent
relationships and pattern matching are outside this library. Producers should omit
the default branch rather than use its numeric ID, and prefer minimal project/object
chains for comparable stored identities.
