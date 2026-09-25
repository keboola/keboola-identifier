import { Entity, InvalidKidError, Kid, type KidObject, type ErrorCode } from '@keboola/identifier';

const kid: Kid = Kid.create('s', { [Entity.PROJECT]: 1, [Entity.CONFIG]: 'my config', row: 10n });
const parsed: Kid = Kid.parse(kid.toString());
const object: KidObject = parsed.toObject();
const stack: string = object.stack;
const branch: string = kid.branch();
const project: string | undefined = kid.project();
const entities: Record<string, string> = kid.entities();
const equal: boolean = kid.equals(Kid.parse(kid.toString()));
const error = new InvalidKidError('bad_value', 'config-');
const code: ErrorCode = error.code;
void [stack, branch, project, entities, equal, code];

// @ts-expect-error Factories require string or integer values.
Kid.create('s', { project: true });
// @ts-expect-error Factories require a string stack.
Kid.create(1, {});
// @ts-expect-error Constructor is private.
new Kid();
// @ts-expect-error branch() is never undefined.
const absent: undefined = kid.branch();
void absent;
