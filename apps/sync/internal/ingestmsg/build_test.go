package ingestmsg

import (
	"context"
	"strings"
	"testing"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/blobcrypt"
	"tresso/sync/internal/storage/storagetest"
)

func TestBuildStoresRawAndDescribesTheMessage(t *testing.T) {
	store := storagetest.New()
	raw := []byte("From: Sam <sam@example.test>\r\nTo: kim@example.test\r\nSubject: Hello\r\nMessage-ID: <a@example.test>\r\nDate: Mon, 19 Oct 2026 10:00:00 +0000\r\n\r\nBody\r\n")
	received := time.Date(2026, 10, 19, 12, 0, 0, 0, time.FixedZone("x", 3600))

	msg, err := Build(context.Background(), store, "acc-1", raw, "gmail-id", received, api.Flags{Seen: true})
	if err != nil {
		t.Fatal(err)
	}
	if msg.RemoteUID != "gmail-id" || !msg.Flags.Seen || msg.Subject != "Hello" || msg.From.Address != "sam@example.test" || msg.Size != int64(len(raw)) || msg.BodyText != "Body" {
		t.Errorf("message = %+v", msg)
	}
	if !msg.ReceivedAt.Equal(received) || msg.ReceivedAt.Location() != time.UTC {
		t.Errorf("receivedAt = %v", msg.ReceivedAt)
	}
	if string(store.Plaintext(msg.RawObjectKey)) != string(raw) || msg.KeyVersion != 1 {
		t.Errorf("raw message not stored under %q with key version 1 (got %d)", msg.RawObjectKey, msg.KeyVersion)
	}
	if parsed, ok := blobcrypt.ParseKey(msg.RawObjectKey); !ok || parsed.Kind != blobcrypt.KindRaw || parsed.OwnerID != storagetest.OwnerID {
		t.Errorf("raw key %q is not the owner's", msg.RawObjectKey)
	}

	// Without a provider date the Date header is used.
	msg, _ = Build(context.Background(), store, "acc-1", raw, "x", time.Time{}, api.Flags{})
	if !msg.ReceivedAt.Equal(time.Date(2026, 10, 19, 10, 0, 0, 0, time.UTC)) {
		t.Errorf("fallback receivedAt = %v", msg.ReceivedAt)
	}
}

func TestBuildSendsTheSignalHeaders(t *testing.T) {
	raw := []byte("From: news@shop.example\r\nPrecedence: bulk\r\nList-Unsubscribe: <https://shop.example/u>\r\nSubject: Deals\r\n\r\nBody\r\n")

	msg, err := Build(context.Background(), storagetest.New(), "acc-1", raw, "x", time.Time{}, api.Flags{})
	if err != nil {
		t.Fatal(err)
	}
	if msg.Headers["precedence"] != "bulk" || msg.Headers["list-unsubscribe"] != "<https://shop.example/u>" || len(msg.Headers) != 2 {
		t.Errorf("headers = %v", msg.Headers)
	}
}

func TestBuildReportsTheCalendarParts(t *testing.T) {
	raw := []byte("From: anna@finance.example\r\nTo: kim@company.example\r\nSubject: Invitation\r\nMIME-Version: 1.0\r\n" +
		"Content-Type: text/calendar; charset=utf-8; method=REQUEST\r\n\r\n" +
		"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nUID:a@finance.example\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")

	msg, err := Build(context.Background(), storagetest.New(), "acc-1", raw, "gmail-id", time.Now(), api.Flags{})
	if err != nil {
		t.Fatal(err)
	}

	if len(msg.CalendarParts) != 1 || msg.CalendarParts[0].Method == nil || *msg.CalendarParts[0].Method != "REQUEST" {
		t.Fatalf("calendar parts = %+v", msg.CalendarParts)
	}
}

func TestBuildEncryptsTheAttachmentsWithTheMessage(t *testing.T) {
	store := storagetest.New()
	raw := []byte("From: anna@finance.example\r\nSubject: Invoice\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n" +
		"--b\r\nContent-Type: text/plain\r\n\r\nAttached.\r\n--b\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=invoice.pdf\r\n\r\n%PDF-1.4 secret\r\n--b--\r\n")

	msg, err := Build(context.Background(), store, "acc-1", raw, "1", time.Now(), api.Flags{})
	if err != nil {
		t.Fatal(err)
	}
	if len(msg.Attachments) != 1 {
		t.Fatalf("attachments = %+v", msg.Attachments)
	}
	key := blobcrypt.Key(blobcrypt.KindAttachments, storagetest.OwnerID, msg.Attachments[0].Fingerprint)
	if got := store.Plaintext(key); string(got) != "%PDF-1.4 secret" {
		t.Errorf("attachment at %q = %q", key, got)
	}
	for k, object := range store.Objects.Objects {
		if strings.Contains(string(object), "secret") {
			t.Errorf("%s holds plaintext", k)
		}
	}
}
