package providersend

import (
	"fmt"
	"strings"
	"testing"
	"time"
)

func TestMarkersAreOpaqueAndStable(t *testing.T) {
	id := "019a0b3c-1d2e-7f00-8a00-0123456789ab"
	m := OutgoingMarker(id, "x@example.test")
	if len(m) != 64 || strings.Contains(m, "019a0b3c") || m != OutgoingMarker(id, "x@example.test") {
		t.Errorf("marker %q", m)
	}
	if m == OutgoingMarker(id, "y@example.test") || DraftCopyMarker("x@example.test") == OutgoingMarker("", "x@example.test") {
		t.Error("markers must differ for different input and purpose")
	}
}

func TestUUIDv7Time(t *testing.T) {
	at := time.Date(2026, 10, 19, 8, 0, 0, 123e6, time.UTC)
	hex := fmt.Sprintf("%012x", at.UnixMilli())
	id := hex[:8] + "-" + hex[8:12] + "-7abc-8def-0123456789ab"
	if got, ok := UUIDv7Time(id); !ok || !got.Equal(at) {
		t.Errorf("UUIDv7Time(%s) = %v, %v", id, got, ok)
	}
	for _, other := range []string{"", "not-a-uuid", "019a0b3c-1d2e-4f00-8a00-0123456789ab"} {
		if _, ok := UUIDv7Time(other); ok {
			t.Errorf("%q taken as a UUIDv7", other)
		}
	}
}
