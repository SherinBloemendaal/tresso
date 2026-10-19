package lease

import (
	"context"
	"errors"
	"testing"
	"time"

	"tresso/sync/internal/api"
)

type funcSyncer func(ctx context.Context, account api.Account) error

func (f funcSyncer) Run(ctx context.Context, account api.Account) error { return f(ctx, account) }

func TestTheCompanionStopsWithThePrimary(t *testing.T) {
	stopped := make(chan struct{})
	companion := funcSyncer(func(ctx context.Context, _ api.Account) error {
		<-ctx.Done()
		close(stopped)
		return ctx.Err()
	})
	refused := errors.New("login refused")
	primary := funcSyncer(func(context.Context, api.Account) error { return refused })

	err := WithCompanion(primary, companion).Run(context.Background(), api.Account{ID: "a1"})

	if !errors.Is(err, refused) {
		t.Fatalf("want the primary's error, got %v", err)
	}
	select {
	case <-stopped:
	case <-time.After(time.Second):
		t.Fatal("the companion kept running")
	}
}

func TestACompanionFailureDoesNotStopThePrimary(t *testing.T) {
	companion := funcSyncer(func(context.Context, api.Account) error { return errors.New("no calendar server") })
	ctx, cancel := context.WithCancel(context.Background())
	primary := funcSyncer(func(ctx context.Context, _ api.Account) error {
		time.Sleep(20 * time.Millisecond)
		cancel()
		<-ctx.Done()
		return ctx.Err()
	})

	err := WithCompanion(primary, companion).Run(ctx, api.Account{ID: "a1"})

	if !errors.Is(err, context.Canceled) {
		t.Fatalf("the primary ran until it was stopped, got %v", err)
	}
}
