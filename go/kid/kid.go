// Package kid implements immutable KID 1.0 identifiers and patterns.
package kid

import (
	"encoding/json"
	"fmt"
	"maps"
	"reflect"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"unicode/utf8"
)

// Entity names of the launch catalog (SPEC §4.3); any other syntactically valid name is an open entity.
const (
	EntityMaintainer = "maintainer"
	EntityOrg        = "org"
	EntityProject    = "project"
	EntityBranch     = "branch"
	EntityBucket     = "bucket"
	EntityTable      = "table"
	EntityComponent  = "component"
	EntityConfig     = "config"
	EntityRow        = "row"
	EntityWorkspace  = "workspace"
	EntityUser       = "user"
	EntityApp        = "app"
	EntityStream     = "stream"
	// DefaultBranch is what Branch, Get and Entities report when a KID carries no branch (SPEC §4.2).
	DefaultBranch = "default"
	unencoded     = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-"
)

var (
	stackPattern  = regexp.MustCompile(`^[a-z0-9]+(-[a-z0-9]+)*$`)
	entityPattern = regexp.MustCompile(`^[a-z][a-z0-9]*$`)
	// No leading zeros: project-007 would be a second identity for project 7 (SPEC §4.1).
	numberPattern = regexp.MustCompile(`^(0|[1-9][0-9]*)$`)
	valuePattern  = regexp.MustCompile(`^[A-Za-z0-9._%\-]+$`)
	hexPattern    = regexp.MustCompile(`^[0-9A-F]{2}$`)
)

// InvalidKidError describes the first failed validation check.
// Segment is empty when not applicable (or for an empty input segment).
type InvalidKidError struct{ Code, Segment string }

func (e *InvalidKidError) Error() string { return e.Code }
func invalid(code, segment string) error { return &InvalidKidError{code, segment} }

// Kid is an immutable value holding only its canonical representation. Obtain one with
// New or Parse; == compares canonical strings. The zero value is not a valid identifier:
// its String is "" and it does not round-trip through Parse.
type Kid struct{ canonical string }

func checkStack(stack string) error {
	if len(stack) > 64 || !stackPattern.MatchString(stack) {
		return invalid("bad_stack", "")
	}
	return nil
}
func checkEntity(entity, segment string) error {
	if entity == "stack" || !entityPattern.MatchString(entity) {
		return invalid("bad_segment", segment)
	}
	return nil
}
func checkValue(entity, value, segment string) error {
	valid := value != ""
	switch entity {
	case EntityMaintainer, EntityOrg, EntityProject, EntityBranch:
		valid = value == "*" || numberPattern.MatchString(value) || (entity == EntityBranch && (value == "dev" || value == DefaultBranch))
	}
	if !valid {
		return invalid("bad_value", segment)
	}
	return nil
}
func decode(value, segment string) (string, error) {
	raw := make([]byte, 0, len(value))
	for i := 0; i < len(value); i++ {
		if value[i] != '%' {
			raw = append(raw, value[i])
			continue
		}
		if i+2 >= len(value) || !hexPattern.MatchString(value[i+1:i+3]) {
			return "", invalid("non_canonical_encoding", segment)
		}
		n, _ := strconv.ParseUint(value[i+1:i+3], 16, 8)
		if strings.IndexByte(unencoded, byte(n)) >= 0 {
			return "", invalid("non_canonical_encoding", segment)
		}
		raw = append(raw, byte(n))
		i += 2
	}
	if !utf8.Valid(raw) {
		return "", invalid("bad_utf8", segment)
	}
	if len(raw) > 255 {
		return "", invalid("too_long", segment)
	}
	return string(raw), nil
}
func serialize(stack string, entities map[string]string) string {
	names := make([]string, 0, len(entities))
	for name, value := range entities {
		if name != EntityBranch || value != DefaultBranch {
			names = append(names, name)
		}
	}
	sort.Strings(names)
	var text strings.Builder
	text.WriteString("KID:" + stack)
	for _, name := range names {
		text.WriteString(":" + name + "-" + entities[name])
	}
	return text.String()
}

// Parse runs the full validation sequence on text and returns its canonical identity.
// It is the only way from text to a Kid.
func Parse(text string) (Kid, error) {
	if !strings.HasPrefix(text, "KID:") {
		return Kid{}, invalid("bad_prefix", "")
	}
	if len(text) > 2048 {
		return Kid{}, invalid("too_long", "")
	}
	parts := strings.Split(text[4:], ":")
	if err := checkStack(parts[0]); err != nil {
		return Kid{}, err
	}
	entities := make(map[string]string, len(parts)-1)
	for _, segment := range parts[1:] {
		if segment == "" {
			return Kid{}, invalid("empty_segment", segment)
		}
		entity, value, ok := strings.Cut(segment, "-")
		if !ok {
			return Kid{}, invalid("bad_segment", segment)
		}
		if err := checkEntity(entity, segment); err != nil {
			return Kid{}, err
		}
		if _, exists := entities[entity]; exists {
			return Kid{}, invalid("duplicate_entity", segment)
		}
		if err := checkValue(entity, value, segment); err != nil {
			return Kid{}, err
		}
		if value != "*" && !valuePattern.MatchString(value) {
			return Kid{}, invalid("bad_value", segment)
		}
		if _, err := decode(value, segment); err != nil {
			return Kid{}, err
		}
		entities[entity] = value
	}
	return Kid{serialize(parts[0], entities)}, nil
}

// New accepts raw UTF-8 values. A whole "*" denotes a wildcard.
// Go has no mixed-type map, so numeric IDs are passed as their decimal strings (strconv.Itoa).
// Entities are checked in ascending name order, so the reported error is deterministic.
func New(stack string, entities map[string]string) (Kid, error) {
	if err := checkStack(stack); err != nil {
		return Kid{}, err
	}
	encoded := make(map[string]string, len(entities))
	for _, entity := range slices.Sorted(maps.Keys(entities)) {
		value := entities[entity]
		segment := entity + "-" + value
		if err := checkEntity(entity, segment); err != nil {
			return Kid{}, err
		}
		if err := checkValue(entity, value, segment); err != nil {
			return Kid{}, err
		}
		if len(value) > 255 {
			return Kid{}, invalid("too_long", segment)
		}
		if !utf8.ValidString(value) {
			return Kid{}, invalid("bad_utf8", segment)
		}
		if value == "*" {
			encoded[entity] = value
			continue
		}
		encoded[entity] = encode(value)
	}
	canonical := serialize(stack, encoded)
	if len(canonical) > 2048 {
		return Kid{}, invalid("too_long", "")
	}
	return Kid{canonical}, nil
}

// encode applies the SPEC §5 byte table to a raw value.
func encode(raw string) string {
	var out strings.Builder
	for i := 0; i < len(raw); i++ {
		if strings.IndexByte(unencoded, raw[i]) >= 0 {
			out.WriteByte(raw[i])
		} else {
			fmt.Fprintf(&out, "%%%02X", raw[i])
		}
	}
	return out.String()
}

// EncodeValue returns the SPEC §5 encoding of one raw value, with the value checks New applies
// to an open entity. A whole "*" is a literal ("%2A"), never the wildcard. Errors carry no segment.
func EncodeValue(raw string) (string, error) {
	if raw == "" {
		return "", invalid("bad_value", "")
	}
	if len(raw) > 255 {
		return "", invalid("too_long", "")
	}
	if !utf8.ValidString(raw) {
		return "", invalid("bad_utf8", "")
	}
	return encode(raw), nil
}

// Validate reports whether Parse would succeed.
func Validate(text string) bool { _, err := Parse(text); return err == nil }

// Canonicalize returns Parse(text).String(), with the same error as Parse.
func Canonicalize(text string) (string, error) {
	k, err := Parse(text)
	if err != nil {
		return "", err
	}
	return k.String(), nil
}

// IsCanonical reports whether text is valid and equal to its canonical form; false, never an error, for invalid text.
func IsCanonical(text string) bool {
	canonical, err := Canonicalize(text)
	return err == nil && canonical == text
}

// String returns the canonical form; every serialization of a Kid emits exactly this string.
func (k Kid) String() string { return k.canonical }

// Stack returns the stack identifier.
func (k Kid) Stack() string {
	if k.canonical == "" {
		return ""
	}
	return strings.SplitN(k.canonical, ":", 3)[1]
}

// EncodedEntities returns the encoded values exactly as the canonical form carries them, so a
// literal "%2A" stays distinct from the wildcard "*". It is lossless and has no default-branch
// fallback: "branch" is present only when the canonical form carries it. Go maps are unordered;
// String is the only ordered representation.
func (k Kid) EncodedEntities() map[string]string {
	entities := map[string]string{}
	parts := strings.Split(k.canonical, ":")
	if len(parts) < 2 {
		return entities
	}
	for _, segment := range parts[2:] {
		name, value, _ := strings.Cut(segment, "-")
		entities[name] = value
	}
	return entities
}

// encoded returns the canonical segments; an absent branch reads as DefaultBranch (SPEC §4.2).
func (k Kid) encoded() map[string]string {
	entities := k.EncodedEntities()
	if k.canonical == "" {
		return entities
	}
	if _, ok := entities[EntityBranch]; !ok {
		entities[EntityBranch] = DefaultBranch
	}
	return entities
}

// Get returns the decoded value of an entity; a whole "*" is the wildcard (see IsWildcard).
func (k Kid) Get(entity string) (string, bool) {
	value, ok := k.encoded()[entity]
	if !ok {
		return "", false
	}
	raw, _ := decode(value, "")
	return raw, true
}

// Has reports whether the entity is present; always true for "branch" on a valid Kid.
func (k Kid) Has(entity string) bool { _, ok := k.encoded()[entity]; return ok }

// Entities returns a decoded copy with branch reported as DefaultBranch when absent.
// Go maps are unordered; String is the only ordered representation.
func (k Kid) Entities() map[string]string {
	values := k.encoded()
	for name, value := range values {
		values[name], _ = decode(value, "")
	}
	return values
}

// ToMap returns "stack" and the decoded entities as one flat map (SPEC toArray()).
// New(m["stack"], m without "stack") rebuilds the same Kid, except that a decoded
// literal "*" becomes a wildcard.
func (k Kid) ToMap() map[string]string {
	values := k.Entities()
	values["stack"] = k.Stack()
	return values
}

// Maintainer returns the decoded "maintainer" value.
func (k Kid) Maintainer() (string, bool) { return k.Get(EntityMaintainer) }

// Organization returns the decoded "org" value.
func (k Kid) Organization() (string, bool) { return k.Get(EntityOrg) }

// Project returns the decoded "project" value.
func (k Kid) Project() (string, bool) { return k.Get(EntityProject) }

// Branch is never absent on a valid Kid: a KID without a branch is on the default
// branch (SPEC §4.2). Only the invalid zero value reports "".
func (k Kid) Branch() string { value, _ := k.Get(EntityBranch); return value }

// IsWildcard reports whether the entity's value is the wildcard, as opposed to a decoded literal "*".
func (k Kid) IsWildcard(entity string) bool { return k.encoded()[entity] == "*" }

// IsPattern reports whether any entity is a wildcard or the branch is "dev".
func (k Kid) IsPattern() bool {
	for name, value := range k.encoded() {
		if value == "*" || (name == EntityBranch && value == "dev") {
			return true
		}
	}
	return false
}

// Equals reports canonical equality; it is the same as ==.
func (k Kid) Equals(other Kid) bool { return k == other }

// MarshalBinary is MarshalText, so encoding/gob also carries the canonical string.
func (k Kid) MarshalBinary() ([]byte, error) { return k.MarshalText() }

// UnmarshalBinary is UnmarshalText.
func (k *Kid) UnmarshalBinary(data []byte) error { return k.UnmarshalText(data) }

// MarshalText emits the canonical string, so encoding/json and other text-based
// encoders serialize a Kid as that string (SPEC §9). The zero value is not
// serializable and yields an error rather than silently producing ""; an optional
// field should be *Kid or use `json:",omitzero"`.
func (k Kid) MarshalText() ([]byte, error) {
	if k.canonical == "" {
		return nil, invalid("bad_prefix", "")
	}
	return []byte(k.canonical), nil
}

// UnmarshalJSON accepts only a JSON string and runs it through Parse. encoding/json
// skips UnmarshalText for null, which would leave a value-typed Kid unvalidated;
// null and other non-strings are an *json.UnmarshalTypeError instead. An optional
// *Kid field still decodes null as nil, since encoding/json never calls this then.
func (k *Kid) UnmarshalJSON(data []byte) error {
	var text string
	if len(data) == 0 || data[0] != '"' {
		return &json.UnmarshalTypeError{Value: jsonKind(data), Type: reflect.TypeFor[Kid]()}
	}
	if err := json.Unmarshal(data, &text); err != nil {
		return err
	}
	return k.UnmarshalText([]byte(text))
}

// jsonKind names a non-string JSON value the way encoding/json does in its errors.
func jsonKind(data []byte) string {
	switch {
	case len(data) == 0:
		return "empty"
	case data[0] == 'n':
		return "null"
	case data[0] == 't' || data[0] == 'f':
		return "bool"
	case data[0] == '{':
		return "object"
	case data[0] == '[':
		return "array"
	}
	return "number"
}

// UnmarshalText runs Parse, so a decoded Kid is always validated and canonical.
func (k *Kid) UnmarshalText(text []byte) error {
	parsed, err := Parse(string(text))
	if err != nil {
		return err
	}
	*k = parsed
	return nil
}
