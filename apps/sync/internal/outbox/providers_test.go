package outbox

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"regexp"
	"slices"
	"strings"
	"sync"
	"testing"

	"tresso/sync/internal/api"
	"tresso/sync/internal/fakeapi"
	"tresso/sync/internal/gmailsync"
	"tresso/sync/internal/graphsync"
	"tresso/sync/internal/storage"
)

var messageIDHeader = regexp.MustCompile(`(?m)^Message-ID: <([^>]+)>`)

// providerMailbox is the state both provider fakes keep: drafts by Message-ID and messages with their folders (Gmail
// labels or a Graph parent folder). ops logs every change in order.
type providerMailbox struct {
	mu      sync.Mutex
	drafts  map[string]string   // provider draft id -> Message-ID
	folders map[string][]string // message id -> labels, or the one parent folder
	nextID  int
	ops     []string
	// refuse answers every request with this status: 401 (no token is accepted) or 403 (no mail scope).
	refuse int
}

// refused answers the request as refused when the mailbox refuses everything.
func (p *providerMailbox) refused(w http.ResponseWriter, r *http.Request, gmail bool) bool {
	switch {
	case p.refuse == http.StatusUnauthorized:
		http.Error(w, `{"error":{"code":401}}`, http.StatusUnauthorized)
	case p.refuse == http.StatusForbidden && gmail:
		http.Error(w, `{"error":{"code":403,"errors":[{"reason":"insufficientPermissions"}]}}`, http.StatusForbidden)
	case p.refuse == http.StatusForbidden:
		http.Error(w, `{"error":{"code":"ErrorAccessDenied"}}`, http.StatusForbidden)
	default:
		return false
	}
	p.log("refused " + r.Method + " " + r.URL.Path)
	return true
}

func newProviderMailbox() *providerMailbox {
	return &providerMailbox{drafts: map[string]string{}, folders: map[string][]string{}}
}

func (p *providerMailbox) log(op string) { p.ops = append(p.ops, op) }

func (p *providerMailbox) createDraft(raw []byte) string {
	p.nextID++
	id := "draft-" + string(rune('0'+p.nextID))
	match := messageIDHeader.FindSubmatch(raw)
	p.drafts[id] = string(match[1])
	p.log("create " + string(match[1]))
	return id
}

func (p *providerMailbox) draftsWith(messageID string) []string {
	var ids []string
	for id, mid := range p.drafts {
		if mid == messageID {
			ids = append(ids, id)
		}
	}
	slices.Sort(ids)
	return ids
}

// fakeGmailAPI serves the Gmail endpoints the outbox uses.
func fakeGmailAPI(t *testing.T, box *providerMailbox) *httptest.Server {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		box.mu.Lock()
		defer box.mu.Unlock()
		if box.refused(w, r, true) {
			return
		}
		path := strings.TrimPrefix(r.URL.Path, "/gmail/v1/users/me")
		body, _ := io.ReadAll(r.Body)
		switch {
		case r.Method == http.MethodPost && path == "/drafts":
			var in struct {
				Message struct{ Raw string } `json:"message"`
			}
			_ = json.Unmarshal(body, &in)
			raw, _ := base64.URLEncoding.DecodeString(in.Message.Raw)
			writeJSONResponse(w, map[string]string{"id": box.createDraft(raw)})
		case r.Method == http.MethodGet && path == "/drafts":
			var out []map[string]string
			for _, id := range box.draftsWith(strings.TrimPrefix(r.URL.Query().Get("q"), "rfc822msgid:")) {
				out = append(out, map[string]string{"id": id})
			}
			writeJSONResponse(w, map[string]any{"drafts": out})
		case r.Method == http.MethodDelete && strings.HasPrefix(path, "/drafts/"):
			id := strings.TrimPrefix(path, "/drafts/")
			box.log("delete " + box.drafts[id])
			delete(box.drafts, id)
			w.WriteHeader(http.StatusNoContent)
		case strings.HasPrefix(path, "/messages/"):
			id, action, _ := strings.Cut(strings.TrimPrefix(path, "/messages/"), "/")
			labels, ok := box.folders[id]
			if !ok {
				http.Error(w, `{"error":{"code":404}}`, http.StatusNotFound)
				return
			}
			switch action {
			case "":
				writeJSONResponse(w, map[string]any{"id": id, "labelIds": labels})
			case "modify":
				var in struct{ AddLabelIds, RemoveLabelIds []string }
				_ = json.Unmarshal(body, &in)
				labels = slices.DeleteFunc(labels, func(l string) bool { return slices.Contains(in.RemoveLabelIds, l) })
				box.folders[id] = append(labels, in.AddLabelIds...)
				box.log("modify " + id + " +" + strings.Join(in.AddLabelIds, ",") + " -" + strings.Join(in.RemoveLabelIds, ","))
				writeJSONResponse(w, map[string]string{"id": id})
			case "trash":
				box.folders[id] = append(slices.DeleteFunc(labels, func(l string) bool { return l == "INBOX" }), "TRASH")
				box.log("trash " + id)
				writeJSONResponse(w, map[string]string{"id": id})
			default:
				t.Errorf("unexpected message action %s", action)
			}
		default:
			t.Errorf("unexpected request %s %s", r.Method, r.URL)
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

// fakeGraphAPI serves the Graph endpoints the outbox uses.
func fakeGraphAPI(t *testing.T, box *providerMailbox) *httptest.Server {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		box.mu.Lock()
		defer box.mu.Unlock()
		if !strings.Contains(r.Header.Get("Prefer"), `IdType="ImmutableId"`) {
			t.Errorf("%s without the immutable id preference", r.URL.Path)
		}
		if box.refused(w, r, false) {
			return
		}
		path := strings.TrimPrefix(r.URL.Path, "/v1.0/me")
		body, _ := io.ReadAll(r.Body)
		switch {
		case r.Method == http.MethodPost && path == "/messages":
			raw, _ := base64.StdEncoding.DecodeString(string(body))
			w.WriteHeader(http.StatusCreated)
			writeJSONResponse(w, map[string]string{"id": box.createDraft(raw)})
		case r.Method == http.MethodGet && path == "/mailFolders/drafts/messages":
			filter := r.URL.Query().Get("$filter")
			messageID := strings.TrimSuffix(strings.TrimPrefix(filter, "internetMessageId eq '<"), ">'")
			var out []map[string]string
			for _, id := range box.draftsWith(messageID) {
				out = append(out, map[string]string{"id": id})
			}
			writeJSONResponse(w, map[string]any{"value": out})
		case strings.HasPrefix(path, "/messages/"):
			id, action, _ := strings.Cut(strings.TrimPrefix(path, "/messages/"), "/")
			if action == "permanentDelete" {
				if _, ok := box.drafts[id]; !ok {
					http.Error(w, `{"error":{"code":"ErrorItemNotFound"}}`, http.StatusNotFound)
					return
				}
				box.log("delete " + box.drafts[id])
				delete(box.drafts, id)
				w.WriteHeader(http.StatusNoContent)
				return
			}
			parent, ok := box.folders[id]
			if !ok {
				http.Error(w, `{"error":{"code":"ErrorItemNotFound"}}`, http.StatusNotFound)
				return
			}
			switch {
			case r.Method == http.MethodGet && action == "":
				writeJSONResponse(w, map[string]string{"id": id, "parentFolderId": parent[0]})
			case r.Method == http.MethodPatch && action == "":
				box.log("patch " + id + " " + strings.TrimSpace(string(body)))
				writeJSONResponse(w, map[string]string{"id": id})
			case r.Method == http.MethodPost && action == "move":
				var in struct{ DestinationID string }
				_ = json.Unmarshal(body, &in)
				box.folders[id] = []string{in.DestinationID}
				box.log("move " + id + " " + in.DestinationID)
				w.WriteHeader(http.StatusCreated)
				writeJSONResponse(w, map[string]string{"id": id, "parentFolderId": in.DestinationID})
			default:
				t.Errorf("unexpected request %s %s", r.Method, r.URL)
			}
		default:
			t.Errorf("unexpected request %s %s", r.Method, r.URL)
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

func writeJSONResponse(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}

// providerDispatcher runs the dispatcher with the real transport of provider against its fake.
func providerDispatcher(t *testing.T, provider string, box *providerMailbox) (*Dispatcher, *outboxAPI, *storage.Memory) {
	t.Helper()
	client := &outboxAPI{API: fakeapi.New(), provider: provider}
	client.API.RefreshedToken = "" // the fakes take any token
	store := storage.NewMemory()
	var transport Transport
	switch provider {
	case "gmail":
		srv := fakeGmailAPI(t, box)
		opts := gmailsync.DefaultClientOptions()
		opts.BaseURL = srv.URL
		transport = GmailTransport(client.API, "w1", opts)
	case "microsoft":
		srv := fakeGraphAPI(t, box)
		opts := graphsync.DefaultClientOptions()
		opts.BaseURL = srv.URL + "/v1.0"
		transport = GraphTransport(client.API, "w1", opts)
	}
	d := NewDispatcher(client, store, map[string]Transport{provider: transport}, "w1", slog.New(slog.NewTextHandler(io.Discard, nil)))
	return d, client, store
}

func draftRaw(messageID string) []byte {
	return []byte("From: kim@example.test\r\nTo: anna@example.test\r\nSubject: Draft\r\nMessage-ID: <" + messageID + ">\r\n\r\nNot done yet.\r\n")
}

func TestProviderDraftsAreReplacedOnlyAfterTheNewCopyIsIn(t *testing.T) {
	for _, provider := range []string{"gmail", "microsoft"} {
		t.Run(provider, func(t *testing.T) {
			box := newProviderMailbox()
			d, client, store := providerDispatcher(t, provider, box)
			_ = store.PutIfAbsent(context.Background(), "drafts/aa/1", draftRaw("d1.1@example.test"), "message/rfc822")
			_ = store.PutIfAbsent(context.Background(), "drafts/aa/2", draftRaw("d1.2@example.test"), "message/rfc822")
			_ = store.PutIfAbsent(context.Background(), "drafts/aa/9", draftRaw("d9.1@example.test"), "message/rfc822")
			run := func() {
				d.Trigger(context.Background(), "acc")
				d.Wait()
			}

			client.drafts = api.DraftWork{Files: []api.DraftFile{
				{DraftID: "d1", Version: 1, RawObjectKey: "drafts/aa/1", MessageID: "d1.1@example.test"},
				{DraftID: "d9", Version: 1, RawObjectKey: "drafts/aa/9", MessageID: "d9.1@example.test"},
			}}
			run()
			previous := "d1.1@example.test"
			client.drafts = api.DraftWork{
				Files:    []api.DraftFile{{DraftID: "d1", Version: 2, RawObjectKey: "drafts/aa/2", MessageID: "d1.2@example.test", PreviousMessageID: &previous}},
				Removals: []api.DraftRemoval{{ID: "r9", MessageID: "d9.1@example.test"}, {ID: "r0", MessageID: "never-filed@example.test"}},
			}
			run()

			want := []string{"create d1.1@example.test", "create d9.1@example.test", "create d1.2@example.test", "delete d1.1@example.test", "delete d9.1@example.test"}
			if !slices.Equal(box.ops, want) {
				t.Errorf("ops = %v\nwant %v", box.ops, want)
			}
			if !slices.Equal(client.filed, []string{"d1 v1 d1.1@example.test", "d9 v1 d9.1@example.test", "d1 v2 d1.2@example.test"}) {
				t.Errorf("filed = %v", client.filed)
			}
			if !slices.Equal(client.removed, []string{"r9", "r0"}) {
				t.Errorf("removed = %v; a copy that is not there counts as removed", client.removed)
			}
		})
	}
}

func TestProviderDraftWorkGoesOnAfterALostClaim(t *testing.T) {
	for _, provider := range []string{"gmail", "microsoft"} {
		t.Run(provider, func(t *testing.T) {
			box := newProviderMailbox()
			d, client, store := providerDispatcher(t, provider, box)
			_ = store.PutIfAbsent(context.Background(), "drafts/aa/1", draftRaw("d1.1@example.test"), "message/rfc822")
			_ = store.PutIfAbsent(context.Background(), "drafts/aa/2", draftRaw("d2.1@example.test"), "message/rfc822")
			client.lostClaims = map[string]bool{"d1": true}
			client.drafts = api.DraftWork{Files: []api.DraftFile{
				{DraftID: "d1", Version: 1, RawObjectKey: "drafts/aa/1", MessageID: "d1.1@example.test"},
				{DraftID: "d2", Version: 1, RawObjectKey: "drafts/aa/2", MessageID: "d2.1@example.test"},
			}}

			d.Trigger(context.Background(), "acc")
			d.Wait()

			if !slices.Equal(client.filed, []string{"d2 v1 d2.1@example.test"}) || len(box.drafts) != 2 {
				t.Errorf("filed %v, drafts %v", client.filed, box.drafts)
			}
		})
	}
}

func TestGmailMailboxChangesAreLabelChanges(t *testing.T) {
	box := newProviderMailbox()
	box.folders["g1"] = []string{"INBOX", "UNREAD"}
	box.folders["g2"] = []string{"INBOX"}
	box.folders["g3"] = []string{"Label_1"}
	box.folders["g4"] = []string{"INBOX"}
	box.folders["g5"] = []string{"Label_2"}
	d, client, _ := providerDispatcher(t, "gmail", box)
	yes := true
	inbox := func(id string) *api.MailboxLocation { return &api.MailboxLocation{Folder: "INBOX", RemoteUID: id} }
	client.changes = []api.MailboxChange{
		{ID: "c1", MessageID: "m1", Kind: api.MailboxFlags, Seen: &yes, Flagged: &yes, Locations: []api.MailboxLocation{*inbox("g1"), {Folder: "tresso:all", RemoteUID: "g1"}}},
		{ID: "c2", MessageID: "m2", Kind: api.MailboxMove, Source: inbox("g2"), Target: &api.MailboxTarget{Folder: "tresso:all"}},
		{ID: "c3", MessageID: "m3", Kind: api.MailboxMove, Source: &api.MailboxLocation{Folder: "Label_1", RemoteUID: "g3"}, Target: &api.MailboxTarget{Folder: "Label_2"}},
		{ID: "c4", MessageID: "m4", Kind: api.MailboxMove, Source: inbox("g4"), Target: &api.MailboxTarget{Folder: "TRASH"}},
		{ID: "c5", MessageID: "m5", Kind: api.MailboxMove, Source: inbox("g5"), Target: &api.MailboxTarget{Folder: "Label_1"}},
		{ID: "c6", MessageID: "m6", Kind: api.MailboxFlags, Seen: &yes, Locations: []api.MailboxLocation{*inbox("deleted")}},
	}

	d.Trigger(context.Background(), "acc")
	d.Wait()

	want := []string{
		"modify g1 +STARRED -UNREAD", // once, although the message is in two folders
		"modify g2 + -INBOX",         // archive: only the source label goes
		"modify g3 +Label_2 -Label_1",
		"trash g4",
		"modify g4 + -INBOX",
	}
	if !slices.Equal(box.ops, want) {
		t.Errorf("ops = %v\nwant %v", box.ops, want)
	}
	reports := []mailboxReport{
		{"c1", api.MailboxDone, "", ""},
		{"c2", api.MailboxDone, "", "g2"},
		{"c3", api.MailboxDone, "", "g3"},
		{"c4", api.MailboxDone, "", "g4"},
		{"c5", api.MailboxGone, gmailsync.ErrMessageGone.Error(), ""},
		{"c6", api.MailboxDone, "", ""},
	}
	if !slices.Equal(client.changed, reports) {
		t.Errorf("reports = %+v\nwant %+v", client.changed, reports)
	}
}

func TestGraphMailboxChangesUseFlagsAndMoves(t *testing.T) {
	box := newProviderMailbox()
	box.folders["o1"] = []string{"f-inbox"}
	box.folders["o2"] = []string{"f-inbox"}
	box.folders["o3"] = []string{"f-archive"}
	d, client, _ := providerDispatcher(t, "microsoft", box)
	yes, no := true, false
	client.changes = []api.MailboxChange{
		{ID: "c1", MessageID: "m1", Kind: api.MailboxFlags, Seen: &yes, Flagged: &no, Locations: []api.MailboxLocation{{Folder: "f-inbox", RemoteUID: "o1"}}},
		{ID: "c2", MessageID: "m2", Kind: api.MailboxMove, Source: &api.MailboxLocation{Folder: "f-inbox", RemoteUID: "o2"}, Target: &api.MailboxTarget{Folder: "f-archive"}},
		{ID: "c3", MessageID: "m3", Kind: api.MailboxMove, Source: &api.MailboxLocation{Folder: "f-inbox", RemoteUID: "o3"}, Target: &api.MailboxTarget{Folder: "f-trash"}},
		{ID: "c4", MessageID: "m4", Kind: api.MailboxMove, Source: &api.MailboxLocation{Folder: "f-inbox", RemoteUID: "deleted"}, Target: &api.MailboxTarget{Folder: "f-trash"}},
		{ID: "c5", MessageID: "m5", Kind: api.MailboxFlags, Seen: &yes, Locations: []api.MailboxLocation{{Folder: "f-inbox", RemoteUID: "deleted"}}},
	}

	d.Trigger(context.Background(), "acc")
	d.Wait()

	want := []string{`patch o1 {"flag":{"flagStatus":"notFlagged"},"isRead":true}`, "move o2 f-archive"}
	if !slices.Equal(box.ops, want) {
		t.Errorf("ops = %v\nwant %v", box.ops, want)
	}
	reports := []mailboxReport{
		{"c1", api.MailboxDone, "", ""},
		{"c2", api.MailboxDone, "", "o2"},
		{"c3", api.MailboxGone, graphsync.ErrMessageGone.Error(), ""}, // already moved elsewhere
		{"c4", api.MailboxGone, graphsync.ErrMessageGone.Error(), ""},
		{"c5", api.MailboxDone, "", ""},
	}
	if !slices.Equal(client.changed, reports) {
		t.Errorf("reports = %+v\nwant %+v", client.changed, reports)
	}
}

func TestLostAccessReportsNothingAndSendsStayQueued(t *testing.T) {
	for _, provider := range []string{"gmail", "microsoft"} {
		for _, status := range []int{http.StatusUnauthorized, http.StatusForbidden} {
			t.Run(fmt.Sprintf("%s %d", provider, status), func(t *testing.T) {
				box := newProviderMailbox()
				box.refuse = status
				d, client, store := providerDispatcher(t, provider, box)
				_ = store.PutIfAbsent(context.Background(), "outgoing/aa/1", draftRaw("o1@example.test"), "message/rfc822")
				_ = store.PutIfAbsent(context.Background(), "drafts/aa/1", draftRaw("d1.1@example.test"), "message/rfc822")
				yes := true
				client.queue = []api.OutgoingMessage{{ID: "o1", RawObjectKey: "outgoing/aa/1", Recipients: []string{"anna@example.test"}, MessageID: "o1@example.test", Attempt: 1}}
				client.drafts = api.DraftWork{
					Files:    []api.DraftFile{{DraftID: "d1", Version: 1, RawObjectKey: "drafts/aa/1", MessageID: "d1.1@example.test"}, {DraftID: "d2", Version: 1, RawObjectKey: "drafts/aa/1", MessageID: "d1.1@example.test"}},
					Removals: []api.DraftRemoval{{ID: "r1", MessageID: "d0@example.test"}},
				}
				client.changes = []api.MailboxChange{
					{ID: "c1", MessageID: "m1", Kind: api.MailboxFlags, Seen: &yes, Locations: []api.MailboxLocation{{Folder: "INBOX", RemoteUID: "x1"}}},
					{ID: "c2", MessageID: "m2", Kind: api.MailboxMove, Source: &api.MailboxLocation{Folder: "INBOX", RemoteUID: "x2"}, Target: &api.MailboxTarget{Folder: "TRASH"}},
				}

				d.Trigger(context.Background(), "acc")
				d.Wait()

				if len(client.reports) != 1 || client.reports[0].outcome != api.OutcomeRetry {
					t.Errorf("send reports = %+v; lost access is a temporary failure", client.reports)
				}
				if len(client.filed) != 0 || len(client.removed) != 0 {
					t.Errorf("draft work reported: filed %v, removed %v", client.filed, client.removed)
				}
				if len(client.changed) != 0 {
					t.Errorf("mailbox changes reported %+v; a failure would make the API undo them", client.changed)
				}
				// Lost access stops each kind of work at its first step: the second draft, the removal and the move are
				// never tried. A 401 is tried once more with a renewed token.
				createDraft, listDrafts := "POST /gmail/v1/users/me/drafts", "GET /gmail/v1/users/me/drafts"
				if provider == "microsoft" {
					createDraft, listDrafts = "POST /v1.0/me/messages", "GET /v1.0/me/mailFolders/drafts/messages"
				}
				attempts := 1
				if status == http.StatusUnauthorized {
					attempts = 2
				}
				if got := slices.Index(box.ops, "refused "+createDraft); got < 0 || countOps(box.ops, "refused "+createDraft) != attempts {
					t.Errorf("draft creations tried %d times, want %d: %v", countOps(box.ops, "refused "+createDraft), attempts, box.ops)
				}
				for _, op := range box.ops {
					if strings.HasSuffix(op, listDrafts) || strings.Contains(op, "/messages/x2") {
						t.Errorf("work went on after access was lost: %s", op)
					}
				}
			})
		}
	}
}

func countOps(ops []string, op string) int {
	n := 0
	for _, o := range ops {
		if o == op {
			n++
		}
	}
	return n
}
