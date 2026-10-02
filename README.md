# Keboola Identifier (KID)

KID is an offline textual identifier for Keboola stacks and objects:

```text
KID:kbc-eu-central-1:bucket-in.c-main:project-9876:table-orders
```

Implementations share the same specification and committed test vectors:

| Language | Package | Runtime |
|---|---|---|
| [PHP](php/README.md) | `keboola/identifier` | PHP 8.3+ |
| [Go](go/README.md) | `github.com/keboola/keboola-identifier/go/kid` | Go 1.23+ |
| [Python](python/README.md) | `keboola-identifier` (import `keboola_identifier`) | Python 3.11+ |
| [JavaScript](javascript/README.md) | `@keboola/identifier` (ESM, TypeScript declarations included) | Node.js 22+ |

Package installation from this checkout is documented below; registry publication
is separate from implementation.

## Contract and repository layout

The [format specification](SPEC.md) defines the KID contract. All packages
implement this contract and are tested against the shared test vectors.

| Path | Contents |
|---|---|
| [SPEC.md](SPEC.md) | Normative KID 1.0-draft grammar, API and entity catalog (§4.3) |
| [spec/test-vectors.json](spec/test-vectors.json) | Committed shared test data, including regressions |
| [php/](php/) | Composer package, examples and tests |
| [go/](go/) | Go module, example and tests |
| [python/](python/) | Python package, example and tests |
| [javascript/](javascript/) | JavaScript package, TypeScript declarations, example and tests |
| [.github/workflows/](.github/workflows/) | Per-language CI matrices |

## Format at a glance

- Exact prefix `KID:` followed by a syntactically valid stack; a bare stack is valid.
- Entity order has no effect on identity. Canonical output sorts all entities by
  ASCII name, without exceptions. Segments split on their first hyphen.
- `maintainer`, `org`, `project` and `branch` identify their respective platform objects.
- `branch-default` is accepted but omitted from canonical output.
- Bare `*` is a wildcard. `branch-dev` is also a pattern, but not a wildcard.
- Values use the fixed encoding table in §5, without trimming or Unicode normalization.
- Limits: 255 decoded bytes per value, 2048 bytes per KID, 64 characters per stack.
- The catalog is open. Unknown syntactically valid entities are accepted, except
  `stack`, which is reserved for the mandatory stack field.

## PHP usage

```php
use Keboola\Identifier\Kid;

$kid = Kid::parse('KID:kbc-eu-central-1:project-9876:config-my%20config');
echo $kid->project();     // 9876
echo $kid->get('config'); // my config
echo $kid->branch();      // default
Kid::validate('KID:s:project-1'); // true
Kid::parse('KID:eu-central:project-1')->toArray();
// ['stack' => 'eu-central', 'branch' => 'default', 'project' => '1']
```

PHP uses a single `Kid` class: static methods provide validation, parsing and
creation; instances represent immutable identifiers. Entity names are passed as
strings.

See [PHP documentation](php/README.md) for installation, the complete public API,
errors, wildcard edge cases and packaging.

The other packages expose the same operations under language-idiomatic names
(`toArray()` is `ToMap()` in Go, `to_dict()` in Python and `toObject()` in
JavaScript); SPEC §9.2 maps them and lists the per-language differences.

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

The JSON vectors are committed test data and are read directly by PHPUnit.
PHP-specific regression cases live in the `KidTest` data providers.

For the other packages, run from their respective directories:

```bash
# go/
go test -race -cover ./...
go vet ./...
go run ./examples/usage

# python/ (activate a virtual environment first)
python -m pip install .
python -m unittest discover -s tests -v
python examples/usage.py

# javascript/ (no dependencies or build step)
npm test
npm run example
npm pack --dry-run
```

All suites read `spec/test-vectors.json` directly and test error codes and offending
segments as well as canonicalization and accessors. Language-specific tests cover
all 720 permutations of six entities, the ASCII encoding table, immutable state,
and literal-star versus wildcard equality. Go additionally supplies a round-trip
fuzz target. See package READMEs for Unicode fixture handling and Go map ordering.

## Producer conventions and scope

For comparable stored identities, prefer the minimal project/object chain (`project` plus
object entities, without optional `maintainer`/`org`). Omit `branch` for the default branch; never
substitute its numeric ID, which the library cannot recognize as default.

The library does not generate IDs, check object existence, enforce parent
relationships, perform pattern matching, provide strict catalog mode or convert
authorization URNs. All operations are syntactic and offline.
