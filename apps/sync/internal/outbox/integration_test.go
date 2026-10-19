//go:build integration

// Integration test against a real mail server (GreenMail): the whole sending path, SMTP delivery, the copy in Sent and
// the protection against sending twice. Run with:
//
//	GREENMAIL_HOST=localhost go test -tags integration ./internal/outbox
//
// It uses lisa@tresso.test, so it never touches the mailbox the IMAP sync tests empty.
package outbox

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"net"
	"os"
	"strconv"
	"testing"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapclient"

	"tresso/sync/internal/api"
	"tresso/sync/internal/imapsync"
	"tresso/sync/internal/netguard"
	"tresso/sync/internal/smtpsend"
	"tresso/sync/internal/storage"
)

const (
	sender   = "lisa@tresso.test"
	password = "dev-password-lisa"
)

func port(name string, fallback int) int {
	if value := os.Getenv(name); value != "" {
		p, _ := strconv.Atoi(value)
		return p
	}
	return fallback
}

type integrationAPI struct {
	credentials api.Credentials
	messages    []api.OutgoingMessage
	reports     []report
	drafts      api.DraftWork
	filed       []string
	removed     []string
}

func (a *integrationAPI) Credentials(context.Context, string, string) (api.Credentials, error) {
	return a.credentials, nil
}

func (a *integrationAPI) ClaimOutbox(context.Context, string, string) ([]api.OutgoingMessage, error) {
	claimed := a.messages
	a.messages = nil
	return claimed, nil
}

func (a *integrationAPI) ClaimDrafts(context.Context, string, string) (api.DraftWork, error) {
	work := a.drafts
	a.drafts = api.DraftWork{}
	return work, nil
}

func (a *integrationAPI) ReportDraftFiled(_ context.Context, _, draftID, _ string, version int, messageID string) error {
	a.filed = append(a.filed, fmt.Sprintf("%s v%d %s", draftID, version, messageID))
	return nil
}

func (a *integrationAPI) ReportDraftRemoved(_ context.Context, _, removalID, _ string) error {
	a.removed = append(a.removed, removalID)
	return nil
}

func (a *integrationAPI) ReportOutbox(_ context.Context, _, messageID, _, outcome, text string, copied bool) error {
	a.reports = append(a.reports, report{messageID, outcome, text, copied})
	return nil
}

func imapClient(t *testing.T, host string) *imapclient.Client {
	t.Helper()
	client, err := imapclient.DialInsecure(net.JoinHostPort(host, strconv.Itoa(port("GREENMAIL_IMAP_PORT", 3143))), nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := client.Login(sender, password).Wait(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = client.Close() })
	return client
}

func count(t *testing.T, client *imapclient.Client, mailbox, messageID string) int {
	t.Helper()
	if _, err := client.Select(mailbox, &imap.SelectOptions{ReadOnly: true}).Wait(); err != nil {
		t.Fatal(err)
	}
	data, err := client.UIDSearch(&imap.SearchCriteria{Header: []imap.SearchCriteriaHeaderField{{Key: "Message-ID", Value: "<" + messageID + ">"}}}, nil).Wait()
	if err != nil {
		t.Fatal(err)
	}
	return len(data.AllUIDs())
}

func TestSendsFilesAndNeverSendsTwice(t *testing.T) {
	host := os.Getenv("GREENMAIL_HOST")
	if host == "" {
		t.Skip("GREENMAIL_HOST is not set")
	}
	check := imapClient(t, host)
	// GreenMail starts with an INBOX only; a folder named Sent is recognized by its name.
	_ = check.Create("Sent", nil).Wait()

	messageID := fmt.Sprintf("outbox-%d@tresso.test", time.Now().UnixNano())
	raw := "From: Lisa <lisa@tresso.test>\r\nTo: Lisa <lisa@tresso.test>\r\nSubject: Outbox integration\r\nMessage-ID: <" + messageID + ">\r\nDate: " + time.Now().Format(time.RFC1123Z) + "\r\n\r\nHello from the outbox.\r\n"
	store := storage.NewMemory()
	_ = store.PutIfAbsent(context.Background(), "outgoing/aa/m1", []byte(raw), "message/rfc822")

	dialer := netguard.New(true)
	client := &integrationAPI{credentials: api.Credentials{
		AccountID: "acc",
		Connection: api.Connection{
			IMAP:     api.ServerSettings{Host: host, Port: port("GREENMAIL_IMAP_PORT", 3143), Security: "none"},
			SMTP:     api.ServerSettings{Host: host, Port: port("GREENMAIL_SMTP_PORT", 3025), Security: "none"},
			Username: sender,
		},
		Secrets: map[string]string{"type": "password", "password": password},
	}}
	send := func(ctx context.Context, server api.ServerSettings, username, password string, envelope smtpsend.Envelope, raw []byte) error {
		return smtpsend.Send(ctx, dialer, server, username, password, envelope, raw)
	}
	openSent := func(ctx context.Context, credentials api.Credentials) (SentStore, error) {
		return imapsync.OpenSent(ctx, dialer, credentials)
	}
	openDrafts := func(ctx context.Context, credentials api.Credentials) (DraftsStore, error) {
		return imapsync.OpenDrafts(ctx, dialer, credentials)
	}
	dispatcher := NewDispatcher(client, store, send, openSent, openDrafts, "w1", slog.New(slog.NewTextHandler(io.Discard, nil)))
	message := api.OutgoingMessage{ID: "m1", RawObjectKey: "outgoing/aa/m1", EnvelopeFrom: sender, Recipients: []string{sender}, MessageID: messageID, Attempt: 1}

	client.messages = []api.OutgoingMessage{message}
	dispatcher.Trigger(context.Background(), "acc")
	dispatcher.Wait()

	if len(client.reports) != 1 || client.reports[0] != (report{"m1", api.OutcomeSent, "", true}) {
		t.Fatalf("reports = %+v", client.reports)
	}
	deadline := time.Now().Add(5 * time.Second)
	for count(t, check, "INBOX", messageID) == 0 {
		if time.Now().After(deadline) {
			t.Fatal("the message was not delivered")
		}
		time.Sleep(100 * time.Millisecond)
	}
	if n := count(t, check, "Sent", messageID); n != 1 {
		t.Fatalf("copies in Sent = %d", n)
	}

	// The worker crashed before reporting; the claim expired and the message comes back as attempt 2.
	message.Attempt = 2
	client.messages = []api.OutgoingMessage{message}
	dispatcher.Trigger(context.Background(), "acc")
	dispatcher.Wait()

	if len(client.reports) != 2 || client.reports[1] != (report{"m1", api.OutcomeSent, "", true}) {
		t.Fatalf("reports = %+v", client.reports)
	}
	time.Sleep(500 * time.Millisecond)
	if n := count(t, check, "INBOX", messageID); n != 1 {
		t.Errorf("deliveries = %d, want exactly one", n)
	}
	if n := count(t, check, "Sent", messageID); n != 1 {
		t.Errorf("copies in Sent = %d, want exactly one", n)
	}
}

func TestKeepsTheDraftsFolderInStep(t *testing.T) {
	host := os.Getenv("GREENMAIL_HOST")
	if host == "" {
		t.Skip("GREENMAIL_HOST is not set")
	}
	check := imapClient(t, host)
	stamp := time.Now().UnixNano()
	first := fmt.Sprintf("draft-%d.1@tresso.test", stamp)
	second := fmt.Sprintf("draft-%d.2@tresso.test", stamp)
	raw := func(id string) []byte {
		return []byte("From: Lisa <lisa@tresso.test>\r\nTo: anna@finance.example\r\nSubject: Draft\r\nMessage-ID: <" + id + ">\r\n\r\nNot done yet.\r\n")
	}
	store := storage.NewMemory()
	_ = store.PutIfAbsent(context.Background(), "drafts/aa/1", raw(first), "message/rfc822")
	_ = store.PutIfAbsent(context.Background(), "drafts/aa/2", raw(second), "message/rfc822")

	dialer := netguard.New(true)
	client := &integrationAPI{credentials: api.Credentials{
		AccountID: "acc",
		Connection: api.Connection{
			IMAP:     api.ServerSettings{Host: host, Port: port("GREENMAIL_IMAP_PORT", 3143), Security: "none"},
			Username: sender,
		},
		Secrets: map[string]string{"type": "password", "password": password},
	}}
	openDrafts := func(ctx context.Context, credentials api.Credentials) (DraftsStore, error) {
		return imapsync.OpenDrafts(ctx, dialer, credentials)
	}
	noSend := func(context.Context, api.ServerSettings, string, string, smtpsend.Envelope, []byte) error { return nil }
	noSent := func(context.Context, api.Credentials) (SentStore, error) { return nil, imapsync.ErrNoSentFolder }
	dispatcher := NewDispatcher(client, store, noSend, noSent, openDrafts, "w1", slog.New(slog.NewTextHandler(io.Discard, nil)))
	run := func() {
		dispatcher.Trigger(context.Background(), "acc")
		dispatcher.Wait()
	}

	client.drafts = api.DraftWork{Files: []api.DraftFile{{DraftID: "d1", Version: 1, RawObjectKey: "drafts/aa/1", MessageID: first}}}
	run()
	if n := count(t, check, "Drafts", first); n != 1 {
		t.Fatalf("first version in Drafts = %d", n)
	}

	client.drafts = api.DraftWork{Files: []api.DraftFile{{DraftID: "d1", Version: 2, RawObjectKey: "drafts/aa/2", MessageID: second, PreviousMessageID: &first}}}
	run()
	if count(t, check, "Drafts", first) != 0 || count(t, check, "Drafts", second) != 1 {
		t.Fatalf("after the second version: first %d, second %d", count(t, check, "Drafts", first), count(t, check, "Drafts", second))
	}

	client.drafts = api.DraftWork{Removals: []api.DraftRemoval{{ID: "r1", MessageID: second}}}
	run()
	if n := count(t, check, "Drafts", second); n != 0 {
		t.Fatalf("sent draft still in Drafts: %d", n)
	}
	if len(client.filed) != 2 || len(client.removed) != 1 {
		t.Errorf("filed %v, removed %v", client.filed, client.removed)
	}
}
