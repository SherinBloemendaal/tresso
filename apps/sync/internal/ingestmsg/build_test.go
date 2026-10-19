package ingestmsg

import (
	"context"
	"testing"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/storage"
)

func TestBuildStoresRawAndDescribesTheMessage(t *testing.T) {
	store := storage.NewMemory()
	raw := []byte("From: Sam <sam@example.test>\r\nTo: kim@example.test\r\nSubject: Hello\r\nMessage-ID: <a@example.test>\r\nDate: Mon, 19 Oct 2026 10:00:00 +0000\r\n\r\nBody\r\n")
	received := time.Date(2026, 10, 19, 12, 0, 0, 0, time.FixedZone("x", 3600))

	msg, err := Build(context.Background(), store, raw, "gmail-id", received, api.Flags{Seen: true})
	if err != nil {
		t.Fatal(err)
	}
	if msg.RemoteUID != "gmail-id" || !msg.Flags.Seen || msg.Subject != "Hello" || msg.From.Address != "sam@example.test" || msg.Size != int64(len(raw)) || msg.BodyText != "Body" {
		t.Errorf("message = %+v", msg)
	}
	if !msg.ReceivedAt.Equal(received) || msg.ReceivedAt.Location() != time.UTC {
		t.Errorf("receivedAt = %v", msg.ReceivedAt)
	}
	if string(store.Objects[msg.RawObjectKey]) != string(raw) {
		t.Error("raw message not stored under its key")
	}

	// Without a provider date the Date header is used.
	msg, _ = Build(context.Background(), store, raw, "x", time.Time{}, api.Flags{})
	if !msg.ReceivedAt.Equal(time.Date(2026, 10, 19, 10, 0, 0, 0, time.UTC)) {
		t.Errorf("fallback receivedAt = %v", msg.ReceivedAt)
	}
}

func TestBuildSendsTheSignalHeaders(t *testing.T) {
	raw := []byte("From: news@shop.example\r\nPrecedence: bulk\r\nList-Unsubscribe: <https://shop.example/u>\r\nSubject: Deals\r\n\r\nBody\r\n")

	msg, err := Build(context.Background(), storage.NewMemory(), raw, "x", time.Time{}, api.Flags{})
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

	msg, err := Build(context.Background(), storage.NewMemory(), raw, "gmail-id", time.Now(), api.Flags{})
	if err != nil {
		t.Fatal(err)
	}

	if len(msg.CalendarParts) != 1 || msg.CalendarParts[0].Method == nil || *msg.CalendarParts[0].Method != "REQUEST" {
		t.Fatalf("calendar parts = %+v", msg.CalendarParts)
	}
}
