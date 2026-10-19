package realtime

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
	"time"

	"tresso/sync/internal/api"
)

type tokens struct {
	mu     sync.Mutex
	hub    string
	expiry time.Duration
	issued int
}

func (t *tokens) RealtimeToken(context.Context) (api.RealtimeAccess, error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.issued++
	return api.RealtimeAccess{HubURL: t.hub, Topic: "urn:tresso:sync:outbox", Token: fmt.Sprintf("token-%d", t.issued), ExpiresAt: time.Now().Add(t.expiry)}, nil
}

func TestReportsOutboxUpdates(t *testing.T) {
	var seen sync.Map
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seen.Store("auth", r.Header.Get("Authorization"))
		seen.Store("match", r.URL.Query().Get("match"))
		w.Header().Set("Content-Type", "text/event-stream")
		w.WriteHeader(http.StatusOK)
		_, _ = io.WriteString(w, ":\n\nevent: outbox\nid: urn:uuid:1\ndata: {\"mailAccountId\":\"a1\"}\n\nevent: changes\ndata: {\"cursor\":3}\n\nevent: outbox\ndata: not json\n\nevent: outbox\r\ndata: {\"mailAccountId\":\"a2\"}\r\n\r\n")
		w.(http.Flusher).Flush()
		<-r.Context().Done()
	}))
	defer server.Close()

	got := make(chan string, 4)
	subscriber := NewSubscriber(&tokens{hub: server.URL + "/.well-known/mercure", expiry: time.Hour}, server.Client(), func(id string) { got <- id }, slog.New(slog.NewTextHandler(io.Discard, nil)))
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { subscriber.Run(ctx); close(done) }()

	for _, want := range []string{"a1", "a2"} {
		select {
		case id := <-got:
			if id != want {
				t.Fatalf("account = %s, want %s", id, want)
			}
		case <-time.After(5 * time.Second):
			t.Fatalf("no update for %s", want)
		}
	}
	cancel()
	<-done

	if auth, _ := seen.Load("auth"); auth != "Bearer token-1" {
		t.Errorf("authorization = %v", auth)
	}
	if match, _ := seen.Load("match"); match != "urn:tresso:sync:outbox" {
		t.Errorf("match = %v", match)
	}
}

func TestReconnectsAndRenewsTheToken(t *testing.T) {
	var mu sync.Mutex
	connections := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		connections++
		n := connections
		mu.Unlock()
		if n == 1 {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		w.WriteHeader(http.StatusOK)
		w.(http.Flusher).Flush()
		<-r.Context().Done()
	}))
	defer server.Close()

	source := &tokens{hub: server.URL, expiry: 50 * time.Millisecond}
	subscriber := NewSubscriber(source, server.Client(), func(string) {}, slog.New(slog.NewTextHandler(io.Discard, nil)))
	subscriber.retryDelays = []time.Duration{time.Millisecond}
	subscriber.renewBefore = 0
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { subscriber.Run(ctx); close(done) }()

	deadline := time.Now().Add(5 * time.Second)
	for {
		mu.Lock()
		n := connections
		mu.Unlock()
		if n >= 4 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("connections = %d; want a retry after the refusal and reconnects for renewed tokens", n)
		}
		time.Sleep(10 * time.Millisecond)
	}
	cancel()
	<-done
}
