# KID — Keboola Identifier. Format specification

Version: 1.0-draft, full entity names and uniform ordering (2026-09-22). Normative for every implementation in this repository
(PHP, TypeScript, Python, Go). The shared test vectors in `spec/test-vectors.json` are
part of this specification; an implementation that disagrees with a vector is wrong.

This draft intentionally supersedes the entity naming and special head ordering
in PR #1: the hierarchy entities are `maintainer`, `org`, `project`, `branch`, with
no ordering priority over any other entity. No automatic conversion or aliasing
of old names occurs.

## 1. Purpose

KID is a textual identifier for any object in the Keboola platform: stack, maintainer,
organization, project, branch, bucket, table, component configuration and row, workspace,
user, and future objects. It can be built and validated offline, without calling any API.

A KID without wildcards is an **identity**. A KID with a wildcard is a **pattern**. This
library builds, parses, validates, canonicalizes and compares KIDs. It does **not**
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

Open (tracked outside this spec): the object-level authorization RFC (keboola/connection
PR 7082) uses the API hostname (`connection.eu-central-1.keboola.com`) as its stack
identifier. One canonical stack identifier for both, or a mapping table, is to be agreed.

## 4. Entities

- Entity regex: `^[a-z][a-z0-9]*$`. Lowercase, no hyphen, no underscore.
- The catalog of entities is **open**: an unknown entity passes validation and its value is
  treated as an opaque string. Known entities are listed in §4.3 below and gain
  typed accessors and value constraints; adding an entity to the catalog is a
  pull request amending that table, it is not required for validation to pass.
- No aliases: one object kind has exactly one registered entity name. Unknown names
  remain opaque entities; they are never translated to known names or read by typed accessors.
- Combinations are not interpreted. `project-1:user-5` is syntactically valid; what it means is
  the producer's business.

### 4.1 Hierarchy entities with constrained values

| Entity | Meaning | Allowed values |
|---|---|---|
| `maintainer` | maintainer | `^[0-9]+$` or `*` |
| `org` | organization | `^[0-9]+$` or `*` |
| `project` | project | `^[0-9]+$` or `*` |
| `branch` | branch | `^[0-9]+$`, `dev`, `default` or `*` |

Any other value for these entities is `bad_value`.

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
  a canonical KID.
- Producers must never write the default branch's numeric id (`branch-4321` where 4321 is the
  default branch). The library cannot detect this; it is a documented producer rule and
  the most likely source of silent identity mismatch.
- `isWildcard("branch")` is true only for `branch-*`. `isPattern()` is true for any `*` value and
  for `branch-dev`.

### 4.3 Known entities (launch catalog)

| Entity | Value | Usually with | Note |
|---|---|---|---|
| `maintainer` | number, `*` | | maintainer |
| `org` | number, `*` | `maintainer` | organization |
| `project` | number, `*` | `org` | project |
| `branch` | number, `dev`, `default`, `*` | `project` | branch |
| `bucket` | bucket id (`in.c-main`) | `project`, `branch` | `c-` prefix not guaranteed |
| `table` | table name only (`orders`) | `bucket` | the bucket carries the rest of today's table id |
| `component` | component id (`keboola.ex-db-snowflake`) | `project`, `branch` | classification, not ownership |
| `config` | configuration id | `component` | user-supplied string, ≤ 100 chars in Connection |
| `row` | configuration row id | `config` | user-supplied string, ≤ 100 chars in Connection (`rowId VARCHAR(100)`) |
| `workspace` | number | `project`, `branch` | a workspace always belongs to one branch |
| `user` | number | | no project |
| `app`, `stream` | object id | `project`, `branch` | a data app is a configuration (≤ 100); stream ids are not stored by Connection and are bounded only by the 255-byte value cap (§7) |

Parent relationships (`table` under `bucket`, `row` under `config` under `component`) are
**not enforced** by the library. They are conventions for reading and for the URN
conversion. The alphabetical canonical order preserves them for the known entities:
`bucket` < `table`, `component` < `config` < `row`.

### 4.4 Identity form (recommendation, not enforced)

Because `maintainer` and `org` are optional and parents are not enforced, one object can be written
in several valid ways. The library validates all of them and canonicalizes each one
individually; it cannot know which ancestors are "redundant". To make identities
comparable across services, producers **should** write identities in the minimal form:

- stack-level objects: `stack` + the object entity (`KID:s:org-42`, `KID:s:user-5`);
- project-scoped objects: `project` + the object entity chain, **without** `maintainer` and `org`
  (`KID:s:project-9876:bucket-in.c-main:table-orders`);
- `branch` only for non-default branches.

`maintainer` and `org` on project-scoped KIDs are for patterns (grants) and for human context, not for
stored identities. This also avoids stale ancestors when a project moves between
organizations.

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

Why encoding at all: Keboola configuration ids are restricted only by type and length, so
existing ids may contain spaces, colons or anything else.

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

All limits are in **bytes** of the encoded text (not characters, not UTF-16 code units).

- A raw value is at most **255 bytes** before encoding, hence at most 765 bytes encoded.
  A longer value is `too_long` (in `create()` on the raw value, in `fromString()` on the
  decoded value).
- A KID is at most **2048 bytes**. `fromString()` checks the input text; because
  canonicalization only reorders segments and drops `branch-default`, a canonical form is never
  longer than its valid input, so the canonical form is within the limit whenever the
  input is. `create()` checks the canonical string it produces.
- Sizing behind the numbers. Every identifier column Connection stores for the known
  entities is `VARCHAR(100)`: `configurationId` and `rowId` (`migrations/init2025.sql`),
  bucket `name` (`StorageBucket` entity); `CreateConfigurationRequest` enforces 100 for
  configuration ids at the API. A data app is a configuration, so its id has the same
  bound. Stream ids are not stored by Connection; 255 is the assumed bound for them and
  for any future entity. The cap of 255 is therefore 2.5× above every value Connection can
  hold today, and it is **frozen**: raising it would let a newer producer emit a KID an
  older library rejects, which without a format version is a breaking change (§11).
  An identity with up to 22 digits in each hierarchy ID uses
  `KID:` (4) + stack (64) + `:maintainer-…`, `:org-…`, `:project-…`, `:branch-…`
  (34 prefix bytes + 4 × 22 = 122) + `:component-` (11) + 765 + `:config-` (8) +
  765 = 1739 bytes and fits; three maximal encoded values do not, and such a KID
  is rejected as `too_long` rather than truncated.

## 8. Validation and error codes

Validation is syntactic and identical in every language. `validate()` returns a boolean;
every other entry point raises one error type carrying a machine-readable `code` and,
where applicable, the offending `segment`. The message text is informational and not part
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
| 4b | segment contains a hyphen and the entity part matches `^[a-z][a-z0-9]*$` | `bad_segment` |
| 4c | the entity has not occurred before | `duplicate_entity` (reachable only from text input; `create()` receives a map whose keys are unique by construction) |
| 4d | value is non-empty; for `maintainer`/`org`/`project`/`branch` it is in the allowed set; otherwise it is exactly `*`, or every character is one of `A-Z a-z 0-9 . _ -` or `%` (structure only; a bare `*` anywhere in a longer value fails here) | `bad_value` |
| 4e | every `%` is followed by exactly two **uppercase** hex digits, and no escape encodes a byte from the unencoded set (`%69`, `%2E`, `%2D` are non-canonical); a `%` at the end, `%F`, `%2a`, `%zz` all fail here | `non_canonical_encoding` |
| 4f | the decoded value is valid UTF-8 | `bad_utf8` |
| 4g | the decoded value is ≤ 255 bytes | `too_long` (with `segment` set; check 2 reports `too_long` without a segment, which is how a consumer tells "shorten this value" from "drop an entity") |

Only 4e judges percent-escapes; 4d never inspects what follows a `%`. This keeps
`non_canonical_encoding` reachable and identical across implementations.

`create()` accepts **strings only**, never coercing other types. It applies, in order:

1. The stack is a string and passes check 3 (`bad_stack`).
2. For each entity, in map iteration order:
   - the entity regex (`bad_segment`);
   - uniqueness (`duplicate_entity`, not reachable with a map of unique keys);
   - the raw value is a string, is non-empty and satisfies constrained-entity sets (`bad_value`);
   - raw value ≤ 255 bytes (`too_long`);
   - raw value is valid UTF-8 (`bad_utf8`).
3. Encode the values and check 2 on the canonical string produced (`too_long`).

In `create()`, a value that is both too long and invalid UTF-8 reports `too_long`.
In text parsing, UTF-8 validity precedes the decoded-value length check as listed above.

## 9. Library API (language-neutral contract)

| Operation | Behaviour |
|---|---|
| `validate(text) -> bool` | true iff `fromString` would succeed |
| `parse(text) -> {stack, entities}` | runs the **full** check sequence 1–4g of §8 and throws on the first failure, exactly like `fromString`; differs from it only in that it does not canonicalize: values **as written** (encoded), `*` as `*`, `branch-default` kept, input order kept |
| `fromString(text) -> Kid` | parses, validates, canonicalizes; throws |
| `create(stack, entities) -> Kid` | raw (decoded) string values; `*` means wildcard; throws |
| `canonicalize(text) -> text` | `fromString(text).toString()`; throws the same error as `fromString` |
| `isCanonical(text) -> bool` | true iff the text is valid **and** equal to its canonical form; **false, never an error**, for invalid input |
| `stack()` | stack |
| `maintainer()`, `organization()`, `project()`, `branch()` | decoded value of `maintainer`, `org`, `project`, `branch`, or absent; `branch()` is absent for the default branch |
| `get(entity)` | decoded value or absent; entity name as written (`get("project")`) |
| `has(entity)` | presence |
| `entities()` | map entity → decoded value, wildcard as `*`. **Iteration order is not part of the contract** (Go maps iterate randomly); the only ordered representation is `toString()`. **Lossy**: a decoded literal `*` (`%2A`) and the wildcard are indistinguishable here, and `create(stack, kid.entities())` turns such a literal into a wildcard. The lossless round trip is `parse(kid.toString())` (encoded values) or `fromString(kid.toString())`. |
| `isWildcard(entity)` | value is the wildcard (distinguishes it from a decoded literal `*`) |
| `isPattern()` | any wildcard or `branch-dev` |
| `equals(other)` | canonical strings equal |
| `toString()` | canonical form |

Absent is `null` (PHP), `undefined` (TypeScript), `None` (Python), `("", false)` (Go).
Objects are immutable; there is no `with()`/`without()`.

`parse()` keeps the stack separate from its `entities` map because the catalog is
open. For example, `KID:s:stack-x` parses as `{stack: "s", entities: {stack: "x"}}`.
Flattening these maps would lose either the actual stack or the `stack` entity.

A literal `*` cannot be produced through `create()` (its `*` always means wildcard); such
a KID can only be read via `fromString`. Documented edge.

### 9.1 Language mapping

| PHP | TypeScript | Python | Go |
|---|---|---|---|
| `Kid::validate()` | `Kid.validate()` | `kid.validate()` | `kid.Validate()` |
| `Kid::parse(): array` | `Kid.parse(): ParsedKid` | `kid.parse() -> ParsedKid` | `kid.Parse() (Parsed, error)` |
| `Kid::fromString()` | `Kid.fromString()` | `Kid.from_string()` | `kid.FromString() (*Kid, error)` |
| `Kid::create($stack, array $entities)` | `Kid.create(stack, entities)` | `Kid.create(stack, entities: dict)` | `kid.New(stack, map[string]string) (*Kid, error)` |
| `Kid::canonicalize()`, `Kid::isCanonical()` | same | `kid.canonicalize()`, `kid.is_canonical()` | `kid.Canonicalize() (string, error)`, `kid.IsCanonical() bool` |
| `stack()`, `maintainer()`, `organization()`, `project()`, `branch(): ?string` | same, `string \| undefined` | properties, `str \| None` | `Stack() string`, `Maintainer() (string, bool)`, … `Branch() (string, bool)` |
| `get(): ?string`, `has()`, `entities(): array` | `get(): string \| undefined`, `has()`, `entities(): Record` | `get()`, `has()`, `entities() -> dict` | `Get() (string, bool)`, `Has()`, `Entities() map[string]string` |
| `isWildcard()`, `isPattern()`, `equals()`, `toString()`, `__toString()` | `isWildcard()`, `isPattern()`, `equals()`, `toString()` | `is_wildcard()`, `is_pattern()`, `__eq__`, `__hash__`, `__str__` | `IsWildcard()`, `IsPattern()`, `Equals()`, `String()` |
| `InvalidKidException` (`code`, `segment: ?string`) | `InvalidKidError extends Error` (`code`, `segment?`) | `InvalidKidError(ValueError)` (`code`, `segment`) | `*InvalidKidError{Code, Segment string}` via `errors.As`; `Segment == ""` means not applicable |
| entity name strings, e.g. `'project'` | `Entity.PROJECT` | `Entity.PROJECT` (`StrEnum`) | `kid.EntityProject` |

Language notes:

- Go has no exceptions; constructors return `error`. `Validate` stays a boolean.
- Python `__eq__` returns `NotImplemented` for foreign types; `__hash__` hashes the
  canonical string, so a `Kid` works as a dict key.
- PHP and TypeScript keep the canonical string as the single internal state so that
  accidental `==` / structural comparison behaves.
- Go `Branch()` returns `("", false)` for the default branch, consistent with `Get("branch")`.

## 10. Test vectors

`spec/test-vectors.json` is loaded by every implementation's test suite and contains, at
minimum:

- valid canonical KIDs with the expected `parse()` output and decoded `entities()`;
- valid non-canonical inputs with the expected canonical form (shuffled order,
  `branch-default`, both together);
- invalid inputs with the expected error code, including: each error code at least once,
  `%2A` vs `*`, bare `*` inside a value, `%2a`, `%69`, trailing `%`, `%F`, empty value,
  `project-abc`, `branch-main`, duplicate entity, uppercase entity, missing hyphen, 2048 vs 2049
  bytes, a 255- vs 256-byte raw value, invalid UTF-8 (`%FF`), `+` and `~` handling, a
  multibyte UTF-8 value, `isCanonical` on invalid input (false);
- `create()` cases: raw value → canonical, entities given in non-canonical order → canonical, `branch-default` dropped, non-string input rejected, invalid UTF-8 rejected;
- `entities()` on a `%2A` literal versus a `*` wildcard (both read back as `*`, `isWildcard` differs).

The vectors live in this monorepo next to the four packages, so every package at a given
commit tests against the same file.

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

## 11. Out of scope for 1.0, tracked

- Pattern matching (`matches(pattern, identity)`). Semantics will be specified with shared
  vectors before any service implements its own matcher.
- Strict mode (reject entities outside the catalog).
- Conversion to and from the authorization URN (`kbc://…`), pending agreement on the open
  differences listed in the review document.
- Format versioning. The `KID:` prefix carries no version; a breaking change would need a
  new prefix after this draft is finalized. This draft's naming and ordering revisions are
  documented at the top of this specification. Accepted risk is mitigated by freezing
  the constrained entity rules, uniform ASCII serialization order, the
  encoding table and both length limits (255 per value, 2048 total). Changing any of
  those is a new prefix, not a new library release.
