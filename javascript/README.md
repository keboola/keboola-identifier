# Keboola Identifier — JavaScript

Dependency-free ESM package `@keboola/identifier`, for Node.js 22+.
TypeScript declarations are included; no build step is required. Implements
[SPEC.md](../SPEC.md) with the same vectors as PHP, Python and Go.

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
  [Entity.PROJECT]: 9876, [Entity.CONFIG]: 'my config',
});
console.log(kid.toString()); // KID:kbc-eu-central-1:config-my%20config:project-9876
console.log(kid.project()); // '9876'
console.log(kid.get('config')); // 'my config'
console.log(kid.branch()); // 'default'
console.log(Kid.parse('KID:s:project-1').toObject()); // { stack: 's', branch: 'default', project: '1' }
```

## API

- Static: `Kid.validate(text)`, `Kid.parse(text)`, `Kid.create(stack, entities)`,
  `Kid.canonicalize(text)`, `Kid.isCanonical(text)`. `parse()` is the only way from
  text to a `Kid`: it validates fully and returns the canonical identity.
- Accessors: `stack()`, `maintainer()`, `organization()`, `project()`, `branch()`,
  `get(entity)`, `has(entity)`, `entities()` (a decoded copy in canonical order),
  `toObject()` (`stack` first, then `entities()`; the counterpart of SPEC `toArray()`).
  Absent values are `undefined`; IDs remain strings. `branch()` is never `undefined`:
  a KID without a branch is on the default branch and reports `'default'`.
- Inspection: `isWildcard(entity)`, `isPattern()`, `equals(other)`, `toString()`.
  Use `equals()` for canonical equality rather than JavaScript reference equality.
- Serialization: `toJSON()` returns the canonical string, so `JSON.stringify(kid)`
  yields `"KID:…"` rather than `{}`; read it back with `Kid.parse()`.
- `Entity` is a frozen constants object: `MAINTAINER`, `ORG`, `PROJECT`, `BRANCH`,
  `BUCKET`, `TABLE`, `COMPONENT`, `CONFIG`, `ROW`, `WORKSPACE`, `USER`, `APP`, `STREAM`.
  Unknown entity names are accepted as plain strings. `DEFAULT_BRANCH` is `'default'`.

Instances are frozen and keep their canonical text private. Use factories rather
than `new Kid()`. Canonicalization omits the default branch and sorts all entities
by ASCII name. When the KID carries no branch, every reader falls back to the
default branch: `branch()`, `get('branch')`, `entities()` and `toObject()` report
`'default'` and `has('branch')` is `true`; the canonical string is unaffected.
`stack` is reserved as an entity name; `constructor` and `entities` work as
ordinary entities. Only own enumerable string keys of the creation object are
considered.

`create()` accepts decoded string values and integers, which it converts to their
decimal string form: safe integers (`{ project: 9876 }` equals `{ project: '9876' }`)
and `bigint` values for IDs beyond `Number.MAX_SAFE_INTEGER`. A negative integer
becomes `'-1'` and fails the numeric entity sets. Non-integer numbers, unsafe
integers, booleans, `null`, wrapper objects and containers raise `bad_value`.
Numeric IDs of `maintainer`, `org`, `project` and `branch` must not have leading
zeros (`bad_value`); `0` is accepted. Open-catalog values keep their characters.

Failures raise `InvalidKidError extends Error`, with `code` and optional `segment`.
See SPEC §8 for precedence. `validate()` and `isCanonical()` return false for invalid
KID strings. Text entry points expect strings; `create()` rejects non-string stacks
without coercion. Limits are UTF-8 bytes (255 raw, 2048 serialized), not
UTF-16 code units. Lone surrogates are rejected as `bad_utf8` rather than silently
replaced. During creation, length is checked first using `TextEncoder` byte length
(three bytes per unpaired surrogate), then Unicode validity. BOMs are preserved.

The two shared raw-invalid-byte creation fixtures cannot be represented as JS
strings and are explicitly skipped, as is any fixture marked `skip: ["javascript"]`.
Separate tests verify lone-surrogate rejection, length precedence, surrogate pairs,
integer conversion and rejection of byte arrays. Every encoded invalid-UTF-8 parsing
vector runs unchanged.

## Patterns and round trips

`Kid.create('s', {config: '*'})` creates a wildcard. A literal star is read with
`Kid.parse('KID:s:config-%2A')`. Both decode to `*`, but `isWildcard()`
distinguishes them. `branch-dev` is a pattern, not a wildcard. `entities()` and
`toObject()` are therefore lossy when fed back into `create()`; use
`Kid.parse(kid.toString())` for lossless round trips. Unicode normalization is never
performed.

Producers omit default branches rather than writing their numeric IDs. Pattern
matching and object existence checks are outside this library's scope.
