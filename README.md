# Keboola Identifier (KID)

KID is an offline textual identifier for Keboola stacks and objects:

```text
KID:kbc-eu-central-1:p-9876:bucket-in.c-main:table-orders
```

This reimplementation currently delivers the [PHP package](php/README.md)
`keboola/identifier`, namespace `Keboola\Identifier`, for PHP 8.2 or newer.

## Contract and repository layout

The contract is fixed to PR #1 commit `1f6eed74c198460f8160daa425b6e3e680a88a06`.
Original handoff materials are preserved verbatim. Implementation is independently
written from the specification and test vectors.

| Path | Contents |
|---|---|
| [SPEC.md](SPEC.md) | Normative KID 1.0 grammar and API |
| [spec/entities.json](spec/entities.json) | Open entity catalog |
| [spec/test-vectors.json](spec/test-vectors.json) | Shared normative cases plus regressions |
| [spec/generate_vectors.py](spec/generate_vectors.py) | Deterministic vector generator |
| [spec/check_vectors.py](spec/check_vectors.py) | Regeneration and original-case preservation check |
| [php/](php/) | Composer package, examples and tests |
| [handoff/pr1-reimplementation/](handoff/pr1-reimplementation/PROMPT.md) | Frozen task and source materials |
| [.github/workflows/php.yml](.github/workflows/php.yml) | PHP 8.2–8.5 and shared-vector CI |

## Format at a glance

- Exact prefix `KID:` followed by a syntactically valid stack; a bare stack is valid.
- Input segments may be unordered. Output orders `m`, `o`, `p`, `b` first, then
  all other entities by ASCII bytes. Segments split on their first hyphen.
- The frozen short entities mean maintainer, organization, project and branch.
- `b-default` is accepted but omitted from canonical output.
- Bare `*` is a wildcard. `b-dev` is also a pattern, but not a wildcard.
- Values use the fixed encoding table in §5, without trimming or Unicode normalization.
- Limits: 255 decoded bytes per value, 2048 bytes per KID, 64 characters per stack.
- The catalog is open. Unknown syntactically valid entities are accepted.

## PHP usage

```php
use Keboola\Identifier\Kid;

$kid = Kid::fromString('KID:kbc-eu-central-1:p-9876:config-my%20config');
echo $kid->project();     // 9876
echo $kid->get('config'); // my config
```

See [PHP documentation](php/README.md) for installation, the complete public API,
errors, wildcard edge cases and packaging.

## Development

From `php/`:

```bash
composer install
composer validate --strict
composer lint
composer test
composer test-package
php examples/usage.php
```

No build step is necessary. The dependency-free test runner always executes its
checks, independently of PHP's `zend.assertions` setting, and reads the shared
root vectors directly. Additional tests cover PHP types, invalid byte strings,
ASCII encoding, error precedence, immutability and literal-star equality.

From the repository root:

```bash
python3 spec/generate_vectors.py
python3 spec/check_vectors.py
```

The check fails if committed vectors differ from regenerated output or original
snapshot cases were changed. Added regressions preserve U+FEFF (including a value
consisting only of U+FEFF), control characters and open entity `constructor`, and
exercise trailing line terminators and the distinction between input/output limits.
The original boundary corrections (`€` is three UTF-8 bytes; `b` is constrained)
are retained. Python 3.10+ is required for the generator and check script.

## Producer conventions and scope

For comparable stored identities, prefer the minimal project/object chain (`p` plus
object entities, without optional `m`/`o`). Omit `b` for the default branch; never
substitute its numeric ID, which the library cannot recognize as default.

The library does not generate IDs, check object existence, enforce parent
relationships, perform pattern matching, provide strict catalog mode or convert
authorization URNs. All operations are syntactic and offline.
