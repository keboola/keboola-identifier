# Keboola Identifier — Go

Module `github.com/keboola/keboola-identifier/go`, package `kid`, Go 1.23+.
No external dependencies. Implements the [PR #2 contract](../SPEC.md), with shared
vectors, race-enabled tests and a round-trip fuzz target.

## Use from this checkout

In a consuming module, use a local replacement while this branch is under review:

```bash
go mod edit -replace github.com/keboola/keboola-identifier/go=/path/to/keboola-identifier/go
go get github.com/keboola/keboola-identifier/go
```

From `go/`:

```bash
go vet ./...
go test -race -cover ./...
go run ./examples/usage
go test -fuzz=FuzzRoundTrip -fuzztime=10s
```

## Usage

```go
import kid "github.com/keboola/keboola-identifier/go"

value, err := kid.New("kbc-eu-central-1", map[string]string{
    kid.EntityProject: "9876", kid.EntityConfig: "my config",
})
if err != nil { return err }
fmt.Println(value) // KID:kbc-eu-central-1:config-my%20config:project-9876
project, ok := value.Project() // "9876", true
```

## API

- `Validate(text) bool`, `Parse(text) (Parsed, error)`,
  `FromString(text) (*Kid, error)`, `New(stack, map[string]string) (*Kid, error)`,
  `Canonicalize(text) (string, error)`, `IsCanonical(text) bool`.
- `Stack() string`; `Maintainer()`, `Organization()`, `Project()`, `Branch()` and
  `Get(entity)` return `(string, bool)` — `("", false)` when absent.
- `Has(entity)`, `IsWildcard(entity)`, `IsPattern()`, `Equals(other *Kid)`;
  `String()` returns canonical text; `Entities()` returns a decoded map copy.
- Constants: `EntityMaintainer`, `EntityOrg`, `EntityProject`, `EntityBranch`,
  `EntityBucket`, `EntityTable`, `EntityComponent`, `EntityConfig`, `EntityRow`,
  `EntityWorkspace`, `EntityUser`, `EntityApp`, `EntityStream`. Ordinary strings
  support the open catalog.

`Kid` has no exported mutable state; factories validate and canonicalize. Its zero
value is not a valid identifier. Input and output maps do not alias internal state.

`Parsed` is a flat `map[string]string` containing `stack` and encoded entities,
including `branch-default`. Go maps cannot preserve input iteration order; parsing
still **validates left to right**. `New` checks entities in Go map iteration order,
so the first reported error among multiple invalid entities is unspecified. Use
text parsing when ordered cross-entity error precedence matters. Canonical output
always sorts all entity names by ASCII bytes and omits `branch-default`.

Errors are `*InvalidKidError{Code, Segment}` and support `errors.As`:

```go
var invalid *kid.InvalidKidError
if errors.As(err, &invalid) {
    fmt.Println(invalid.Code, invalid.Segment)
}
```

An empty `Segment` means not applicable (or an empty input segment). See SPEC §8
for validation precedence. Go strings may contain invalid UTF-8; these are rejected
as `bad_utf8`, after the raw length check in `New` and before the decoded length
check in parsing. Limits are bytes: 255 raw per value and 2048 per KID.
Shared non-string creation fixtures are explicitly skipped because they cannot be
passed to `map[string]string`; both raw-invalid-byte fixtures run unchanged.

## Patterns and round trips

A whole `*` passed to `New` is a wildcard; a literal star must be read from `%2A`
using `FromString`. `Entities()` decodes both to `*`, so recreating from this map is
lossy. Use `FromString(value.String())` for a lossless round trip. `IsWildcard`
distinguishes the two; `branch-dev` is a pattern without being a wildcard.

`stack` is reserved as an entity; unknown names otherwise remain opaque.
Producers omit default branches rather than writing their numeric IDs. The library
does not normalize Unicode, match patterns or check object existence.
