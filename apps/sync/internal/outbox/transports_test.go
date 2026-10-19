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
	"strings"
	"testing"

	"tresso/sync/internal/api"
	"tresso/sync/internal/fakeapi"
	"tresso/sync/internal/gmailsync"
	"tresso/sync/internal/graphsync"
	"tresso/sync/internal/providersend"
	"tresso/sync/internal/smtpsend"
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
	err = sender.Send(context.Background(), smtpsend.Envelope{From: "kim@example.test", Recipients: []string{"anna@example.test", "boss@example.test"}}, []byte(outgoing))
	if err != nil {
		t.Fatal(err)
	}
	if client.Refreshes != 1 || len(*delivered) != 1 || !strings.HasPrefix((*delivered)[0], "Bcc: <boss@example.test>\r\nFrom:") {
		t.Errorf("refreshes %d, delivered %q", client.Refreshes, *delivered)
	}
}

func TestGraphTransportRenewsTheTokenAndAddsBcc(t *testing.T) {
	srv, delivered := providerServer(t, "/v1.0/me/sendMail", func(body []byte) string {
		raw, _ := base64.StdEncoding.DecodeString(string(body))
		return string(raw)
	})
	client := fakeapi.New()
	opts := graphsync.DefaultClientOptions()
	opts.BaseURL = srv.URL + "/v1.0"
	sender, err := GraphTransport(client, "w1", opts).Open(oauthCredentials(t, client, "microsoft"))
	if err != nil {
		t.Fatal(err)
	}
	err = sender.Send(context.Background(), smtpsend.Envelope{Recipients: []string{"anna@example.test", "boss@example.test"}}, []byte(outgoing))
	if err != nil {
		t.Fatal(err)
	}
	if client.Refreshes != 1 || len(*delivered) != 1 || !strings.HasPrefix((*delivered)[0], "Bcc: <boss@example.test>\r\nFrom:") {
		t.Errorf("refreshes %d, delivered %q", client.Refreshes, *delivered)
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
	err = sender.Send(context.Background(), smtpsend.Envelope{Recipients: []string{"anna@example.test"}}, []byte(outgoing))

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
