# KID — Keboola Identifier. Format specification

Version: 1.0-draft.

This document defines the KID format: syntax, entity names, encoding,
canonicalization, validation and library operations. The
[shared test vectors](spec/test-vectors.json) are part of the specification and
provide expected results for valid and invalid inputs.

The repository provides implementations for [PHP](php/README.md),
[Go](go/README.md), [Python](python/README.md) and
[JavaScript/TypeScript](javascript/README.md).

## 1. Purpose

KID is a textual identifier for any object in the Keboola platform: stack, maintainer,
organization, project, branch, bucket, table, component configuration and row, workspace,
user, and other objects. It can be built and validated offline, without calling any API.

A KID with a wildcard or `branch-dev` is a **pattern**. Any other valid KID is an
**identity**. The library builds, parses, validates, canonicalizes and compares KIDs. It does **not**
evaluate whether a pattern matches an identity, does not generate object IDs, and does not
verify that a stack, project or object exists.

## 2. Grammar

```
KID     := "KID" ":" stack { ":" segment }
segment := entity "-" value
```

- The prefix is exactly `KID`, case-sensitive.
- `stack` is required and always first. It is the only part without an entity prefix.
- After the stack comes a **set** of `entity-value` segments separated by `:`.
  Everything after the stack is an entity, including maintainer, organization, project and
  branch.
- A segment is split on its **first** hyphen: entity before it, value after it. The entity
  cannot contain a hyphen; the value can (`bucket-in.c-main`).
- Each entity occurs at most once.
- Input order of segments is **not** enforced. `KID:s:project-9876:org-42` and
  `KID:s:org-42:project-9876` are both valid and denote the same object.
- Empty segments (`::`), a trailing colon and a segment without a hyphen are invalid.

## 3. Stack

The stack value is the `KEBOOLA_STACK` value of the deployment, i.e. the stack's folder
name in the `kbc-stacks` repository (the folder that holds `terraform/env.yaml` or
`infrastructure.tfvars`; other root folders such as `apps`, `cli`, `docs` are not stacks).
Examples: `kbc-eu-central-1`, `kbc-us-east-1`, `com-keboola-gcp-europe-west3`,
`cloud-keboola-cs`, `dev-keboola-aws-eu-west-1`.

- Regex: `^[a-z0-9]+(-[a-z0-9]+)*$`, 1 to 64 characters.
- The stack never carries a wildcard.
- The list of stacks is not validated; validation is syntactic only.
- A bare `KID:<stack>` is valid and is the identity of the stack itself.

The stack is a deployment identifier, not an API hostname. The library does not
convert hostnames to stack identifiers.

Producer rules:

- Producers read the stack from the deployment's `KEBOOLA_STACK` value.
- A producer **must** fail when the stack value is missing or empty and **must not**
  substitute a default. The library rejects an empty stack (`bad_stack`), but a fallback
  filled in before `create()` yields a valid KID for the wrong stack.
- A non-production deployment (local, test) uses its own `KEBOOLA_STACK` value, the same
  value in every producer of that deployment. No stack value is reserved for this; values
  such as `localhost` or `connection` are syntactically valid stacks.

Informative: a client outside the deployment can discover the stack from the `stack` field
of Connection's `GET /v2/storage`. That this field equals `KEBOOLA_STACK` is a guarantee of
Connection, not of this specification.

## 4. Entities

- Entity regex: `^[a-z][a-z0-9]*$`. Lowercase, no hyphen, no underscore.
- `stack` is reserved for the mandatory stack field and is not an entity name.
  A segment using it, such as `stack-x`, is `bad_segment`.
- The catalog of entities is **open**: an unknown entity passes validation and its value is
  treated as an opaque string. Known entities are listed in §4.3. The hierarchy
  entities have dedicated accessors and the value constraints in §4.1; other
  entities use the general value rules in §5.
- No aliases: one object kind has exactly one registered entity name. Unknown names
  remain opaque entities; they are never translated to known names or read by typed accessors.
- Combinations are not interpreted. `project-1:user-5` is syntactically valid; what it means is
  defined by the producer.

### 4.1 Hierarchy entities with constrained values

| Entity | Meaning | Allowed values |
|---|---|---|
| `maintainer` | maintainer | `^(0|[1-9][0-9]*)$` or `*` |
| `org` | organization | `^(0|[1-9][0-9]*)$` or `*` |
| `project` | project | `^(0|[1-9][0-9]*)$` or `*` |
| `branch` | branch | `^(0|[1-9][0-9]*)$`, `dev`, `default` or `*` |

Any other value for these entities is `bad_value`, including a numeric ID with leading
zeros: `project-007` would otherwise be a second canonical identity for project 7, and
identities are compared by exact canonical string (§6). The library rejects such input
rather than normalizing it.

These value constraints do not imply any ordering or parent relationship.
All entities have equal ordering semantics: input order does not affect identity,
and canonical serialization sorts every entity by its name using ASCII byte order.

### 4.2 Branch semantics

| `branch` in canonical form | Meaning | Kind |
|---|---|---|
| absent | default branch | identity |
| `branch-555` | the dev branch with id 555 | identity |
| `branch-dev` | any non-default branch | pattern |
| `branch-*` | any branch | pattern |

- `branch-default` is accepted on input and **removed by canonicalization**. It never appears in
  a canonical KID. Readers fall back to it: when a KID carries no `branch`, `branch()`,
  `get("branch")`, `entities()` and `toArray()` all report `default` and `has("branch")` is true.
  The canonical string is unaffected, so identity does not change.
- Producers must never write the default branch's numeric id (`branch-4321` where 4321 is the
  default branch). The library cannot recognize a numeric ID as the default branch,
  so it treats that KID as a different identity.
- `isWildcard("branch")` is true only for `branch-*`. `isPattern()` is true for any `*` value and
  for `branch-dev`.

### 4.3 Known entities

| Entity | Value | Usually with | Note |
|---|---|---|---|
| `maintainer` | number, `*` | | maintainer |
| `org` | number, `*` | `maintainer` | organization |
| `project` | number, `*` | `org` | project |
| `branch` | number, `dev`, `default`, `*` | `project` | branch |
| `bucket` | bucket id (`in.c-main`) | `project`, `branch` | `c-` prefix not guaranteed |
| `table` | table name only (`orders`) | `bucket` | the bucket carries the rest of the table id |
| `component` | component id (`keboola.ex-db-snowflake`) | `project`, `branch` | classification, not ownership |
| `config` | configuration id | `component` | user-supplied string, ≤ 100 chars in Connection |
| `row` | configuration row id | `config` | user-supplied string, ≤ 100 chars in Connection (`rowId VARCHAR(100)`) |
| `workspace` | number | `project`, `branch` | a workspace always belongs to one branch |
| `user` | number | | no project |
| `app`, `stream` | object id | `project`, `branch` | a data app is a configuration (≤ 100); stream ids are not stored by Connection and are bounded only by the 255-byte value cap (§7) |

Parent relationships (`table` under `bucket`, `row` under `config` under `component`) are
**not enforced** by the library. They describe the object relationships represented
by producers. The alphabetical canonical order preserves them for the known entities:
`bucket` < `table`, `component` < `config` < `row`.

### 4.4 Identity form (recommendation, not enforced)

Because `maintainer` and `org` are optional and parents are not enforced, one object can be written
in several valid ways. The library validates all of them and canonicalizes each one
individually; it cannot know which ancestors are "redundant". To make stored identities
comparable across services, producers **should** store and log identities in the minimal form:

- stack-level objects: `stack` + the object entity (`KID:s:org-42`, `KID:s:user-5`);
- project-scoped objects: `project` + the object entity chain, **without** `maintainer` and `org`
  (`KID:s:bucket-in.c-main:project-9876:table-orders`);
- `branch` only for non-default branches.

`maintainer` and `org` on project-scoped KIDs are for patterns (grants), for identities a
consumer builds for its own purpose and for human context, not for stored identities. This also
avoids stale ancestors when a project moves between organizations.

The minimal form applies to **stored and logged** identities only:

- A KID that carries ancestors, such as `KID:s:org-5:project-123`, is a fully conforming identity.
- A consumer may require ancestors on identities it builds for its own purpose, such as
  authorization requests and patterns that pin an organization.
- Consumers must not "normalize" such identities by dropping ancestors. A pattern that pins
  `org-5` matches only requests that carry `org-5`; dropping it silently changes what the
  pattern matches.
- A request identity with ancestors and a stored minimal identity are different KIDs under
  §6, and `equals()` is false for them. Relating them is consumer-side matching, which the
  library does not provide (§11).

## 5. Values and encoding

Values are stored and compared in an encoded form. The encoding is percent-encoding with a
**fixed byte table that is not the RFC 3986 unreserved set** (`~` is encoded here).

| Byte | Written as |
|---|---|
| `A`–`Z`, `a`–`z`, `0`–`9`, `.`, `_`, `-` | the byte itself, never encoded |
| every other byte, including `~`, space, `:`, `/`, `%`, `+`, `*`, `!`, `(`, `)`, `'` and all bytes ≥ 0x80 | `%XX`, `X` = uppercase hex digit |

Rules:

- Exactly one encoded form exists for each raw value. `%69` (an unreserved `i`), lowercase
  hex (`%2a`) and `%` not followed by two hex digits are **non-canonical** and rejected.
- The raw value is a sequence of bytes that must be valid UTF-8 after decoding; otherwise
  the KID is rejected (`bad_utf8`). No Unicode normalization is performed anywhere: a value
  in NFC and the same text in NFD are two different values. Producers normalize if they
  need to.
- A raw value must not be empty. `project-` is `bad_value`.
- The wildcard is a bare `*` as the **entire** value. A literal `*` inside a raw value is
  encoded as `%2A`. A bare `*` anywhere else in a value (`bucket-in.c-*`) is `bad_value`.
- `+` is never a space. Decoders that map `+` to space (`urldecode`, `unquote_plus`,
  `url.QueryUnescape`) must not be used.
- Implementations must encode and decode with their own byte loop, not with a standard
  library helper (`encodeURIComponent`, `rawurlencode`, `quote`, `url.PathEscape`), because
  every one of those differs from this table for at least one byte.

Percent-encoding allows values such as configuration IDs to contain spaces, colons
and other characters without conflicting with KID delimiters.

## 6. Canonical form

Canonical form is the only serialization the library emits and the only form that should be
stored, logged or passed to another service.

1. `KID:` + stack.
2. Drop `branch-default` if present.
3. Write **all** remaining entities in **ascending byte order** of the entity name (plain ASCII
   comparison; no locale collation, no `localeCompare`, no `strcoll`, PHP `SORT_STRING`).
4. Values as encoded per section 5.

Equality of two KIDs is byte-wise equality of their canonical forms.
Sorting is only a deterministic serialization convention, not a semantic hierarchy:
no entity has a privileged position, and all input permutations denote the same KID.

## 7. Limits

Lengths are measured in **bytes**, not characters or UTF-16 code units. The value
limit applies before encoding; the total KID limit applies to encoded text.

- A raw value is at most **255 bytes** before encoding, hence at most 765 bytes encoded.
  A longer value is `too_long` (in `create()` on the raw value, in `parse()` on the
  decoded value).
- A KID is at most **2048 bytes**. `parse()` checks the input text; because
  canonicalization only reorders segments and drops `branch-default`, a canonical form is never
  longer than its valid input, so the canonical form is within the limit whenever the
  input is. `create()` checks the canonical string it produces.
- A stack is at most **64 ASCII characters** (§3).

The limits apply to the complete identifier even when each individual value is
within its own limit. A KID with three maximally encoded 255-byte values exceeds
2048 bytes and is rejected as `too_long`, never truncated.

These limits are part of the format's compatibility contract (§11).

## 8. Validation and error codes

Validation is syntactic. `validate()` and `isCanonical()` return booleans. Parsing,
creation and canonicalization raise one error type for invalid input, carrying a
machine-readable `code` and, where applicable, the offending `segment`. The message text is informational and not part
of the contract.

Checks run in this order and stop at the first failure, so that inputs with several defects
yield the same code everywhere:

| # | Check | Code |
|---|---|---|
| 1 | starts with `KID:` | `bad_prefix` |
| 2 | input text ≤ 2048 bytes | `too_long` |
| 3 | stack non-empty and matches its regex | `bad_stack` |
| 4 | for each remaining segment, left to right: | |
| 4a | segment is non-empty (also covers a trailing colon) | `empty_segment` |
| 4b | segment contains a hyphen and the entity part matches `^[a-z][a-z0-9]*$` and is not the reserved name `stack` | `bad_segment` |
| 4c | the entity has not occurred before | `duplicate_entity` (reachable only from text input; `create()` receives a map whose keys are unique by construction) |
| 4d | value is non-empty; for `maintainer`/`org`/`project`/`branch` it is in the allowed set; otherwise it is exactly `*`, or every character is one of `A-Z a-z 0-9 . _ -` or `%` (structure only; a bare `*` anywhere in a longer value fails here) | `bad_value` |
| 4e | every `%` is followed by exactly two **uppercase** hex digits, and no escape encodes a byte from the unencoded set (`%69`, `%2E`, `%2D` are non-canonical); a `%` at the end, `%F`, `%2a`, `%zz` all fail here | `non_canonical_encoding` |
| 4f | the decoded value is valid UTF-8 | `bad_utf8` |
| 4g | the decoded value is ≤ 255 bytes | `too_long` (with `segment` set; check 2 reports `too_long` without a segment, which is how a consumer tells "shorten this value" from "drop an entity") |

Only 4e judges percent-escapes; 4d never inspects what follows a `%`. This keeps
`non_canonical_encoding` reachable and identical across implementations.

`create()` accepts **string values**, and **integer values**, which it converts to their
decimal string form (`9876` → `"9876"`, `-1` → `"-1"`) before any check. No other type is
coerced. It applies, in order:

1. The stack is a string and passes check 3 (`bad_stack`).
2. For each entity, in map iteration order:
   - the entity regex and reserved-name check (`bad_segment`);
   - uniqueness (`duplicate_entity`, not reachable with a map of unique keys);
   - the raw value is a string or an integer, is non-empty and satisfies constrained-entity sets (`bad_value`);
   - raw value ≤ 255 bytes (`too_long`);
   - raw value is valid UTF-8 (`bad_utf8`).
3. Encode the values and check 2 on the canonical string produced (`too_long`).

In `create()`, a value that is both too long and invalid UTF-8 reports `too_long`.
In text parsing, UTF-8 validity precedes the decoded-value length check as listed above.

## 9. Library API (language-neutral contract)

| Operation | Behaviour |
|---|---|
| `validate(text) -> bool` | true iff `parse` would succeed |
| `parse(text) -> Kid` | runs the **full** check sequence 1–4g of §8 and throws on the first failure; returns the canonical identity. It is the only way from text to a `Kid` |
| `create(stack, entities) -> Kid` | raw (decoded) string values, or integers converted to decimal strings; `*` means wildcard; throws |
| `canonicalize(text) -> text` | `parse(text).toString()`; throws the same error as `parse` |
| `isCanonical(text) -> bool` | true iff the text is valid **and** equal to its canonical form; **false, never an error**, for invalid input |
| `stack()` | stack |
| `maintainer()`, `organization()`, `project()`, `branch()` | decoded value of `maintainer`, `org`, `project`, `branch`, or absent; `branch()` returns `default` for the default branch (§4.2) |
| `get(entity)` | decoded value or absent; entity name as written (`get("project")`); `get("branch")` falls back to `default` |
| `has(entity)` | presence; always true for `branch` |
| `entities()` | map entity → decoded value in canonical order, wildcard as `*`, `branch` as `default` when absent from the canonical form. **Lossy**: a decoded literal `*` (`%2A`) and the wildcard are indistinguishable here, and `create(stack, kid.entities())` turns such a literal into a wildcard. The lossless round trip is `parse(kid.toString())`. |
| `toArray()` | `stack` first, then `entities()`; the whole identity as one flat map of decoded strings. `create(array.stack, array without stack)` rebuilds the same KID, with the same literal-`*` caveat as `entities()` |
| `isWildcard(entity)` | value is the wildcard (distinguishes it from a decoded literal `*`) |
| `isPattern()` | any wildcard or `branch-dev` |
| `equals(other)` | canonical strings equal |
| `toString()` | canonical form; any other serialization the library offers (JSON, language-native) emits exactly this string |

Absent values are `null` (PHP), `undefined` (JavaScript/TypeScript), `None`
(Python) or `("", false)` (Go).
Objects are immutable; there is no `with()`/`without()`.

`toArray()` returns a flat map. For example, `parse("KID:eu-central:project-1").toArray()` is
`{stack: "eu-central", branch: "default", project: "1"}`. Numeric values remain strings; no
aliases or numeric conversions are performed. The name `stack` is reserved so an entity
cannot overwrite it. The name `entities` remains an ordinary open entity name.

A literal `*` cannot be produced through `create()` (its `*` always means wildcard); such
a KID can be read via `parse()`.

### 9.1 PHP API

The PHP package exposes these operations through `Keboola\Identifier\Kid`.
Validation, parsing, creation and canonicalization are static methods; accessors,
comparison and serialization operate on immutable instances. Entity names are
strings such as `'project'` or `'config'`.

Invalid input raises `InvalidKidException`, with the machine-readable code in the
`errorCode` property and an optional `segment`; the integer `getCode()` is `0`. `validate()` and `isCanonical()` return `false` for invalid
text instead of raising this exception.

See the [PHP API documentation](php/README.md#public-api) for signatures,
installation and usage examples.

### 9.2 Go, Python and JavaScript APIs

See the [Go](go/README.md#api), [Python](python/README.md#api) and
[JavaScript/TypeScript](javascript/README.md#api) documentation for signatures,
installation and usage examples.

| §9 operation | Go | Python | JavaScript |
|---|---|---|---|
| `parse(text)` | `Parse(text) (Kid, error)` | `parse(text)`, `Kid.parse(text)`, `Kid(text)` | `Kid.parse(text)` |
| `create(stack, entities)` | `New(stack, map[string]string)` | `Kid.create(stack, dict)` | `Kid.create(stack, object)` |
| `toArray()` | `ToMap() map[string]string` | `to_dict()` | `toObject()` |
| `branch()` | `Branch() string` | `branch` property | `branch()` |
| `toString()` | `String()` | `str(kid)` | `toString()` |

- Go has no exceptions; constructors return `error`. `Validate` stays a boolean.
- Python `__eq__` returns `NotImplemented` for foreign types; `__hash__` hashes the
  canonical string, so a `Kid` works as a dict key.
- JavaScript uses `equals()` for canonical equality; `==` compares references.
- The default-branch fallback (§4.2) applies in every language: `Branch()`/`branch`
  never reports an absent value, and `Get("branch")`/`get("branch")` succeeds.
- Serialization: Go `MarshalText`/`MarshalBinary` (JSON, gob), JavaScript `toJSON()`,
  Python `pickle`/`copy` emit or re-validate the canonical string. Generic object
  walkers (`structuredClone` in JavaScript, `dataclasses.asdict` in Python) do not
  see the private state and are not serializations of a KID; use `toString()`.
- Integer values in `create()`: Python accepts `int` (not `bool`, which is an `int`
  subclass); JavaScript accepts safe integers and `bigint`, and since a JavaScript
  number has no integer type, `1.0` and `1e2` are the integers `1` and `100` there
  while PHP and Python reject floats; Go's `map[string]string` has no integer values,
  so the caller converts with `strconv.Itoa`.
- Go maps are unordered, so `Entities()` and `ToMap()` carry no order; `String()` is
  the only ordered representation. Parsing still validates segments left to right.
  Creation checks entities in ascending name order, so the reported error is
  deterministic but may differ from the insertion-ordered check in PHP and Python
  when several entries are invalid. JavaScript checks in property enumeration order,
  which lists integer-like keys first; such keys are always `bad_segment`, so only
  the reported error can differ, never the canonical form. Canonical serialization
  is always ordered as specified in §6.
- Python and JavaScript creation APIs accept Unicode strings, not byte arrays.
  Unpaired surrogates are `bad_utf8`. Raw length is checked first: Python counts
  UTF-8 with `surrogatepass`, JavaScript counts `TextEncoder` bytes (three bytes
  per unpaired surrogate). Valid Unicode uses ordinary UTF-8 byte length.

## 10. Test vectors

[`spec/test-vectors.json`](spec/test-vectors.json) contains shared cases for:

- valid canonical KIDs with the expected `toArray()` output (`array`) and decoded `entities()`, both with the `branch` fallback applied;
- valid non-canonical inputs with the expected canonical form (shuffled order,
  `branch-default`, both together);
- invalid inputs with the expected error code, including: each error code at least once,
  `%2A` vs `*`, bare `*` inside a value, `%2a`, `%69`, trailing `%`, `%F`, empty value,
  `project-abc`, `project-001` (leading zero), `branch-main`, duplicate entity, uppercase entity, missing hyphen, 2048 vs 2049
  bytes, a 255- vs 256-byte raw value, invalid UTF-8 (`%FF`), `+` and `~` handling, a
  multibyte UTF-8 value, `isCanonical` on invalid input (false);
- `create()` cases: raw value → canonical, entities given in non-canonical order → canonical, `branch-default` dropped, integer values converted, other non-string input rejected, invalid UTF-8 rejected;
- `entities()` on a `%2A` literal versus a `*` wildcard (both read back as `*`, `isWildcard` differs).

Every language's test suite loads this file directly. PHP-specific cases are documented in
the [testing instructions](php/README.md#development-and-distribution).

### 10.1 Byte-string fixtures

JSON strings cannot contain invalid UTF-8. Creation cases for byte-string inputs
therefore use `entitiesHex` **instead of** `entities`: a map from entity name to an
even-length hexadecimal string describing the raw bytes passed to `create()`.
`segmentHex` **instead of** `segment` describes the expected offending segment as
bytes. Test loaders decode these fields before invoking the API and comparing the
exception. They are fixture notation only, not an accepted API input format.

For example, `entitiesHex: {"config": "ff"}` means a raw one-byte string `0xFF`,
not the two-character string `"ff"`; the expected code is `bad_utf8` and
`segmentHex` is `"636f6e6669672dff"` (`config-` followed by `0xFF`).

A creation fixture may carry `skip`, a list of language names whose API types cannot
represent its input (`"go"` for null and boolean map values); those loaders skip it.
Integer fixture values are loaded as integers where `create()` accepts them and as
their decimal strings in Go. Python and JavaScript loaders additionally skip
`entitiesHex` fixtures whose bytes are not valid Unicode text; Python can load
encoded surrogates with `surrogatepass`. Unicode-string implementations test
malformed native strings and byte-array rejection separately. All encoded
text-parsing vectors apply to every language.

## 11. Scope and compatibility

The library builds, parses, validates, canonicalizes and compares KIDs offline.
It does not provide:

- pattern matching between a pattern and an identity;
- strict catalog validation that rejects unknown entity names;
- conversion to or from authorization URNs (`kbc://…`);
- object ID generation, object existence checks or parent relationship validation.

The `KID:` prefix carries no version. Constrained entity rules, ASCII serialization
order, the encoding table and length limits are part of the format contract.
Incompatible changes require a new prefix; a library version alone cannot
distinguish incompatible identifiers.
