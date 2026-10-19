package outbox

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"slices"
	"sync"
	"testing"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/smtpsend"
	"tresso/sync/internal/storage"
)

type report struct {
	id, outcome, text string
	copied            bool
}

type fakeAPI struct {
	mu       sync.Mutex
	smtpHost string
	queue    [][]api.OutgoingMessage
	reports  []report
	claims   int
	active   int
	overlap  bool
	gate     chan struct{}
}

func (f *fakeAPI) Credentials(context.Context, string, string) (api.Credentials, error) {
	return api.Credentials{
		AccountID:  "a1",
		Connection: api.Connection{SMTP: api.ServerSettings{Host: f.smtpHost, Port: 465, Security: "tls"}, Username: "kim@company.example"},
		Secrets:    map[string]string{"type": "password", "password": "secret"},
	}, nil
}

func (f *fakeAPI) ClaimOutbox(context.Context, string, string) ([]api.OutgoingMessage, error) {
	f.mu.Lock()
	f.claims++
	f.active++
	if f.active > 1 {
		f.overlap = true
	}
	gate := f.gate
	var next []api.OutgoingMessage
	if len(f.queue) > 0 {
		next, f.queue = f.queue[0], f.queue[1:]
	}
	f.mu.Unlock()
	if gate != nil {
		<-gate
	}
	f.mu.Lock()
	f.active--
	f.mu.Unlock()
	return next, nil
}

func (f *fakeAPI) ReportOutbox(_ context.Context, _, messageID, _, outcome, text string, copied bool) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.reports = append(f.reports, report{messageID, outcome, text, copied})
	return nil
}

type fakeSent struct {
	contains map[string]bool
	appended [][]byte
	openErr  error
}

func (s *fakeSent) Contains(id string) (bool, error) { return s.contains[id], nil }
func (s *fakeSent) Append(raw []byte, _ time.Time) error {
	s.appended = append(s.appended, raw)
	return nil
}
func (s *fakeSent) Close() {}

type harness struct {
	api   *fakeAPI
	sent  *fakeSent
	store *storage.Memory
	sends []smtpsend.Envelope
	fail  error
	mu    sync.Mutex
	d     *Dispatcher
}

func newHarness(messages ...api.OutgoingMessage) *harness {
	h := &harness{api: &fakeAPI{smtpHost: "smtp.company.example", queue: [][]api.OutgoingMessage{messages}}, sent: &fakeSent{contains: map[string]bool{}}, store: storage.NewMemory()}
	_ = h.store.PutIfAbsent(context.Background(), "outgoing/ab/raw1", []byte("Subject: Hi\r\n\r\nHello\r\n"), "message/rfc822")
	send := func(_ context.Context, _ api.ServerSettings, _, _ string, envelope smtpsend.Envelope, _ []byte) error {
		h.mu.Lock()
		defer h.mu.Unlock()
		h.sends = append(h.sends, envelope)
		return h.fail
	}
	open := func(context.Context, api.Credentials) (SentStore, error) {
		if h.sent.openErr != nil {
			return nil, h.sent.openErr
		}
		return h.sent, nil
	}
	h.d = NewDispatcher(h.api, h.store, send, open, "w1", slog.New(slog.NewTextHandler(io.Discard, nil)))
	return h
}

func (h *harness) run() {
	h.d.Trigger(context.Background(), "a1")
	h.d.Wait()
}

func message(id string, attempt int) api.OutgoingMessage {
	return api.OutgoingMessage{ID: id, RawObjectKey: "outgoing/ab/raw1", EnvelopeFrom: "kim@company.example", Recipients: []string{"anna@finance.example", "archive@company.example"}, MessageID: id + "@company.example", Attempt: attempt}
}

func TestSendsAndFilesACopy(t *testing.T) {
	h := newHarness(message("m1", 1))

	h.run()

	if len(h.sends) != 1 || !slices.Equal(h.sends[0].Recipients, []string{"anna@finance.example", "archive@company.example"}) || h.sends[0].From != "kim@company.example" {
		t.Fatalf("sends = %+v", h.sends)
	}
	if len(h.sent.appended) != 1 {
		t.Errorf("copies in Sent = %d", len(h.sent.appended))
	}
	if !slices.Equal(h.api.reports, []report{{"m1", api.OutcomeSent, "", true}}) {
		t.Errorf("reports = %+v", h.api.reports)
	}
}

func TestNeverSendsTwiceAfterAnUnreportedAttempt(t *testing.T) {
	h := newHarness(message("m1", 2))
	h.sent.contains["m1@company.example"] = true

	h.run()

	if len(h.sends) != 0 || len(h.sent.appended) != 0 {
		t.Fatalf("sent again: sends %d, copies %d", len(h.sends), len(h.sent.appended))
	}
	if !slices.Equal(h.api.reports, []report{{"m1", api.OutcomeSent, "", true}}) {
		t.Errorf("reports = %+v", h.api.reports)
	}
}

func TestPermanentRefusalFails(t *testing.T) {
	h := newHarness(message("m1", 1))
	h.fail = &smtpsend.Error{Permanent: true, Message: "The outgoing mail server refused the recipient anna@finance.example.", Err: errors.New("550")}

	h.run()

	if !slices.Equal(h.api.reports, []report{{"m1", api.OutcomeFailed, "The outgoing mail server refused the recipient anna@finance.example.", false}}) {
		t.Errorf("reports = %+v", h.api.reports)
	}
}

func TestTemporaryProblemsAreRetried(t *testing.T) {
	h := newHarness(message("m1", 1))
	h.fail = &smtpsend.Error{Permanent: false, Message: "Could not connect to the outgoing mail server.", Err: errors.New("dial")}

	h.run()

	if !slices.Equal(h.api.reports, []report{{"m1", api.OutcomeRetry, "Could not connect to the outgoing mail server.", false}}) {
		t.Errorf("reports = %+v", h.api.reports)
	}
}

func TestMissingMessageFails(t *testing.T) {
	h := newHarness(api.OutgoingMessage{ID: "m1", RawObjectKey: "outgoing/zz/missing", Attempt: 1})

	h.run()

	if len(h.sends) != 0 || len(h.api.reports) != 1 || h.api.reports[0].outcome != api.OutcomeFailed {
		t.Errorf("sends %d, reports %+v", len(h.sends), h.api.reports)
	}
}

func TestProvidersThatFileSentMailGetNoSecondCopy(t *testing.T) {
	h := newHarness(message("m1", 1))
	h.api.smtpHost = "smtp.gmail.com"

	h.run()

	if len(h.sent.appended) != 0 {
		t.Errorf("copies in Sent = %d", len(h.sent.appended))
	}
	if !slices.Equal(h.api.reports, []report{{"m1", api.OutcomeSent, "", true}}) {
		t.Errorf("reports = %+v", h.api.reports)
	}
}

func TestSentWithoutCopyWhenTheSentFolderIsMissing(t *testing.T) {
	h := newHarness(message("m1", 1))
	h.sent.openErr = errors.New("no sent folder")

	h.run()

	if !slices.Equal(h.api.reports, []report{{"m1", api.OutcomeSent, "", false}}) {
		t.Errorf("reports = %+v", h.api.reports)
	}
}

func TestTriggersDuringARunNeverOverlap(t *testing.T) {
	h := newHarness(message("m1", 1))
	h.api.queue = append(h.api.queue, []api.OutgoingMessage{message("m2", 1)})
	h.api.gate = make(chan struct{})

	h.d.Trigger(context.Background(), "a1")
	// Wait until the first pass is busy claiming, then trigger while it runs.
	for {
		h.api.mu.Lock()
		busy := h.api.active == 1
		h.api.mu.Unlock()
		if busy {
			break
		}
		time.Sleep(time.Millisecond)
	}
	h.d.Trigger(context.Background(), "a1")
	h.d.Trigger(context.Background(), "a1")
	close(h.api.gate)
	h.d.Wait()

	if h.api.overlap {
		t.Fatal("two runs for one account overlapped")
	}
	if h.api.claims != 2 {
		t.Errorf("claims = %d, want the running pass plus one more for the triggers it missed", h.api.claims)
	}
	if len(h.api.reports) != 2 {
		t.Errorf("reports = %+v", h.api.reports)
	}
}
