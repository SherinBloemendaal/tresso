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
