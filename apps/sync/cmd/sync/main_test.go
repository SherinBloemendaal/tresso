package main

import (
	"context"
	"testing"

	"tresso/sync/internal/api"
	"tresso/sync/internal/config"
)

// The unsubscribe dialer stays strict when the user's own mail servers may be private (local testing): an address
// from a stranger's message never reaches this machine or its network.
func TestTheUnsubscriberRefusesPrivateAddressesWhateverTheConfig(t *testing.T) {
	for _, allowUnsafe := range []bool{false, true} {
		sender := newUnsubscriber(config.Config{AllowUnsafeServers: allowUnsafe})

		outcome, text := sender.Post(context.Background(), "https://127.0.0.1/unsubscribe")

		// Refused before connecting (failed); a dialer that allowed it would find nothing listening and retry.
		if outcome != api.OutcomeFailed || text != "The unsubscribe address is not public." {
			t.Errorf("AllowUnsafeServers=%v: outcome = %q %q, want failed: not public", allowUnsafe, outcome, text)
		}
	}
}
