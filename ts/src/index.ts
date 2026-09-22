export enum Entity {
  MAINTAINER = "m",
  ORGANIZATION = "o",
  PROJECT = "p",
  BRANCH = "b",
  BUCKET = "bucket",
  TABLE = "table",
  COMPONENT = "component",
  CONFIG = "config",
  ROW = "row",
  WORKSPACE = "workspace",
  USER = "user",
  APP = "app",
  STREAM = "stream",
}

export type InvalidKidCode =
  | "bad_prefix" | "too_long" | "bad_stack" | "empty_segment"
  | "bad_segment" | "duplicate_entity" | "bad_value"
  | "non_canonical_encoding" | "bad_utf8";

export class InvalidKidError extends Error {
  readonly code: InvalidKidCode;
  readonly segment?: string;

  constructor(code: InvalidKidCode, segment?: string) {
    super(`Invalid KID: ${code}${segment === undefined ? "" : ` (${segment})`}`);
    this.name = "InvalidKidError";
    this.code = code;
    this.segment = segment;
  }
}

export interface ParsedKid {
  stack: string;
  entities: Record<string, string>;
}

const MAX_KID_BYTES = 2048;
const MAX_VALUE_BYTES = 255;
const HEAD = ["m", "o", "p", "b"] as const;
const STACK_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const ENTITY_RE = /^[a-z][a-z0-9]*$/;
const OPEN_VALUE_RE = /^[A-Za-z0-9._%\-]+$/;
const HEX_RE = /^[0-9A-F]$/;
const encoder = new TextEncoder();
const decoder = new TextDecoder("utf-8", {fatal: true});

function isUnencoded(byte: number): boolean {
  return (byte >= 0x41 && byte <= 0x5a) || (byte >= 0x61 && byte <= 0x7a)
    || (byte >= 0x30 && byte <= 0x39) || byte === 0x2e || byte === 0x5f || byte === 0x2d;
}

function fail(code: InvalidKidCode, segment?: string): never {
  throw new InvalidKidError(code, segment);
}

function validStack(stack: unknown): stack is string {
  return typeof stack === "string" && stack.length <= 64 && STACK_RE.test(stack);
}

function validShort(entity: string, value: string): boolean {
  if (entity === "m" || entity === "o" || entity === "p") return value === "*" || /^[0-9]+$/.test(value);
  if (entity === "b") return value === "*" || value === "dev" || value === "default" || /^[0-9]+$/.test(value);
  return true;
}

function decodeValue(value: string, segment: string): {decoded: string; bytes: Uint8Array} {
  const bytes: number[] = [];
  for (let i = 0; i < value.length;) {
    if (value[i] === "%") {
      if (i + 2 >= value.length || !HEX_RE.test(value[i + 1]) || !HEX_RE.test(value[i + 2])) {
        fail("non_canonical_encoding", segment);
      }
      const byte = Number.parseInt(value.slice(i + 1, i + 3), 16);
      if (isUnencoded(byte)) fail("non_canonical_encoding", segment);
      bytes.push(byte);
      i += 3;
    } else {
      bytes.push(value.charCodeAt(i)); // syntax validation has restricted it to ASCII
      i += 1;
    }
  }
  const array = Uint8Array.from(bytes);
  try {
    return {decoded: decoder.decode(array), bytes: array};
  } catch {
    return fail("bad_utf8", segment);
  }
}

function encodeValue(raw: string, segment?: string): string {
  const bytes = encoder.encode(raw);
  // TextEncoder replaces lone UTF-16 surrogates; KID values must represent valid UTF-8 text.
  if (decoder.decode(bytes) !== raw) fail("bad_utf8", segment);
  let output = "";
  for (const byte of bytes) output += isUnencoded(byte) ? String.fromCharCode(byte) : `%${byte.toString(16).toUpperCase().padStart(2, "0")}`;
  return output;
}

function ordered(entities: Record<string, string>): string[] {
  return [...HEAD.filter((entity) => entity in entities), ...Object.keys(entities).filter((entity) => !HEAD.includes(entity as typeof HEAD[number])).sort()];
}

function canonicalFromEncoded(stack: string, source: Record<string, string>): string {
  const entities = Object.fromEntries(Object.entries(source).filter(([entity, value]) => !(entity === "b" && value === "default")));
  return ["KID", stack, ...ordered(entities).map((entity) => `${entity}-${entities[entity]}`)].join(":");
}

export class Kid {
  readonly #canonical: string;

  private constructor(canonical: string) {
    this.#canonical = canonical;
    Object.freeze(this);
  }

  static parse(text: string): ParsedKid {
    if (typeof text !== "string" || !text.startsWith("KID:")) fail("bad_prefix");
    if (encoder.encode(text).byteLength > MAX_KID_BYTES) fail("too_long");
    const parts = text.split(":");
    const stack = parts[1];
    if (!validStack(stack)) fail("bad_stack");
    const entities: Record<string, string> = {};
    for (const segment of parts.slice(2)) {
      if (segment === "") fail("empty_segment", segment);
      const hyphen = segment.indexOf("-");
      const entity = hyphen < 0 ? "" : segment.slice(0, hyphen);
      if (hyphen < 0 || !ENTITY_RE.test(entity)) fail("bad_segment", segment);
      if (Object.hasOwn(entities, entity)) fail("duplicate_entity", segment);
      const value = segment.slice(hyphen + 1);
      if (value === "" || !validShort(entity, value)) fail("bad_value", segment);
      if (!HEAD.includes(entity as typeof HEAD[number]) && value !== "*" && !OPEN_VALUE_RE.test(value)) fail("bad_value", segment);
      const decoded = decodeValue(value, segment);
      if (decoded.bytes.byteLength > MAX_VALUE_BYTES) fail("too_long", segment);
      entities[entity] = value;
    }
    return {stack, entities};
  }

  static validate(text: string): boolean {
    try { Kid.parse(text); return true; } catch (error) { if (error instanceof InvalidKidError) return false; throw error; }
  }

  static fromString(text: string): Kid {
    const parsed = Kid.parse(text);
    return new Kid(canonicalFromEncoded(parsed.stack, parsed.entities));
  }

  static create(stack: string, entities: Record<string, string>): Kid {
    if (!validStack(stack)) fail("bad_stack");
    const encoded: Record<string, string> = {};
    for (const [entity, raw] of Object.entries(entities)) {
      const segment = typeof raw === "string" ? `${entity}-${raw}` : undefined;
      if (!ENTITY_RE.test(entity)) fail("bad_segment", segment);
      if (typeof raw !== "string") fail("bad_value");
      if (raw === "" || !validShort(entity, raw)) fail("bad_value", `${entity}-${raw}`);
      const bytes = encoder.encode(raw);
      if (decoder.decode(bytes) !== raw) fail("bad_utf8", `${entity}-${raw}`);
      if (bytes.byteLength > MAX_VALUE_BYTES) fail("too_long", `${entity}-${raw}`);
      encoded[entity] = raw === "*" ? "*" : encodeValue(raw, `${entity}-${raw}`);
    }
    const canonical = canonicalFromEncoded(stack, encoded);
    if (encoder.encode(canonical).byteLength > MAX_KID_BYTES) fail("too_long");
    return new Kid(canonical);
  }

  static canonicalize(text: string): string { return Kid.fromString(text).toString(); }
  static isCanonical(text: string): boolean {
    try { return Kid.canonicalize(text) === text; } catch (error) { if (error instanceof InvalidKidError) return false; throw error; }
  }

  private parsed(): ParsedKid { return Kid.parse(this.#canonical); }
  stack(): string { return this.parsed().stack; }
  get(entity: string): string | undefined {
    const value = this.parsed().entities[entity];
    return value === undefined ? undefined : decodeValue(value, `${entity}-${value}`).decoded;
  }
  has(entity: string): boolean { return Object.hasOwn(this.parsed().entities, entity); }
  entities(): Record<string, string> {
    return Object.fromEntries(Object.keys(this.parsed().entities).map((entity) => [entity, this.get(entity)!]));
  }
  maintainer(): string | undefined { return this.get("m"); }
  organization(): string | undefined { return this.get("o"); }
  project(): string | undefined { return this.get("p"); }
  branch(): string | undefined { return this.get("b"); }
  isWildcard(entity: string): boolean { return this.parsed().entities[entity] === "*"; }
  isPattern(): boolean {
    const entities = this.parsed().entities;
    return Object.values(entities).includes("*") || entities.b === "dev";
  }
  equals(other: Kid): boolean { return other instanceof Kid && this.#canonical === other.#canonical; }
  toString(): string { return this.#canonical; }
}
