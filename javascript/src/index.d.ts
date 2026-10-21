export type ErrorCode = 'bad_prefix' | 'too_long' | 'bad_stack' | 'empty_segment' | 'bad_segment' | 'duplicate_entity' | 'bad_value' | 'non_canonical_encoding' | 'bad_utf8';
/** Decoded entity values; integers are converted to their decimal string form. */
export type EntityValues = Record<string, string | number | bigint>;
/** stack first, then the decoded entities in canonical order, with branch always present. */
export type KidObject = { stack: string; branch: string } & Record<string, string>;
export declare const Entity: Readonly<{
  MAINTAINER: 'maintainer'; ORG: 'org'; PROJECT: 'project'; BRANCH: 'branch';
  BUCKET: 'bucket'; TABLE: 'table'; COMPONENT: 'component'; CONFIG: 'config';
  ROW: 'row'; WORKSPACE: 'workspace'; USER: 'user'; APP: 'app'; STREAM: 'stream';
}>;
export declare const DEFAULT_BRANCH: 'default';
export declare class InvalidKidError extends Error {
  readonly code: ErrorCode;
  readonly segment?: string;
  constructor(code: ErrorCode, segment?: string);
}
export declare class Kid {
  private constructor();
  static parse(text: string): Kid;
  static create(stack: string, entities: EntityValues): Kid;
  /** SPEC §5 encoding of one raw value; a whole '*' encodes as a literal '%2A'. */
  static encodeValue(raw: string | number | bigint): string;
  static validate(text: string): boolean;
  static canonicalize(text: string): string;
  static isCanonical(text: string): boolean;
  stack(): string;
  maintainer(): string | undefined;
  organization(): string | undefined;
  project(): string | undefined;
  branch(): string;
  get(entity: string): string | undefined;
  has(entity: string): boolean;
  entities(): Record<string, string>;
  /** Encoded values of the canonical form, without the default-branch fallback. */
  encodedEntities(): Record<string, string>;
  toObject(): KidObject;
  isWildcard(entity: string): boolean;
  isPattern(): boolean;
  equals(other: unknown): boolean;
  toString(): string;
  toJSON(): string;
}
