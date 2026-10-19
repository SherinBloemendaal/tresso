package api

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestLeaseSendsWorkerAndDecodesAccounts(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost || r.URL.Path != "/internal/sync/leases" {
			t.Errorf("unexpected request %s %s", r.Method, r.URL.Path)
		}
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["workerId"] != "w1" || body["capacity"] != float64(5) {
			t.Errorf("unexpected body %v", body)
		}
		_, _ = w.Write([]byte(`{"leaseSeconds":90,"accounts":[{"id":"x","emailAddress":"a@b.c","provider":"imap","leaseExpiresAt":"2026-10-18T21:36:09+00:00"}]}`))
	}))
	defer srv.Close()

	resp, err := NewWithHTTPClient(srv.URL+"/", srv.Client()).Lease(context.Background(), "w1", 5)
	if err != nil {
		t.Fatal(err)
	}
	if resp.LeaseSeconds != 90 || len(resp.Accounts) != 1 || resp.Accounts[0].Provider != "imap" {
		t.Fatalf("unexpected response %+v", resp)
	}
}

func TestErrorStatusIsReported(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		http.Error(w, "nope", http.StatusForbidden)
	}))
	defer srv.Close()

	err := NewWithHTTPClient(srv.URL, srv.Client()).Release(context.Background(), "w1")
	if err == nil || !strings.Contains(err.Error(), "403") {
		t.Fatalf("expected a 403 error, got %v", err)
	}
}

func TestCredentialsSendsWorkerAndDecodes(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet || r.URL.Path != "/internal/sync/accounts/acc-1/credentials" {
			t.Errorf("unexpected request %s %s", r.Method, r.URL.Path)
		}
		if r.URL.Query().Get("workerId") != "w 1" {
			t.Errorf("workerId not sent: %q", r.URL.RawQuery)
		}
		if r.Header.Get("Content-Type") != "" {
			t.Errorf("GET must not send a body content type")
		}
		_, _ = w.Write([]byte(`{"accountId":"acc-1","emailAddress":"kim@example.test","provider":"imap",` +
			`"connection":{"imap":{"host":"imap.example.test","port":993,"security":"tls"},"smtp":{"host":"smtp.example.test","port":465,"security":"tls"},"username":"kim"},` +
			`"credentials":{"type":"password","password":"s3cret"}}`))
	}))
	defer srv.Close()

	creds, err := NewWithHTTPClient(srv.URL, srv.Client()).Credentials(context.Background(), "acc-1", "w 1")
	if err != nil {
		t.Fatal(err)
	}
	if creds.Connection.IMAP.Port != 993 || creds.Connection.Username != "kim" || creds.Provider != "imap" {
		t.Fatalf("unexpected credentials %+v", creds)
	}
	password, err := creds.Password()
	if err != nil || password != "s3cret" {
		t.Fatalf("password = %q, %v", password, err)
	}
}

func TestPasswordRequiresPasswordCredentials(t *testing.T) {
	_, err := Credentials{AccountID: "a", Secrets: map[string]string{"type": "oauth"}}.Password()
	if err == nil {
		t.Fatal("expected an error for non-password credentials")
	}
}

func TestCredentialsNotLeasedIsAnError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		http.Error(w, "not leased", http.StatusNotFound)
	}))
	defer srv.Close()

	_, err := NewWithHTTPClient(srv.URL, srv.Client()).Credentials(context.Background(), "acc-1", "w1")
	if err == nil || !strings.Contains(err.Error(), "404") {
		t.Fatalf("expected a 404 error, got %v", err)
	}
}

func TestIngestCallsSendArraysNotNull(t *testing.T) {
	var bodies []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		bodies = append(bodies, string(b))
		_, _ = w.Write([]byte(`{}`))
	}))
	defer srv.Close()
	c := NewWithHTTPClient(srv.URL, srv.Client())

	_, _ = c.Messages(context.Background(), "a", MessageBatch{WorkerID: "w", Folder: "INBOX", Messages: []Message{{RemoteUID: "1"}}})
	_, _ = c.Changes(context.Background(), "a", ChangeBatch{WorkerID: "w", Folder: "INBOX", Presence: []PresenceRange{{FromUID: 1, ToUID: 2}}})

	for _, body := range bodies {
		if strings.Contains(body, "null") && !strings.Contains(body, `"cursor":null`) {
			t.Errorf("body contains null: %s", body)
		}
	}
	if !strings.Contains(bodies[0], `"references":[]`) || !strings.Contains(bodies[1], `"uids":[]`) || !strings.Contains(bodies[1], `"vanished":[]`) {
		t.Errorf("empty arrays missing: %v", bodies)
	}
}

func TestRevokedTokenIsReplacedAndTheRequestRetried(t *testing.T) {
	var issued, rejected int
	mux := http.NewServeMux()
	mux.HandleFunc("POST /oauth/token", func(w http.ResponseWriter, _ *http.Request) {
		issued++
		w.Header().Set("Content-Type", "application/json")
		_, _ = fmt.Fprintf(w, `{"access_token":"token-%d","token_type":"Bearer","expires_in":3600}`, issued)
	})
	mux.HandleFunc("POST /internal/sync/leases", func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		if r.Header.Get("Authorization") == "Bearer token-1" {
			rejected++
			http.Error(w, "revoked", http.StatusUnauthorized)
			return
		}
		if !strings.Contains(string(body), `"workerId":"w1"`) {
			t.Errorf("retried request lost its body: %s", body)
		}
		_, _ = w.Write([]byte(`{"leaseSeconds":90,"accounts":[]}`))
	})
	srv := httptest.NewServer(mux)
	defer srv.Close()

	client := New(context.Background(), srv.URL, srv.URL+"/oauth/token", "sync-worker", "secret")
	if _, err := client.Lease(context.Background(), "w1", 10); err != nil {
		t.Fatal(err)
	}
	if issued != 2 || rejected != 1 {
		t.Errorf("issued %d tokens, %d rejected; want a fresh token after one rejection", issued, rejected)
	}

	// A second rejection with the fresh token is reported, not retried forever.
	mux2 := http.NewServeMux()
	mux2.HandleFunc("POST /oauth/token", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"access_token":"x","token_type":"Bearer","expires_in":3600}`))
	})
	mux2.HandleFunc("POST /internal/sync/leases", func(w http.ResponseWriter, _ *http.Request) {
		http.Error(w, "no", http.StatusUnauthorized)
	})
	srv2 := httptest.NewServer(mux2)
	defer srv2.Close()
	if _, err := New(context.Background(), srv2.URL, srv2.URL+"/oauth/token", "a", "b").Lease(context.Background(), "w1", 1); err == nil || !strings.Contains(err.Error(), "401") {
		t.Errorf("expected a 401 error, got %v", err)
	}
}
