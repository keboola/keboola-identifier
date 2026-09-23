export type ParsedKid = { stack: string } & Record<string, string>;
export type ErrorCode = 'bad_prefix' | 'too_long' | 'bad_stack' | 'empty_segment' | 'bad_segment' | 'duplicate_entity' | 'bad_value' | 'non_canonical_encoding' | 'bad_utf8';
export declare const Entity: Readonly<{
  MAINTAINER: 'maintainer'; ORG: 'org'; PROJECT: 'project'; BRANCH: 'branch';
  BUCKET: 'bucket'; TABLE: 'table'; COMPONENT: 'component'; CONFIG: 'config';
  ROW: 'row'; WORKSPACE: 'workspace'; USER: 'user'; APP: 'app'; STREAM: 'stream';
}>;
export declare class InvalidKidError extends Error {
  readonly code: ErrorCode;
  readonly segment?: string;
  constructor(code: ErrorCode, segment?: string);
}
export declare class Kid {
  private constructor();
  static parse(text: string): ParsedKid;
  static fromString(text: string): Kid;
  static create(stack: string, entities: Record<string, string>): Kid;
  static validate(text: string): boolean;
  static canonicalize(text: string): string;
  static isCanonical(text: string): boolean;
  stack(): string;
  maintainer(): string | undefined;
  organization(): string | undefined;
  project(): string | undefined;
  branch(): string | undefined;
  get(entity: string): string | undefined;
  has(entity: string): boolean;
  entities(): Record<string, string>;
  isWildcard(entity: string): boolean;
  isPattern(): boolean;
  equals(other: Kid): boolean;
  toString(): string;
}
