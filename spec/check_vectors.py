#!/usr/bin/env python3
"""Check deterministic generation and migrated historical regression coverage."""
import json
from pathlib import Path
import subprocess
import sys

from snapshot_cases import migrated_snapshot

root = Path(__file__).resolve().parent.parent
vectors = root / "spec/test-vectors.json"
expected = vectors.read_bytes()
subprocess.run([sys.executable, str(root / "spec/generate_vectors.py")], check=True)
if vectors.read_bytes() != expected:
    raise SystemExit("Vectors are stale: regenerate and commit spec/test-vectors.json")
original = migrated_snapshot()
current = json.loads(expected)
for key in ("valid", "invalid", "create", "createInvalid"):
    if current[key][:len(original[key])] != original[key]:
        raise SystemExit(f"Migrated snapshot cases changed: {key}")
catalog = json.loads((root / "spec/entities.json").read_text(encoding="utf-8"))
if current["canonicalOrder"] != catalog["canonicalOrder"]:
    raise SystemExit("Vector serialization order differs from the current entity catalog")
print("OK: deterministic regeneration; historical cases migrated to full names and uniform order")
