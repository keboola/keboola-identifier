import { Entity, Kid } from '@keboola/identifier';

const kid = Kid.create('kbc-eu-central-1', { [Entity.PROJECT]: '9876', [Entity.CONFIG]: 'my config' });
console.log(kid.toString());
console.log(kid.project());
console.log(kid.get(Entity.CONFIG));
console.log(Kid.parse(kid.toString()));
if (!kid.equals(Kid.fromString(kid.toString()))) throw new Error('Round trip failed');
