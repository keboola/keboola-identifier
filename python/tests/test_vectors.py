import json
from pathlib import Path

import pytest

from keboola_identifier import InvalidKidError, Kid, canonicalize, is_canonical, parse, validate

VECTORS = json.loads((Path(__file__).parents[2] / "spec/test-vectors.json").read_text())


@pytest.mark.parametrize("case", VECTORS["valid"])
def test_valid(case):
    parsed = parse(case["input"])
    assert parsed.stack == case["parse"]["stack"]
    assert dict(parsed.entities) == case["parse"]["entities"]
    kid = Kid.from_string(case["input"])
    assert str(kid) == case["canonical"]
    assert kid.entities() == case["entities"]
    assert kid.branch == case["branch"]
    assert kid.is_pattern() == case["isPattern"]
    assert [e for e in case["entities"] if kid.is_wildcard(e)] == case["wildcards"]
    assert validate(case["input"])
    assert is_canonical(case["input"]) == case["isCanonical"]
    assert canonicalize(case["input"]) == case["canonical"]


@pytest.mark.parametrize("case", VECTORS["invalid"])
def test_invalid(case):
    assert not validate(case["input"])
    assert not is_canonical(case["input"])
    with pytest.raises(InvalidKidError) as raised:
        Kid.from_string(case["input"])
    assert raised.value.code == case["code"]
    assert raised.value.segment == case["segment"]


@pytest.mark.parametrize("case", VECTORS["create"])
def test_create(case):
    assert str(Kid.create(case["stack"], case["entities"])) == case["canonical"]


@pytest.mark.parametrize("case", VECTORS["createInvalid"])
def test_create_invalid(case):
    if "python" in case.get("skip", []):
        pytest.skip()
    with pytest.raises(InvalidKidError) as raised:
        Kid.create(case["stack"], case["entities"])
    assert raised.value.code == case["code"]
    assert raised.value.segment == case["segment"]


def test_equality_and_hash():
    left = Kid.from_string("KID:s:p-1:o-2")
    right = Kid.from_string("KID:s:o-2:p-1")
    assert left == right
    assert hash(left) == hash(right)
    assert left != "KID:s:o-2:p-1"


def test_immutable():
    kid = Kid.from_string("KID:s:p-1")
    with pytest.raises(AttributeError):
        kid._canonical = "KID:s:p-2"
