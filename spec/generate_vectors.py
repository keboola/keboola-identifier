#!/usr/bin/env python3
"""Generates spec/test-vectors.json from SPEC.md rules.

The JSON file is the artifact every implementation tests against. This script is the
single place the vectors are authored, so long or byte-exact inputs (length limits,
UTF-8 edge cases) cannot be mistyped. Run: python3 spec/generate_vectors.py
"""
from __future__ import annotations

import json
from pathlib import Path

STACK = "kbc-eu-central-1"
UNENCODED = set(b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-")


def enc(raw: str) -> str:
    """Reference encoder from SPEC §5: byte loop, fixed table, uppercase hex."""
    out = []
    for byte in raw.encode("utf-8"):
        out.append(chr(byte) if byte in UNENCODED else f"%{byte:02X}")
    return "".join(out)


def valid(input_: str, canonical: str, stack: str, parse_entities: dict, entities: dict,
          *, wildcards: list[str] | None = None, is_pattern: bool = False,
          branch: str | None = None, note: str = "") -> dict:
    v = {
        "input": input_,
        "canonical": canonical,
        "parse": {"stack": stack, "entities": parse_entities},
        "entities": entities,
        "wildcards": wildcards or [],
        "isPattern": is_pattern,
        "isCanonical": input_ == canonical,
        "branch": branch,
    }
    if note:
        v["note"] = note
    return v


def invalid(input_: str, code: str, segment: str | None = None, note: str = "") -> dict:
    v = {"input": input_, "code": code, "segment": segment}
    if note:
        v["note"] = note
    return v


def kid(*segments: str, stack: str = STACK) -> str:
    return ":".join(["KID", stack, *segments])


vectors: dict = {
    "spec": "SPEC.md 1.0-draft 2026-09-22",
    "limits": {"maxKidBytes": 2048, "maxValueBytes": 255, "maxStackChars": 64},
    "canonicalOrderHead": ["m", "o", "p", "b"],
    "valid": [],
    "invalid": [],
    "create": [],
    "createInvalid": [],
}
V, I, C, CI = (vectors[k] for k in ("valid", "invalid", "create", "createInvalid"))

# ---------------------------------------------------------------- valid, canonical
V.append(valid(kid(), kid(), STACK, {}, {}, note="bare stack = identity of the stack"))
V.append(valid(kid("m-123"), kid("m-123"), STACK, {"m": "123"}, {"m": "123"}))
V.append(valid(kid("o-42"), kid("o-42"), STACK, {"o": "42"}, {"o": "42"}))
V.append(valid(kid("m-123", "o-42", "p-9876"), kid("m-123", "o-42", "p-9876"), STACK,
               {"m": "123", "o": "42", "p": "9876"}, {"m": "123", "o": "42", "p": "9876"}))
V.append(valid(kid("p-9876", "b-555"), kid("p-9876", "b-555"), STACK,
               {"p": "9876", "b": "555"}, {"p": "9876", "b": "555"}, branch="555"))
V.append(valid(kid("p-9876", "bucket-in.c-main", "table-orders"),
               kid("p-9876", "bucket-in.c-main", "table-orders"), STACK,
               {"p": "9876", "bucket": "in.c-main", "table": "orders"},
               {"p": "9876", "bucket": "in.c-main", "table": "orders"},
               note="hyphen inside a value; split on the first hyphen only"))
V.append(valid(kid("p-9876", "b-555", "bucket-in.c-main", "table-orders"),
               kid("p-9876", "b-555", "bucket-in.c-main", "table-orders"), STACK,
               {"p": "9876", "b": "555", "bucket": "in.c-main", "table": "orders"},
               {"p": "9876", "b": "555", "bucket": "in.c-main", "table": "orders"}, branch="555"))
V.append(valid(kid("p-9876", "component-keboola.ex-db-snowflake", "config-1234", "row-7"),
               kid("p-9876", "component-keboola.ex-db-snowflake", "config-1234", "row-7"), STACK,
               {"p": "9876", "component": "keboola.ex-db-snowflake", "config": "1234", "row": "7"},
               {"p": "9876", "component": "keboola.ex-db-snowflake", "config": "1234", "row": "7"}))
V.append(valid(kid("p-9876", "component-keboola.ex-db-snowflake", "config-my%20config"),
               kid("p-9876", "component-keboola.ex-db-snowflake", "config-my%20config"), STACK,
               {"p": "9876", "component": "keboola.ex-db-snowflake", "config": "my%20config"},
               {"p": "9876", "component": "keboola.ex-db-snowflake", "config": "my config"},
               note="parse() keeps the encoded value, entities()/get() decode"))
V.append(valid(kid("user-555"), kid("user-555"), STACK, {"user": "555"}, {"user": "555"}))
V.append(valid(kid("p-9876", "workspace-8812"), kid("p-9876", "workspace-8812"), STACK,
               {"p": "9876", "workspace": "8812"}, {"p": "9876", "workspace": "8812"}))
V.append(valid(kid("p-9876", "dashboard-abc-42"), kid("p-9876", "dashboard-abc-42"), STACK,
               {"p": "9876", "dashboard": "abc-42"}, {"p": "9876", "dashboard": "abc-42"},
               note="unknown entity passes, value opaque"))
V.append(valid(kid("p-1", stack="kbc-us-east-1"), kid("p-1", stack="kbc-us-east-1"),
               "kbc-us-east-1", {"p": "1"}, {"p": "1"}))
V.append(valid(kid("p-1", stack="a"), kid("p-1", stack="a"), "a", {"p": "1"}, {"p": "1"},
               note="one-character stack"))
V.append(valid(kid(stack="x" * 64), kid(stack="x" * 64), "x" * 64, {}, {}, note="stack at 64 chars"))

# patterns
V.append(valid(kid("o-42", "p-*", "bucket-*"), kid("o-42", "p-*", "bucket-*"), STACK,
               {"o": "42", "p": "*", "bucket": "*"}, {"o": "42", "p": "*", "bucket": "*"},
               wildcards=["p", "bucket"], is_pattern=True))
V.append(valid(kid("p-9876", "b-dev", "component-keboola.wr-db", "config-*"),
               kid("p-9876", "b-dev", "component-keboola.wr-db", "config-*"), STACK,
               {"p": "9876", "b": "dev", "component": "keboola.wr-db", "config": "*"},
               {"p": "9876", "b": "dev", "component": "keboola.wr-db", "config": "*"},
               wildcards=["config"], is_pattern=True, branch="dev",
               note="b-dev makes it a pattern but is not a wildcard"))
V.append(valid(kid("p-9876", "b-*", "bucket-in.c-main"), kid("p-9876", "b-*", "bucket-in.c-main"), STACK,
               {"p": "9876", "b": "*", "bucket": "in.c-main"}, {"p": "9876", "b": "*", "bucket": "in.c-main"},
               wildcards=["b"], is_pattern=True, branch="*"))
V.append(valid(kid("p-*"), kid("p-*"), STACK, {"p": "*"}, {"p": "*"}, wildcards=["p"], is_pattern=True))
V.append(valid(kid("m-*", "o-*", "p-*", "b-*"), kid("m-*", "o-*", "p-*", "b-*"), STACK,
               {"m": "*", "o": "*", "p": "*", "b": "*"}, {"m": "*", "o": "*", "p": "*", "b": "*"},
               wildcards=["m", "o", "p", "b"], is_pattern=True, branch="*"))

# literal star vs wildcard
V.append(valid(kid("p-9876", "component-x", "config-%2A"), kid("p-9876", "component-x", "config-%2A"), STACK,
               {"p": "9876", "component": "x", "config": "%2A"}, {"p": "9876", "component": "x", "config": "*"},
               wildcards=[], is_pattern=False,
               note="decoded literal * looks like a wildcard in entities(); isWildcard(config) is false"))
V.append(valid(kid("p-9876", "config-a%2Ab"), kid("p-9876", "config-a%2Ab"), STACK,
               {"p": "9876", "config": "a%2Ab"}, {"p": "9876", "config": "a*b"}))

# encoding edge cases
V.append(valid(kid("p-9876", "config-" + enc("řeší")), kid("p-9876", "config-" + enc("řeší")), STACK,
               {"p": "9876", "config": enc("řeší")}, {"p": "9876", "config": "řeší"}, note="multibyte UTF-8"))
V.append(valid(kid("p-9876", "config-" + enc("a~b+c!d(e)f'g/h:i%j")),
               kid("p-9876", "config-" + enc("a~b+c!d(e)f'g/h:i%j")), STACK,
               {"p": "9876", "config": enc("a~b+c!d(e)f'g/h:i%j")},
               {"p": "9876", "config": "a~b+c!d(e)f'g/h:i%j"},
               note="~ + ! ( ) ' / : % are all encoded; stdlib helpers disagree on several of these"))
V.append(valid(kid("p-9876", "config-A-Za_z0.9"), kid("p-9876", "config-A-Za_z0.9"), STACK,
               {"p": "9876", "config": "A-Za_z0.9"}, {"p": "9876", "config": "A-Za_z0.9"},
               note="every unencoded byte class, case preserved"))
V.append(valid(kid("p-9876", "config-" + enc("😀")), kid("p-9876", "config-" + enc("😀")), STACK,
               {"p": "9876", "config": enc("😀")}, {"p": "9876", "config": "😀"}, note="4-byte UTF-8"))
V.append(valid(kid("p-9876", "config-" + enc("́")), kid("p-9876", "config-" + enc("́")), STACK,
               {"p": "9876", "config": enc("́")}, {"p": "9876", "config": "́"},
               note="combining mark alone; no normalization, byte-wise identity"))

# valid, non-canonical
V.append(valid(kid("p-9876", "b-default", "bucket-in.c-main"), kid("p-9876", "bucket-in.c-main"), STACK,
               {"p": "9876", "b": "default", "bucket": "in.c-main"}, {"p": "9876", "bucket": "in.c-main"},
               branch=None, note="b-default is kept by parse(), dropped by canonicalization; branch() absent"))
V.append(valid(kid("b-default"), kid(), STACK, {"b": "default"}, {}, branch=None))
V.append(valid(kid("table-orders", "p-9876", "bucket-in.c-main", "o-42"),
               kid("o-42", "p-9876", "bucket-in.c-main", "table-orders"), STACK,
               {"table": "orders", "p": "9876", "bucket": "in.c-main", "o": "42"},
               {"o": "42", "p": "9876", "bucket": "in.c-main", "table": "orders"},
               note="shuffled input; parse() keeps input order, canonical reorders"))
V.append(valid(kid("zeta-1", "b-default", "alpha-2", "m-5"), kid("m-5", "alpha-2", "zeta-1"), STACK,
               {"zeta": "1", "b": "default", "alpha": "2", "m": "5"}, {"m": "5", "alpha": "2", "zeta": "1"}))
V.append(valid(kid("ab-z", "a1-x", "a-y"), kid("a-y", "a1-x", "ab-z"), STACK,
               {"ab": "z", "a1": "x", "a": "y"}, {"a": "y", "a1": "x", "ab": "z"},
               note="byte order: a < a1 < ab because 0x31 < 0x62; no locale collation"))
V.append(valid(kid("b-555", "p-9876", "o-42", "m-1"), kid("m-1", "o-42", "p-9876", "b-555"), STACK,
               {"b": "555", "p": "9876", "o": "42", "m": "1"}, {"m": "1", "o": "42", "p": "9876", "b": "555"},
               branch="555", note="frozen head order m o p b regardless of input"))
V.append(valid(kid("b-*", "p-9876", "b2-x", "a-1"), kid("p-9876", "b-*", "a-1", "b2-x"), STACK,
               {"b": "*", "p": "9876", "b2": "x", "a": "1"}, {"p": "9876", "b": "*", "a": "1", "b2": "x"},
               wildcards=["b"], is_pattern=True, branch="*",
               note="b is in the frozen head; b2 is an ordinary entity sorted alphabetically"))

# length boundaries
v255 = "a" * 255
V.append(valid(kid("p-9876", "config-" + v255), kid("p-9876", "config-" + v255), STACK,
               {"p": "9876", "config": v255}, {"p": "9876", "config": v255}, note="raw value at 255 bytes"))
v85 = "ř" * 85  # 85 * 3 bytes = 255 raw bytes -> 765 encoded
V.append(valid(kid("p-9876", "config-" + enc(v85)), kid("p-9876", "config-" + enc(v85)), STACK,
               {"p": "9876", "config": enc(v85)}, {"p": "9876", "config": v85},
               note="255 raw bytes of multibyte text = 765 encoded bytes; the cap is on raw bytes"))
head = kid(stack="s")  # "KID:s" = 5 bytes
segs = [f"{e}-{'a' * 255}" for e in "abcdefg"]  # 7 * (1 + 2 + 255) = 1806
total_so_far = len(head) + sum(1 + len(s) for s in segs)
tail_len = 2048 - total_so_far - len(":z-")
k2048 = ":".join([head, *segs, "z-" + "a" * tail_len])
assert len(k2048.encode()) == 2048, len(k2048.encode())
V.append(valid(k2048, k2048, "s", {**{e: "a" * 255 for e in "abcdefg"}, "z": "a" * tail_len},
                {**{e: "a" * 255 for e in "abcdefg"}, "z": "a" * tail_len}, note="exactly 2048 bytes"))

# ---------------------------------------------------------------- invalid
I.append(invalid("kid:kbc-eu-central-1", "bad_prefix", note="prefix is case-sensitive"))
I.append(invalid("KID", "bad_prefix"))
I.append(invalid("KIDX:s", "bad_prefix"))
I.append(invalid("", "bad_prefix"))
I.append(invalid(" KID:s", "bad_prefix"))
I.append(invalid("KID:", "bad_stack", note="empty stack"))
I.append(invalid("KID:AWS-EU:p-1", "bad_stack"))
I.append(invalid("KID:-kbc:p-1", "bad_stack", note="leading hyphen"))
I.append(invalid("KID:kbc-:p-1", "bad_stack", note="trailing hyphen"))
I.append(invalid("KID:kbc--eu:p-1", "bad_stack", note="double hyphen"))
I.append(invalid("KID:kbc_eu:p-1", "bad_stack"))
I.append(invalid("KID:kbc.eu:p-1", "bad_stack"))
I.append(invalid("KID:*:p-1", "bad_stack", note="no wildcard on stack"))
I.append(invalid("KID:" + "x" * 65, "bad_stack", note="65 chars"))
I.append(invalid("KID:s::p-1", "empty_segment", ""))
I.append(invalid("KID:s:p-1:", "empty_segment", "", note="trailing colon"))
I.append(invalid("KID:s:", "empty_segment", ""))
I.append(invalid("KID:s:p-1:x", "bad_segment", "x", note="no hyphen"))
I.append(invalid("KID:s:-abc", "bad_segment", "-abc", note="empty entity"))
I.append(invalid("KID:s:p-1:Bucket-in.c-main", "bad_segment", "Bucket-in.c-main", note="entity must be lowercase"))
I.append(invalid("KID:s:p-1:1a-b", "bad_segment", "1a-b", note="entity must start with a letter"))
I.append(invalid("KID:s:p-1:my_ent-b", "bad_segment", "my_ent-b"))
I.append(invalid("KID:s:p-1:ent%20-b", "bad_segment", "ent%20-b"))
I.append(invalid("KID:s:p-1:p-2", "duplicate_entity", "p-2"))
I.append(invalid("KID:s:bucket-a:bucket-a", "duplicate_entity", "bucket-a", note="same value still duplicate"))
I.append(invalid("KID:s:p-abc", "bad_value", "p-abc"))
I.append(invalid("KID:s:p-", "bad_value", "p-", note="empty value"))
I.append(invalid("KID:s:p-1:config-", "bad_value", "config-", note="empty value on an open entity"))
I.append(invalid("KID:s:p-*1", "bad_value", "p-*1"))
I.append(invalid("KID:s:p-1*", "bad_value", "p-1*"))
I.append(invalid("KID:s:p-%2A", "bad_value", "p-%2A", note="short entities take digits or a bare *, never an encoded star"))
I.append(invalid("KID:s:m-01x", "bad_value", "m-01x"))
I.append(invalid("KID:s:o-4.2", "bad_value", "o-4.2"))
I.append(invalid("KID:s:b-main", "bad_value", "b-main"))
I.append(invalid("KID:s:b-Default", "bad_value", "b-Default"))
I.append(invalid("KID:s:b-DEV", "bad_value", "b-DEV"))
I.append(invalid("KID:s:b-", "bad_value", "b-"))
I.append(invalid("KID:s:p-1:bucket-in.c-*", "bad_value", "bucket-in.c-*", note="partial wildcard"))
I.append(invalid("KID:s:p-1:bucket-*.c-main", "bad_value", "bucket-*.c-main"))
I.append(invalid("KID:s:p-1:bucket-**", "bad_value", "bucket-**"))
I.append(invalid("KID:s:p-1:config-my config", "bad_value", "config-my config", note="unencoded space"))
I.append(invalid("KID:s:p-1:config-a~b", "bad_value", "config-a~b", note="~ is not in the unencoded set"))
I.append(invalid("KID:s:p-1:config-a+b", "bad_value", "config-a+b"))
I.append(invalid("KID:s:p-1:config-a/b", "bad_value", "config-a/b"))
I.append(invalid("KID:s:p-1:config-ře", "bad_value", "config-ře", note="raw multibyte must be encoded"))
I.append(invalid("KID:s:p-1:config-my%20conf%69g", "non_canonical_encoding", "config-my%20conf%69g", note="%69 is the unencoded byte i"))
I.append(invalid("KID:s:p-1:config-%2a", "non_canonical_encoding", "config-%2a", note="lowercase hex"))
I.append(invalid("KID:s:p-1:config-abc%", "non_canonical_encoding", "config-abc%", note="trailing %"))
I.append(invalid("KID:s:p-1:config-%F", "non_canonical_encoding", "config-%F"))
I.append(invalid("KID:s:p-1:config-%zz", "non_canonical_encoding", "config-%zz"))
I.append(invalid("KID:s:p-1:config-%2E", "non_canonical_encoding", "config-%2E", note="encoded ."))
I.append(invalid("KID:s:p-1:config-%2D", "non_canonical_encoding", "config-%2D", note="encoded -"))
I.append(invalid("KID:s:p-1:config-%5F", "non_canonical_encoding", "config-%5F", note="encoded _"))
I.append(invalid("KID:s:p-1:config-%41", "non_canonical_encoding", "config-%41", note="encoded A"))
I.append(invalid("KID:s:p-1:config-%%20", "non_canonical_encoding", "config-%%20"))
I.append(invalid("KID:s:p-1:config-%FF", "bad_utf8", "config-%FF"))
I.append(invalid("KID:s:p-1:config-%C3", "bad_utf8", "config-%C3", note="truncated sequence"))
I.append(invalid("KID:s:p-1:config-%C3%28", "bad_utf8", "config-%C3%28", note="invalid continuation"))
I.append(invalid("KID:s:p-1:config-%ED%A0%80", "bad_utf8", "config-%ED%A0%80", note="UTF-16 surrogate encoded in UTF-8"))
I.append(invalid("KID:s:p-1:config-%C0%AF", "bad_utf8", "config-%C0%AF", note="overlong encoding of /"))
I.append(invalid("KID:s:p-1:config-%F4%90%80%80", "bad_utf8", "config-%F4%90%80%80", note="above U+10FFFF"))
I.append(invalid("KID:s:p-1:config-" + "a" * 256, "too_long", "config-" + "a" * 256, note="raw value 256 bytes; segment set"))
v86 = "ř" * 86  # 258 raw bytes
I.append(invalid("KID:s:p-1:config-" + enc(v86), "too_long", "config-" + enc(v86), note="86 three-byte chars = 258 raw bytes"))
k2049 = k2048 + "a"
I.append(invalid(k2049, "too_long", None, note="2049 bytes; whole-KID limit, no segment"))
I.append(invalid("KID:" + "x" * 2100, "too_long", None, note="length is checked before the stack"))
# precedence
I.append(invalid("KID:s:p-1:p-2:x", "duplicate_entity", "p-2", note="left to right: duplicate before the bad segment"))
I.append(invalid("KID:s:p-abc:p-2", "bad_value", "p-abc", note="first failing segment wins"))
I.append(invalid("kid:s::", "bad_prefix", note="prefix before anything else"))
I.append(invalid("KID:s:x:p-1:p-1", "bad_segment", "x"))
I.append(invalid("KID:s:p-1:config-%2a:q", "non_canonical_encoding", "config-%2a"))
I.append(invalid("KID:s:p-1:config-%2a%FF", "non_canonical_encoding", "config-%2a%FF", note="4e before 4f"))
I.append(invalid("KID:s:p-1:config-%FF" + "a" * 254, "bad_utf8", "config-%FF" + "a" * 254, note="4f before 4g"))
I.append(invalid("KID:s:p-1:config-my config:", "bad_value", "config-my config", note="value error before the trailing empty segment"))

# ---------------------------------------------------------------- create
def create(stack, entities, canonical, note=""):
    v = {"stack": stack, "entities": entities, "canonical": canonical}
    if note:
        v["note"] = note
    return v

C.append(create(STACK, {}, kid()))
C.append(create(STACK, {"p": "9876", "bucket": "in.c-main", "table": "orders"}, kid("p-9876", "bucket-in.c-main", "table-orders")))
C.append(create(STACK, {"table": "orders", "bucket": "in.c-main", "o": "42", "p": "9876"},
                kid("o-42", "p-9876", "bucket-in.c-main", "table-orders"), note="non-canonical input order"))
C.append(create(STACK, {"p": "9876", "b": "default"}, kid("p-9876"), note="b-default dropped"))
C.append(create(STACK, {"p": "9876", "b": "555"}, kid("p-9876", "b-555")))
C.append(create(STACK, {"p": "9876", "b": "dev"}, kid("p-9876", "b-dev")))
C.append(create(STACK, {"p": "*"}, kid("p-*"), note="* is always the wildcard"))
C.append(create(STACK, {"p": "9876", "config": "*"}, kid("p-9876", "config-*")))
C.append(create(STACK, {"p": "9876", "config": "my config"}, kid("p-9876", "config-my%20config")))
C.append(create(STACK, {"p": "9876", "config": "a*b"}, kid("p-9876", "config-a%2Ab"), note="star inside a value is a literal"))
C.append(create(STACK, {"p": "9876", "config": "a~b+c:d/e%f!g(h)i'j"}, kid("p-9876", "config-" + enc("a~b+c:d/e%f!g(h)i'j"))))
C.append(create(STACK, {"p": "9876", "config": "řeší"}, kid("p-9876", "config-" + enc("řeší"))))
C.append(create(STACK, {"p": "9876", "config": "😀"}, kid("p-9876", "config-" + enc("😀"))))
C.append(create(STACK, {"p": "9876", "config": "A-Za_z0.9"}, kid("p-9876", "config-A-Za_z0.9")))
C.append(create(STACK, {"p": "9876", "config": v255}, kid("p-9876", "config-" + v255), note="255 raw bytes"))
C.append(create(STACK, {"p": "9876", "config": v85}, kid("p-9876", "config-" + enc(v85)), note="255 raw bytes multibyte"))
C.append(create(STACK, {"ab": "z", "a1": "x", "a": "y"}, kid("a-y", "a1-x", "ab-z")))
C.append(create("a", {"p": "1"}, kid("p-1", stack="a")))

def create_invalid(stack, entities, code, segment=None, note="", skip=None):
    v = {"stack": stack, "entities": entities, "code": code, "segment": segment}
    if note:
        v["note"] = note
    if skip:
        v["skip"] = skip
    return v

CI.append(create_invalid("AWS", {"p": "1"}, "bad_stack"))
CI.append(create_invalid("", {"p": "1"}, "bad_stack"))
CI.append(create_invalid("x" * 65, {}, "bad_stack"))
CI.append(create_invalid(STACK, {"p": "abc"}, "bad_value", "p-abc"))
CI.append(create_invalid(STACK, {"p": ""}, "bad_value", "p-"))
CI.append(create_invalid(STACK, {"config": ""}, "bad_value", "config-"))
CI.append(create_invalid(STACK, {"b": "main"}, "bad_value", "b-main"))
CI.append(create_invalid(STACK, {"p": "*1"}, "bad_value", "p-*1"))
CI.append(create_invalid(STACK, {"Bucket": "x"}, "bad_segment", "Bucket-x"))
CI.append(create_invalid(STACK, {"bad-ent": "x"}, "bad_segment", "bad-ent-x"))
CI.append(create_invalid(STACK, {"": "x"}, "bad_segment", "-x"))
CI.append(create_invalid(STACK, {"config": "a" * 256}, "too_long", "config-" + "a" * 256, note="raw 256 bytes"))
CI.append(create_invalid(STACK, {"config": "ř" * 86}, "too_long", "config-" + "ř" * 86, note="258 raw bytes; segment carries the raw value"))
CI.append(create_invalid("s", {**{e: "a" * 255 for e in "abcdefg"}, "z": "a" * (tail_len + 1)}, "too_long", None,
                         note="canonical output would be 2049 bytes"))
CI.append(create_invalid(STACK, {"p": 9876}, "bad_value", None, note="non-string value is rejected, not coerced; segment not applicable",
                         skip=["go"]))
CI.append(create_invalid(STACK, {"config": None}, "bad_value", None, note="null is not a string", skip=["go"]))
CI.append(create_invalid(STACK, {"p": True}, "bad_value", None, note="bool is not a string", skip=["go"]))

out = Path(__file__).with_name("test-vectors.json")
out.write_text(json.dumps(vectors, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(f"{out}: {len(V)} valid, {len(I)} invalid, {len(C)} create, {len(CI)} createInvalid")
