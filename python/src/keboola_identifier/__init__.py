"""KID 1.0 identifiers; see the repository SPEC.md for the normative contract."""

import re
from collections.abc import Mapping
from dataclasses import dataclass
from enum import StrEnum
from typing import TypeVar


class Entity(StrEnum):
    MAINTAINER = "maintainer"
    ORG = "org"
    PROJECT = "project"
    BRANCH = "branch"
    BUCKET = "bucket"
    TABLE = "table"
    COMPONENT = "component"
    CONFIG = "config"
    ROW = "row"
    WORKSPACE = "workspace"
    USER = "user"
    APP = "app"
    STREAM = "stream"


#: What ``branch``, ``get("branch")``, ``entities()`` and ``to_dict()`` report when a KID
#: carries no branch (SPEC §4.2).
DEFAULT_BRANCH = "default"


class InvalidKidError(ValueError):
    def __init__(self, code: str, segment: str | None = None):
        super().__init__(code)
        self.code = code
        self.segment = segment


_UNENCODED = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-"
# Mapping keys are invariant, so a dict[Entity, str] needs a str-bound type variable to type-check.
_EntityName = TypeVar("_EntityName", bound=str)


def _bytes(value: str) -> bytes:
    # Preserve malformed Unicode for length-before-UTF-8 checks.
    return value.encode("utf-8", "surrogatepass")


def _decimal(value: int) -> str:
    try:
        return str(value)
    except ValueError:
        # Beyond CPython's int-to-str digit limit (4300 by default, 640 at the lowest a caller can
        # set): render in 600-digit chunks so the usual checks still run in SPEC §8 order and
        # report the full segment.
        magnitude, chunks = abs(value), []
        while magnitude:
            magnitude, chunk = divmod(magnitude, 10**600)
            chunks.append(str(chunk))
        return ("-" if value < 0 else "") + chunks[-1] + "".join(chunk.zfill(600) for chunk in reversed(chunks[:-1]))


def _stack(stack: str) -> None:
    if not isinstance(stack, str) or len(stack) > 64 or not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", stack):
        raise InvalidKidError("bad_stack")


def _entity(entity: str, segment: str | None) -> None:
    if not isinstance(entity, str) or entity == "stack" or not re.fullmatch(r"[a-z][a-z0-9]*", entity):
        raise InvalidKidError("bad_segment", segment)


def _value(entity: str, value: str, segment: str | None) -> None:
    valid = bool(value)
    if entity in ("maintainer", "org", "project", "branch"):
        # No leading zeros: project-007 would be a second identity for project 7 (SPEC §4.1).
        valid = value == "*" or re.fullmatch(r"0|[1-9][0-9]*", value) is not None
        if entity == "branch" and value in ("dev", DEFAULT_BRANCH):
            valid = True
    if not valid:
        raise InvalidKidError("bad_value", segment)


def _decode(value: str, segment: str | None = None) -> str:
    raw = bytearray()
    i = 0
    while i < len(value):
        if value[i] == "%":
            hex_value = value[i + 1:i + 3]
            if not re.fullmatch(r"[0-9A-F]{2}", hex_value):
                raise InvalidKidError("non_canonical_encoding", segment)
            byte = int(hex_value, 16)
            if byte in _UNENCODED:
                raise InvalidKidError("non_canonical_encoding", segment)
            raw.append(byte)
            i += 3
        else:
            raw.append(ord(value[i]))
            i += 1
    try:
        decoded = raw.decode("utf-8")
    except UnicodeDecodeError:
        raise InvalidKidError("bad_utf8", segment) from None
    if len(raw) > 255:
        raise InvalidKidError("too_long", segment)
    return decoded


def _serialize(stack: str, entities: dict[str, str]) -> str:
    return "KID:" + stack + "".join(
        f":{name}-{entities[name]}" for name in sorted(entities)
        if not (name == "branch" and entities[name] == DEFAULT_BRANCH)
    )


def _canonicalize(text: str) -> str:
    if not text.startswith("KID:"):
        raise InvalidKidError("bad_prefix")
    if len(_bytes(text)) > 2048:
        raise InvalidKidError("too_long")
    stack, *segments = text[4:].split(":")
    _stack(stack)
    entities: dict[str, str] = {}
    for segment in segments:
        if not segment:
            raise InvalidKidError("empty_segment", segment)
        entity, sep, value = segment.partition("-")
        if not sep:
            raise InvalidKidError("bad_segment", segment)
        _entity(entity, segment)
        if entity in entities:
            raise InvalidKidError("duplicate_entity", segment)
        _value(entity, value, segment)
        if value != "*" and not re.fullmatch(r"[A-Za-z0-9._%\-]+", value):
            raise InvalidKidError("bad_value", segment)
        _decode(value, segment)
        entities[entity] = value
    return _serialize(stack, entities)


def validate(text: str) -> bool:
    try:
        Kid(text)
        return True
    except InvalidKidError:
        return False


def parse(text: str) -> "Kid":
    """Run the full validation sequence on text and return its canonical identity."""
    return Kid(text)


def canonicalize(text: str) -> str:
    return str(Kid(text))


def is_canonical(text: str) -> bool:
    try:
        return canonicalize(text) == text
    except InvalidKidError:
        return False


@dataclass(frozen=True, slots=True, init=False)
class Kid:
    _canonical: str

    def __init__(self, text: str):
        object.__setattr__(self, "_canonical", _canonicalize(text))

    @classmethod
    def parse(cls, text: str) -> "Kid":
        """The only way from text to a Kid: validates fully and canonicalizes."""
        return cls(text)

    @classmethod
    def create(cls, stack: str, entities: Mapping[_EntityName, str | int]) -> "Kid":
        _stack(stack)
        encoded: dict[str, str] = {}
        for entity, value in entities.items():
            # Integers become their decimal string form; bool is an int subclass but not accepted.
            if isinstance(value, int) and not isinstance(value, bool):
                value = _decimal(int(value))
            segment = f"{entity}-{value}" if isinstance(value, str) else None
            _entity(entity, segment)
            if not isinstance(value, str):
                raise InvalidKidError("bad_value")
            _value(entity, value, segment)
            raw = _bytes(value)
            if len(raw) > 255:
                raise InvalidKidError("too_long", segment)
            try:
                raw.decode("utf-8")
            except UnicodeDecodeError:
                raise InvalidKidError("bad_utf8", segment) from None
            encoded[entity] = "*" if value == "*" else "".join(
                chr(byte) if byte in _UNENCODED else f"%{byte:02X}" for byte in raw
            )
        return cls(_serialize(stack, encoded))

    @property
    def stack(self) -> str:
        return self._canonical.split(":", 2)[1]

    @property
    def maintainer(self) -> str | None:
        return self.get("maintainer")

    @property
    def organization(self) -> str | None:
        return self.get("org")

    @property
    def project(self) -> str | None:
        return self.get("project")

    @property
    def branch(self) -> str:
        """Never None: a KID without a branch is on the default branch (SPEC §4.2)."""
        return _decode(self._encoded()["branch"])

    def _encoded(self) -> dict[str, str]:
        """Canonical order; an absent branch reads as the default branch (SPEC §4.2)."""
        entities = dict(segment.split("-", 1) for segment in self._canonical.split(":")[2:])
        if "branch" not in entities:
            entities["branch"] = DEFAULT_BRANCH
            entities = dict(sorted(entities.items()))
        return entities

    def get(self, entity: str) -> str | None:
        value = self._encoded().get(entity)
        return None if value is None else _decode(value)

    def has(self, entity: str) -> bool:
        return entity in self._encoded()

    def entities(self) -> dict[str, str]:
        """Decoded copy in canonical order, with branch reported as default when absent."""
        return {entity: _decode(value) for entity, value in self._encoded().items()}

    def to_dict(self) -> dict[str, str]:
        """stack first, then entities(); the counterpart of toArray() in SPEC §9."""
        return {"stack": self.stack, **self.entities()}

    def is_wildcard(self, entity: str) -> bool:
        return self._encoded().get(entity) == "*"

    def is_pattern(self) -> bool:
        return self.branch == "dev" or "*" in self._encoded().values()

    def __str__(self) -> str:
        return self._canonical

    def __hash__(self) -> int:
        return hash(self._canonical)

    def __reduce__(self) -> tuple[type["Kid"], tuple[str]]:
        # Pickle stores the canonical string and rebuilds through the constructor, so a forged or
        # stale payload is re-validated rather than restored unchecked.
        return (Kid, (self._canonical,))
