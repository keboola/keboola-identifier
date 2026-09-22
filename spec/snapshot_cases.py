"""Migrate historical PR #1 fixtures to the current full-name draft.

This is test-data migration only, not an alias table for any implementation.
Values, stack names and error expectations are deliberately left untouched.
"""
import json
from pathlib import Path
import re

SNAPSHOT = Path(__file__).resolve().parent.parent / "handoff/pr1-reimplementation/spec/test-vectors.json"
RENAMES = {"m": "maintainer", "o": "org", "p": "project", "b": "branch"}


def segment(text):
    if text is None or "-" not in text:
        return text
    entity, value = text.split("-", 1)
    return RENAMES.get(entity, entity) + "-" + value


def kid_text(text):
    parts = text.split(":")
    return ":".join(parts[:2] + [segment(part) for part in parts[2:]])


def entities(values):
    return {RENAMES.get(entity, entity): value for entity, value in values.items()}


def migrated_snapshot():
    vectors = json.loads(SNAPSHOT.read_text(encoding="utf-8"))
    vectors["spec"] = "SPEC.md 1.0-draft full entity names"
    vectors["canonicalOrderHead"] = list(RENAMES.values())
    for group in ("valid", "invalid", "create", "createInvalid"):
        for case in vectors[group]:
            for field in ("input", "canonical"):
                if field in case:
                    case[field] = kid_text(case[field])
            if "segment" in case:
                case["segment"] = segment(case["segment"])
            if "entities" in case:
                case["entities"] = entities(case["entities"])
            if "parse" in case:
                case["parse"]["entities"] = entities(case["parse"]["entities"])
            if "wildcards" in case:
                case["wildcards"] = [RENAMES.get(name, name) for name in case["wildcards"]]
            if "note" in case:
                case["note"] = re.sub(r"\b[mopb]\b", lambda match: RENAMES[match[0]], case["note"])
                case["note"] = case["note"].replace("short entities", "constrained entities")
    return vectors
