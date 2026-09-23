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
    valid = value === '*' || /^[0-9]+(?![\s\S])/.test(value)
      || (entity === 'branch' && ['dev', 'default'].includes(value));
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
    .filter(name => name !== 'branch' || entities[name] !== 'default')
    .map(name => `:${name}-${entities[name]}`).join('');
}

export class Kid {
  #canonical;

  constructor(key, canonical) {
    if (key !== token) throw new TypeError('Use Kid.fromString() or Kid.create()');
    this.#canonical = canonical;
    Object.freeze(this);
  }

  static parse(text) {
    if (!text.startsWith('KID:')) throw new InvalidKidError('bad_prefix');
    if (encoder.encode(text).length > 2048) throw new InvalidKidError('too_long');
    const [stack, ...segments] = text.slice(4).split(':');
    checkStack(stack);
    const parsed = { stack };
    for (const segment of segments) {
      if (segment === '') throw new InvalidKidError('empty_segment', segment);
      const separator = segment.indexOf('-');
      if (separator < 0) throw new InvalidKidError('bad_segment', segment);
      const entity = segment.slice(0, separator), value = segment.slice(separator + 1);
      checkEntity(entity, segment);
      if (Object.hasOwn(parsed, entity)) throw new InvalidKidError('duplicate_entity', segment);
      checkValue(entity, value, segment);
      if (value !== '*' && !/^[A-Za-z0-9._%\-]+(?![\s\S])/.test(value)) throw new InvalidKidError('bad_value', segment);
      decode(value, segment);
      parsed[entity] = value;
    }
    return parsed;
  }

  static fromString(text) {
    const { stack, ...entities } = Kid.parse(text);
    return new Kid(token, serialize(stack, entities));
  }

  static create(stack, entities) {
    checkStack(stack);
    const encoded = {};
    for (const [entity, value] of Object.entries(entities)) {
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
    try { Kid.parse(text); return true; }
    catch (error) { if (error instanceof InvalidKidError) return false; throw error; }
  }

  static canonicalize(text) { return Kid.fromString(text).toString(); }
  static isCanonical(text) {
    try { return Kid.canonicalize(text) === text; }
    catch (error) { if (error instanceof InvalidKidError) return false; throw error; }
  }

  #encoded() {
    return Object.fromEntries(this.#canonical.split(':').slice(2).map(segment => {
      const index = segment.indexOf('-');
      return [segment.slice(0, index), segment.slice(index + 1)];
    }));
  }

  stack() { return this.#canonical.split(':')[1]; }
  maintainer() { return this.get('maintainer'); }
  organization() { return this.get('org'); }
  project() { return this.get('project'); }
  branch() { return this.get('branch'); }
  get(entity) { const values = this.#encoded(); return Object.hasOwn(values, entity) ? decode(values[entity]) : undefined; }
  has(entity) { return Object.hasOwn(this.#encoded(), entity); }
  entities() { return Object.fromEntries(Object.entries(this.#encoded()).map(([name, value]) => [name, decode(value)])); }
  isWildcard(entity) { const values = this.#encoded(); return Object.hasOwn(values, entity) && values[entity] === '*'; }
  isPattern() { return this.branch() === 'dev' || Object.values(this.#encoded()).includes('*'); }
  equals(other) { return other instanceof Kid && this.#canonical === other.#canonical; }
  toString() { return this.#canonical; }
}
