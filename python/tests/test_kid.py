import copy
import itertools
import json
import pickle
import unittest
from dataclasses import FrozenInstanceError
from pathlib import Path

import keboola_identifier as kid
from keboola_identifier import DEFAULT_BRANCH, Entity, InvalidKidError, Kid


VECTORS = json.loads((Path(__file__).resolve().parents[2] / "spec/test-vectors.json").read_text())


class ErrorAssertions(unittest.TestCase):
    def assert_error(self, operation, code, segment):
        with self.assertRaises(InvalidKidError) as raised:
            operation()
        self.assertEqual(code, raised.exception.code)
        self.assertEqual(segment, raised.exception.segment)


class SharedVectorsTest(ErrorAssertions):
    def test_valid(self):
        for case in VECTORS["valid"]:
            with self.subTest(input=case["input"]):
                value = Kid.parse(case["input"])
                self.assertTrue(kid.validate(case["input"]))
                self.assertEqual(value, kid.parse(case["input"]))
                self.assertEqual(case["canonical"], str(value))
                self.assertEqual(case["canonical"], kid.canonicalize(case["input"]))
                self.assertEqual(case["isCanonical"], kid.is_canonical(case["input"]))
                self.assertEqual(case["array"]["stack"], value.stack)
                self.assertEqual(list(case["array"].items()), list(value.to_dict().items()), "stack, then canonical order")
                self.assertEqual(list(case["entities"].items()), list(value.entities().items()))
                for name in set(case["array"]) | {"missing", "constructor"}:
                    self.assertEqual(case["entities"].get(name), value.get(name))
                    self.assertEqual(name in case["entities"], value.has(name))
                    self.assertEqual(name in case["wildcards"], value.is_wildcard(name))
                for name, accessor in [("maintainer", "maintainer"), ("org", "organization"), ("project", "project")]:
                    self.assertEqual(case["entities"].get(name), getattr(value, accessor))
                self.assertEqual(case["branch"], value.branch)
                self.assertEqual(case["isPattern"], value.is_pattern())
                self.assertEqual(value, Kid.parse(str(value)))
                self.assertEqual(hash(str(value)), hash(value))
                fields = value.to_dict()
                del fields["stack"]
                # A decoded literal `*` (%2A) becomes a wildcard in create(); that round trip is documented as lossy.
                literal_star = any(raw == "*" and name not in case["wildcards"] for name, raw in fields.items())
                self.assertEqual(not literal_star, value == Kid.create(value.stack, fields))
                copy = value.entities()
                copy["config"] = "changed"
                value.to_dict()["stack"] = "changed"
                self.assertEqual(case["canonical"], str(value))

    def test_invalid(self):
        for case in VECTORS["invalid"]:
            with self.subTest(input=case["input"]):
                self.assertFalse(kid.validate(case["input"]))
                self.assertFalse(kid.is_canonical(case["input"]))
                for operation in (kid.parse, Kid.parse, Kid, kid.canonicalize):
                    self.assert_error(lambda: operation(case["input"]), case["code"], case["segment"])

    def test_create(self):
        for case in VECTORS["create"]:
            with self.subTest(case=case.get("note", case["canonical"])):
                value = Kid.create(case["stack"], case["entities"])
                self.assertEqual(case["canonical"], str(value))
                self.assertTrue(kid.is_canonical(str(value)))

    def test_create_invalid(self):
        for index, case in enumerate(VECTORS["createInvalid"]):
            with self.subTest(index=index, note=case.get("note")):
                if "python" in case.get("skip", []):
                    self.skipTest("Fixture marked as unrepresentable in Python")
                if "entitiesHex" in case:
                    try:
                        entities = {name: bytes.fromhex(raw).decode("utf-8", "surrogatepass") for name, raw in case["entitiesHex"].items()}
                        segment = bytes.fromhex(case["segmentHex"]).decode("utf-8", "surrogatepass")
                    except UnicodeDecodeError:
                        # Arbitrary bytes are not Python str; covered as bytes rejection below.
                        self.skipTest("Raw 0xFF is not representable in Python str")
                else:
                    entities, segment = case["entities"], case["segment"]
                self.assert_error(lambda: Kid.create(case["stack"], entities), case["code"], segment)


class PythonTest(ErrorAssertions):
    def test_unicode_and_types(self):
        # Built by concatenation: Python 3.14 joins adjacent surrogate escapes in one literal into U+10000.
        for raw in ("\ud800", "\udfff", "\ud800" + "\udc00"):
            self.assert_error(lambda: Kid.create("s", {"config": raw}), "bad_utf8", "config-" + raw)
        self.assertEqual("KID:s:config-%F0%90%80%80", str(Kid.create("s", {"config": "\U00010000"})))
        raw = "x" * 253 + "\ud800"
        self.assert_error(lambda: Kid.create("s", {"config": raw}), "too_long", "config-" + raw)
        for stack in (1, True, None, b"s", [], {}):
            self.assert_error(lambda: Kid.create(stack, {"Bad": ""}), "bad_stack", None)
        for raw in (b"\xff", [], {}, False, True, 1.0, None):
            self.assert_error(lambda: Kid.create("s", {"config": raw}), "bad_value", None)
        self.assert_error(lambda: Kid.create("s", {1: "x"}), "bad_segment", "1-x")
        self.assert_error(lambda: Kid.create("s", {"z": "", "Bad": "x"}), "bad_value", "z-")

    def test_integer_values_are_converted_to_decimal_strings(self):
        value = Kid.create("s", {"project": 9876, "org": 0, "config": 42})
        self.assertEqual("KID:s:config-42:org-0:project-9876", str(value))
        self.assertEqual("9876", value.project)
        self.assertEqual(value, Kid.create("s", {"project": "9876", "org": "0", "config": "42"}))
        # A negative integer becomes a string with a minus sign and is validated as such.
        self.assert_error(lambda: Kid.create("s", {"project": -1}), "bad_value", "project--1")
        self.assertEqual("KID:s:config--1", str(Kid.create("s", {"config": -1})))
        self.assertEqual("KID:s:project-3", str(Kid.create("s", {"project": Entity.PROJECT and 3})))
        # bool is an int subclass in Python but is not an integer value here.
        self.assert_error(lambda: Kid.create("s", {"project": True}), "bad_value", None)

    def test_default_branch_falls_back_in_every_reader(self):
        value = Kid.parse("KID:s:project-1:branch-default:config-my%20config")
        self.assertEqual("KID:s:config-my%20config:project-1", str(value))
        self.assertEqual(
            [("stack", "s"), ("branch", DEFAULT_BRANCH), ("config", "my config"), ("project", "1")],
            list(value.to_dict().items()),
        )
        self.assertEqual([("branch", "default"), ("config", "my config"), ("project", "1")], list(value.entities().items()))
        self.assertEqual("default", value.branch)
        self.assertEqual("default", value.get("branch"))
        self.assertTrue(value.has("branch"))
        self.assertFalse(value.is_wildcard("branch"))
        self.assertFalse(value.is_pattern())
        self.assertEqual(value, Kid.parse("KID:s:config-my%20config:project-1"))
        self.assertEqual(value, Kid.create("s", value.entities()))
        self.assertEqual("555", Kid.parse("KID:s:branch-555").branch)
        self.assertEqual("dev", Kid.parse("KID:s:branch-dev").to_dict()["branch"])
        self.assertEqual("*", Kid.parse("KID:s:branch-*").get("branch"))

    def test_leading_zeros_are_rejected_not_normalized(self):
        self.assert_error(lambda: Kid.create("s", {"project": "001"}), "bad_value", "project-001")
        self.assert_error(lambda: Kid.parse("KID:s:project-01"), "bad_value", "project-01")
        self.assert_error(lambda: Kid.create("s", {"branch": "007"}), "bad_value", "branch-007")
        self.assertEqual("0", Kid.create("s", {"project": "0"}).project)
        self.assertEqual("10", Kid.parse("KID:s:org-10").organization)
        # Open-catalog entities keep their value bytes as given.
        self.assertEqual("007", Kid.create("s", {"config": "007"}).get("config"))

    def test_pickle_round_trips_through_canonical_form(self):
        value = Kid.parse("KID:s:project-1:branch-default:config-my%20config")
        restored = pickle.loads(pickle.dumps(value))
        self.assertEqual(value, restored)
        self.assertEqual("KID:s:config-my%20config:project-1", str(restored))
        self.assertEqual(value, copy.deepcopy(value))
        # A forged payload must not bypass validation.
        forged = pickle.dumps(value).replace(b"KID:s:config-my%20config:project-1", b"KID:s:config-my%20config:project-a")
        self.assert_error(lambda: pickle.loads(forged), "bad_value", "project-a")
        with self.assertRaises(TypeError):
            json.dumps(value)

    def test_immutable_and_equality(self):
        entities = {Entity.PROJECT: "1"}
        value = Kid.create("s", entities)
        entities[Entity.PROJECT] = "2"
        with self.assertRaises(FrozenInstanceError):
            value._canonical = "KID:other"
        self.assertEqual("1", value.project)
        self.assertIs(NotImplemented, value.__eq__("KID:s:project-1"))
        self.assertEqual("found", {value: "found"}[Kid.parse(str(value))])
        literal = Kid.parse("KID:s:config-%2A")
        wildcard = Kid.create("s", literal.entities())
        self.assertNotEqual(literal, wildcard)
        self.assertFalse(literal.is_pattern())
        self.assertTrue(wildcard.is_pattern())

    def test_permutations(self):
        fields = [("project", "1"), ("org", "2"), ("branch", "default"), ("maintainer", "3"), ("constructor", "x"), ("bucket", "in.c-main")]
        expected = Kid.create("s", dict(fields))
        for order in itertools.permutations(fields):
            text = "KID:s" + "".join(f":{name}-{raw}" for name, raw in order)
            self.assertEqual(expected, Kid.parse(text))
            self.assertEqual(["stack", *sorted(name for name, _ in order)], list(Kid.parse(text).to_dict()))
            self.assertEqual(expected, Kid.create("s", dict(order)))

    def test_ascii_encoding(self):
        safe = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-"
        for byte in range(128):
            raw = "x" + chr(byte)
            encoded = "x" + (chr(byte) if chr(byte) in safe else f"%{byte:02X}")
            value = Kid.create("s", {"config": raw})
            self.assertEqual("KID:s:config-" + encoded, str(value))
            self.assertEqual(raw, value.get("config"))
