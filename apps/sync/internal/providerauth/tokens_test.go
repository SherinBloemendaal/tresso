package providerauth

import (
	"context"
	"errors"
	"testing"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/fakeapi"
)

func TestTokenIsCachedUntilShortlyBeforeExpiry(t *testing.T) {
	f := fakeapi.New()
	f.ExpiresAt = time.Now().Add(10 * time.Minute)
	ts := New(f, "acc", "w1")
	now := time.Now()
	ts.now = func() time.Time { return now }

	token, err := ts.Token(context.Background())
	if err != nil || token != "token-1" {
		t.Fatalf("Token = %q, %v", token, err)
	}
	f.Token = "token-later"
	if token, _ := ts.Token(context.Background()); token != "token-1" {
		t.Errorf("cached token not used: %q", token)
	}
	now = now.Add(8*time.Minute + time.Second) // inside the 2 minute margin
	if token, _ := ts.Token(context.Background()); token != "token-later" {
		t.Errorf("token near expiry still used: %q", token)
	}
}

func TestInvalidateRenewsOncePerRefusedToken(t *testing.T) {
	f := fakeapi.New()
	ts := New(f, "acc", "w1")
	first, _ := ts.Token(context.Background())

	// Several requests saw the same token refused: only the first renews it.
	for range 3 {
		if err := ts.Invalidate(context.Background(), first); err != nil {
			t.Fatal(err)
		}
	}
	if f.Refreshes != 1 {
		t.Errorf("refreshes = %d", f.Refreshes)
	}
	if token, _ := ts.Token(context.Background()); token != "token-2" {
		t.Errorf("token after renewal = %q", token)
	}
}

func TestReauthorizeIsPassedOn(t *testing.T) {
	f := fakeapi.New()
	f.Reauthorize = true
	if _, err := New(f, "acc", "w1").Token(context.Background()); !errors.Is(err, api.ErrReauthorize) {
		t.Errorf("err = %v", err)
	}
}

func TestFromCredentialsUsesTheGivenToken(t *testing.T) {
	f := fakeapi.New()
	creds, _ := f.Credentials(context.Background(), "acc", "w1")
	f.Token = "not-asked"
	ts, err := FromCredentials(f, creds, "w1")
	if err != nil {
		t.Fatal(err)
	}
	if token, _ := ts.Token(context.Background()); token != "token-1" {
		t.Errorf("token = %q", token)
	}
	if _, err := FromCredentials(f, api.Credentials{Secrets: map[string]string{"type": "password", "password": "x"}}, "w1"); err == nil {
		t.Error("password credentials have no access token")
	}
}
