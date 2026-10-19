package storage

import (
	"context"
	"regexp"
	"testing"
)

func TestKeysMatchWhatTheAPIAccepts(t *testing.T) {
	sha := SHA256([]byte("hello"))
	if !regexp.MustCompile(`^raw/[0-9a-f]{2}/[0-9a-f]{64}$`).MatchString(RawKey(sha)) {
		t.Errorf("raw key %q", RawKey(sha))
	}
	if AttachmentKey(sha) != "attachments/"+sha[:2]+"/"+sha {
		t.Errorf("attachment key %q", AttachmentKey(sha))
	}
}

func TestMemoryStoresEachKeyOnce(t *testing.T) {
	m := NewMemory()
	ctx := context.Background()
	_ = m.PutIfAbsent(ctx, "a", []byte("1"), "text/plain")
	_ = m.PutIfAbsent(ctx, "a", []byte("2"), "text/plain")
	if m.Puts != 1 || string(m.Objects["a"]) != "1" {
		t.Errorf("puts=%d value=%q", m.Puts, m.Objects["a"])
	}
}
