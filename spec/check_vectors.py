#!/usr/bin/env python3
"""Check deterministic generation and preserve every original snapshot vector."""
import json
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parent.parent
vectors = root / "spec/test-vectors.json"
expected = vectors.read_bytes()
subprocess.run([sys.executable, str(root / "spec/generate_vectors.py")], check=True)
if vectors.read_bytes() != expected:
    raise SystemExit("Vectors are stale: regenerate and commit spec/test-vectors.json")
original = json.loads((root / "handoff/pr1-reimplementation/spec/test-vectors.json").read_text(encoding="utf-8"))
current = json.loads(expected)
for key in ("valid", "invalid", "create", "createInvalid"):
    if current[key][:len(original[key])] != original[key]:
        raise SystemExit(f"Original snapshot cases changed: {key}")
print("OK: deterministic regeneration; all original PR #1 cases preserved")
