# Keboola Identifier (KID)

KID is a portable textual identifier for objects in the Keboola platform. It
can identify a whole stack or objects such as maintainers, organizations,
projects, branches, buckets, tables, component configurations, rows,
workspaces, users, apps, streams, and future entity types.

This repository is the source of truth for the [KID 1.0 format](SPEC.md) and
contains matching helper libraries for PHP, TypeScript, Python, and Go. The
format is deliberately offline: parsing or creating a KID does not call a
Keboola API or verify that the referenced object exists.

```text
KID:kbc-eu-central-1:p-9876:bucket-in.c-main:table-orders
```

## Format at a glance

```text
KID:<stack>:<entity>-<value>:<entity>-<value>...
```

- `KID:<stack>` identifies the stack itself.
- Segments may arrive in any order; serialization always emits canonical order.
- `{m, o, p, b}` means maintainer, organization, project, and branch.
- Values use a fixed percent-encoding and are compared byte-for-byte without
  Unicode normalization.
- A bare `*` value is a wildcard. `b-dev` means any non-default branch and also
  makes the KID a pattern.
- `b-default` is accepted as input but omitted from canonical output.
- The entity catalog is open. Unknown lowercase entity names remain valid and
  their values are treated as opaque strings.

A KID without wildcard semantics is an **identity**. A KID containing a
wildcard or `b-dev` is a **pattern**. Pattern matching is intentionally outside
the scope of version 1.0.

See [`SPEC.md`](SPEC.md) for the normative grammar, encoding table, limits,
validation order, error codes, and language-neutral API contract.

## Repository layout

| Path | Contents |
|---|---|
| [`SPEC.md`](SPEC.md) | Normative KID 1.0 format and API specification |
| [`spec/entities.json`](spec/entities.json) | Catalog of known entities and the frozen canonical head order |
| [`spec/test-vectors.json`](spec/test-vectors.json) | Normative cross-language valid, invalid, and creation cases |
| [`spec/generate_vectors.py`](spec/generate_vectors.py) | Deterministic source for the shared vectors |
| [`php/`](php/) | PHP package `keboola/identifier` |
| [`ts/`](ts/) | TypeScript package `@keboola/identifier` |
| [`python/`](python/) | Python package `keboola-identifier` |
| [`go/`](go/) | Go module and `kid` package |

All implementations provide the same operations, adapted to language
conventions:

| Capability | Description |
|---|---|
| Validate and parse | Apply the complete ordered validation sequence and expose encoded input segments |
| Create | Build a KID from a stack and decoded string values |
| Canonicalize | Reorder entities, remove `b-default`, and emit canonical encoding |
| Read | Access the stack, known entities, or any entity by name |
| Inspect | Detect wildcard entities and patterns |
| Compare | Compare canonical forms for equality |

Invalid input raises or returns the language-specific `InvalidKidError` (or
`InvalidKidException` in PHP), with a stable machine-readable error code and,
where applicable, the offending segment.

## Quick examples

### PHP

```php
use Keboola\Identifier\Kid;

$kid = Kid::fromString('KID:kbc-eu-central-1:p-9876:config-my%20config');

echo $kid->stack();   // kbc-eu-central-1
echo $kid->project(); // 9876
echo $kid->get('config'); // my config
```

### TypeScript

```ts
import {Kid} from '@keboola/identifier';

const kid = Kid.fromString('KID:kbc-eu-central-1:p-9876:config-my%20config');

console.log(kid.stack());       // kbc-eu-central-1
console.log(kid.project());     // 9876
console.log(kid.get('config')); // my config
```

### Python

```python
from keboola_identifier import Kid

kid = Kid.from_string("KID:kbc-eu-central-1:p-9876:config-my%20config")

print(kid.stack)         # kbc-eu-central-1
print(kid.project)       # 9876
print(kid.get("config")) # my config
```

### Go

```go
identifier, err := kid.FromString("KID:kbc-eu-central-1:p-9876:config-my%20config")
if err != nil {
    // handle invalid KID
}

stack := identifier.Stack()          // kbc-eu-central-1
project, _ := identifier.Project()   // 9876
config, _ := identifier.Get("config") // my config
```

Parsing encoded text and creating a KID from decoded values are intentionally
different operations. For example, `parse()` preserves `my%20config` as
written, object accessors decode it to `my config`, and `create()` emits the
encoded segment automatically from that decoded value.

## Development

Every package loads the same vectors. A change is compatible only when all four
implementations agree with them.

### Run tests

```bash
(cd php && composer install && composer test)
(cd ts && npm ci && npm test)
(cd python && python -m pip install -e '.[test]' && pytest)
(cd go && go test ./...)
```

Regenerate shared vectors after changing their source:

```bash
python3 spec/generate_vectors.py
```

CI also verifies that regeneration leaves `spec/test-vectors.json` unchanged.
When adding a known entity, update `spec/entities.json`, the typed constants in
all implementations, and the shared vectors. Unknown entities do not need to be
registered for validation to accept them.

## Scope

The repository handles KID syntax and representation only. It does not:

- generate object IDs;
- check whether a stack, project, or object exists;
- enforce parent relationships between entities;
- match patterns against identities;
- convert authorization URNs.

These boundaries keep the format deterministic and usable by services without
network access.
