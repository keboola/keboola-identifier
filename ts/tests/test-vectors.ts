import assert from "node:assert/strict";
import {readFileSync} from "node:fs";
import {test} from "node:test";
import {fileURLToPath} from "node:url";
import {dirname, resolve} from "node:path";
import {InvalidKidError, Kid} from "../src/index.js";

const here = dirname(fileURLToPath(import.meta.url));
const vectors = JSON.parse(readFileSync(resolve(here, "../../../spec/test-vectors.json"), "utf8"));

for (const entry of vectors.valid) test(`valid ${entry.input.slice(0, 60)}`, () => {
  assert.deepEqual(Kid.parse(entry.input), entry.parse);
  const kid = Kid.fromString(entry.input);
  assert.equal(kid.toString(), entry.canonical);
  assert.deepEqual(kid.entities(), entry.entities);
  assert.equal(kid.branch(), entry.branch ?? undefined);
  assert.equal(kid.isPattern(), entry.isPattern);
  assert.deepEqual(Object.keys(entry.entities).filter((e) => kid.isWildcard(e)), entry.wildcards);
  assert.equal(Kid.validate(entry.input), true);
  assert.equal(Kid.isCanonical(entry.input), entry.isCanonical);
});

for (const entry of vectors.invalid) test(`invalid ${entry.input.slice(0, 60)}`, () => {
  assert.equal(Kid.validate(entry.input), false);
  assert.equal(Kid.isCanonical(entry.input), false);
  assert.throws(() => Kid.fromString(entry.input), (error) => {
    assert.ok(error instanceof InvalidKidError);
    assert.equal(error.code, entry.code);
    assert.equal(error.segment, entry.segment ?? undefined);
    return true;
  });
});

for (const entry of vectors.create) test(`create ${entry.canonical.slice(0, 60)}`, () => {
  assert.equal(Kid.create(entry.stack, entry.entities).toString(), entry.canonical);
});

for (const entry of vectors.createInvalid) test(`create invalid ${entry.code}`, () => {
  if (entry.skip?.includes("typescript")) return;
  assert.throws(() => Kid.create(entry.stack, entry.entities), (error) => {
    assert.ok(error instanceof InvalidKidError);
    assert.equal(error.code, entry.code);
    assert.equal(error.segment, entry.segment ?? undefined);
    return true;
  });
});

test("equality", () => {
  assert.equal(Kid.fromString("KID:s:p-1:o-2").equals(Kid.fromString("KID:s:o-2:p-1")), true);
});
