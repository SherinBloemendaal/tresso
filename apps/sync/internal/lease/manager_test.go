package lease

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"slices"
	"sync"
	"testing"
	"time"

	"tresso/sync/internal/api"
)

type fakeLeaser struct {
	mu       sync.Mutex
	accounts []api.Account
	err      error
	released bool
}

func (f *fakeLeaser) Lease(context.Context, string, int) (api.LeaseResponse, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.err != nil {
		return api.LeaseResponse{}, f.err
	}
	return api.LeaseResponse{LeaseSeconds: 90, Accounts: slices.Clone(f.accounts)}, nil
}

func (f *fakeLeaser) Release(context.Context, string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.released = true
	return nil
}

type blockingSyncer struct{ stopped chan string }

func (s blockingSyncer) Run(ctx context.Context, account api.Account) error {
	<-ctx.Done()
	s.stopped <- account.ID
	return ctx.Err()
}

func newTestManager(leaser Leaser, stopped chan string) *Manager {
	factory := func(provider string) (Syncer, error) {
		if provider != "imap" {
			return nil, ErrUnsupportedProvider
		}
		return blockingSyncer{stopped: stopped}, nil
	}
	return NewManager(leaser, factory, "w1", 10, time.Hour, slog.New(slog.NewTextHandler(io.Discard, nil)))
}

func sorted(ids []string) []string {
	slices.Sort(ids)
	return ids
}

func TestHeartbeatStartsAndStopsSyncers(t *testing.T) {
	stopped := make(chan string, 10)
	leaser := &fakeLeaser{accounts: []api.Account{{ID: "a", Provider: "imap"}, {ID: "b", Provider: "imap"}, {ID: "c", Provider: "pop3"}}}
	m := newTestManager(leaser, stopped)
	ctx := context.Background()

	m.Heartbeat(ctx)
	if got := sorted(m.Running()); !slices.Equal(got, []string{"a", "b"}) {
		t.Fatalf("running after first heartbeat = %v, want [a b] (unsupported provider skipped)", got)
	}

	leaser.accounts = []api.Account{{ID: "b", Provider: "imap"}}
	m.Heartbeat(ctx)
	if got := m.Running(); !slices.Equal(got, []string{"b"}) {
		t.Fatalf("running after lease loss = %v, want [b]", got)
	}
	if id := <-stopped; id != "a" {
		t.Fatalf("stopped %q, want a", id)
	}
}

func TestFailedHeartbeatKeepsSyncersUntilLeaseTimeout(t *testing.T) {
	stopped := make(chan string, 10)
	leaser := &fakeLeaser{accounts: []api.Account{{ID: "a", Provider: "imap"}}}
	m := newTestManager(leaser, stopped)
	ctx := context.Background()

	m.Heartbeat(ctx)
	leaser.err = errors.New("api down")

	m.Heartbeat(ctx)
	if got := m.Running(); !slices.Equal(got, []string{"a"}) {
		t.Fatalf("running after one failed heartbeat = %v, want [a]", got)
	}

	// Pretend the last successful heartbeat was longer ago than the lease timeout.
	m.mu.Lock()
	m.lastBeat = time.Now().Add(-2 * time.Minute)
	m.mu.Unlock()

	m.Heartbeat(ctx)
	if got := m.Running(); len(got) != 0 {
		t.Fatalf("running after lease timeout = %v, want none", got)
	}
	if _, _, err := m.Status(); err == nil {
		t.Fatal("status should report the last heartbeat error")
	}
}

func TestRunReleasesLeasesOnShutdown(t *testing.T) {
	stopped := make(chan string, 10)
	leaser := &fakeLeaser{accounts: []api.Account{{ID: "a", Provider: "imap"}}}
	m := newTestManager(leaser, stopped)

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		m.Run(ctx)
		close(done)
	}()

	deadline := time.After(2 * time.Second)
	for len(m.Running()) == 0 {
		select {
		case <-deadline:
			t.Fatal("syncer never started")
		default:
			time.Sleep(5 * time.Millisecond)
		}
	}
	cancel()
	<-done

	if !leaser.released {
		t.Fatal("leases were not released on shutdown")
	}
	if id := <-stopped; id != "a" {
		t.Fatalf("stopped %q, want a", id)
	}
}
