#!/usr/bin/env python3
"""Generate current full-name vectors, retaining migrated historical coverage.

The immutable handoff JSON supplies the original cases. snapshot_cases.py migrates
entity names and expected serialization order. New cases are authored below using
the current contract. Run: python3 spec/generate_vectors.py
"""
import json
from pathlib import Path

from snapshot_cases import canonical_order, migrated_snapshot

UNENCODED = set(b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-")


def enc(raw):
    return "".join(chr(byte) if byte in UNENCODED else f"%{byte:02X}" for byte in raw.encode("utf-8"))


def valid(text, canonical, parsed, decoded, *, wildcards=None, branch=None, pattern=False, note=""):
    return {
        "input": text, "canonical": canonical,
        "parse": {"stack": "s", "entities": parsed}, "entities": decoded,
        "wildcards": wildcards or [], "isPattern": pattern,
        "isCanonical": text == canonical, "branch": branch, "note": note,
    }


def invalid(text, code, segment=None):
    return {"input": text, "code": code, "segment": segment}


def create(values, canonical):
    return {"stack": "s", "entities": values, "canonical": canonical}


vectors = migrated_snapshot()
V, I, C, CI = (vectors[key] for key in ("valid", "invalid", "create", "createInvalid"))

# Preserve opaque UTF-8 and control bytes, especially a leading or standalone BOM.
for raw in ("\ufeffx", "\ufeff", "\x00", "\r\n", "é", "e\u0301", "\uFFFD"):
    text = "KID:s:config-" + enc(raw)
    V.append(valid(text, text, {"config": enc(raw)}, {"config": raw},
                   note="opaque Unicode/control value preserved exactly"))
    C.append(create({"config": raw}, text))
text = "KID:s:constructor-x"
V.append(valid(text, text, {"constructor": "x"}, {"constructor": "x"},
               note="constructor is a valid open entity"))
C.append(create({"constructor": "x"}, text))
I.append(invalid("KID:s:constructor-x:constructor-y", "duplicate_entity", "constructor-y"))
for suffix in ("\n", "\r", "\u2028"):
    I.append(invalid("KID:s" + suffix, "bad_stack"))
    I.append(invalid("KID:s:project-1" + suffix, "bad_value", "project-1" + suffix))
    I.append(invalid("KID:s:config-x" + suffix, "bad_value", "config-x" + suffix))
    I.append(invalid("KID:s:config" + suffix + "-x", "bad_segment", "config" + suffix + "-x"))

# The input limit applies before dropping branch-default; create checks output.
boundary = {entity: "a" * 255 for entity in "acdefgh"}
prefix = "KID:s" + "".join(f":{entity}-{value}" for entity, value in boundary.items())
boundary["z"] = "a" * (2048 - len(prefix) - len(":z-"))
text = prefix + ":z-" + boundary["z"]
assert len(text.encode("utf-8")) == 2048
C.append(create({**boundary, "branch": "default"}, text))
I.append(invalid(text + ":branch-default", "too_long"))

# All entities follow the same serialization rule, regardless of their meaning.
parsed = {"zeta": "9", "branch": "555", "project": "3", "org": "2", "maintainer": "1", "alpha": "x"}
decoded = {"maintainer": "1", "org": "2", "project": "3", "branch": "555", "alpha": "x", "zeta": "9"}
canonical = "KID:s:alpha-x:branch-555:maintainer-1:org-2:project-3:zeta-9"
text = "KID:s:zeta-9:branch-555:project-3:org-2:maintainer-1:alpha-x"
V.append(valid(text, canonical, parsed, decoded, branch="555", note="uniform ASCII order for all entities"))
C.append(create(parsed, canonical))

# Apply every constraint to every named entity, not just project.
for entity in ("maintainer", "org", "project", "branch"):
    for raw in ("0", "001", "9" * 255, "*"):
        text = f"KID:s:{entity}-{raw}"
        V.append(valid(text, text, {entity: raw}, {entity: raw},
                       wildcards=[entity] if raw == "*" else [], pattern=raw == "*",
                       branch=raw if entity == "branch" else None))
        C.append(create({entity: raw}, text))
    for raw in ("abc", "%31", "%2A", "١", "1.0", "-1", ""):
        part = f"{entity}-{raw}"
        I.append(invalid("KID:s:" + part, "bad_value", part))
        CI.append({"stack": "s", "entities": {entity: raw}, "code": "bad_value", "segment": part})
    part = entity + "-" + "9" * 256
    I.append(invalid("KID:s:" + part, "too_long", part))
    CI.append({"stack": "s", "entities": {entity: "9" * 256}, "code": "too_long", "segment": part})
    I.append(invalid(f"KID:s:{entity}-1:{entity}-abc", "duplicate_entity", f"{entity}-abc"))
    if entity != "branch":
        for raw in ("dev", "default"):
            I.append(invalid(f"KID:s:{entity}-{raw}", "bad_value", f"{entity}-{raw}"))

# Bare branch-dev is a pattern even without a wildcard elsewhere.
V.append(valid("KID:s:branch-dev", "KID:s:branch-dev", {"branch": "dev"}, {"branch": "dev"},
               branch="dev", pattern=True))

# Byte boundaries with full entity names, not just generic one-letter entities.
values = {"maintainer": "1", "org": "2", "project": "3", "branch": "4"}
values.update({f"extra{i}": "x" * 255 for i in range(7)})
prefix = "KID:s" + "".join(f":{entity}-{value}" for entity, value in values.items())
values["tail"] = "x" * (2048 - len(prefix) - len(":tail-"))
text = prefix + ":tail-" + values["tail"]
assert len(text.encode("utf-8")) == 2048
V.append(valid(text, canonical_order(text), values, values, branch="4", note="2048 bytes with full entity names"))
C.append(create(values, canonical_order(text)))
I.append(invalid(text + "x", "too_long"))
CI.append({"stack": "s", "entities": {**values, "tail": values["tail"] + "x"},
           "code": "too_long", "segment": None})

out = Path(__file__).with_name("test-vectors.json")
out.write_text(json.dumps(vectors, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(f"{out}: {len(V)} valid, {len(I)} invalid, {len(C)} create, {len(CI)} createInvalid")
