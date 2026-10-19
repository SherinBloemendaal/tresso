package graphsync

import (
	"testing"
	"time"

	"tresso/sync/internal/fakeapi"
)

// Clients choose draft ids, so a device whose clock runs ahead must not move the search past its own copies.
func TestDraftSearchAllowsForAClientClockRunningAhead(t *testing.T) {
	now := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	filed := now.Add(-time.Hour)
	draftID := fakeapi.UUIDv7(now.Add(3*time.Hour), 1)

	since := draftsSince(draftID+".2@tresso.test", now)

	if !since.Before(filed) {
		t.Fatalf("search starts at %s, after the copy filed at %s", since, filed)
	}
}
