package gmailsync

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"slices"
	"strconv"
	"strings"
	"testing"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/fakeapi"
	"tresso/sync/internal/providerauth"
	"tresso/sync/internal/providersend"
	"tresso/sync/internal/storage"
)

func testOptions(baseURL string) Options {
	opts := DefaultOptions()
	opts.Client.BaseURL = baseURL
	opts.Client.HTTP.MinBackoff = time.Millisecond
	opts.Client.HTTP.MaxBackoff = 5 * time.Millisecond
	opts.Client.HTTP.MaxAttempts = 3
	opts.Client.HTTP.UnitsPerSecond = 0 // the limiter has its own tests; here it would only slow the tests down
	opts.ListPageSize = 2
	opts.BackfillPagesPerPass = 10
	opts.MinBackoff = time.Millisecond
	opts.MaxBackoff = 5 * time.Millisecond
	return opts
}

type harness struct {
	gmail   *fakeGmail
	api     *fakeapi.API
	store   *storage.Memory
	syncer  *Syncer
	session *session
}

func newHarness(t *testing.T) *harness {
	t.Helper()
	h := &harness{gmail: newFakeGmail(t), api: fakeapi.New(), store: storage.NewMemory()}
	h.syncer = New(h.api, h.store, "w1", testOptions(h.gmail.server.URL), slog.New(slog.NewTextHandler(io.Discard, nil)))
	return h
}

// start opens a session and runs its first step, as Run does.
func (h *harness) start(t *testing.T) {
	t.Helper()
	h.session = h.syncer.newSession(api.Account{ID: "acc"}, h.syncer.logger)
	if err := h.session.refreshFolders(context.Background()); err != nil {
		t.Fatal(err)
	}
	h.step(t)
}

func (h *harness) step(t *testing.T) {
	t.Helper()
	if err := h.session.step(context.Background()); err != nil {
		t.Fatal(err)
	}
}

func (h *harness) cursor(t *testing.T) Cursor {
	t.Helper()
	raw := h.api.Cursor(allMailFolder)
	c, err := DecodeCursor(&raw)
	if err != nil || c == nil {
		t.Fatalf("All Mail cursor %q: %v", raw, err)
	}
	return *c
}

func raw(id string) []byte { return fakeapi.RawMessage(id+"@example.test", "Message "+id) }

// backfilled is a mailbox with mail in every kind of place, synced once.
func backfilled(t *testing.T) *harness {
	h := newHarness(t)
	g := h.gmail
	g.addLabel("Label_1", "Projects/Acme")
	g.add("m1", 1000, raw("m1"), "INBOX", "UNREAD")
	g.add("m2", 2000, raw("m2"), "CATEGORY_PERSONAL") // archived
	g.add("m3", 3000, raw("m3"), "SENT")
	g.add("m4", 4000, raw("m4"), "INBOX", "STARRED", "Label_1", "IMPORTANT")
	g.add("m5", 5000, raw("m5"), "TRASH")
	g.add("m6", 6000, raw("m6"), "SPAM")
	h.start(t)
	return h
}

func expectUIDs(t *testing.T, h *harness, folder string, want ...string) {
	t.Helper()
	if want == nil {
		want = []string{}
	}
	if got := h.api.UIDs(folder); !slices.Equal(got, want) {
		t.Errorf("%s holds %v, want %v", folder, got, want)
	}
}

func TestBackfillIngestsNewestFirstIntoEveryFolder(t *testing.T) {
	h := backfilled(t)

	var names []string
	for _, f := range h.api.ReportedFolders() {
		names = append(names, f.RemoteName+"="+f.DisplayName+"/"+f.Role)
		if f.UIDValidity != nil {
			t.Errorf("folder %s has a uidValidity", f.RemoteName)
		}
	}
	want := []string{"INBOX=Inbox/inbox", "SENT=Sent/sent", "DRAFT=Drafts/drafts", "TRASH=Trash/trash", "SPAM=Spam/spam", "tresso:all=All Mail/all", "Label_1=Projects/Acme/custom"}
	if !slices.Equal(names, want) {
		t.Errorf("folders = %v, want %v", names, want)
	}

	expectUIDs(t, h, "INBOX", "m1", "m4")
	expectUIDs(t, h, allMailFolder, "m1", "m2", "m3", "m4")
	expectUIDs(t, h, "SENT", "m3")
	expectUIDs(t, h, "TRASH", "m5")
	expectUIDs(t, h, "SPAM", "m6")
	expectUIDs(t, h, "Label_1", "m4")
	expectUIDs(t, h, "DRAFT")

	if got := h.api.Ingested(allMailFolder); !slices.Equal(got, []string{"m4", "m3", "m2", "m1"}) {
		t.Errorf("All Mail ingested in order %v, want newest first", got)
	}
	if f, _ := h.api.Flags("INBOX", "m1"); f.Seen || f.Flagged {
		t.Errorf("m1 flags = %+v, want unread", f)
	}
	if f, _ := h.api.Flags("Label_1", "m4"); !f.Seen || !f.Flagged || f.Answered {
		t.Errorf("m4 flags = %+v, want read and flagged", f)
	}

	c := h.cursor(t)
	if !c.BackfillDone || c.HistoryID != "106" || c.BackfillPageToken != "" {
		t.Errorf("cursor = %+v", c)
	}
	for _, f := range []string{"INBOX", "SENT", "Label_1", "TRASH"} {
		if h.api.Cursor(f) != "" {
			t.Errorf("%s has a cursor; only All Mail has one", f)
		}
	}
	for _, id := range []string{"m1", "m2", "m3", "m4", "m5", "m6"} {
		if got, _ := h.gmail.gets(id); got != 1 {
			t.Errorf("%s downloaded %d times", id, got)
		}
	}
	if h.store.Len() == 0 {
		t.Error("raw messages were not stored")
	}
}

func TestBackfillIsInterleavedAndResumes(t *testing.T) {
	h := newHarness(t)
	for i := 1; i <= 6; i++ {
		id := "m" + strconv.Itoa(i)
		h.gmail.add(id, int64(i)*1000, raw(id), "INBOX")
	}
	h.syncer.opts.BackfillPagesPerPass = 1
	h.start(t)

	c := h.cursor(t)
	if c.BackfillDone || c.BackfillPageToken == "" {
		t.Fatalf("after one page cursor = %+v", c)
	}
	expectUIDs(t, h, "INBOX", "m5", "m6")

	// New mail arrives while the backfill runs: the next step takes it in before the next page.
	h.gmail.add("m7", 7000, raw("m7"), "INBOX")
	h.step(t)
	expectUIDs(t, h, "INBOX", "m3", "m4", "m5", "m6", "m7")

	h.step(t)
	expectUIDs(t, h, "INBOX", "m1", "m2", "m3", "m4", "m5", "m6", "m7")
	if !h.cursor(t).BackfillDone {
		t.Error("backfill not done")
	}
}

func TestHistoryRoundAppliesEveryKindOfChange(t *testing.T) {
	h := backfilled(t)
	g := h.gmail
	g.add("m7", 7000, raw("m7"), "INBOX", "UNREAD") // new mail
	g.removeLabels("m1", "UNREAD")                  // read
	g.removeLabels("m4", "INBOX")                   // archived
	g.addLabels("m2", "Label_1")                    // labelled
	g.remove("m3", false)                           // deleted for good
	g.addLabels("m6", "STARRED")                    // flagged in Spam

	h.step(t)

	expectUIDs(t, h, "INBOX", "m1", "m7")
	expectUIDs(t, h, allMailFolder, "m1", "m2", "m4", "m7")
	expectUIDs(t, h, "Label_1", "m2", "m4")
	expectUIDs(t, h, "SENT")
	if f, _ := h.api.Flags("INBOX", "m1"); !f.Seen {
		t.Error("m1 not marked read")
	}
	if f, _ := h.api.Flags("SPAM", "m6"); !f.Flagged {
		t.Error("m6 not flagged")
	}

	// Flag changes and removals never download the MIME again; the new message is downloaded once although both
	// Inbox and All Mail lacked it. A label added to m2 is a new location, which the ingest API only takes as a
	// complete message, so m2 is downloaded once more (object storage keeps one copy).
	for id, want := range map[string]int{"m1": 1, "m2": 2, "m4": 1, "m6": 1, "m7": 1} {
		if got, _ := g.gets(id); got != want {
			t.Errorf("%s downloaded %d times, want %d", id, got, want)
		}
	}
	deleted := h.api.DeletedKeys()
	if len(deleted) != 1 || !strings.Contains(deleted[0], "m3@") {
		t.Errorf("deleted = %v, want only m3", deleted)
	}
	if c := h.cursor(t); c.HistoryID != strconv.Itoa(g.historyID) {
		t.Errorf("cursor history id %s, want %d", c.HistoryID, g.historyID)
	}
}

func TestExpiredHistoryResyncsEveryFolder(t *testing.T) {
	h := backfilled(t)
	g := h.gmail
	g.remove("m2", true)
	g.add("m8", 8000, raw("m8"), "INBOX")
	g.mu.Lock()
	g.messages["m1"].labels = []string{"INBOX"} // read, without history
	g.mu.Unlock()
	g.expireHistory()

	h.step(t)

	expectUIDs(t, h, allMailFolder, "m1", "m3", "m4", "m8")
	expectUIDs(t, h, "INBOX", "m1", "m4", "m8")
	if f, _ := h.api.Flags("INBOX", "m1"); !f.Seen {
		t.Error("m1 flags not refreshed by the resync")
	}
	if deleted := h.api.DeletedKeys(); len(deleted) != 1 || !strings.Contains(deleted[0], "m2@") {
		t.Errorf("deleted = %v, want m2", deleted)
	}
	if got, _ := g.gets("m4"); got != 1 {
		t.Errorf("m4 downloaded %d times during the resync", got)
	}
	if c := h.cursor(t); c.HistoryID != strconv.Itoa(g.historyID) || !c.BackfillDone {
		t.Errorf("cursor after resync = %+v", c)
	}

	// History works again from the new id.
	g.add("m9", 9000, raw("m9"), "INBOX")
	h.step(t)
	expectUIDs(t, h, "INBOX", "m1", "m4", "m8", "m9")
}

func TestUnknownLabelInHistoryRefreshesFolders(t *testing.T) {
	h := backfilled(t)
	calls := h.api.Calls()
	h.gmail.addLabel("Label_2", "Receipts")
	h.gmail.addLabels("m1", "Label_2")

	h.step(t)

	if h.api.Calls() != calls+1 {
		t.Errorf("folder list reported %d more times, want once", h.api.Calls()-calls)
	}
	if !slices.ContainsFunc(h.api.ReportedFolders(), func(f api.Folder) bool { return f.RemoteName == "Label_2" && f.DisplayName == "Receipts" }) {
		t.Error("Label_2 was not reported")
	}
	expectUIDs(t, h, "Label_2", "m1")
}

func TestRefusedTokenIsRenewedOnce(t *testing.T) {
	h := newHarness(t)
	h.gmail.add("m1", 1000, raw("m1"), "INBOX")
	h.gmail.token = "token-2" // the API's token has been revoked; the renewed one works

	h.start(t)

	if h.api.Refreshes != 1 || h.gmail.authRejected != 1 {
		t.Errorf("refreshes %d, rejected %d; want one renewal", h.api.Refreshes, h.gmail.authRejected)
	}
	expectUIDs(t, h, "INBOX", "m1")
}

func TestRefusedRenewedTokenReportsAuthFailure(t *testing.T) {
	h := newHarness(t)
	h.gmail.token = "nothing-works"

	runUntilReturn(t, h.syncer)

	if log := h.api.StatusLog(); !strings.HasPrefix(log, api.StatusAuthFailed+": Google no longer accepts") {
		t.Errorf("statuses = %q", log)
	}
}

func TestReauthorizeFromTheAPIEndsTheRunQuietly(t *testing.T) {
	h := newHarness(t)
	h.api.Reauthorize = true

	runUntilReturn(t, h.syncer)

	if log := h.api.StatusLog(); log != "" {
		t.Errorf("statuses = %q; the API already marked the account", log)
	}
}

func TestMissingMailScopeReportsAuthFailure(t *testing.T) {
	h := newHarness(t)
	h.gmail.fail(http.StatusForbidden, `{"error":{"code":403,"message":"Request had insufficient authentication scopes.","status":"PERMISSION_DENIED",`+
		`"errors":[{"reason":"insufficientPermissions"}],"details":[{"reason":"ACCESS_TOKEN_SCOPE_INSUFFICIENT"}]}}`)

	runUntilReturn(t, h.syncer)

	if log := h.api.StatusLog(); !strings.Contains(log, api.StatusAuthFailed+": ") || !strings.Contains(log, "allow access to your mail") {
		t.Errorf("statuses = %q", log)
	}
}

func TestRateLimitedRequestIsRetried(t *testing.T) {
	h := newHarness(t)
	h.gmail.add("m1", 1000, raw("m1"), "INBOX")
	h.gmail.fail(http.StatusForbidden, `{"error":{"code":403,"errors":[{"reason":"userRateLimitExceeded"}]}}`)

	h.start(t)

	expectUIDs(t, h, "INBOX", "m1")
}

// runUntilReturn runs the syncer and fails when Run does not end on its own.
func runUntilReturn(t *testing.T, s *Syncer) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := s.Run(ctx, api.Account{ID: "acc"}); err != nil {
		t.Fatal(err)
	}
	if ctx.Err() != nil {
		t.Fatal("Run did not end after losing access")
	}
}

func TestFoldersAndFlagsOfLabels(t *testing.T) {
	isFolder := map[string]bool{"INBOX": true, "TRASH": true, "Label_1": true, allMailFolder: true}
	cases := []struct {
		labels []string
		want   []string
	}{
		{[]string{"INBOX", "UNREAD", "CATEGORY_UPDATES"}, []string{"INBOX", allMailFolder}},
		{nil, []string{allMailFolder}},
		{[]string{"TRASH", "Label_1"}, []string{"TRASH", "Label_1"}},
		{[]string{"CHAT"}, nil},
	}
	for _, c := range cases {
		if got := foldersOf(c.labels, isFolder); !slices.Equal(got, c.want) {
			t.Errorf("foldersOf(%v) = %v, want %v", c.labels, got, c.want)
		}
	}
	if f := flagsOf([]string{"UNREAD", "STARRED", "DRAFT"}); f.Seen || !f.Flagged || !f.Draft || f.Answered {
		t.Errorf("flags = %+v", f)
	}
}

func TestCursorRoundTripAndLimit(t *testing.T) {
	encoded, err := Cursor{HistoryID: "42", BackfillPageToken: "tok"}.Encode()
	if err != nil {
		t.Fatal(err)
	}
	c, err := DecodeCursor(&encoded)
	if err != nil || c == nil || c.HistoryID != "42" || c.BackfillPageToken != "tok" || c.Version != cursorVersion {
		t.Fatalf("decoded %+v, %v", c, err)
	}
	if _, err := (Cursor{HistoryID: "1", BackfillPageToken: strings.Repeat("x", api.MaxCursorLength)}).Encode(); err == nil {
		t.Error("an oversized cursor must fail, not be cut")
	}
	old := `{"v":0,"historyId":"1"}`
	if c, err := DecodeCursor(&old); c != nil || err != nil {
		t.Errorf("old cursor = %+v, %v", c, err)
	}
}

func sendClient(h *harness, opts ClientOptions) *Client {
	return NewClient(providerauth.New(h.api, "acc", "w1"), opts)
}

func TestSendAddsBccForEnvelopeOnlyRecipients(t *testing.T) {
	h := newHarness(t)
	message := []byte("From: kim@example.test\r\nTo: Anna <anna@example.test>\r\nCc: bo@example.test\r\nSubject: Hi\r\n\r\nHello\r\n")

	err := Send(context.Background(), sendClient(h, h.syncer.opts.Client), message, []string{"anna@example.test", "BO@example.test", "secret@example.test"})
	if err != nil {
		t.Fatal(err)
	}

	sent := h.gmail.sent
	if len(sent) != 1 || !strings.HasPrefix(sent[0].path, "/gmail/v1/users/me/messages/send") || sent[0].contentType != "application/json" {
		t.Fatalf("sent = %+v", sent)
	}
	var body struct {
		Raw string `json:"raw"`
	}
	if err := json.Unmarshal(sent[0].raw, &body); err != nil {
		t.Fatal(err)
	}
	delivered, err := base64.URLEncoding.DecodeString(body.Raw)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(string(delivered), "Bcc: <secret@example.test>\r\nFrom: kim@example.test\r\n") {
		t.Errorf("delivered message = %q", delivered)
	}
}

func TestSendUploadsLargeMessagesAsMedia(t *testing.T) {
	h := newHarness(t)
	message := append([]byte("From: kim@example.test\r\nTo: anna@example.test\r\nSubject: Big\r\n\r\n"), make([]byte, uploadThreshold+1)...)

	if err := Send(context.Background(), sendClient(h, h.syncer.opts.Client), message, []string{"anna@example.test"}); err != nil {
		t.Fatal(err)
	}

	sent := h.gmail.sent
	if len(sent) != 1 || sent[0].path != "/upload/gmail/v1/users/me/messages/send?uploadType=media" || sent[0].contentType != "message/rfc822" || len(sent[0].raw) != len(message) {
		t.Fatalf("sent = path %q type %q size %d", sent[0].path, sent[0].contentType, len(sent[0].raw))
	}
}

func TestSendErrorsArePermanentOrTemporary(t *testing.T) {
	cases := []struct {
		status    int
		body      string
		permanent bool
		text      string
	}{
		{http.StatusBadRequest, `{"error":{"code":400,"message":"Invalid To header"}}`, true, "Gmail refused the message. Gmail said: Invalid To header"},
		{http.StatusTooManyRequests, `{"error":{"code":429}}`, false, "Gmail is limiting how much mail Tresso sends right now."},
		{http.StatusInternalServerError, `{"error":{"code":500}}`, false, "Gmail could not send the message right now."},
	}
	for _, c := range cases {
		h := newHarness(t)
		opts := h.syncer.opts.Client
		opts.HTTP.MaxAttempts = 1
		h.gmail.fail(c.status, c.body)

		err := Send(context.Background(), sendClient(h, opts), []byte("To: a@example.test\r\n\r\nx"), []string{"a@example.test"})

		var sendErr *providersend.Error
		if !errors.As(err, &sendErr) || sendErr.Permanent != c.permanent || sendErr.Message != c.text {
			t.Errorf("status %d: err = %#v", c.status, err)
		}
	}
}

func TestSentStoreFindsEarlierSends(t *testing.T) {
	h := newHarness(t)
	h.gmail.add("m1", 1000, raw("m1"), "SENT")
	store := OpenSent(context.Background(), sendClient(h, h.syncer.opts.Client))

	found, err := store.Contains("m1@example.test")
	if err != nil || !found {
		t.Errorf("Contains = %v, %v", found, err)
	}
	if err := store.Append(nil, time.Now()); !errors.Is(err, ErrFilesSentItself) {
		t.Errorf("Append = %v", err)
	}
}

func TestChatsAreLeftOutOfBackfillAndResync(t *testing.T) {
	h := newHarness(t)
	h.gmail.add("m1", 1000, raw("m1"), "INBOX")
	h.gmail.add("c1", 2000, raw("c1"), "CHAT")
	h.start(t)

	expectUIDs(t, h, allMailFolder, "m1")
	h.gmail.expireHistory()
	h.step(t)

	expectUIDs(t, h, allMailFolder, "m1")
	if got, _ := h.gmail.gets("c1"); got != 0 {
		t.Errorf("chat message downloaded %d times", got)
	}
}

func TestMovesMadeByTheMailboxDoNotComeBackThroughHistory(t *testing.T) {
	h := backfilled(t)
	mailbox := OpenMailbox(context.Background(), sendClient(h, h.syncer.opts.Client))
	moves := []struct{ source, target, id string }{
		{"INBOX", "Label_1", "m1"},     // between labels
		{"INBOX", allMailFolder, "m4"}, // archive: the message already sits in All Mail
		{"SENT", "TRASH", "m3"},
	}
	for _, move := range moves {
		uid, err := mailbox.Move(api.MailboxLocation{Folder: move.source, RemoteUID: move.id}, move.target, "")
		if err != nil || uid != move.id {
			t.Fatalf("Move(%s) = %q, %v", move.id, uid, err)
		}
		h.api.MoveLocation(move.source, move.target, move.id, uid) // what the API does with the report
	}

	h.step(t)

	expectUIDs(t, h, "INBOX")
	expectUIDs(t, h, "Label_1", "m1", "m4")
	expectUIDs(t, h, "TRASH", "m3", "m5")
	expectUIDs(t, h, allMailFolder, "m1", "m2", "m4")
	if deleted := h.api.DeletedKeys(); len(deleted) != 0 {
		t.Errorf("deleted = %v", deleted)
	}
	for _, id := range []string{"m1", "m3", "m4"} {
		if got, _ := h.gmail.gets(id); got != 1 {
			t.Errorf("%s downloaded %d times; the history round must find it known", id, got)
		}
	}
}
