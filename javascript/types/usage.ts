import { Entity, InvalidKidError, Kid, type ParsedKid, type ErrorCode } from '@keboola/identifier';

const kid: Kid = Kid.create('s', { [Entity.PROJECT]: '1' });
const parsed: ParsedKid = Kid.parse(kid.toString());
const stack: string = parsed.stack;
const project: string | undefined = kid.project();
const entities: Record<string, string> = kid.entities();
const equal: boolean = kid.equals(Kid.fromString(kid.toString()));
const error = new InvalidKidError('bad_value', 'config-');
const code: ErrorCode = error.code;
void [stack, project, entities, equal, code];

// @ts-expect-error Factories require string values.
Kid.create('s', { project: 1 });
// @ts-expect-error Factories require a string stack.
Kid.create(1, {});
// @ts-expect-error Constructor is private.
new Kid();
