import itertools
import json
import unittest
from dataclasses import FrozenInstanceError
from pathlib import Path

import keboola_identifier as kid
from keboola_identifier import Entity, InvalidKidError, Kid


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
                value = Kid.from_string(case["input"])
                self.assertTrue(kid.validate(case["input"]))
                self.assertEqual(list(case["parse"].items()), list(kid.parse(case["input"]).items()))
                self.assertEqual(case["canonical"], str(value))
                self.assertEqual(case["canonical"], kid.canonicalize(case["input"]))
                self.assertEqual(case["isCanonical"], kid.is_canonical(case["input"]))
                self.assertEqual(case["parse"]["stack"], value.stack)
                self.assertEqual(case["entities"], value.entities())
                for name in set(case["parse"]) | {"missing", "constructor"}:
                    self.assertEqual(case["entities"].get(name), value.get(name))
                    self.assertEqual(name in case["entities"], value.has(name))
                    self.assertEqual(name in case["wildcards"], value.is_wildcard(name))
                for name, accessor in [("maintainer", "maintainer"), ("org", "organization"), ("project", "project"), ("branch", "branch")]:
                    self.assertEqual(case["entities"].get(name), getattr(value, accessor))
                self.assertEqual(case["isPattern"], value.is_pattern())
                self.assertEqual(value, Kid.from_string(str(value)))
                self.assertEqual(hash(str(value)), hash(value))
                copy = value.entities()
                copy["config"] = "changed"
                self.assertEqual(case["canonical"], str(value))

    def test_invalid(self):
        for case in VECTORS["invalid"]:
            with self.subTest(input=case["input"]):
                self.assertFalse(kid.validate(case["input"]))
                self.assertFalse(kid.is_canonical(case["input"]))
                for operation in (kid.parse, Kid.from_string, kid.canonicalize):
                    self.assert_error(lambda: operation(case["input"]), case["code"], case["segment"])

    def test_create(self):
        for case in VECTORS["create"]:
            with self.subTest(case=case.get("note", case["canonical"])):
                value = Kid.create(case["stack"], case["entities"])
                self.assertEqual(case["canonical"], str(value))
                self.assertTrue(kid.is_canonical(str(value)))

    def test_create_invalid(self):
        for index, case in enumerate(VECTORS["createInvalid"]):
            with self.subTest(index=index):
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
        for raw in ("\ud800", "\udfff", "\ud800\udc00"):
            self.assert_error(lambda: Kid.create("s", {"config": raw}), "bad_utf8", "config-" + raw)
        raw = "x" * 253 + "\ud800"
        self.assert_error(lambda: Kid.create("s", {"config": raw}), "too_long", "config-" + raw)
        for stack in (1, True, None, b"s", [], {}):
            self.assert_error(lambda: Kid.create(stack, {"Bad": ""}), "bad_stack", None)
        for raw in (b"\xff", [], {}, False, 1.0):
            self.assert_error(lambda: Kid.create("s", {"config": raw}), "bad_value", None)
        self.assert_error(lambda: Kid.create("s", {1: "x"}), "bad_segment", "1-x")
        self.assert_error(lambda: Kid.create("s", {"z": "", "Bad": "x"}), "bad_value", "z-")

    def test_immutable_and_equality(self):
        entities = {Entity.PROJECT: "1"}
        value = Kid.create("s", entities)
        entities[Entity.PROJECT] = "2"
        with self.assertRaises(FrozenInstanceError):
            value._canonical = "KID:other"
        self.assertEqual("1", value.project)
        self.assertIs(NotImplemented, value.__eq__("KID:s:project-1"))
        self.assertEqual("found", {value: "found"}[Kid.from_string(str(value))])
        literal = Kid.from_string("KID:s:config-%2A")
        wildcard = Kid.create("s", literal.entities())
        self.assertNotEqual(literal, wildcard)
        self.assertFalse(literal.is_pattern())
        self.assertTrue(wildcard.is_pattern())

    def test_permutations(self):
        fields = [("project", "1"), ("org", "2"), ("branch", "default"), ("maintainer", "3"), ("constructor", "x"), ("bucket", "in.c-main")]
        expected = Kid.create("s", dict(fields))
        for order in itertools.permutations(fields):
            text = "KID:s" + "".join(f":{name}-{raw}" for name, raw in order)
            self.assertEqual(["stack", *(name for name, _ in order)], list(kid.parse(text)))
            self.assertEqual(expected, Kid.from_string(text))
            self.assertEqual(expected, Kid.create("s", dict(order)))

    def test_ascii_encoding(self):
        safe = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-"
        for byte in range(128):
            raw = "x" + chr(byte)
            encoded = "x" + (chr(byte) if chr(byte) in safe else f"%{byte:02X}")
            value = Kid.create("s", {"config": raw})
            self.assertEqual("KID:s:config-" + encoded, str(value))
            self.assertEqual(raw, value.get("config"))
