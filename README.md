# Keboola Identifier (KID)

Implementations of the [KID 1.0 specification](SPEC.md) for PHP, TypeScript,
Python, and Go. All packages use the same normative vectors in
[`spec/test-vectors.json`](spec/test-vectors.json).

```text
KID:kbc-eu-central-1:p-9876:bucket-in.c-main:table-orders
```

Each implementation supports parsing, validation, canonicalization, creation,
typed accessors, wildcard inspection, pattern detection, and equality. Unknown
entities remain valid as required by the open entity catalog.

## Quick examples

### PHP

```php
use Keboola\Identifier\Kid;

$kid = Kid::create('kbc-eu-central-1', ['p' => '9876', 'config' => 'my config']);
echo $kid; // KID:kbc-eu-central-1:p-9876:config-my%20config
```

### TypeScript

```ts
import {Kid} from '@keboola/identifier';

const kid = Kid.create('kbc-eu-central-1', {p: '9876', config: 'my config'});
console.log(kid.toString());
```

### Python

```python
from keboola_identifier import Kid

kid = Kid.create("kbc-eu-central-1", {"p": "9876", "config": "my config"})
print(kid)
```

### Go

```go
identifier, err := kid.New("kbc-eu-central-1", map[string]string{
    "p": "9876", "config": "my config",
})
```

Invalid input raises/returns `InvalidKidError` (or
`InvalidKidException` in PHP), including the stable error code and offending
segment defined by the specification.

## Tests

```bash
(cd php && composer install && composer test)
(cd ts && npm ci && npm test)
(cd python && python -m pip install -e '.[test]' && pytest)
(cd go && go test ./...)
```

Regenerate shared vectors after changing their source:

```bash
python3 spec/generate_vectors.py
```
