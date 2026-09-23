import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { Entity, InvalidKidError, Kid } from '../src/index.js';

const vectors = JSON.parse(readFileSync(new URL('../../spec/test-vectors.json', import.meta.url)));
const errorIs = (code, segment) => error => {
  assert.ok(error instanceof InvalidKidError);
  assert.equal(error.code, code);
  assert.equal(error.segment, segment ?? undefined);
  return true;
};

for (const [index, c] of vectors.valid.entries()) {
  test(`valid #${index}`, () => {
    const kid = Kid.fromString(c.input);
    assert.equal(Kid.validate(c.input), true);
    assert.deepEqual(Kid.parse(c.input), c.parse);
    assert.deepEqual(Object.keys(Kid.parse(c.input)), Object.keys(c.parse));
    assert.equal(kid.toString(), c.canonical);
    assert.equal(String(kid), c.canonical);
    assert.equal(Kid.canonicalize(c.input), c.canonical);
    assert.equal(Kid.isCanonical(c.input), c.isCanonical);
    assert.equal(kid.stack(), c.parse.stack);
    assert.deepEqual(kid.entities(), c.entities);
    for (const name of new Set([...Object.keys(c.parse), 'missing', 'constructor'])) {
      assert.equal(kid.get(name), Object.hasOwn(c.entities, name) ? c.entities[name] : undefined);
      assert.equal(kid.has(name), Object.hasOwn(c.entities, name));
      assert.equal(kid.isWildcard(name), c.wildcards.includes(name));
    }
    for (const [name, accessor] of [['maintainer', 'maintainer'], ['org', 'organization'], ['project', 'project'], ['branch', 'branch']]) {
      assert.equal(kid[accessor](), c.entities[name]);
    }
    assert.equal(kid.isPattern(), c.isPattern);
    assert.equal(kid.equals(Kid.fromString(kid.toString())), true);
    kid.entities().config = 'changed';
    assert.equal(kid.toString(), c.canonical);
  });
}
for (const [index, c] of vectors.invalid.entries()) {
  test(`invalid #${index}`, () => {
    assert.equal(Kid.validate(c.input), false);
    assert.equal(Kid.isCanonical(c.input), false);
    for (const method of ['parse', 'fromString', 'canonicalize']) {
      assert.throws(() => Kid[method](c.input), errorIs(c.code, c.segment));
    }
  });
}
for (const [index, c] of vectors.create.entries()) {
  test(`create #${index}`, () => {
    const kid = Kid.create(c.stack, c.entities);
    assert.equal(kid.toString(), c.canonical);
    assert.equal(Kid.isCanonical(kid.toString()), true);
  });
}
for (const [index, c] of vectors.createInvalid.entries()) {
  test(`createInvalid #${index}`, { skip: c.entitiesHex ? 'Raw invalid UTF-8 bytes are not JavaScript strings; lone surrogates tested separately' : false }, () => {
    assert.throws(() => Kid.create(c.stack, c.entities), errorIs(c.code, c.segment));
  });
}

test('Unicode validity and length precedence', () => {
  for (const value of ['\uD800', '\uDFFF', 'a\uD800b']) {
    assert.throws(() => Kid.create('s', { config: value }), errorIs('bad_utf8', `config-${value}`));
  }
  const value = 'x'.repeat(253) + '\uD800';
  assert.throws(() => Kid.create('s', { config: value }), errorIs('too_long', `config-${value}`));
  assert.equal(Kid.create('s', { config: '\uD83D\uDE00' }).get('config'), '😀');
  assert.throws(() => Kid.create('s', { config: new Uint8Array([255]) }), errorIs('bad_value'));
});

test('strict inputs, precedence and immutable state', () => {
  for (const stack of [1, true, null, undefined, {}, [], new String('s')]) {
    assert.throws(() => Kid.create(stack, { Bad: '' }), errorIs('bad_stack'));
  }
  for (const value of [false, [], {}, 1.5, new String('x')]) {
    assert.throws(() => Kid.create('s', { config: value }), errorIs('bad_value'));
  }
  assert.throws(() => Kid.create('s', { z: '', Bad: 'x' }), errorIs('bad_value', 'z-'));
  assert.throws(() => Kid.fromString('KID:s:project-1\n'), errorIs('bad_value', 'project-1\n'));
  assert.throws(() => new Kid('KID:s'), TypeError);
  const entities = { [Entity.PROJECT]: '1' }, kid = Kid.create('s', entities);
  entities.project = '2';
  assert.throws(() => { kid.canonical = 'KID:other'; }, TypeError);
  assert.equal(kid.project(), '1');
  assert.equal(kid.equals(null), false);
  const literal = Kid.fromString('KID:s:config-%2A');
  const wildcard = Kid.create('s', literal.entities());
  assert.equal(literal.equals(wildcard), false);
  assert.equal(literal.isPattern(), false);
  assert.equal(wildcard.isPattern(), true);
});

function* permutations(items) {
  if (!items.length) { yield []; return; }
  for (let i = 0; i < items.length; i++) {
    for (const rest of permutations(items.filter((_, j) => j !== i))) yield [items[i], ...rest];
  }
}

test('all 720 entity permutations', () => {
  const fields = [['project', '1'], ['org', '2'], ['branch', 'default'], ['maintainer', '3'], ['constructor', 'x'], ['bucket', 'in.c-main']];
  const expected = Kid.create('s', Object.fromEntries(fields));
  for (const order of permutations(fields)) {
    const text = 'KID:s' + order.map(([name, value]) => `:${name}-${value}`).join('');
    assert.deepEqual(Object.keys(Kid.parse(text)), ['stack', ...order.map(([name]) => name)]);
    assert.ok(expected.equals(Kid.fromString(text)));
    assert.ok(expected.equals(Kid.create('s', Object.fromEntries(order))));
  }
});

test('entire ASCII encoding table', () => {
  const safe = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-';
  for (let byte = 0; byte < 128; byte++) {
    const char = String.fromCharCode(byte), raw = 'x' + char;
    const encoded = 'x' + (safe.includes(char) ? char : `%${byte.toString(16).toUpperCase().padStart(2, '0')}`);
    const kid = Kid.create('s', { config: raw });
    assert.equal(kid.toString(), 'KID:s:config-' + encoded);
    assert.equal(kid.get('config'), raw);
  }
});
