# keboola/identifier

Immutable PHP 8.3+ implementation of the Keboola Identifier 1.0 draft, using the
hierarchy entity names `maintainer`, `org`, `project`, `branch`. Runtime requires PHP
and its bundled PCRE extension, with no third-party runtime dependencies or build step.

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
The repository root is not a Composer package; use the `php/` subdirectory.
Package metadata declares `proprietary`; the repository does not include an
open-source license.

## Create and read

```php
require 'vendor/autoload.php';

use Keboola\Identifier\Kid;

$kid = Kid::create('kbc-eu-central-1', [
    'config' => 'my config',
    'project' => '9876',
    'branch' => 'default',
]);
echo $kid; // KID:kbc-eu-central-1:config-my%20config:project-9876
$kid->stack();        // 'kbc-eu-central-1'
$kid->project();      // '9876', always a string
$kid->branch();       // 'default' (no branch segment in the KID)
$kid->get('config');  // 'my config'
$kid->has('config');  // true
$kid->get('missing'); // null
```

`create()` accepts decoded **string values** and **integers**, which it converts to
their decimal string form (`['project' => 9876]` equals `['project' => '9876']`;
a negative integer becomes `"-1"` and fails the numeric entity sets). Booleans, floats,
null, arrays and stringable objects raise `bad_value`. It validates in map iteration order.
Raw strings must be valid UTF-8; bytes, including leading U+FEFF, NUL and control
characters, are preserved without trimming or Unicode normalization.
Numeric IDs of `maintainer`, `org`, `project`, `branch`, `user`, `workspace` and
`serviceaccount` must not have leading zeros (`bad_value`); `0` is accepted. Open-catalog values keep their bytes as given.

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
Kid::canonicalize($text);  // 'KID:s:config-my%20config:project-1'
$kid = Kid::parse($text);
$kid->toArray();           // ['stack' => 's', 'branch' => 'default',
                          //   'config' => 'my config', 'project' => '1']
$kid->entities();          // ['branch' => 'default', 'config' => 'my config', 'project' => '1']
$kid->equals(Kid::parse('KID:s:project-1:config-my%20config')); // true
```

`parse()` is the only way from text to a `Kid`. It validates fully, checks the
2048-byte **input** limit before parsing and returns the canonical identity;
`create()` checks the canonical **output** after removing `branch-default`.
Both enforce the 255-byte decoded value limit. Lowercase hex, escapes for unencoded
bytes, raw spaces, raw `+` and raw `~` are rejected, not repaired.

`toArray()` returns a flat map with `stack` first and decoded entities in canonical
order. When the KID carries no branch, every reader falls back to the default branch:
`branch()`, `get('branch')`, `entities()` and `toArray()` report `'default'` and
`has('branch')` is `true`. The canonical string stays without `branch-default`.
Values remain strings, including numeric IDs. `stack` is reserved and cannot be used
as an entity name (`bad_segment`), so an input such as `KID:s:stack-x` cannot
overwrite the actual stack.

## Wildcards and immutable objects

```php
$literal = Kid::parse('KID:s:config-%2A');
$literal->get('config');        // '*'
$literal->isWildcard('config'); // false
$literal->isPattern();         // false
$pattern = Kid::create('s', ['config' => '*']);
$pattern->isWildcard('config'); // true
$pattern->isPattern();         // true
$dev = Kid::parse('KID:s:branch-dev');
$dev->isPattern();             // true
$dev->isWildcard('branch');     // false
```

`create()` cannot represent a whole literal `*`. A star within a longer raw string,
such as `a*b`, is encoded normally. `entities()` is a decoded copy and therefore loses
the whole-star distinction: recreating a literal-star identifier from that map turns it
into a wildcard. Use `Kid::parse($kid->toString())` for a lossless round trip.

## Lossless encoded view

```php
$literal = Kid::parse('KID:s:config-%2A:project-1');
$literal->encodedEntities();   // ['config' => '%2A', 'project' => '1']
Kid::parse('KID:s:config-*:project-1')->encodedEntities(); // ['config' => '*', 'project' => '1']
Kid::encodeValue('*');         // '%2A', always a literal
Kid::encodeValue('my config'); // 'my%20config'
Kid::parse('KID:s:project-1:config-' . Kid::encodeValue('*'))->equals($literal); // true
```

`encodedEntities()` returns the values exactly as the canonical form carries them, in
canonical order, so a literal `%2A` and the wildcard `*` stay distinct. It has no `stack`
key and no default-branch fallback: `branch` is present only when the canonical form
carries it, unlike `has('branch')` and `get('branch')`.

`encodeValue()` encodes one raw value with the §5 table and the value checks `create()`
applies to an open entity: it accepts a string or an integer, rejects an empty value
(`bad_value`), more than 255 bytes (`too_long`) and invalid UTF-8 (`bad_utf8`), and its
errors carry no segment. Unlike `create()`, a whole `*` becomes `%2A`. Validate text
assembled from encoded values with `parse()`; a constrained entity such as `project-%2A`
is still `bad_value`.

Objects are final and readonly, with only the canonical string as instance state.
`equals()` and PHP `==` compare canonical representations. There are no mutators.
`entities()` and `toArray()` iterate in canonical order.

## Public API

- Static methods on `Kid`: `validate(string): bool`, `parse(string): Kid`,
  `create(mixed, array): Kid` (stack must be a string),
  `encodeValue(mixed): string` (a string or an integer),
  `canonicalize(string): string`, `isCanonical(string): bool`.
- Accessors: `stack(): string`, `maintainer(): ?string`, `organization(): ?string`,
  `project(): ?string`, `branch(): string`, `get(string): ?string`,
  `has(string): bool`, `entities(): array`, `encodedEntities(): array`, `toArray(): array`.
- Inspection: `isWildcard(string): bool`, `isPattern(): bool`, `equals(Kid): bool`.
- Serialization: `toString(): string`, `__toString(): string`, `jsonSerialize(): string`
  (`json_encode($kid)` yields the canonical string), PHP `serialize()`/`unserialize()`
  round-trips through the canonical string and re-validates on `unserialize()`.
- Entity names are strings such as `'project'`, `'branch'`, `'bucket'` and `'config'`.
  Any syntactically valid lowercase entity name is accepted, including `constructor`.

`Kid` combines static service operations (validation, parsing and creation) with immutable
identifier instances (accessors, comparison and serialization).
Input order has no effect on identity, for any entity. Canonical output sorts all
entity names in ASCII order with no special priority; this only provides a stable
serialization for equality and storage. `organization()` reads `'org'`.
Value constraints and branch semantics are independent of ordering. There is no
automatic aliasing of unknown entity names.

## Errors

```php
use Keboola\Identifier\InvalidKidException;

try {
    Kid::parse('KID:s:project-abc');
} catch (InvalidKidException $error) {
    echo $error->errorCode; // bad_value
    echo $error->segment;   // project-abc
}
```

`errorCode` carries the stable string code; `getCode()` stays `0`, so the exception can
be re-wrapped with `new RuntimeException($e->getMessage(), $e->getCode(), $e)` under
`strict_types`.

Codes, in text validation order: `bad_prefix`, `too_long` (whole input),
`bad_stack`, then per segment `empty_segment`, `bad_segment`, `duplicate_entity`,
`bad_value`, `non_canonical_encoding`, `bad_utf8`, `too_long` (decoded value).
The first failure wins. `segment` is null for global errors and non-string values,
`''` for an empty segment, otherwise the offending segment. Creation errors carry
the raw value in the segment; `encodeValue()` errors carry none. Creation and
`encodeValue()` check raw length before UTF-8 validity.
Exception messages are informational, not stable API.

## Development and distribution

From `php/` in a full repository checkout:

```bash
composer install
composer validate --strict
composer test
composer test -- --filter testInvalidEntryPoint
php examples/usage.php
composer archive --format=tar --dir=/path/to/output
```

`composer test` runs PHPUnit 11, configured by `phpunit.xml`. Standard
`#[DataProvider(...)]` methods provide named datasets:

- `tests/SharedVectorsTest.php` reads `../spec/test-vectors.json` for valid,
  invalid, creation and `encodeValue()` cases. Every dataset is a separate PHPUnit
  test result; failures identify the vector and, for errors, the public entry point.
  Invalid UTF-8 cases use `entitiesHex`, `rawHex` and `segmentHex` fixture fields;
  the provider decodes these to byte strings before calling the API (see SPEC §10).
- `tests/KidTest.php` defines PHP-specific data providers and regression tests,
  including non-string inputs, invalid byte strings, all ASCII bytes and entity
  order permutations. It deliberately uses PHP's default coercive mode to verify
  that non-string input is rejected even without `strict_types`.

The JSON is committed static test data that PHPUnit reads directly. PHP-specific
cases live in `KidTest` data providers. PHPUnit is a development-only dependency.
The committed `composer.lock` makes development and CI installs reproducible.

Development tests, PHPUnit configuration/cache, lockfile and vendor files are
excluded from archives.

The [repository specification](../SPEC.md)
defines the complete syntax. Validation is offline; object existence, parent
relationships and pattern matching are outside this library. Producers should omit
the default branch rather than use its numeric ID, and prefer minimal project/object
chains for comparable stored identities.
