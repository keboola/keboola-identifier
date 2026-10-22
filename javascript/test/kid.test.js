import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { DEFAULT_BRANCH, Entity, InvalidKidError, Kid } from '../src/index.js';

const vectors = JSON.parse(readFileSync(new URL('../../spec/test-vectors.json', import.meta.url)));
const errorIs = (code, segment) => error => {
  assert.ok(error instanceof InvalidKidError);
  assert.equal(error.code, code);
  assert.equal(error.segment, segment ?? undefined);
  return true;
};

for (const [index, c] of vectors.valid.entries()) {
  test(`valid #${index}`, () => {
    const kid = Kid.parse(c.input);
    assert.equal(Kid.validate(c.input), true);
    assert.equal(kid.toString(), c.canonical);
    assert.equal(String(kid), c.canonical);
    assert.equal(Kid.canonicalize(c.input), c.canonical);
    assert.equal(Kid.isCanonical(c.input), c.isCanonical);
    assert.equal(kid.stack(), c.array.stack);
    assert.deepEqual(kid.toObject(), c.array);
    assert.deepEqual(Object.keys(kid.toObject()), Object.keys(c.array), 'stack, then canonical order');
    assert.deepEqual(kid.entities(), c.entities);
    assert.deepEqual(Object.keys(kid.entities()), Object.keys(c.entities), 'canonical order');
    assert.deepEqual(kid.encodedEntities(), c.encodedEntities);
    assert.deepEqual(Object.keys(kid.encodedEntities()), Object.keys(c.encodedEntities), 'canonical order');
    for (const name of new Set([...Object.keys(c.array), 'missing', 'constructor'])) {
      assert.equal(kid.get(name), Object.hasOwn(c.entities, name) ? c.entities[name] : undefined);
      assert.equal(kid.has(name), Object.hasOwn(c.entities, name));
      assert.equal(kid.isWildcard(name), c.wildcards.includes(name));
    }
    for (const [name, accessor] of [['maintainer', 'maintainer'], ['org', 'organization'], ['project', 'project']]) {
      assert.equal(kid[accessor](), c.entities[name]);
    }
    assert.equal(kid.branch(), c.branch);
    assert.equal(kid.isPattern(), c.isPattern);
    assert.equal(kid.equals(Kid.parse(kid.toString())), true);
    const { stack, ...fields } = kid.toObject();
    // A decoded literal `*` (%2A) becomes a wildcard in create(); that round trip is documented as lossy.
    const literalStar = Object.entries(fields).some(([name, raw]) => raw === '*' && !c.wildcards.includes(name));
    assert.equal(kid.equals(Kid.create(stack, fields)), !literalStar, 'toObject round trip');
    kid.entities().config = 'changed';
    kid.toObject().stack = 'changed';
    assert.equal(kid.toString(), c.canonical);
  });
}
for (const [index, c] of vectors.invalid.entries()) {
  test(`invalid #${index}`, () => {
    assert.equal(Kid.validate(c.input), false);
    assert.equal(Kid.isCanonical(c.input), false);
    for (const method of ['parse', 'canonicalize']) {
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
  const skip = c.skip?.includes('javascript') ? 'Fixture marked as unrepresentable in JavaScript'
    : c.entitiesHex ? 'Byte-string fixture; raw 0xFF has no JS string form and the encoded surrogate is covered by the lone-surrogate test' : false;
  test(`createInvalid #${index}`, { skip }, () => {
    assert.throws(() => Kid.create(c.stack, c.entities), errorIs(c.code, c.segment));
  });
}

for (const [index, c] of vectors.encodeValue.entries()) {
  test(`encodeValue #${index}`, () => {
    assert.equal(Kid.encodeValue(c.raw), c.encoded);
  });
}
for (const [index, c] of vectors.encodeValueInvalid.entries()) {
  const skip = c.skip?.includes('javascript') ? 'Fixture marked as unrepresentable in JavaScript'
    : c.rawHex ? 'Byte-string fixture; covered by the lone-surrogate encodeValue test' : false;
  test(`encodeValueInvalid #${index}`, { skip }, () => {
    assert.throws(() => Kid.encodeValue(c.raw), errorIs(c.code, c.segment));
  });
}

test('encodedEntities() and encodeValue() keep a literal star', () => {
  const literal = Kid.parse('KID:s:config-%2A:project-1');
  const wildcard = Kid.parse('KID:s:config-*:project-1');
  assert.deepEqual(literal.encodedEntities(), { config: '%2A', project: '1' });
  assert.deepEqual(wildcard.encodedEntities(), { config: '*', project: '1' });
  assert.deepEqual(literal.entities(), wildcard.entities());
  assert.equal(literal.equals(Kid.parse(`KID:s:project-1:config-${Kid.encodeValue('*')}`)), true);
  assert.throws(() => Kid.parse(`KID:s:project-${Kid.encodeValue('*')}`), errorIs('bad_value', 'project-%2A'));
  // No default-branch fallback, unlike has() and get().
  assert.deepEqual(Kid.parse('KID:s:branch-default:project-1').encodedEntities(), { project: '1' });
  assert.deepEqual(Kid.parse('KID:s:project-1:branch-555').encodedEntities(), { branch: '555', project: '1' });
  assert.throws(() => Kid.encodeValue('\uD800'), errorIs('bad_utf8'));
  assert.throws(() => Kid.encodeValue('x'.repeat(253) + '\uD800'), errorIs('too_long'));
  assert.throws(() => Kid.encodeValue(new Uint8Array([120])), errorIs('bad_value'));
  assert.equal(Kid.encodeValue(10n), '10');
});

test('Unicode validity and length precedence', () => {
  for (const value of ['\uD800', '\uDFFF', 'a\uD800b']) {
    assert.throws(() => Kid.create('s', { config: value }), errorIs('bad_utf8', `config-${value}`));
  }
  const value = 'x'.repeat(253) + '\uD800';
  assert.throws(() => Kid.create('s', { config: value }), errorIs('too_long', `config-${value}`));
  assert.equal(Kid.create('s', { config: '😀' }).get('config'), '😀');
  assert.throws(() => Kid.create('s', { config: new Uint8Array([255]) }), errorIs('bad_value'));
});

test('integer values are converted to decimal strings', () => {
  const kid = Kid.create('s', { project: 9876, org: 0, config: 42, row: 12345678901234567890n });
  assert.equal(kid.toString(), 'KID:s:config-42:org-0:project-9876:row-12345678901234567890');
  assert.equal(kid.project(), '9876');
  assert.ok(kid.equals(Kid.create('s', { project: '9876', org: '0', config: '42', row: '12345678901234567890' })));
  // A negative integer becomes a string with a minus sign and is validated as such.
  assert.throws(() => Kid.create('s', { project: -1 }), errorIs('bad_value', 'project--1'));
  assert.equal(Kid.create('s', { config: -1 }).toString(), 'KID:s:config--1');
  assert.equal(Kid.create('s', { project: -0 }).toString(), 'KID:s:project-0');
  // Non-integers and numbers beyond the safe range are not integer values.
  for (const value of [1.5, Number.NaN, Number.POSITIVE_INFINITY, 2 ** 53, 1e21]) {
    assert.throws(() => Kid.create('s', { config: value }), errorIs('bad_value'));
  }
});

test('JSON.stringify emits the canonical string', () => {
  const kid = Kid.parse('KID:s:project-1:branch-default:config-my%20config');
  assert.equal(JSON.stringify(kid), '"KID:s:config-my%20config:project-1"');
  assert.equal(JSON.stringify({ kid, list: [kid] }), '{"kid":"KID:s:config-my%20config:project-1","list":["KID:s:config-my%20config:project-1"]}');
  assert.ok(kid.equals(Kid.parse(JSON.parse(JSON.stringify(kid)))));
});

test('default branch falls back in every reader', () => {
  const kid = Kid.parse('KID:s:project-1:branch-default:config-my%20config');
  assert.equal(kid.toString(), 'KID:s:config-my%20config:project-1');
  assert.deepEqual(kid.toObject(), { stack: 's', branch: DEFAULT_BRANCH, config: 'my config', project: '1' });
  assert.deepEqual(Object.keys(kid.toObject()), ['stack', 'branch', 'config', 'project']);
  assert.deepEqual(kid.entities(), { branch: 'default', config: 'my config', project: '1' });
  assert.equal(kid.branch(), 'default');
  assert.equal(kid.get('branch'), 'default');
  assert.equal(kid.has('branch'), true);
  assert.equal(kid.isWildcard('branch'), false);
  assert.equal(kid.isPattern(), false);
  assert.ok(kid.equals(Kid.parse('KID:s:config-my%20config:project-1')));
  assert.ok(kid.equals(Kid.create('s', kid.entities())));
  assert.equal(Kid.parse('KID:s:branch-555').branch(), '555');
  assert.equal(Kid.parse('KID:s:branch-dev').toObject().branch, 'dev');
  assert.equal(Kid.parse('KID:s:branch-*').get('branch'), '*');
});

test('leading zeros in numeric IDs are rejected, not normalized', () => {
  assert.throws(() => Kid.create('s', { project: '001' }), errorIs('bad_value', 'project-001'));
  assert.throws(() => Kid.parse('KID:s:project-01'), errorIs('bad_value', 'project-01'));
  assert.throws(() => Kid.create('s', { branch: '007' }), errorIs('bad_value', 'branch-007'));
  assert.equal(Kid.create('s', { project: '0' }).project(), '0');
  assert.equal(Kid.parse('KID:s:org-10').organization(), '10');
  // Open-catalog entities keep their value as given.
  assert.equal(Kid.create('s', { config: '007' }).get('config'), '007');
});

test('user, workspace and serviceaccount take numeric IDs', () => {
  assert.throws(() => Kid.create('s', { [Entity.USER]: '007' }), errorIs('bad_value', 'user-007'));
  assert.throws(() => Kid.parse('KID:s:workspace-01'), errorIs('bad_value', 'workspace-01'));
  assert.throws(() => Kid.create('s', { [Entity.SERVICE_ACCOUNT]: 'abc' }), errorIs('bad_value', 'serviceaccount-abc'));
  assert.equal(Kid.create('s', { org: 5, serviceaccount: 7 }).get(Entity.SERVICE_ACCOUNT), '7');
  assert.equal(Kid.parse('KID:s:org-5:user-*').isWildcard(Entity.USER), true);
  // role stays an open-catalog value: a role definition id.
  assert.equal(Kid.parse('KID:s:org-5:role-0192f3a4-7b8c-7d9e-a1b2-c3d4e5f60718').get(Entity.ROLE), '0192f3a4-7b8c-7d9e-a1b2-c3d4e5f60718');
});

test('strict inputs, precedence and immutable state', () => {
  for (const stack of [1, true, null, undefined, {}, [], new String('s')]) {
    assert.throws(() => Kid.create(stack, { Bad: '' }), errorIs('bad_stack'));
  }
  for (const value of [false, true, null, undefined, [], {}, 1.5, new String('x'), new Number(1)]) {
    assert.throws(() => Kid.create('s', { config: value }), errorIs('bad_value'));
  }
  assert.throws(() => Kid.create('s', { z: '', Bad: 'x' }), errorIs('bad_value', 'z-'));
  for (const entities of [new Map([['project', '1']]), [['project', '1']], null, undefined, 'project-1', new (class {})(), new Set()]) {
    assert.throws(() => Kid.create('s', entities), TypeError);
  }
  // Text entry points take primitive strings only; a boxed String would otherwise be coerced.
  for (const text of [new String('KID:s'), null, undefined, 1, { startsWith: () => true }]) {
    for (const method of ['parse', 'validate', 'canonicalize', 'isCanonical']) {
      assert.throws(() => Kid[method](text), TypeError);
    }
  }
  assert.equal(Kid.create('s', Object.assign(Object.create(null), { project: '1' })).toString(), 'KID:s:project-1');
  assert.equal(Kid.create('s', {}).toString(), 'KID:s');
  assert.throws(() => Kid.parse('KID:s:project-1\n'), errorIs('bad_value', 'project-1\n'));
  assert.throws(() => new Kid('KID:s'), TypeError);
  const entities = { [Entity.PROJECT]: '1' }, kid = Kid.create('s', entities);
  entities.project = '2';
  assert.throws(() => { kid.canonical = 'KID:other'; }, TypeError);
  assert.equal(kid.project(), '1');
  assert.equal(kid.equals(null), false);
  const literal = Kid.parse('KID:s:config-%2A');
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
  const sorted = ['stack', ...fields.map(([name]) => name).sort()];
  for (const order of permutations(fields)) {
    const text = 'KID:s' + order.map(([name, value]) => `:${name}-${value}`).join('');
    assert.ok(expected.equals(Kid.parse(text)));
    assert.deepEqual(Object.keys(Kid.parse(text).toObject()), sorted);
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
