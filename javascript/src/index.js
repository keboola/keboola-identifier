const unencoded = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-';
const encoder = new TextEncoder();
// ignoreBOM preserves U+FEFF rather than treating it as a transport marker.
const decoder = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true });
const token = Symbol('validated KID');

export const Entity = Object.freeze({
  MAINTAINER: 'maintainer', ORG: 'org', PROJECT: 'project', BRANCH: 'branch',
  BUCKET: 'bucket', TABLE: 'table', COMPONENT: 'component', CONFIG: 'config',
  ROW: 'row', WORKSPACE: 'workspace', USER: 'user', APP: 'app', STREAM: 'stream',
});

/** What branch(), get('branch'), entities() and toObject() report when a KID carries no branch (SPEC §4.2). */
export const DEFAULT_BRANCH = 'default';

export class InvalidKidError extends Error {
  constructor(code, segment = undefined) {
    super(code);
    this.name = 'InvalidKidError';
    this.code = code;
    this.segment = segment;
  }
}

function checkStack(stack) {
  if (typeof stack !== 'string' || stack.length > 64 || !/^[a-z0-9]+(?:-[a-z0-9]+)*(?![\s\S])/.test(stack)) {
    throw new InvalidKidError('bad_stack');
  }
}

function checkEntity(entity, segment) {
  if (entity === 'stack' || !/^[a-z][a-z0-9]*(?![\s\S])/.test(entity)) {
    throw new InvalidKidError('bad_segment', segment);
  }
}

function checkValue(entity, value, segment) {
  let valid = value.length > 0;
  if (['maintainer', 'org', 'project', 'branch'].includes(entity)) {
    // No leading zeros: project-007 would be a second identity for project 7 (SPEC §4.1).
    valid = value === '*' || /^(?:0|[1-9][0-9]*)(?![\s\S])/.test(value)
      || (entity === 'branch' && ['dev', DEFAULT_BRANCH].includes(value));
  }
  if (!valid) throw new InvalidKidError('bad_value', segment);
}

function decode(value, segment) {
  const bytes = [];
  for (let i = 0; i < value.length; i++) {
    if (value[i] !== '%') {
      bytes.push(value.charCodeAt(i));
      continue;
    }
    const hex = value.slice(i + 1, i + 3);
    if (!/^[0-9A-F]{2}$/.test(hex)) throw new InvalidKidError('non_canonical_encoding', segment);
    const byte = Number.parseInt(hex, 16);
    if (unencoded.includes(String.fromCharCode(byte))) throw new InvalidKidError('non_canonical_encoding', segment);
    bytes.push(byte);
    i += 2;
  }
  let raw;
  try {
    raw = decoder.decode(new Uint8Array(bytes));
  } catch {
    throw new InvalidKidError('bad_utf8', segment);
  }
  if (bytes.length > 255) throw new InvalidKidError('too_long', segment);
  return raw;
}

function serialize(stack, entities) {
  return 'KID:' + stack + Object.keys(entities).sort()
    .filter(name => name !== 'branch' || entities[name] !== DEFAULT_BRANCH)
    .map(name => `:${name}-${entities[name]}`).join('');
}

function canonicalize(text) {
  if (!text.startsWith('KID:')) throw new InvalidKidError('bad_prefix');
  if (encoder.encode(text).length > 2048) throw new InvalidKidError('too_long');
  const [stack, ...segments] = text.slice(4).split(':');
  checkStack(stack);
  const entities = {};
  for (const segment of segments) {
    if (segment === '') throw new InvalidKidError('empty_segment', segment);
    const separator = segment.indexOf('-');
    if (separator < 0) throw new InvalidKidError('bad_segment', segment);
    const entity = segment.slice(0, separator), value = segment.slice(separator + 1);
    checkEntity(entity, segment);
    if (Object.hasOwn(entities, entity)) throw new InvalidKidError('duplicate_entity', segment);
    checkValue(entity, value, segment);
    if (value !== '*' && !/^[A-Za-z0-9._%\-]+(?![\s\S])/.test(value)) throw new InvalidKidError('bad_value', segment);
    decode(value, segment);
    entities[entity] = value;
  }
  return serialize(stack, entities);
}

export class Kid {
  #canonical;

  constructor(key, canonical) {
    if (key !== token) throw new TypeError('Use Kid.parse() or Kid.create()');
    this.#canonical = canonical;
    Object.freeze(this);
  }

  /** Runs the full validation sequence and returns the canonical identity; the only way from text to a Kid. */
  static parse(text) {
    return new Kid(token, canonicalize(text));
  }

  static create(stack, entities) {
    checkStack(stack);
    // Only a plain object is an entity map; a Map, array or class instance would otherwise be silently read as empty.
    const prototype = entities === null || typeof entities !== 'object' ? undefined : Object.getPrototypeOf(entities);
    if (prototype !== Object.prototype && prototype !== null) {
      throw new TypeError('Kid.create() entities must be a plain object');
    }
    const encoded = {};
    for (let [entity, value] of Object.entries(entities)) {
      // Integers (safe integers and bigints) become their decimal string form; other non-strings are rejected.
      if (typeof value === 'bigint' || (typeof value === 'number' && Number.isSafeInteger(value))) value = String(value);
      const segment = typeof value === 'string' ? `${entity}-${value}` : undefined;
      checkEntity(entity, segment);
      if (typeof value !== 'string') throw new InvalidKidError('bad_value');
      checkValue(entity, value, segment);
      const bytes = encoder.encode(value);
      if (bytes.length > 255) throw new InvalidKidError('too_long', segment);
      // TextEncoder silently replaces lone surrogates; reject them explicitly.
      if (!value.isWellFormed()) throw new InvalidKidError('bad_utf8', segment);
      encoded[entity] = value === '*' ? '*' : Array.from(bytes, byte => {
        const char = String.fromCharCode(byte);
        return unencoded.includes(char) ? char : `%${byte.toString(16).toUpperCase().padStart(2, '0')}`;
      }).join('');
    }
    const canonical = serialize(stack, encoded);
    if (canonical.length > 2048) throw new InvalidKidError('too_long');
    return new Kid(token, canonical);
  }

  static validate(text) {
    try { canonicalize(text); return true; }
    catch (error) { if (error instanceof InvalidKidError) return false; throw error; }
  }

  static canonicalize(text) { return canonicalize(text); }
  static isCanonical(text) {
    try { return canonicalize(text) === text; }
    catch (error) { if (error instanceof InvalidKidError) return false; throw error; }
  }

  /** Canonical order; an absent branch reads as the default branch (SPEC §4.2). */
  #encoded() {
    const entities = Object.fromEntries(this.#canonical.split(':').slice(2).map(segment => {
      const index = segment.indexOf('-');
      return [segment.slice(0, index), segment.slice(index + 1)];
    }));
    if (Object.hasOwn(entities, 'branch')) return entities;
    entities.branch = DEFAULT_BRANCH;
    return Object.fromEntries(Object.keys(entities).sort().map(name => [name, entities[name]]));
  }

  stack() { return this.#canonical.split(':')[1]; }
  maintainer() { return this.get('maintainer'); }
  organization() { return this.get('org'); }
  project() { return this.get('project'); }
  /** Never undefined: a KID without a branch is on the default branch (SPEC §4.2). */
  branch() { return this.get('branch'); }
  get(entity) { const values = this.#encoded(); return Object.hasOwn(values, entity) ? decode(values[entity]) : undefined; }
  has(entity) { return Object.hasOwn(this.#encoded(), entity); }
  /** Decoded copy in canonical order, with branch reported as default when absent. */
  entities() { return Object.fromEntries(Object.entries(this.#encoded()).map(([name, value]) => [name, decode(value)])); }
  /** stack first, then entities(); the counterpart of toArray() in SPEC §9. */
  toObject() { return { stack: this.stack(), ...this.entities() }; }
  isWildcard(entity) { const values = this.#encoded(); return Object.hasOwn(values, entity) && values[entity] === '*'; }
  isPattern() { return this.branch() === 'dev' || Object.values(this.#encoded()).includes('*'); }
  equals(other) { return other instanceof Kid && this.#canonical === other.#canonical; }
  toString() { return this.#canonical; }
  /** JSON.stringify emits the canonical string (SPEC §9); the private field would otherwise serialize as {}. */
  toJSON() { return this.#canonical; }
}
