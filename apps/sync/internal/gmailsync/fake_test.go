package gmailsync

import (
	"encoding/base64"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"
)

// fakeGmail is an in-memory Gmail mailbox behind the Gmail API's endpoints: labels, messages with raw MIME, and a
// history log with ids.
type fakeGmail struct {
	t      *testing.T
	server *httptest.Server

	mu       sync.Mutex
	token    string // the only access token accepted
	labels   []label
	messages map[string]*fakeMessage
	history  []fakeRecord
	// historyID is the mailbox's current history id; a startHistoryId below expiredBefore answers 404.
	historyID     int
	expiredBefore int
	// failNext answers the next request with this status and body, once.
	failNext     int
	failBody     string
	rawGets      map[string]int
	minimalGets  map[string]int
	sent         []sentRequest
	authRejected int
}

type fakeMessage struct {
	id       string
	raw      []byte
	labels   []string
	received int64 // ms
}

type fakeRecord struct {
	id     int
	record historyRecord
}

type sentRequest struct {
	path        string
	contentType string
	raw         []byte
}

func newFakeGmail(t *testing.T) *fakeGmail {
	f := &fakeGmail{
		t:         t,
		token:     "token-1",
		messages:  map[string]*fakeMessage{},
		historyID: 100,
		labels: []label{
			{ID: "INBOX", Name: "INBOX", Type: "system"}, {ID: "SENT", Name: "SENT", Type: "system"},
			{ID: "DRAFT", Name: "DRAFT", Type: "system"}, {ID: "TRASH", Name: "TRASH", Type: "system"},
			{ID: "SPAM", Name: "SPAM", Type: "system"}, {ID: "UNREAD", Name: "UNREAD", Type: "system"},
			{ID: "STARRED", Name: "STARRED", Type: "system"}, {ID: "IMPORTANT", Name: "IMPORTANT", Type: "system"},
			{ID: "CATEGORY_PERSONAL", Name: "CATEGORY_PERSONAL", Type: "system"},
		},
		rawGets:     map[string]int{},
		minimalGets: map[string]int{},
	}
	f.server = httptest.NewServer(http.HandlerFunc(f.serve))
	t.Cleanup(f.server.Close)
	return f
}

func (f *fakeGmail) addLabel(id, name string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.labels = append(f.labels, label{ID: id, Name: name, Type: "user"})
}

// add puts a message in the mailbox; received orders messages (higher is newer). It records history unless quiet.
func (f *fakeGmail) add(id string, received int64, raw []byte, labels ...string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.messages[id] = &fakeMessage{id: id, raw: raw, labels: labels, received: received}
	f.record(historyRecord{MessagesAdded: []struct {
		Message historyMessage `json:"message"`
	}{{Message: historyMessage{ID: id, LabelIDs: labels}}}})
}

func (f *fakeGmail) addLabels(id string, labels ...string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	m := f.messages[id]
	for _, l := range labels {
		if !slices.Contains(m.labels, l) {
			m.labels = append(m.labels, l)
		}
	}
	f.record(historyRecord{LabelsAdded: []labelChange{{Message: historyMessage{ID: id}, LabelIDs: labels}}})
}

func (f *fakeGmail) removeLabels(id string, labels ...string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	m := f.messages[id]
	m.labels = slices.DeleteFunc(m.labels, func(l string) bool { return slices.Contains(labels, l) })
	f.record(historyRecord{LabelsRemoved: []labelChange{{Message: historyMessage{ID: id}, LabelIDs: labels}}})
}

// remove deletes a message; quiet leaves no history, as if the history had expired meanwhile.
func (f *fakeGmail) remove(id string, quiet bool) {
	f.mu.Lock()
	defer f.mu.Unlock()
	delete(f.messages, id)
	if !quiet {
		f.record(historyRecord{MessagesDeleted: []struct {
			Message historyMessage `json:"message"`
		}{{Message: historyMessage{ID: id}}}})
	}
}

func (f *fakeGmail) record(r historyRecord) {
	f.historyID++
	f.history = append(f.history, fakeRecord{id: f.historyID, record: r})
}

func (f *fakeGmail) expireHistory() {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.expiredBefore = f.historyID
}

func (f *fakeGmail) fail(status int, body string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.failNext, f.failBody = status, body
}

func (f *fakeGmail) gets(id string) (raw, minimal int) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.rawGets[id], f.minimalGets[id]
}

func (f *fakeGmail) serve(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()

	if r.Header.Get("Authorization") != "Bearer "+f.token {
		f.authRejected++
		writeError(w, http.StatusUnauthorized, `{"error":{"code":401,"status":"UNAUTHENTICATED"}}`)
		return
	}
	if f.failNext != 0 {
		status, body := f.failNext, f.failBody
		f.failNext = 0
		writeError(w, status, body)
		return
	}

	q := r.URL.Query()
	path := r.URL.Path
	switch {
	case r.Method == http.MethodPost && (path == "/gmail/v1/users/me/messages/send" || path == "/upload/gmail/v1/users/me/messages/send"):
		body, _ := io.ReadAll(r.Body)
		f.sent = append(f.sent, sentRequest{path: path + "?" + r.URL.RawQuery, contentType: r.Header.Get("Content-Type"), raw: body})
		writeJSON(w, map[string]string{"id": "sent-1"})
	case path == "/gmail/v1/users/me/profile":
		writeJSON(w, map[string]string{"emailAddress": "kim@example.test", "historyId": strconv.Itoa(f.historyID)})
	case path == "/gmail/v1/users/me/labels":
		writeJSON(w, map[string]any{"labels": f.labels})
	case path == "/gmail/v1/users/me/messages":
		f.list(w, q)
	case r.Method == http.MethodPost && strings.HasPrefix(path, "/gmail/v1/users/me/messages/"):
		id, action, _ := strings.Cut(strings.TrimPrefix(path, "/gmail/v1/users/me/messages/"), "/")
		m, ok := f.messages[id]
		if !ok {
			writeError(w, http.StatusNotFound, `{"error":{"code":404}}`)
			return
		}
		var add, remove []string
		switch action {
		case "modify":
			var in struct{ AddLabelIds, RemoveLabelIds []string }
			_ = json.NewDecoder(r.Body).Decode(&in)
			add, remove = in.AddLabelIds, in.RemoveLabelIds
		case "trash":
			add, remove = []string{"TRASH"}, []string{"INBOX"}
		default:
			f.t.Errorf("unexpected message action %s", action)
		}
		m.labels = slices.DeleteFunc(m.labels, func(l string) bool { return slices.Contains(remove, l) })
		m.labels = append(m.labels, add...)
		if len(add) > 0 {
			f.record(historyRecord{LabelsAdded: []labelChange{{Message: historyMessage{ID: id}, LabelIDs: add}}})
		}
		if len(remove) > 0 {
			f.record(historyRecord{LabelsRemoved: []labelChange{{Message: historyMessage{ID: id}, LabelIDs: remove}}})
		}
		writeJSON(w, map[string]any{"id": id, "labelIds": m.labels})
	case strings.HasPrefix(path, "/gmail/v1/users/me/messages/"):
		id := strings.TrimPrefix(path, "/gmail/v1/users/me/messages/")
		m, ok := f.messages[id]
		if !ok {
			writeError(w, http.StatusNotFound, `{"error":{"code":404,"message":"Requested entity was not found."}}`)
			return
		}
		out := map[string]any{"id": m.id, "labelIds": m.labels, "internalDate": strconv.FormatInt(m.received, 10)}
		if q.Get("format") == "raw" {
			f.rawGets[id]++
			out["raw"] = base64.URLEncoding.EncodeToString(m.raw)
		} else {
			f.minimalGets[id]++
		}
		writeJSON(w, out)
	case path == "/gmail/v1/users/me/history":
		start, _ := strconv.Atoi(q.Get("startHistoryId"))
		if start < f.expiredBefore {
			writeError(w, http.StatusNotFound, `{"error":{"code":404,"message":"Requested entity was not found."}}`)
			return
		}
		var records []historyRecord
		for _, h := range f.history {
			if h.id > start {
				records = append(records, h.record)
			}
		}
		// One record per page, to exercise paging.
		offset, _ := strconv.Atoi(q.Get("pageToken"))
		page := map[string]any{"historyId": strconv.Itoa(f.historyID)}
		if offset < len(records) {
			page["history"] = records[offset : offset+1]
			if offset+1 < len(records) {
				page["nextPageToken"] = strconv.Itoa(offset + 1)
			}
		}
		writeJSON(w, page)
	default:
		f.t.Errorf("unexpected request %s %s", r.Method, r.URL)
		http.NotFound(w, r)
	}
}

// list answers messages.list newest first, filtered by one label, without Trash and Spam unless asked for.
func (f *fakeGmail) list(w http.ResponseWriter, q map[string][]string) {
	get := func(name string) string {
		if v := q[name]; len(v) > 0 {
			return v[0]
		}
		return ""
	}
	var all []*fakeMessage
	for _, m := range f.messages {
		search := get("q")
		if id, ok := strings.CutPrefix(search, "rfc822msgid:"); ok && !strings.Contains(string(m.raw), "<"+id+">") {
			continue
		}
		// Gmail lists chats unless the query leaves them out.
		if search == "-in:chats" && slices.Contains(m.labels, "CHAT") {
			continue
		}
		if labelID := get("labelIds"); labelID != "" && !slices.Contains(m.labels, labelID) {
			continue
		}
		hidden := slices.Contains(m.labels, "TRASH") || slices.Contains(m.labels, "SPAM")
		if hidden && get("includeSpamTrash") != "true" {
			continue
		}
		all = append(all, m)
	}
	slices.SortFunc(all, func(a, b *fakeMessage) int { return int(b.received - a.received) })

	// A page token is the receive time of the last message listed, so mail arriving meanwhile does not shift pages.
	if token := get("pageToken"); token != "" {
		before, _ := strconv.ParseInt(token, 10, 64)
		all = slices.DeleteFunc(all, func(m *fakeMessage) bool { return m.received >= before })
	}
	size, _ := strconv.Atoi(get("maxResults"))
	end := min(size, len(all))
	ids := []map[string]string{}
	for _, m := range all[:end] {
		ids = append(ids, map[string]string{"id": m.id, "threadId": m.id})
	}
	out := map[string]any{"messages": ids}
	if end < len(all) {
		out["nextPageToken"] = strconv.FormatInt(all[end-1].received, 10)
	}
	writeJSON(w, out)
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, status int, body string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_, _ = io.WriteString(w, body)
}
