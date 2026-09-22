// Package kid implements Keboola Identifiers as specified by SPEC.md 1.0.
package kid

import (
	"fmt"
	"regexp"
	"sort"
	"strings"
	"unicode/utf8"
)

type Entity string

const (
	EntityMaintainer   Entity = "m"
	EntityOrganization Entity = "o"
	EntityProject      Entity = "p"
	EntityBranch       Entity = "b"
	EntityBucket       Entity = "bucket"
	EntityTable        Entity = "table"
	EntityComponent    Entity = "component"
	EntityConfig       Entity = "config"
	EntityRow          Entity = "row"
	EntityWorkspace    Entity = "workspace"
	EntityUser         Entity = "user"
	EntityApp          Entity = "app"
	EntityStream       Entity = "stream"
)

const maxKidBytes = 2048
const maxValueBytes = 255

var head = []string{"m", "o", "p", "b"}
var stackRE = regexp.MustCompile(`^[a-z0-9]+(-[a-z0-9]+)*$`)
var entityRE = regexp.MustCompile(`^[a-z][a-z0-9]*$`)
var openValueRE = regexp.MustCompile(`^[A-Za-z0-9._%-]+$`)
var digitsRE = regexp.MustCompile(`^[0-9]+$`)

type InvalidKidError struct {
	Code    string
	Segment string
}

func (e *InvalidKidError) Error() string {
	if e.Segment == "" {
		return "invalid KID: " + e.Code
	}
	return fmt.Sprintf("invalid KID: %s (%s)", e.Code, e.Segment)
}

type Parsed struct {
	Stack    string            `json:"stack"`
	Entities map[string]string `json:"entities"`
}

type Kid struct {
	canonical string
}

func invalid(code string, segment ...string) error {
	s := ""
	if len(segment) > 0 {
		s = segment[0]
	}
	return &InvalidKidError{Code: code, Segment: s}
}

func validStack(stack string) bool {
	return len(stack) <= 64 && stackRE.MatchString(stack)
}

func isHead(entity string) bool {
	for _, candidate := range head {
		if entity == candidate {
			return true
		}
	}
	return false
}

func validShort(entity, value string) bool {
	switch entity {
	case "m", "o", "p":
		return value == "*" || digitsRE.MatchString(value)
	case "b":
		return value == "*" || value == "dev" || value == "default" || digitsRE.MatchString(value)
	default:
		return true
	}
}

func unencoded(b byte) bool {
	return b >= 'A' && b <= 'Z' || b >= 'a' && b <= 'z' || b >= '0' && b <= '9' || b == '.' || b == '_' || b == '-'
}

func uppercaseHex(b byte) bool {
	return b >= '0' && b <= '9' || b >= 'A' && b <= 'F'
}

func decode(value, segment string) (string, []byte, error) {
	raw := make([]byte, 0, len(value))
	for i := 0; i < len(value); {
		if value[i] == '%' {
			if i+2 >= len(value) || !uppercaseHex(value[i+1]) || !uppercaseHex(value[i+2]) {
				return "", nil, invalid("non_canonical_encoding", segment)
			}
			var b byte
			for _, h := range []byte{value[i+1], value[i+2]} {
				b *= 16
				if h >= '0' && h <= '9' {
					b += h - '0'
				} else {
					b += h - 'A' + 10
				}
			}
			if unencoded(b) {
				return "", nil, invalid("non_canonical_encoding", segment)
			}
			raw = append(raw, b)
			i += 3
		} else {
			raw = append(raw, value[i])
			i++
		}
	}
	if !utf8.Valid(raw) {
		return "", nil, invalid("bad_utf8", segment)
	}
	return string(raw), raw, nil
}

func Parse(text string) (Parsed, error) {
	if !strings.HasPrefix(text, "KID:") {
		return Parsed{}, invalid("bad_prefix")
	}
	if len(text) > maxKidBytes {
		return Parsed{}, invalid("too_long")
	}
	parts := strings.Split(text, ":")
	stack := parts[1]
	if !validStack(stack) {
		return Parsed{}, invalid("bad_stack")
	}
	entities := make(map[string]string)
	for _, segment := range parts[2:] {
		if segment == "" {
			return Parsed{}, invalid("empty_segment", "")
		}
		hyphen := strings.IndexByte(segment, '-')
		entity := ""
		if hyphen >= 0 {
			entity = segment[:hyphen]
		}
		if hyphen < 0 || !entityRE.MatchString(entity) {
			return Parsed{}, invalid("bad_segment", segment)
		}
		if _, exists := entities[entity]; exists {
			return Parsed{}, invalid("duplicate_entity", segment)
		}
		value := segment[hyphen+1:]
		if value == "" || !validShort(entity, value) {
			return Parsed{}, invalid("bad_value", segment)
		}
		if !isHead(entity) && value != "*" && !openValueRE.MatchString(value) {
			return Parsed{}, invalid("bad_value", segment)
		}
		_, raw, err := decode(value, segment)
		if err != nil {
			return Parsed{}, err
		}
		if len(raw) > maxValueBytes {
			return Parsed{}, invalid("too_long", segment)
		}
		entities[entity] = value
	}
	return Parsed{Stack: stack, Entities: entities}, nil
}

func Validate(text string) bool {
	_, err := Parse(text)
	return err == nil
}

func ordered(entities map[string]string) []string {
	result := make([]string, 0, len(entities))
	for _, entity := range head {
		if _, exists := entities[entity]; exists {
			result = append(result, entity)
		}
	}
	rest := make([]string, 0, len(entities))
	for entity := range entities {
		if !isHead(entity) {
			rest = append(rest, entity)
		}
	}
	sort.Strings(rest)
	return append(result, rest...)
}

func canonicalFromEncoded(stack string, source map[string]string) string {
	entities := make(map[string]string, len(source))
	for entity, value := range source {
		if entity != "b" || value != "default" {
			entities[entity] = value
		}
	}
	parts := []string{"KID", stack}
	for _, entity := range ordered(entities) {
		parts = append(parts, entity+"-"+entities[entity])
	}
	return strings.Join(parts, ":")
}

func encode(raw string) string {
	var out strings.Builder
	for i := 0; i < len(raw); i++ {
		b := raw[i]
		if unencoded(b) {
			out.WriteByte(b)
		} else {
			fmt.Fprintf(&out, "%%%02X", b)
		}
	}
	return out.String()
}

func FromString(text string) (*Kid, error) {
	parsed, err := Parse(text)
	if err != nil {
		return nil, err
	}
	return &Kid{canonical: canonicalFromEncoded(parsed.Stack, parsed.Entities)}, nil
}

func New(stack string, entities map[string]string) (*Kid, error) {
	if !validStack(stack) {
		return nil, invalid("bad_stack")
	}
	encoded := make(map[string]string, len(entities))
	for entity, raw := range entities {
		segment := entity + "-" + raw
		if !entityRE.MatchString(entity) {
			return nil, invalid("bad_segment", segment)
		}
		if raw == "" || !validShort(entity, raw) {
			return nil, invalid("bad_value", segment)
		}
		if !utf8.ValidString(raw) {
			return nil, invalid("bad_utf8", segment)
		}
		if len(raw) > maxValueBytes {
			return nil, invalid("too_long", segment)
		}
		if raw == "*" {
			encoded[entity] = "*"
		} else {
			encoded[entity] = encode(raw)
		}
	}
	canonical := canonicalFromEncoded(stack, encoded)
	if len(canonical) > maxKidBytes {
		return nil, invalid("too_long")
	}
	return &Kid{canonical: canonical}, nil
}

func Canonicalize(text string) (string, error) {
	k, err := FromString(text)
	if err != nil {
		return "", err
	}
	return k.String(), nil
}

func IsCanonical(text string) bool {
	canonical, err := Canonicalize(text)
	return err == nil && canonical == text
}

func (k *Kid) parsed() Parsed {
	parsed, _ := Parse(k.canonical)
	return parsed
}

func (k *Kid) Stack() string  { return k.parsed().Stack }
func (k *Kid) String() string { return k.canonical }
func (k *Kid) Get(entity string) (string, bool) {
	value, exists := k.parsed().Entities[entity]
	if !exists {
		return "", false
	}
	decoded, _, _ := decode(value, entity+"-"+value)
	return decoded, true
}
func (k *Kid) Has(entity string) bool { _, exists := k.parsed().Entities[entity]; return exists }
func (k *Kid) Entities() map[string]string {
	result := make(map[string]string)
	for entity := range k.parsed().Entities {
		result[entity], _ = k.Get(entity)
	}
	return result
}
func (k *Kid) Maintainer() (string, bool)    { return k.Get("m") }
func (k *Kid) Organization() (string, bool)  { return k.Get("o") }
func (k *Kid) Project() (string, bool)       { return k.Get("p") }
func (k *Kid) Branch() (string, bool)        { return k.Get("b") }
func (k *Kid) IsWildcard(entity string) bool { return k.parsed().Entities[entity] == "*" }
func (k *Kid) IsPattern() bool {
	entities := k.parsed().Entities
	for _, value := range entities {
		if value == "*" {
			return true
		}
	}
	return entities["b"] == "dev"
}
func (k *Kid) Equals(other *Kid) bool { return other != nil && k.canonical == other.canonical }
