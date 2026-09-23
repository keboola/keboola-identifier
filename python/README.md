# Keboola Identifier — Python

Python 3.11+, no runtime dependencies. Distribution: `keboola-identifier`;
import: `keboola_identifier`. Implements [SPEC.md](../SPEC.md) and the PR #2
contract, including full hierarchy names and uniform ASCII entity ordering.

## Install from this checkout

From `python/`:

```bash
python3 -m venv .venv
source .venv/bin/activate
python -m pip install .
python -m unittest discover -s tests -v
python examples/usage.py
```

## Usage

```python
import keboola_identifier as kid
from keboola_identifier import Entity, Kid, InvalidKidError

value = Kid.create("kbc-eu-central-1", {
    Entity.PROJECT: "9876", Entity.CONFIG: "my config",
})
assert str(value) == "KID:kbc-eu-central-1:config-my%20config:project-9876"
assert value.project == "9876"
assert value.get("config") == "my config"
assert kid.parse("KID:s:project-1") == {"stack": "s", "project": "1"}
```

## API

- Module functions: `validate(text)`, `parse(text)`, `canonicalize(text)`,
  `is_canonical(text)`. Predicates return booleans for string inputs.
- `Kid.from_string(text)` and `Kid.create(stack, entities)` produce immutable
  identifiers; `Kid(text)` also validates and canonicalizes.
- Properties: `stack`, `maintainer`, `organization`, `project`, `branch`.
  Missing entities return `None`; numeric values remain strings.
- Methods: `get(entity)`, `has(entity)`, `entities()` (a decoded copy),
  `is_wildcard(entity)`, `is_pattern()`.
- `str(value)` returns the canonical KID; equality and hashing use that string.
  Foreign-type equality returns `NotImplemented`. KIDs can be dictionary keys.
- `Entity` is a `StrEnum` of the launch catalog (`MAINTAINER`, `ORG`, `PROJECT`,
  `BRANCH`, `BUCKET`, `TABLE`, `COMPONENT`, `CONFIG`, `ROW`, `WORKSPACE`, `USER`,
  `APP`, `STREAM`). Plain strings also support the open catalog.

`parse()` returns a flat dictionary with `stack` first and encoded entity values
in input order, preserving `branch-default`. Canonical identifiers omit
`branch-default` and sort every entity by ASCII name. The entity name `stack` is
reserved; `entities` and `constructor` are ordinary open names.

Validation failures raise `InvalidKidError(ValueError)` with `code` and `segment`
(`None` when not applicable). Codes and precedence are defined in SPEC §8.
Stack and raw values must be strings, without coercion. Lengths are UTF-8 bytes:
255 per raw value and 2048 per serialized KID. Lone surrogates are `bad_utf8`;
their surrogate-pass byte length is checked first during creation. Bytes objects
are not strings and produce `bad_value`. Text entry points require `str`.

The shared UTF-8 surrogate fixture is loaded with `surrogatepass`. The one raw
`0xFF` creation fixture cannot exist in a Python `str` and is explicitly skipped;
tests separately verify bytes rejection, lone surrogates, and length precedence.
All encoded invalid-UTF-8 parsing vectors run unchanged.

## Patterns and round trips

`*` as a whole raw value passed to `create()` is a wildcard. Read a literal star
with `Kid.from_string("KID:s:config-%2A")`; both decode to `*`, but only the wildcard
sets `is_wildcard()`. `branch-dev` sets `is_pattern()` without being a wildcard.
Use `Kid.from_string(str(value))` for a lossless round trip: rebuilding from
`entities()` turns a literal star into a wildcard. No Unicode normalization occurs.

Producers omit the default branch rather than writing its numeric ID. The library
validates syntax offline; it does not match patterns or check object existence.
