# Keboola Identifier — JavaScript

Dependency-free ESM package `@keboola/identifier`, for Node.js 22+.
TypeScript declarations are included; no build step is required. Implements the
[PR #2 contract](../SPEC.md) with the same vectors as PHP, Python and Go.

## Install from this checkout

From your application's directory, `npm install /path/to/keboola-identifier/javascript`.
From this package directory:

```bash
npm test
npm run test:types # fetches the pinned TypeScript compiler for declaration checks
npm run example
npm pack --dry-run
```

## Usage

```javascript
import { Entity, Kid, InvalidKidError } from '@keboola/identifier';

const kid = Kid.create('kbc-eu-central-1', {
  [Entity.PROJECT]: '9876', [Entity.CONFIG]: 'my config',
});
console.log(kid.toString()); // KID:kbc-eu-central-1:config-my%20config:project-9876
console.log(kid.project()); // '9876'
console.log(kid.get('config')); // 'my config'
console.log(Kid.parse('KID:s:project-1')); // { stack: 's', project: '1' }
```

## API

- Static: `Kid.validate(text)`, `Kid.parse(text)`, `Kid.fromString(text)`,
  `Kid.create(stack, entities)`, `Kid.canonicalize(text)`, `Kid.isCanonical(text)`.
- Accessors: `stack()`, `maintainer()`, `organization()`, `project()`, `branch()`,
  `get(entity)`, `has(entity)`, `entities()` (a decoded copy). Absent values are
  `undefined`; IDs remain strings.
- Inspection: `isWildcard(entity)`, `isPattern()`, `equals(other)`, `toString()`.
  Use `equals()` for canonical equality rather than JavaScript reference equality.
- `Entity` is a frozen constants object: `MAINTAINER`, `ORG`, `PROJECT`, `BRANCH`,
  `BUCKET`, `TABLE`, `COMPONENT`, `CONFIG`, `ROW`, `WORKSPACE`, `USER`, `APP`, `STREAM`.
  Unknown entity names are accepted as plain strings.

Instances are frozen and keep their canonical text private. Use factories rather
than `new Kid()`. `parse()` returns a flat object: `stack` first, then encoded values
in input order, including `branch-default`. Canonicalization omits that default
branch and sorts all entities by ASCII name. `stack` is reserved as an entity name;
`constructor` and `entities` work as ordinary entities. Only own enumerable string
keys of the creation object are considered.

Failures raise `InvalidKidError extends Error`, with `code` and optional `segment`.
See SPEC §8 for precedence. `validate()` and `isCanonical()` return false for invalid
KID strings. Text entry points expect strings; `create()` rejects non-string stacks
and values without coercion. Limits are UTF-8 bytes (255 raw, 2048 serialized), not
UTF-16 code units. Lone surrogates are rejected as `bad_utf8` rather than silently
replaced. During creation, length is checked first using `TextEncoder` byte length
(three bytes per unpaired surrogate), then Unicode validity. BOMs are preserved.

The two shared raw-invalid-byte creation fixtures cannot be represented as JS
strings and are explicitly skipped. Separate tests verify lone-surrogate rejection,
length precedence, surrogate pairs and rejection of byte arrays. Every encoded
invalid-UTF-8 parsing vector runs unchanged.

## Patterns and round trips

`Kid.create('s', {config: '*'})` creates a wildcard. A literal star is read with
`Kid.fromString('KID:s:config-%2A')`. Both decode to `*`, but `isWildcard()`
distinguishes them. `branch-dev` is a pattern, not a wildcard. `entities()` is
therefore lossy when fed back into `create()`; use `Kid.fromString(kid.toString())`
for lossless round trips. Unicode normalization is never performed.

Producers omit default branches rather than writing their numeric IDs. Pattern
matching and object existence checks are outside this library's scope.
