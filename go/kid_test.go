package kid_test

import (
	"bytes"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"reflect"
	"slices"
	"strings"
	"testing"

	kid "github.com/keboola/keboola-identifier/go"
)

type vector struct {
	Input, Canonical, Code string
	Stack, Branch          string
	Segment                *string
	SegmentHex             string
	Array                  map[string]string
	Entities               map[string]any
	EntitiesHex            map[string]string
	Wildcards, Skip        []string
	IsCanonical, IsPattern bool
}

func checkError(t *testing.T, err error, code, segment string) {
	t.Helper()
	var actual *kid.InvalidKidError
	if !errors.As(err, &actual) {
		t.Fatalf("expected InvalidKidError, got %v", err)
	}
	if actual.Code != code || actual.Segment != segment {
		t.Fatalf("got (%s, %q), want (%s, %q)", actual.Code, actual.Segment, code, segment)
	}
}

// stringEntities maps fixture values to New's map[string]string. JSON integers become their
// decimal strings, which is what a Go caller does with strconv.Itoa (SPEC §9.2).
func stringEntities(t *testing.T, c vector) map[string]string {
	t.Helper()
	values := map[string]string{}
	for name, raw := range c.Entities {
		switch value := raw.(type) {
		case string:
			values[name] = value
		case json.Number:
			values[name] = value.String()
		default:
			t.Fatalf("unrepresentable fixture value %v for %s; expected a skip marker", raw, name)
		}
	}
	for name, raw := range c.EntitiesHex {
		value, err := hex.DecodeString(raw)
		if err != nil {
			t.Fatal(err)
		}
		values[name] = string(value)
	}
	return values
}

func TestSharedVectors(t *testing.T) {
	data, err := os.ReadFile("../spec/test-vectors.json")
	if err != nil {
		t.Fatal(err)
	}
	var groups map[string]json.RawMessage
	if err := json.Unmarshal(data, &groups); err != nil {
		t.Fatal(err)
	}
	for _, group := range []string{"valid", "invalid", "create", "createInvalid"} {
		var cases []vector
		decoder := json.NewDecoder(bytes.NewReader(groups[group]))
		decoder.UseNumber()
		if err := decoder.Decode(&cases); err != nil {
			t.Fatal(err)
		}
		for index, c := range cases {
			t.Run(fmt.Sprintf("%s/%d", group, index), func(t *testing.T) {
				if slices.Contains(c.Skip, "go") {
					t.Skip("Fixture marked as unrepresentable in Go")
				}
				segment := ""
				if c.Segment != nil {
					segment = *c.Segment
				}
				if c.SegmentHex != "" {
					raw, err := hex.DecodeString(c.SegmentHex)
					if err != nil {
						t.Fatal(err)
					}
					segment = string(raw)
				}
				switch group {
				case "valid":
					value, err := kid.Parse(c.Input)
					if err != nil {
						t.Fatal(err)
					}
					canonical, err := kid.Canonicalize(c.Input)
					if err != nil || canonical != c.Canonical || value.String() != c.Canonical {
						t.Fatal("canonical mismatch", canonical, err)
					}
					if !kid.Validate(c.Input) || kid.IsCanonical(c.Input) != c.IsCanonical {
						t.Fatal("validation mismatch")
					}
					expected := stringEntities(t, c)
					if value.Stack() != c.Array["stack"] || !reflect.DeepEqual(value.Entities(), expected) {
						t.Fatal("fields mismatch", value.Entities())
					}
					if !reflect.DeepEqual(value.ToMap(), c.Array) {
						t.Fatal("map mismatch", value.ToMap())
					}
					names := []string{"missing", "constructor", "stack"}
					for name := range c.Array {
						names = append(names, name)
					}
					for _, name := range names {
						want, exists := expected[name]
						got, ok := value.Get(name)
						if got != want || ok != exists || value.Has(name) != exists || value.IsWildcard(name) != slices.Contains(c.Wildcards, name) {
							t.Fatalf("accessor mismatch for %s", name)
						}
					}
					for name, accessor := range map[string]func() (string, bool){"maintainer": value.Maintainer, "org": value.Organization, "project": value.Project} {
						got, ok := accessor()
						want, exists := expected[name]
						if got != want || ok != exists {
							t.Fatal("typed accessor mismatch", name)
						}
					}
					if value.Branch() != c.Branch {
						t.Fatalf("branch %q, want %q", value.Branch(), c.Branch)
					}
					if value.IsPattern() != c.IsPattern {
						t.Fatal("pattern mismatch")
					}
					roundTrip, err := kid.Parse(value.String())
					if err != nil || !value.Equals(roundTrip) || value != roundTrip {
						t.Fatal("equality mismatch")
					}
					fields := value.ToMap()
					delete(fields, "stack")
					rebuilt, err := kid.New(value.Stack(), fields)
					if err != nil {
						t.Fatal(err)
					}
					// A decoded literal "*" (%2A) becomes a wildcard in New; that round trip is documented as lossy.
					literalStar := false
					for name, raw := range fields {
						if raw == "*" && !slices.Contains(c.Wildcards, name) {
							literalStar = true
						}
					}
					if value.Equals(rebuilt) == literalStar {
						t.Fatal("ToMap round trip", rebuilt)
					}
					value.Entities()["config"] = "changed"
					value.ToMap()["stack"] = "changed"
					if value.String() != c.Canonical {
						t.Fatal("mutable entities")
					}
				case "invalid":
					if kid.Validate(c.Input) || kid.IsCanonical(c.Input) {
						t.Fatal("invalid predicates")
					}
					_, err := kid.Parse(c.Input)
					checkError(t, err, c.Code, segment)
					_, err = kid.Canonicalize(c.Input)
					checkError(t, err, c.Code, segment)
				case "create":
					value, err := kid.New(c.Stack, stringEntities(t, c))
					if err != nil {
						t.Fatal(err)
					}
					if value.String() != c.Canonical || !kid.IsCanonical(value.String()) {
						t.Fatal("create mismatch")
					}
				case "createInvalid":
					_, err := kid.New(c.Stack, stringEntities(t, c))
					checkError(t, err, c.Code, segment)
				}
			})
		}
	}
}

func TestDefaultBranchFallsBackInEveryReader(t *testing.T) {
	value, err := kid.Parse("KID:s:project-1:branch-default:config-my%20config")
	if err != nil {
		t.Fatal(err)
	}
	if value.String() != "KID:s:config-my%20config:project-1" {
		t.Fatal(value)
	}
	want := map[string]string{"stack": "s", "branch": "default", "config": "my config", "project": "1"}
	if !reflect.DeepEqual(value.ToMap(), want) {
		t.Fatal(value.ToMap())
	}
	branch, ok := value.Get("branch")
	if value.Branch() != "default" || branch != "default" || !ok || !value.Has("branch") {
		t.Fatal("branch fallback")
	}
	if value.IsWildcard("branch") || value.IsPattern() {
		t.Fatal("default branch is not a pattern")
	}
	explicit, _ := kid.Parse("KID:s:branch-555")
	dev, _ := kid.Parse("KID:s:branch-dev")
	star, _ := kid.Parse("KID:s:branch-*")
	if explicit.Branch() != "555" || dev.ToMap()["branch"] != "dev" || star.Branch() != "*" {
		t.Fatal("explicit branch replaced by fallback")
	}
}

func TestLeadingZerosAreRejectedNotNormalized(t *testing.T) {
	_, err := kid.New("s", map[string]string{"project": "001"})
	checkError(t, err, "bad_value", "project-001")
	_, err = kid.Parse("KID:s:project-01")
	checkError(t, err, "bad_value", "project-01")
	_, err = kid.New("s", map[string]string{"branch": "007"})
	checkError(t, err, "bad_value", "branch-007")
	zero, err := kid.New("s", map[string]string{"project": "0"})
	if project, ok := zero.Project(); err != nil || !ok || project != "0" {
		t.Fatal("zero is a valid ID", err)
	}
	// Open-catalog entities keep their value bytes as given.
	open, err := kid.New("s", map[string]string{"config": "007"})
	if config, _ := open.Get("config"); err != nil || config != "007" {
		t.Fatal("open entity normalized", err)
	}
}

func TestNewChecksEntitiesInNameOrder(t *testing.T) {
	entities := map[string]string{"zeta": "", "project": "abc", "Bad": "x", "alpha": ""}
	for i := 0; i < 50; i++ {
		_, err := kid.New("s", entities)
		checkError(t, err, "bad_segment", "Bad-x")
	}
	_, err := kid.New("s", map[string]string{"zeta": "", "project": "abc", "alpha": ""})
	checkError(t, err, "bad_value", "alpha-")
}

func TestByteAndErrorPrecedence(t *testing.T) {
	raw := strings.Repeat("x", 255) + "\xff"
	_, err := kid.New("s", map[string]string{"config": raw})
	checkError(t, err, "too_long", "config-"+raw)
	segment := "config-" + strings.Repeat("x", 255) + "%FF"
	_, err = kid.Parse("KID:s:" + segment)
	checkError(t, err, "bad_utf8", segment)
	_, err = kid.New("Bad", map[string]string{"Bad": ""})
	checkError(t, err, "bad_stack", "")
	_, err = kid.New("s", map[string]string{"stack": ""})
	checkError(t, err, "bad_segment", "stack-")
	safe := "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-"
	for b := 0; b < 128; b++ {
		raw := "x" + string(rune(b))
		encoded := fmt.Sprintf("x%%%02X", b)
		if strings.ContainsRune(safe, rune(b)) {
			encoded = raw
		}
		value, err := kid.New("s", map[string]string{"config": raw})
		if err != nil {
			t.Fatal(err)
		}
		got, ok := value.Get("config")
		if value.String() != "KID:s:config-"+encoded || got != raw || !ok {
			t.Fatalf("byte %d", b)
		}
	}
}

func TestAllPermutations(t *testing.T) {
	segments := []string{"project-1", "org-2", "branch-default", "maintainer-3", "constructor-x", "bucket-in.c-main"}
	expected, err := kid.Parse("KID:s:" + strings.Join(segments, ":"))
	if err != nil {
		t.Fatal(err)
	}
	count := 0
	var visit func(int)
	visit = func(index int) {
		if index == len(segments) {
			count++
			value, err := kid.Parse("KID:s:" + strings.Join(segments, ":"))
			if err != nil || !expected.Equals(value) {
				t.Fatal("order changes identity", err)
			}
			entities := map[string]string{}
			for _, segment := range segments {
				name, raw, _ := strings.Cut(segment, "-")
				entities[name] = raw
			}
			value, err = kid.New("s", entities)
			if err != nil || !expected.Equals(value) {
				t.Fatal("create order changes identity", err)
			}
			return
		}
		for i := index; i < len(segments); i++ {
			segments[index], segments[i] = segments[i], segments[index]
			visit(index + 1)
			segments[index], segments[i] = segments[i], segments[index]
		}
	}
	visit(0)
	if count != 720 {
		t.Fatal(count)
	}
}

func TestValueSemantics(t *testing.T) {
	value, err := kid.Parse("KID:s:project-1")
	if err != nil {
		t.Fatal(err)
	}
	same, _ := kid.New("s", map[string]string{"project": "1"})
	if value != same || fmt.Sprint(value) != "KID:s:project-1" || fmt.Sprintf("%v", []kid.Kid{value}) != "[KID:s:project-1]" {
		t.Fatal("value equality or formatting", value, same)
	}
	var zero kid.Kid
	if zero.String() != "" || zero.Stack() != "" || zero.Has("branch") || zero.Branch() != "" || kid.Validate(zero.String()) {
		t.Fatal("zero value must not look like a valid identifier")
	}
	if _, err := kid.Parse(zero.String()); err == nil {
		t.Fatal("zero value round-tripped")
	}
	keyed := map[kid.Kid]int{value: 1}
	if keyed[same] != 1 {
		t.Fatal("map key")
	}
}

func TestCopiesAndLiteralStar(t *testing.T) {
	entities := map[string]string{kid.EntityProject: "1"}
	value, err := kid.New("s", entities)
	if err != nil {
		t.Fatal(err)
	}
	entities[kid.EntityProject] = "2"
	if project, ok := value.Project(); !ok || project != "1" {
		t.Fatal("retained input map")
	}
	literal, err := kid.Parse("KID:s:config-%2A")
	if err != nil {
		t.Fatal(err)
	}
	wildcard, err := kid.New("s", literal.Entities())
	if err != nil {
		t.Fatal(err)
	}
	if literal.Equals(wildcard) || literal.IsPattern() || !wildcard.IsPattern() || literal.Equals(kid.Kid{}) {
		t.Fatal("literal/wildcard equality")
	}
}

func FuzzRoundTrip(f *testing.F) {
	for _, text := range []string{"KID:s", "KID:s:config-%2A", "KID:s:branch-default:project-1", "KID:s:config-%FF", "KID:s:project-007"} {
		f.Add(text)
	}
	f.Fuzz(func(t *testing.T, text string) {
		value, err := kid.Parse(text)
		if err != nil {
			if kid.Validate(text) || kid.IsCanonical(text) {
				t.Fatal("invalid predicates")
			}
			return
		}
		roundTrip, err := kid.Parse(value.String())
		if err != nil || !value.Equals(roundTrip) || !kid.IsCanonical(value.String()) {
			t.Fatal("round trip failed")
		}
		fields := value.ToMap()
		delete(fields, "stack")
		if _, err := kid.New(value.Stack(), fields); err != nil {
			t.Fatal("ToMap is not a valid New input", err)
		}
	})
}
