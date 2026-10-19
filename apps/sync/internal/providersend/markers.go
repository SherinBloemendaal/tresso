package providersend

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"
)

// OutgoingMarker is the value of OutgoingIDHeader on a sent message. Recipients see the header, so it carries nothing
// internal (an outbox id is a UUIDv7, which tells when the message was written): it is a hash of the outbox id and the
// Message-ID, which the worker computes the same way when it looks for the copy.
func OutgoingMarker(outboxID, messageID string) string {
	return marker("tresso outgoing", outboxID, messageID)
}

// DraftCopyMarker is the value of DraftCopyHeader on a draft copy composed with messageID.
func DraftCopyMarker(messageID string) string {
	return marker("tresso draft copy", messageID)
}

func marker(label string, parts ...string) string {
	sum := sha256.Sum256([]byte(label + "\x00" + strings.Join(parts, "\x00")))
	return hex.EncodeToString(sum[:])
}

// UUIDv7Time returns the time a UUIDv7 was made at (its first 48 bits, milliseconds since the epoch), and false for
// anything that is not a UUIDv7.
func UUIDv7Time(id string) (time.Time, bool) {
	if len(id) != 36 || id[8] != '-' || id[13] != '-' || id[18] != '-' || id[23] != '-' || id[14] != '7' {
		return time.Time{}, false
	}
	ms, err := strconv.ParseUint(id[:8]+id[9:13], 16, 64)
	if err != nil {
		return time.Time{}, false
	}
	return time.UnixMilli(int64(ms)).UTC(), true
}

// ErrNotDecided means a duplicate check cannot tell yet whether an earlier attempt sent the message (a draft the
// provider may still be sending, or a leftover it could not clean up): the outbox tries again later, without sending.
var ErrNotDecided = errors.New("not decided yet whether an earlier attempt sent the message")

// ErrMaybeSending is ErrNotDecided because an earlier attempt's copy may still be on its way (in the Outbox, or a draft
// whose send the provider accepted moments ago): it could still be delivered.
var ErrMaybeSending = fmt.Errorf("%w: an earlier attempt may still be sending", ErrNotDecided)
