package caldav

import (
	"context"
	"reflect"
	"testing"
)

func TestCandidatesStartWithKnownProvidersThenTheDomain(t *testing.T) {
	got := Candidates(context.Background(), "kim@icloud.com", "imap.mail.me.com", nil)
	want := []string{"https://caldav.icloud.com/", "https://icloud.com/.well-known/caldav", "https://me.com/.well-known/caldav", "https://imap.mail.me.com/.well-known/caldav"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("got %v", got)
	}
}

func TestCandidatesForACompanyDomain(t *testing.T) {
	got := Candidates(context.Background(), "kim@company.example", "mail.company.example", nil)
	want := []string{"https://company.example/.well-known/caldav", "https://mail.company.example/.well-known/caldav"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("got %v", got)
	}
}

func TestNoCandidatesForProvidersWithTheirOwnAPI(t *testing.T) {
	if got := Candidates(context.Background(), "kim@gmail.com", "imap.gmail.com", nil); len(got) != 0 {
		t.Fatalf("got %v", got)
	}
}

func TestColorsAreNormalized(t *testing.T) {
	for in, want := range map[string]string{"#2E7D32FF": "#2e7d32", "#abcdef": "#abcdef", "blue": "", "#12345": "", "": ""} {
		if got := color(in); got != want {
			t.Errorf("color(%q) = %q, want %q", in, got, want)
		}
	}
}
