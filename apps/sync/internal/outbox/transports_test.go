package outbox

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"slices"
	"strings"
	"testing"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/fakeapi"
	"tresso/sync/internal/gmailsync"
	"tresso/sync/internal/graphsync"
	"tresso/sync/internal/providersend"
	"tresso/sync/internal/storage"
)

// providerServer accepts only the renewed token, so every send first meets a 401 with the token the credentials
// carried, and records what was delivered.
func providerServer(t *testing.T, path string, decode func(body []byte) string) (*httptest.Server, *[]string) {
	t.Helper()
	var delivered []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer token-2" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		if r.Method != http.MethodPost || r.URL.Path != path {
			t.Errorf("unexpected request %s %s", r.Method, r.URL.Path)
		}
		body, _ := io.ReadAll(r.Body)
		delivered = append(delivered, decode(body))
		w.WriteHeader(http.StatusAccepted)
	}))
	t.Cleanup(srv.Close)
	return srv, &delivered
}

const outgoing = "From: kim@example.test\r\nTo: anna@example.test\r\nSubject: Hi\r\nMessage-ID: <o1@example.test>\r\n\r\nHello\r\n"

func oauthCredentials(t *testing.T, client *fakeapi.API, provider string) api.Credentials {
	t.Helper()
	creds, err := client.Credentials(context.Background(), "acc", "w1")
	if err != nil {
		t.Fatal(err)
	}
	creds.Provider = provider
	return creds
}

func TestGmailTransportRenewsTheTokenAndAddsBcc(t *testing.T) {
	srv, delivered := providerServer(t, "/gmail/v1/users/me/messages/send", func(body []byte) string {
		var payload struct{ Raw string }
		_ = json.Unmarshal(body, &payload)
		raw, _ := base64.URLEncoding.DecodeString(payload.Raw)
		return string(raw)
	})
	client := fakeapi.New()
	opts := gmailsync.DefaultClientOptions()
	opts.BaseURL = srv.URL
	transport := GmailTransport(client, "w1", opts)
	creds := oauthCredentials(t, client, "gmail")

	sender, err := transport.Open(creds)
	if err != nil {
		t.Fatalf("an access token is enough to send: %v", err)
	}
	err = sender.Send(context.Background(), api.OutgoingMessage{ID: "o1", EnvelopeFrom: "kim@example.test", Recipients: []string{"anna@example.test", "boss@example.test"}}, []byte(outgoing))
	if err != nil {
		t.Fatal(err)
	}
	if client.Refreshes != 1 || len(*delivered) != 1 || !strings.HasPrefix((*delivered)[0], "Bcc: <boss@example.test>\r\nX-Tresso-Outgoing-Id: "+providersend.OutgoingMarker("o1", "")+"\r\nFrom:") {
		t.Errorf("refreshes %d, delivered %q", client.Refreshes, *delivered)
	}
}

func TestGraphTransportRenewsTheTokenAndAddsBcc(t *testing.T) {
	// Graph sends through a draft: create it from the MIME, mark it, send it.
	var delivered []string
	var flow []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer token-2" {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		flow = append(flow, r.Method+" "+r.URL.Path)
		body, _ := io.ReadAll(r.Body)
		switch {
		case r.Method == http.MethodPost && r.URL.Path == "/v1.0/me/messages":
			raw, _ := base64.StdEncoding.DecodeString(string(body))
			delivered = append(delivered, string(raw))
			w.WriteHeader(http.StatusCreated)
			_, _ = w.Write([]byte(`{"id":"d1"}`))
		case r.Method == http.MethodPatch && r.URL.Path == "/v1.0/me/messages/d1":
			if !strings.Contains(string(body), providersend.OutgoingMarker("o1", "")) {
				t.Errorf("draft marked with %s", body)
			}
			_, _ = w.Write([]byte(`{"id":"d1"}`))
		case r.Method == http.MethodPost && r.URL.Path == "/v1.0/me/messages/d1/send":
			w.WriteHeader(http.StatusAccepted)
		default:
			t.Errorf("unexpected request %s %s", r.Method, r.URL.Path)
		}
	}))
	t.Cleanup(srv.Close)
	client := fakeapi.New()
	opts := graphsync.DefaultClientOptions()
	opts.BaseURL = srv.URL + "/v1.0"
	sender, err := GraphTransport(client, "w1", opts).Open(oauthCredentials(t, client, "microsoft"))
	if err != nil {
		t.Fatal(err)
	}
	err = sender.Send(context.Background(), api.OutgoingMessage{ID: "o1", Recipients: []string{"anna@example.test", "boss@example.test"}}, []byte(outgoing))
	if err != nil {
		t.Fatal(err)
	}
	if client.Refreshes != 1 || len(delivered) != 1 || !strings.HasPrefix(delivered[0], "Bcc: <boss@example.test>\r\nX-Tresso-Outgoing-Id: "+providersend.OutgoingMarker("o1", "")+"\r\nFrom:") {
		t.Errorf("refreshes %d, delivered %q", client.Refreshes, delivered)
	}
	if want := []string{"POST /v1.0/me/messages", "PATCH /v1.0/me/messages/d1", "POST /v1.0/me/messages/d1/send"}; !slices.Equal(flow, want) {
		t.Errorf("flow = %v, want %v", flow, want)
	}
}

func TestProviderTransportWithLostAccessIsATemporaryFailure(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
	}))
	defer srv.Close()
	client := fakeapi.New()
	opts := gmailsync.DefaultClientOptions()
	opts.BaseURL = srv.URL
	creds := oauthCredentials(t, client, "gmail")

	sender, err := GmailTransport(client, "w1", opts).Open(creds)
	if err != nil {
		t.Fatal(err)
	}
	err = sender.Send(context.Background(), api.OutgoingMessage{ID: "o1", Recipients: []string{"anna@example.test"}}, []byte(outgoing))

	outcome, text := outcomeOf(err)
	var sendErr *providersend.Error
	if !errors.As(err, &sendErr) || outcome != api.OutcomeRetry || text != "Tresso lost access to your Gmail account. Sign in again to send." {
		t.Errorf("err %v: outcome %s, text %q", err, outcome, text)
	}
}

func TestIMAPTransportNeedsAPassword(t *testing.T) {
	if _, err := IMAPTransport(nil).Open(api.Credentials{Secrets: map[string]string{"type": "oauth2", "accessToken": "x"}}); err == nil {
		t.Error("an IMAP account without a password cannot send")
	}
}

// outboxAPI serves the fake API's credentials for a provider account and a queue of outgoing messages.
type outboxAPI struct {
	*fakeapi.API
	provider string
	queue    []api.OutgoingMessage
	reports  []report
	drafts   api.DraftWork
	filed    []string
	removed  []string
	// lostClaims answers these draft ids' filed reports with a lost claim.
	lostClaims map[string]bool
	changes    []api.MailboxChange
	changed    []mailboxReport
}

func (o *outboxAPI) Credentials(ctx context.Context, accountID, workerID string) (api.Credentials, error) {
	creds, err := o.API.Credentials(ctx, accountID, workerID)
	creds.Provider = o.provider
	return creds, err
}

func (o *outboxAPI) ClaimOutbox(context.Context, string, string) ([]api.OutgoingMessage, error) {
	queue := o.queue
	o.queue = nil
	return queue, nil
}

func (o *outboxAPI) ReportOutbox(_ context.Context, _, messageID, _, outcome, text string, copied bool) error {
	o.reports = append(o.reports, report{messageID, outcome, text, copied})
	return nil
}

func (o *outboxAPI) ClaimDrafts(context.Context, string, string) (api.DraftWork, error) {
	work := o.drafts
	o.drafts = api.DraftWork{}
	return work, nil
}

func (o *outboxAPI) ReportDraftFiled(_ context.Context, _, draftID, _ string, version int, messageID string) error {
	if o.lostClaims[draftID] {
		return errors.Join(api.ErrClaimLost, errors.New("409"))
	}
	o.filed = append(o.filed, fmt.Sprintf("%s v%d %s", draftID, version, messageID))
	return nil
}

func (o *outboxAPI) ReportDraftRemoved(_ context.Context, _, removalID, _ string) error {
	o.removed = append(o.removed, removalID)
	return nil
}

func (o *outboxAPI) ClaimMailbox(context.Context, string, string) ([]api.MailboxChange, error) {
	changes := o.changes
	o.changes = nil
	return changes, nil
}

func (o *outboxAPI) ReportMailbox(_ context.Context, _, changeID, _, outcome, text, remoteUID string) error {
	o.changed = append(o.changed, mailboxReport{changeID, outcome, text, remoteUID})
	return nil
}

func TestARunSharesOneProviderClientAcrossMessages(t *testing.T) {
	srv, delivered := providerServer(t, "/gmail/v1/users/me/messages/send", func(body []byte) string { return string(body) })
	client := &outboxAPI{API: fakeapi.New(), provider: "gmail"} // its token-1 is refused by Gmail; the renewal is token-2
	store := storage.NewMemory()
	_ = store.PutIfAbsent(context.Background(), "outgoing/aa/o1", []byte(outgoing), "message/rfc822")
	for _, id := range []string{"o1", "o2", "o3"} {
		client.queue = append(client.queue, api.OutgoingMessage{ID: id, RawObjectKey: "outgoing/aa/o1", Recipients: []string{"anna@example.test"}, MessageID: id + "@example.test", Attempt: 1})
	}
	opts := gmailsync.DefaultClientOptions()
	opts.BaseURL = srv.URL
	d := NewDispatcher(client, store, map[string]Transport{"gmail": GmailTransport(client.API, "w1", opts)}, "w1", slog.New(slog.NewTextHandler(io.Discard, nil)))

	d.Trigger(context.Background(), "acc")
	d.Wait()

	if len(*delivered) != 3 || len(client.reports) != 3 {
		t.Fatalf("delivered %d, reports %+v", len(*delivered), client.reports)
	}
	if client.API.Refreshes != 1 {
		t.Errorf("token renewed %d times for one run; want once", client.API.Refreshes)
	}
}

// Fails on the earlier code, which put the raw outbox id (a UUIDv7, so its creation time) in the header recipients see.
func TestSentMessagesCarryNoOutboxID(t *testing.T) {
	srv, delivered := providerServer(t, "/gmail/v1/users/me/messages/send", func(body []byte) string {
		var payload struct{ Raw string }
		_ = json.Unmarshal(body, &payload)
		raw, _ := base64.URLEncoding.DecodeString(payload.Raw)
		return string(raw)
	})
	client := fakeapi.New()
	opts := gmailsync.DefaultClientOptions()
	opts.BaseURL = srv.URL
	sender, err := GmailTransport(client, "w1", opts).Open(oauthCredentials(t, client, "gmail"))
	if err != nil {
		t.Fatal(err)
	}
	id := fakeapi.UUIDv7(time.Now(), 7)
	if err := sender.Send(context.Background(), api.OutgoingMessage{ID: id, MessageID: "o1@example.test", Recipients: []string{"anna@example.test"}}, []byte(outgoing)); err != nil {
		t.Fatal(err)
	}
	if len(*delivered) != 1 || strings.Contains((*delivered)[0], id) {
		t.Fatalf("the outbox id reached the recipient: %q", *delivered)
	}
	if got := providersend.HeaderValue([]byte((*delivered)[0]), providersend.OutgoingIDHeader); got != providersend.OutgoingMarker(id, "o1@example.test") {
		t.Errorf("header = %q", got)
	}
}
