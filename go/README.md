# Keboola Identifier — Go

Module `github.com/keboola/keboola-identifier/go`, package `kid`, Go 1.23+.
No external dependencies. Implements [SPEC.md](../SPEC.md), with shared
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
    kid.EntityProject: strconv.Itoa(9876), kid.EntityConfig: "my config",
})
if err != nil { return err }
fmt.Println(value)             // KID:kbc-eu-central-1:config-my%20config:project-9876
project, ok := value.Project() // "9876", true
other, _ := kid.Parse("KID:kbc-eu-central-1:project-9876:config-my%20config")
fmt.Println(value == other)    // true: Kid is a comparable value type
fmt.Println(value.Branch())    // default
fmt.Println(value.ToMap())     // map[branch:default config:my config project:9876 stack:kbc-eu-central-1]
```

## API

- `Validate(text) bool`, `Parse(text) (Kid, error)`, `New(stack, map[string]string) (Kid, error)`,
  `Canonicalize(text) (string, error)`, `IsCanonical(text) bool`.
  `Parse` is the only way from text to a `Kid`; it validates fully and returns the
  canonical identity.
- `Stack() string`; `Maintainer()`, `Organization()`, `Project()` and `Get(entity)`
  return `(string, bool)` — `("", false)` when absent. `Branch() string` is never
  absent: a KID without a branch is on the default branch and reports `"default"`.
- `Has(entity)`, `IsWildcard(entity)`, `IsPattern()`, `Equals(other Kid)` (the same as `==`);
  `String()` returns canonical text; `Entities()` returns a decoded map copy;
  `ToMap()` returns `stack` plus the decoded entities as one flat map (SPEC `toArray()`).
- Constants: `EntityMaintainer`, `EntityOrg`, `EntityProject`, `EntityBranch`,
  `EntityBucket`, `EntityTable`, `EntityComponent`, `EntityConfig`, `EntityRow`,
  `EntityWorkspace`, `EntityUser`, `EntityApp`, `EntityStream`, `DefaultBranch`.
  Ordinary strings support the open catalog.

`Kid` is a comparable value type with no exported state: factories validate and
canonicalize, `==` compares canonical strings and a `Kid` works as a map key. Its
zero value is not a valid identifier: `String()` is `""`, `Has` is false for every
entity and `Parse` rejects it. Input and output maps do not alias internal state.

`New` takes `map[string]string`, so numeric IDs are passed as their decimal strings
(`strconv.Itoa(9876)`); this is the Go counterpart of the integer conversion in
SPEC §8. `maintainer`, `org`, `project` and `branch` reject leading zeros
(`project-007` is `bad_value`); `0` is accepted. Open-catalog values keep their bytes.

When a KID carries no `branch`, every reader falls back to the default branch:
`Branch()`, `Get("branch")`, `Entities()` and `ToMap()` report `"default"` and
`Has("branch")` is true. The canonical string stays without `branch-default`, so
identity does not change. Go maps are unordered; `String()` is the only ordered
representation. Parsing validates segments **left to right**. `New` checks entities
in ascending name order (byte order), so the reported error for a map with several
invalid entries is deterministic; PHP, Python and JavaScript check in map insertion
order instead. Canonical output always sorts all entity names by ASCII bytes and
omits `branch-default`.

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
Shared creation fixtures marked `skip: ["go"]` (null and boolean map values) cannot
be passed to `map[string]string` and are skipped; integer fixture values are loaded
as decimal strings and both raw-invalid-byte fixtures run unchanged.

## Patterns and round trips

A whole `*` passed to `New` is a wildcard; a literal star must be read from `%2A`
using `Parse`. `Entities()` and `ToMap()` decode both to `*`, so recreating from
either map is lossy. Use `Parse(value.String())` for a lossless round trip.
`IsWildcard` distinguishes the two; `branch-dev` is a pattern without being a wildcard.

`stack` is reserved as an entity; unknown names otherwise remain opaque.
Producers omit default branches rather than writing their numeric IDs. The library
does not normalize Unicode, match patterns or check object existence.
