package lease

import (
	"context"

	"tresso/sync/internal/api"
)

// WithCompanion runs a companion syncer (calendars) next to the primary one (mail) for the same account and lease.
// The primary decides the account's life: when it returns, the companion is stopped. The companion's own failures
// never end the primary; it is expected to retry by itself.
func WithCompanion(primary, companion Syncer) Syncer {
	return companionSyncer{primary: primary, companion: companion}
}

type companionSyncer struct {
	primary   Syncer
	companion Syncer
}

func (s companionSyncer) Run(ctx context.Context, account api.Account) error {
	companionCtx, cancel := context.WithCancel(ctx)
	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = s.companion.Run(companionCtx, account)
	}()

	err := s.primary.Run(ctx, account)
	cancel()
	<-done
	return err
}
