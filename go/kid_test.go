package kid_test

import (
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
	Stack                  string
	Segment                *string
	SegmentHex             string
	Parse                  kid.Parsed
	Entities               map[string]any
	EntitiesHex            map[string]string
	Wildcards              []string
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

func stringEntities(t *testing.T, c vector) map[string]string {
	t.Helper()
	values := map[string]string{}
	for name, raw := range c.Entities {
		value, ok := raw.(string)
		if !ok {
			t.Skip("Non-string values cannot be passed to map[string]string")
		}
		values[name] = value
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
		if err := json.Unmarshal(groups[group], &cases); err != nil {
			t.Fatal(err)
		}
		for index, c := range cases {
			t.Run(fmt.Sprintf("%s/%d", group, index), func(t *testing.T) {
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
					value, err := kid.FromString(c.Input)
					if err != nil {
						t.Fatal(err)
					}
					parsed, err := kid.Parse(c.Input)
					if err != nil || !reflect.DeepEqual(parsed, c.Parse) {
						t.Fatalf("parse: %v, %v", parsed, err)
					}
					canonical, err := kid.Canonicalize(c.Input)
					if err != nil || canonical != c.Canonical || value.String() != c.Canonical {
						t.Fatal("canonical mismatch", canonical, err)
					}
					if !kid.Validate(c.Input) || kid.IsCanonical(c.Input) != c.IsCanonical {
						t.Fatal("validation mismatch")
					}
					expected := stringEntities(t, c)
					if value.Stack() != c.Parse["stack"] || !reflect.DeepEqual(value.Entities(), expected) {
						t.Fatal("fields mismatch")
					}
					names := []string{"missing", "constructor", "stack"}
					for name := range c.Parse {
						names = append(names, name)
					}
					for _, name := range names {
						want, exists := expected[name]
						got, ok := value.Get(name)
						if got != want || ok != exists || value.Has(name) != exists || value.IsWildcard(name) != slices.Contains(c.Wildcards, name) {
							t.Fatalf("accessor mismatch for %s", name)
						}
					}
					for name, accessor := range map[string]func() (string, bool){"maintainer": value.Maintainer, "org": value.Organization, "project": value.Project, "branch": value.Branch} {
						got, ok := accessor()
						want, exists := expected[name]
						if got != want || ok != exists {
							t.Fatal("typed accessor mismatch", name)
						}
					}
					if value.IsPattern() != c.IsPattern {
						t.Fatal("pattern mismatch")
					}
					roundTrip, err := kid.FromString(value.String())
					if err != nil || !value.Equals(roundTrip) || *value != *roundTrip {
						t.Fatal("equality mismatch")
					}
					value.Entities()["config"] = "changed"
					if value.String() != c.Canonical {
						t.Fatal("mutable entities")
					}
				case "invalid":
					if kid.Validate(c.Input) || kid.IsCanonical(c.Input) {
						t.Fatal("invalid predicates")
					}
					_, err := kid.Parse(c.Input)
					checkError(t, err, c.Code, segment)
					_, err = kid.FromString(c.Input)
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

func TestByteAndErrorPrecedence(t *testing.T) {
	raw := strings.Repeat("x", 255) + "\xff"
	_, err := kid.New("s", map[string]string{"config": raw})
	checkError(t, err, "too_long", "config-"+raw)
	segment := "config-" + strings.Repeat("x", 255) + "%FF"
	_, err = kid.FromString("KID:s:" + segment)
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
	expected, err := kid.FromString("KID:s:" + strings.Join(segments, ":"))
	if err != nil {
		t.Fatal(err)
	}
	count := 0
	var visit func(int)
	visit = func(index int) {
		if index == len(segments) {
			count++
			value, err := kid.FromString("KID:s:" + strings.Join(segments, ":"))
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
	literal, err := kid.FromString("KID:s:config-%2A")
	if err != nil {
		t.Fatal(err)
	}
	wildcard, err := kid.New("s", literal.Entities())
	if err != nil {
		t.Fatal(err)
	}
	if literal.Equals(wildcard) || literal.IsPattern() || !wildcard.IsPattern() || literal.Equals(nil) {
		t.Fatal("literal/wildcard equality")
	}
}

func FuzzRoundTrip(f *testing.F) {
	for _, text := range []string{"KID:s", "KID:s:config-%2A", "KID:s:branch-default:project-1", "KID:s:config-%FF"} {
		f.Add(text)
	}
	f.Fuzz(func(t *testing.T, text string) {
		value, err := kid.FromString(text)
		if err != nil {
			if kid.Validate(text) || kid.IsCanonical(text) {
				t.Fatal("invalid predicates")
			}
			return
		}
		roundTrip, err := kid.FromString(value.String())
		if err != nil || !value.Equals(roundTrip) || !kid.IsCanonical(value.String()) {
			t.Fatal("round trip failed")
		}
	})
}
