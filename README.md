# Keboola Identifier (KID)

KID is an offline textual identifier for Keboola stacks and objects:

```text
KID:kbc-eu-central-1:bucket-in.c-main:project-9876:table-orders
```

This reimplementation currently delivers the [PHP package](php/README.md)
`keboola/identifier`, namespace `Keboola\Identifier`, for PHP 8.2 or newer.

## Contract and repository layout

The current contract uses `maintainer`, `org`, `project` and `branch`, with no
entity-specific ordering priority. This is an
intentional draft revision of PR #1 commit `1f6eed74c198460f8160daa425b6e3e680a88a06`;
the original handoff materials are preserved verbatim as historical reference.
Implementation is independently written from the specification and test vectors.

| Path | Contents |
|---|---|
| [SPEC.md](SPEC.md) | Normative KID 1.0 grammar and API |
| [spec/entities.json](spec/entities.json) | Open entity catalog |
| [spec/test-vectors.json](spec/test-vectors.json) | Committed shared test data, including regressions |
| [php/](php/) | Composer package, examples and tests |
| [handoff/pr1-reimplementation/](handoff/pr1-reimplementation/PROMPT.md) | Frozen task and source materials |
| [.github/workflows/php.yml](.github/workflows/php.yml) | PHP 8.2–8.5 and shared-vector CI |

## Format at a glance

- Exact prefix `KID:` followed by a syntactically valid stack; a bare stack is valid.
- Entity order has no effect on identity. Canonical output sorts all entities by
  ASCII name, without exceptions. Segments split on their first hyphen.
- `maintainer`, `org`, `project` and `branch` identify their respective platform objects.
- `branch-default` is accepted but omitted from canonical output.
- Bare `*` is a wildcard. `branch-dev` is also a pattern, but not a wildcard.
- Values use the fixed encoding table in §5, without trimming or Unicode normalization.
- Limits: 255 decoded bytes per value, 2048 bytes per KID, 64 characters per stack.
- The catalog is open. Unknown syntactically valid entities are accepted.

## PHP usage

```php
use Keboola\Identifier\Kid;

$kid = Kid::fromString('KID:kbc-eu-central-1:project-9876:config-my%20config');
echo $kid->project();     // 9876
echo $kid->get('config'); // my config
Kid::validate('KID:s:project-1'); // true
```

PHP uses a single `Kid` class: static methods provide validation, parsing and
creation; instances represent immutable identifiers. Entity names are passed as
strings. The snapshot's convenience `Entity` constants class is omitted
from this PHP API by design.

See [PHP documentation](php/README.md) for installation, the complete public API,
errors, wildcard edge cases and packaging.

## Development

From `php/`:

```bash
composer install
composer validate --strict
composer test
php examples/usage.php
```

No build step is necessary. `composer test` runs PHPUnit 11 with standard named
data providers. `SharedVectorsTest` loads the committed JSON vectors directly;
`KidTest` contains PHP-specific providers and tests for types, invalid byte strings,
ASCII encoding, error precedence, immutability, ordering and literal-star equality.
The committed Composer lockfile pins development dependencies for CI.

No generation or Python execution happens anywhere in this workflow. PHP tests read
the committed JSON vectors directly; PHP-specific regressions live in `KidTest`
providers. There are no fixture generator scripts to run.
Added regressions preserve U+FEFF (including a value
consisting only of U+FEFF), control characters and open entity `constructor`, and
exercise trailing line terminators and the distinction between input/output limits.

## Producer conventions and scope

For comparable stored identities, prefer the minimal project/object chain (`project` plus
object entities, without optional `maintainer`/`org`). Omit `branch` for the default branch; never
substitute its numeric ID, which the library cannot recognize as default.

The library does not generate IDs, check object existence, enforce parent
relationships, perform pattern matching, provide strict catalog mode or convert
authorization URNs. All operations are syntactic and offline.
