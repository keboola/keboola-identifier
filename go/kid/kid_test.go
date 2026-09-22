package kid

import (
	"encoding/json"
	"errors"
	"os"
	"reflect"
	"testing"
)

type validVector struct {
	Input       string            `json:"input"`
	Canonical   string            `json:"canonical"`
	Parse       Parsed            `json:"parse"`
	Entities    map[string]string `json:"entities"`
	Wildcards   []string          `json:"wildcards"`
	IsPattern   bool              `json:"isPattern"`
	IsCanonical bool              `json:"isCanonical"`
	Branch      *string           `json:"branch"`
}
type invalidVector struct {
	Input, Code string
	Segment     *string `json:"segment"`
}
type createVector struct {
	Stack     string
	Entities  map[string]string
	Canonical string
}
type createInvalidVector struct {
	Stack, Code string
	Entities    map[string]any
	Segment     *string
	Skip        []string
}
type vectorFile struct {
	Valid         []validVector
	Invalid       []invalidVector
	Create        []createVector
	CreateInvalid []createInvalidVector
}

func vectors(t *testing.T) vectorFile {
	t.Helper()
	data, err := os.ReadFile("../../spec/test-vectors.json")
	if err != nil {
		t.Fatal(err)
	}
	var result vectorFile
	if err := json.Unmarshal(data, &result); err != nil {
		t.Fatal(err)
	}
	return result
}

func TestVectors(t *testing.T) {
	v := vectors(t)
	for _, tc := range v.Valid {
		t.Run("valid/"+tc.Input[:min(40, len(tc.Input))], func(t *testing.T) {
			parsed, err := Parse(tc.Input)
			if err != nil {
				t.Fatal(err)
			}
			if !reflect.DeepEqual(parsed, tc.Parse) {
				t.Fatalf("parse mismatch: %#v %#v", parsed, tc.Parse)
			}
			k, err := FromString(tc.Input)
			if err != nil {
				t.Fatal(err)
			}
			if k.String() != tc.Canonical || !reflect.DeepEqual(k.Entities(), tc.Entities) {
				t.Fatal("canonical/entities mismatch")
			}
			branch, ok := k.Branch()
			if tc.Branch == nil {
				if ok {
					t.Fatal("unexpected branch")
				}
			} else if !ok || branch != *tc.Branch {
				t.Fatal("branch mismatch")
			}
			if k.IsPattern() != tc.IsPattern || IsCanonical(tc.Input) != tc.IsCanonical || !Validate(tc.Input) {
				t.Fatal("flags mismatch")
			}
			actual := []string{}
			for _, entity := range tc.Wildcards {
				if k.IsWildcard(entity) {
					actual = append(actual, entity)
				}
			}
			if !reflect.DeepEqual(actual, tc.Wildcards) {
				t.Fatal("wildcard mismatch")
			}
		})
	}
	for _, tc := range v.Invalid {
		if Validate(tc.Input) || IsCanonical(tc.Input) {
			t.Fatal("invalid accepted")
		}
		_, err := FromString(tc.Input)
		var invalid *InvalidKidError
		if !errors.As(err, &invalid) || invalid.Code != tc.Code {
			t.Fatalf("wrong error for %q: %v", tc.Input, err)
		}
		expected := ""
		if tc.Segment != nil {
			expected = *tc.Segment
		}
		if invalid.Segment != expected {
			t.Fatal("wrong segment")
		}
	}
	for _, tc := range v.Create {
		k, err := New(tc.Stack, tc.Entities)
		if err != nil || k.String() != tc.Canonical {
			t.Fatalf("create: %v", err)
		}
	}
	for _, tc := range v.CreateInvalid {
		skip := false
		for _, language := range tc.Skip {
			if language == "go" {
				skip = true
			}
		}
		if skip {
			continue
		}
		entities := map[string]string{}
		for key, value := range tc.Entities {
			entities[key] = value.(string)
		}
		_, err := New(tc.Stack, entities)
		var invalid *InvalidKidError
		if !errors.As(err, &invalid) || invalid.Code != tc.Code {
			t.Fatalf("wrong create error: %v", err)
		}
		expected := ""
		if tc.Segment != nil {
			expected = *tc.Segment
		}
		if invalid.Segment != expected {
			t.Fatal("wrong create segment")
		}
	}
}

func TestEquality(t *testing.T) {
	a, _ := FromString("KID:s:p-1:o-2")
	b, _ := FromString("KID:s:o-2:p-1")
	if !a.Equals(b) {
		t.Fatal("not equal")
	}
}
