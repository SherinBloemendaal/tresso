package api

import (
	"context"
	"encoding/json"
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
