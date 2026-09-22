"""Keboola Identifier implementation following SPEC.md 1.0."""

from __future__ import annotations

from dataclasses import dataclass
from enum import StrEnum
import re
from types import MappingProxyType
from typing import Mapping

MAX_KID_BYTES = 2048
MAX_VALUE_BYTES = 255
HEAD = ("m", "o", "p", "b")
UNENCODED = frozenset(b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-")
STACK_RE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
ENTITY_RE = re.compile(r"^[a-z][a-z0-9]*$")
HEX = frozenset("0123456789ABCDEF")


class Entity(StrEnum):
    MAINTAINER = "m"
    ORGANIZATION = "o"
    PROJECT = "p"
    BRANCH = "b"
    BUCKET = "bucket"
    TABLE = "table"
    COMPONENT = "component"
    CONFIG = "config"
    ROW = "row"
    WORKSPACE = "workspace"
    USER = "user"
    APP = "app"
    STREAM = "stream"


class InvalidKidError(ValueError):
    def __init__(self, code: str, segment: str | None = None, message: str | None = None):
        self.code = code
        self.segment = segment
        super().__init__(message or f"Invalid KID: {code}" + (f" ({segment})" if segment is not None else ""))


@dataclass(frozen=True)
class ParsedKid:
    stack: str
    entities: Mapping[str, str]


def _fail(code: str, segment: str | None = None) -> None:
    raise InvalidKidError(code, segment)


def _valid_stack(stack: object) -> bool:
    return isinstance(stack, str) and len(stack) <= 64 and STACK_RE.fullmatch(stack) is not None


def _valid_short(entity: str, value: str) -> bool:
    if entity in ("m", "o", "p"):
        return value == "*" or (value.isascii() and value.isdigit())
    if entity == "b":
        return value in ("*", "dev", "default") or (value.isascii() and value.isdigit())
    return True


def _decode(value: str, segment: str) -> tuple[str, bytes]:
    raw = bytearray()
    i = 0
    while i < len(value):
        char = value[i]
        if char == "%":
            if i + 2 >= len(value) or value[i + 1] not in HEX or value[i + 2] not in HEX:
                _fail("non_canonical_encoding", segment)
            byte = int(value[i + 1:i + 3], 16)
            if byte in UNENCODED:
                _fail("non_canonical_encoding", segment)
            raw.append(byte)
            i += 3
        else:
            raw.append(ord(char))  # 4d already restricted this to ASCII
            i += 1
    try:
        decoded = bytes(raw).decode("utf-8", errors="strict")
    except UnicodeDecodeError:
        _fail("bad_utf8", segment)
    return decoded, bytes(raw)


def parse(text: str) -> ParsedKid:
    if not isinstance(text, str) or not text.startswith("KID:"):
        _fail("bad_prefix")
    try:
        text_size = len(text.encode("utf-8", errors="strict"))
    except UnicodeEncodeError:
        # A Python surrogate cannot be valid encoded KID text. It cannot pass value syntax,
        # but handling it here also keeps the byte-limit operation total.
        text_size = len(text)
    if text_size > MAX_KID_BYTES:
        _fail("too_long")
    parts = text.split(":")
    stack = parts[1]
    if not _valid_stack(stack):
        _fail("bad_stack")

    entities: dict[str, str] = {}
    for segment in parts[2:]:
        if segment == "":
            _fail("empty_segment", segment)
        hyphen = segment.find("-")
        entity = segment[:hyphen] if hyphen >= 0 else ""
        if hyphen < 0 or ENTITY_RE.fullmatch(entity) is None:
            _fail("bad_segment", segment)
        if entity in entities:
            _fail("duplicate_entity", segment)
        value = segment[hyphen + 1:]
        if value == "" or not _valid_short(entity, value):
            _fail("bad_value", segment)
        if entity not in HEAD and value != "*" and re.fullmatch(r"[A-Za-z0-9._%\-]+", value) is None:
            _fail("bad_value", segment)
        decoded, raw = _decode(value, segment)
        if len(raw) > MAX_VALUE_BYTES:
            _fail("too_long", segment)
        # Keep the encoded spelling; decoding above is validation only.
        entities[entity] = value
    return ParsedKid(stack, MappingProxyType(entities))


def _ordered(entities: Mapping[str, str]) -> list[str]:
    return [e for e in HEAD if e in entities] + sorted(e for e in entities if e not in HEAD)


def _canonical_from_encoded(stack: str, entities: Mapping[str, str]) -> str:
    kept = {e: v for e, v in entities.items() if not (e == "b" and v == "default")}
    return ":".join(["KID", stack, *(f"{e}-{kept[e]}" for e in _ordered(kept))])


def _encode(raw: str) -> str:
    try:
        data = raw.encode("utf-8", errors="strict")
    except UnicodeEncodeError:
        _fail("bad_utf8")
    return "".join(chr(b) if b in UNENCODED else f"%{b:02X}" for b in data)


class Kid:
    __slots__ = ("_canonical", "_stack", "_encoded")

    def __init__(self, text: str):
        parsed = parse(text)
        canonical = _canonical_from_encoded(parsed.stack, parsed.entities)
        canonical_parsed = parse(canonical)
        object.__setattr__(self, "_canonical", canonical)
        object.__setattr__(self, "_stack", canonical_parsed.stack)
        object.__setattr__(self, "_encoded", MappingProxyType(dict(canonical_parsed.entities)))

    def __setattr__(self, name: str, value: object) -> None:
        raise AttributeError("Kid is immutable")

    @classmethod
    def from_string(cls, text: str) -> "Kid":
        parsed = parse(text)
        return cls(_canonical_from_encoded(parsed.stack, parsed.entities))

    @classmethod
    def create(cls, stack: str, entities: Mapping[str, str]) -> "Kid":
        if not _valid_stack(stack):
            _fail("bad_stack")
        encoded: dict[str, str] = {}
        for entity, value in entities.items():
            segment = f"{entity}-{value}" if isinstance(value, str) else None
            if not isinstance(entity, str) or ENTITY_RE.fullmatch(entity) is None:
                _fail("bad_segment", segment)
            if entity in encoded:
                _fail("duplicate_entity", segment)
            if not isinstance(value, str):
                _fail("bad_value")
            segment = f"{entity}-{value}"
            if value == "" or not _valid_short(entity, value):
                _fail("bad_value", segment)
            try:
                raw_size = len(value.encode("utf-8", errors="strict"))
            except UnicodeEncodeError:
                _fail("bad_utf8", segment)
            if raw_size > MAX_VALUE_BYTES:
                _fail("too_long", segment)
            encoded[entity] = "*" if value == "*" else _encode(value)
        canonical = _canonical_from_encoded(stack, encoded)
        if len(canonical.encode("utf-8")) > MAX_KID_BYTES:
            _fail("too_long")
        return cls(canonical)

    @classmethod
    def validate(cls, text: str) -> bool:
        return validate(text)

    @classmethod
    def parse(cls, text: str) -> ParsedKid:
        return parse(text)

    @classmethod
    def canonicalize(cls, text: str) -> str:
        return canonicalize(text)

    @classmethod
    def is_canonical(cls, text: str) -> bool:
        return is_canonical(text)

    @property
    def stack(self) -> str:
        return self._stack

    def get(self, entity: str) -> str | None:
        value = self._encoded.get(entity)
        if value is None:
            return None
        return _decode(value, f"{entity}-{value}")[0]

    def has(self, entity: str) -> bool:
        return entity in self._encoded

    def entities(self) -> dict[str, str]:
        return {entity: self.get(entity) for entity in self._encoded}  # type: ignore[misc]

    @property
    def maintainer(self) -> str | None:
        return self.get("m")

    @property
    def organization(self) -> str | None:
        return self.get("o")

    @property
    def project(self) -> str | None:
        return self.get("p")

    @property
    def branch(self) -> str | None:
        return self.get("b")

    def is_wildcard(self, entity: str) -> bool:
        return self._encoded.get(entity) == "*"

    def is_pattern(self) -> bool:
        return any(value == "*" for value in self._encoded.values()) or self._encoded.get("b") == "dev"

    def __str__(self) -> str:
        return self._canonical

    def __repr__(self) -> str:
        return f"Kid({self._canonical!r})"

    def __eq__(self, other: object) -> bool:
        if not isinstance(other, Kid):
            return NotImplemented
        return self._canonical == other._canonical

    def __hash__(self) -> int:
        return hash(self._canonical)


def validate(text: str) -> bool:
    try:
        parse(text)
        return True
    except InvalidKidError:
        return False


def from_string(text: str) -> Kid:
    return Kid.from_string(text)


def create(stack: str, entities: Mapping[str, str]) -> Kid:
    return Kid.create(stack, entities)


def canonicalize(text: str) -> str:
    return str(Kid.from_string(text))


def is_canonical(text: str) -> bool:
    try:
        return canonicalize(text) == text
    except InvalidKidError:
        return False
