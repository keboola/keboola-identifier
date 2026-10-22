# Keboola Identifier — Python

Python 3.11+, no runtime dependencies. Distribution: `keboola-identifier`;
import: `keboola_identifier`. Implements [SPEC.md](../SPEC.md), including full
hierarchy names, uniform ASCII entity ordering and the default-branch fallback.

The package ships a PEP 561 `py.typed` marker, so mypy and pyright use its
annotations.

## Install from this checkout

From `python/`:

```bash
python3 -m venv .venv
source .venv/bin/activate
python -m pip install .
python -m unittest discover -s tests -v
python examples/usage.py
python -m pip install mypy && (cd src && python -m mypy --strict -p keboola_identifier)
```

## Usage

```python
import keboola_identifier as kid
from keboola_identifier import Entity, Kid, InvalidKidError

value = Kid.create("kbc-eu-central-1", {
    Entity.PROJECT: 9876, Entity.CONFIG: "my config",
})
assert str(value) == "KID:kbc-eu-central-1:config-my%20config:project-9876"
assert value.project == "9876"
assert value.get("config") == "my config"
assert value.branch == "default"
assert kid.parse("KID:s:project-1").to_dict() == {"stack": "s", "branch": "default", "project": "1"}
```

## API

- Module functions: `validate(text)`, `parse(text)`, `canonicalize(text)`,
  `is_canonical(text)`, `encode_value(raw)`. Predicates return booleans for string inputs.
- `Kid.parse(text)` and `Kid.create(stack, entities)` produce immutable
  identifiers; `Kid(text)` is the same as `Kid.parse(text)`. `parse()` is the only
  way from text to a `Kid`: it validates fully and returns the canonical identity.
- Properties: `stack`, `maintainer`, `organization`, `project`, `branch`.
  Missing entities return `None`; numeric values remain strings. `branch` is never
  `None`: a KID without a branch is on the default branch and reports `"default"`.
- Methods: `get(entity)`, `has(entity)`, `entities()` (a decoded copy in canonical
  order), `encoded_entities()` (the encoded values of the canonical form, lossless), `to_dict()` (`stack` first, then `entities()`; the counterpart of SPEC
  `toArray()`), `is_wildcard(entity)`, `is_pattern()`.
- `str(value)` returns the canonical KID; equality and hashing use that string.
  Foreign-type equality returns `NotImplemented`. KIDs can be dictionary keys.
- `pickle` and `copy` round-trip through the canonical string and re-validate on
  load, so a forged payload raises `InvalidKidError`. `json.dumps(value)` raises
  `TypeError` as for any object; serialize `str(value)` instead.
- `Entity` is a `StrEnum` of the launch catalog (`MAINTAINER`, `ORG`, `PROJECT`,
  `BRANCH`, `BUCKET`, `TABLE`, `COMPONENT`, `CONFIG`, `ROW`, `WORKSPACE`, `USER`,
  `APP`, `STREAM`, `SERVICE_ACCOUNT`, `ROLE`). Plain strings also support the open catalog. `DEFAULT_BRANCH`
  is the `"default"` fallback value.

`create()` accepts decoded string values and integers, which it converts to their
decimal string form (`{"project": 9876}` equals `{"project": "9876"}`; `-1` becomes
`"-1"` and fails the numeric entity sets). `bool` is an `int` subclass in Python but
is rejected like floats, bytes, `None` and containers (`bad_value`). Numeric IDs of
`maintainer`, `org`, `project`, `branch`, `user`, `workspace` and `serviceaccount`
must not have leading zeros (`bad_value`);
`0` is accepted. Open-catalog values keep their characters as given. An integer
beyond CPython's int-to-str digit limit is rendered anyway and fails the usual
checks in order, ending in `too_long`. `dataclasses.asdict()` is
not a serialization of a `Kid`; use `str(value)`.

When the KID carries no branch, every reader falls back to the default branch:
`branch`, `get("branch")`, `entities()` and `to_dict()` report `"default"` and
`has("branch")` is `True`. The canonical string stays without `branch-default`, so
identity does not change. `entities()` and `to_dict()` iterate in canonical order.
The entity name `stack` is reserved; `entities` and `constructor` are ordinary open
names.

Validation failures raise `InvalidKidError(ValueError)` with `code` and `segment`
(`None` when not applicable). Codes and precedence are defined in SPEC §8.
The stack must be a string, without coercion. Lengths are UTF-8 bytes:
255 per raw value and 2048 per serialized KID. Lone surrogates are `bad_utf8`;
their surrogate-pass byte length is checked first during creation. Bytes objects
are not strings and produce `bad_value`. Text entry points require `str`.

The shared UTF-8 surrogate fixtures are loaded with `surrogatepass`. The raw `0xFF`
creation and `encode_value()` fixtures cannot exist in a Python `str` and are
explicitly skipped, as is any fixture marked `skip: ["python"]`; tests separately verify bytes rejection,
lone surrogates, and length precedence. All encoded invalid-UTF-8 parsing vectors run
unchanged.

## Patterns and round trips

`*` as a whole raw value passed to `create()` is a wildcard. Read a literal star
with `Kid.parse("KID:s:config-%2A")`; both decode to `*`, but only the wildcard
sets `is_wildcard()`. `branch-dev` sets `is_pattern()` without being a wildcard.
Use `Kid.parse(str(value))` for a lossless round trip: rebuilding from
`entities()` or `to_dict()` turns a literal star into a wildcard. No Unicode
normalization occurs.

`encoded_entities()` is the lossless view: it returns the values exactly as the
canonical form carries them, in canonical order, so a literal `%2A` and the wildcard
`*` stay distinct. It has no default-branch fallback (`branch` is present only when the
canonical form carries it), unlike `has("branch")` and `get("branch")`.

`encode_value(raw)` encodes one raw value with the SPEC §5 table and the value checks
`create()` applies to an open entity: it accepts `str` or `int`, rejects an empty value
(`bad_value`), more than 255 bytes (`too_long`) and lone surrogates (`bad_utf8`), and
its errors carry `segment=None`. A whole `*` becomes `%2A`, so it builds a literal star:

```python
kid.encode_value("*")                                # "%2A"
Kid.parse("KID:s:config-" + kid.encode_value("*"))   # KID:s:config-%2A
Kid.parse("KID:s:config-%2A").encoded_entities()     # {"config": "%2A"}
```

`parse()` still validates the assembled text, so `project-%2A` is `bad_value`.

Producers omit the default branch rather than writing its numeric ID. The library
validates syntax offline; it does not match patterns or check object existence.
