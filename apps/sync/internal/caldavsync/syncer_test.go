package caldavsync

import (
	"context"
	"io"
	"log/slog"
	"strings"
	"testing"

	"tresso/sync/internal/api"
	"tresso/sync/internal/caldav"
)

func event(uid, summary string) string {
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Fake//EN\r\nBEGIN:VEVENT\r\nUID:" + uid + "\r\nDTSTART:20261019T090000Z\r\nSUMMARY:" + summary + "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
}

func openRun(t *testing.T, server *fakeServer, fake *fakeAPI) *run {
	t.Helper()
	syncer := New(fake, plainDialer{}, "w1", Options{AllowHTTP: true}, slog.New(slog.NewTextHandler(io.Discard, nil)))
	client := caldav.New(plainDialer{}, "kim@company.example", "app-password", true)
	account, err := client.Open(context.Background(), server.server.URL+"/dav/")
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	return &run{syncer: syncer, client: client, server: account, account: api.Account{ID: "a1"}, logger: syncer.logger}
}

func TestSyncsWithSyncTokensAndReportsOnlyChanges(t *testing.T) {
	server := newFakeServer(t, true)
	server.put(calendarPath+"standup.ics", event("standup", "Standup"))
	server.put(calendarPath+"review.ics", event("review", "Review"))
	fake := newFakeAPI(server.server.URL + "/dav/")
	r := openRun(t, server, fake)

	if err := r.syncAll(context.Background()); err != nil {
		t.Fatalf("first sync: %v", err)
	}
	list := fake.lists[0]
	if len(list.Calendars) != 1 || list.Calendars[0].Name != "Home" || *list.Calendars[0].Color != "#2e7d32" || *list.Calendars[0].TimeZone != "Europe/Amsterdam" || !list.Calendars[0].Writable {
		t.Fatalf("calendar list = %+v", list.Calendars)
	}
	if list.ProviderSchedules {
		t.Error("the server does not schedule by itself")
	}
	if len(fake.etags) != 2 || len(fake.inventory) != 1 {
		t.Fatalf("the first sync is a full listing: inventory %d, stored %v", len(fake.inventory), fake.etags)
	}
	if cursor := fake.cursors["cal-Home"]; cursor == nil || !strings.Contains(*cursor, `"syncToken":"token-2"`) {
		t.Fatalf("cursor = %v", cursor)
	}

	batches := len(fake.batches)
	if err := r.syncAll(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(fake.batches) != batches {
		t.Fatal("an unchanged calendar is not reported again")
	}

	server.put(calendarPath+"standup.ics", event("standup", "Standup, shorter"))
	server.remove(calendarPath + "review.ics")
	if err := r.syncAll(context.Background()); err != nil {
		t.Fatal(err)
	}
	last := fake.batches[len(fake.batches)-1]
	if len(last.Changed) != 1 || !strings.HasSuffix(last.Changed[0].RemoteID, "standup.ics") || !strings.Contains(*last.Changed[0].Ical, "Standup, shorter") {
		t.Fatalf("changed = %+v", last.Changed)
	}
	if len(last.Removed) != 1 || !strings.HasSuffix(last.Removed[0], "review.ics") {
		t.Fatalf("removed = %v", last.Removed)
	}
	if len(fake.inventory) != 1 {
		t.Fatal("changes come from sync-collection, not a full listing")
	}
}

func TestSyncsByCTagWithoutSyncTokens(t *testing.T) {
	server := newFakeServer(t, false)
	server.put(calendarPath+"standup.ics", event("standup", "Standup"))
	fake := newFakeAPI(server.server.URL + "/dav/")
	r := openRun(t, server, fake)

	if err := r.syncAll(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(fake.etags) != 1 || !strings.Contains(*fake.cursors["cal-Home"], `"ctag":"ctag-1"`) {
		t.Fatalf("stored %v, cursor %v", fake.etags, *fake.cursors["cal-Home"])
	}

	if err := r.syncAll(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(fake.inventory) != 1 {
		t.Fatal("an unchanged ctag skips the listing")
	}

	server.put(calendarPath+"planning.ics", event("planning", "Planning"))
	if err := r.syncAll(context.Background()); err != nil {
		t.Fatal(err)
	}
	last := fake.batches[len(fake.batches)-1]
	if len(last.Changed) != 1 || !strings.HasSuffix(last.Changed[0].RemoteID, "planning.ics") {
		t.Fatalf("only the new resource is fetched: %+v", last.Changed)
	}
}

func TestWritesCreatesUpdatesAndDeletes(t *testing.T) {
	server := newFakeServer(t, true)
	server.put(calendarPath+"standup.ics", event("standup", "Standup"))
	fake := newFakeAPI(server.server.URL + "/dav/")
	r := openRun(t, server, fake)
	calendarURL := server.server.URL + calendarPath
	created := event("new@tresso", "New")
	updated := event("standup", "Standup, moved")
	existing := server.server.URL + calendarPath + "standup.ics"
	etag := `"1"`
	stale := `"0"`
	fake.operations = []api.CalendarOperation{
		{ID: "create", Kind: "put", CalendarRemoteID: calendarURL, UID: "new@tresso", Ical: &created},
		{ID: "update", Kind: "put", CalendarRemoteID: calendarURL, UID: "standup", RemoteID: &existing, IfMatch: &etag, Ical: &updated},
	}

	if err := r.writeAll(context.Background()); err != nil {
		t.Fatal(err)
	}
	if result := fake.results["create"]; result.Outcome != api.OperationDone || !strings.HasSuffix(*result.RemoteID, "/home/new-tresso.ics") || result.ETag == nil {
		t.Fatalf("create = %+v", result)
	}
	if result := fake.results["update"]; result.Outcome != api.OperationDone || *result.ETag != `"3"` {
		t.Fatalf("update = %+v", result)
	}
	if !strings.Contains(server.objects[calendarPath+"standup.ics"].data, "Standup, moved") {
		t.Fatal("the update reached the server")
	}

	conflicting := event("standup", "Mine")
	fake.operations = []api.CalendarOperation{
		{ID: "conflict", Kind: "put", CalendarRemoteID: calendarURL, UID: "standup", RemoteID: &existing, IfMatch: &stale, Ical: &conflicting},
		{ID: "delete", Kind: "delete", CalendarRemoteID: calendarURL, UID: "new@tresso", RemoteID: fake.results["create"].RemoteID, IfMatch: fake.results["create"].ETag},
	}
	if err := r.writeAll(context.Background()); err != nil {
		t.Fatal(err)
	}
	conflict := fake.results["conflict"]
	if conflict.Outcome != api.OperationConflict || conflict.Current == nil || !strings.Contains(*conflict.Current.Ical, "Standup, moved") {
		t.Fatalf("conflict = %+v", conflict)
	}
	if result := fake.results["delete"]; result.Outcome != api.OperationDone {
		t.Fatalf("delete = %+v", result)
	}
	if _, ok := server.objects[calendarPath+"new-tresso.ics"]; ok {
		t.Fatal("the delete reached the server")
	}
}

func TestAWrongPasswordIsReported(t *testing.T) {
	server := newFakeServer(t, true)
	client := caldav.New(plainDialer{}, "kim@company.example", "wrong", true)

	if _, err := client.Open(context.Background(), server.server.URL+"/dav/"); err != caldav.ErrUnauthorized {
		t.Fatalf("want ErrUnauthorized, got %v", err)
	}
}

func TestResourceNamesAreSafe(t *testing.T) {
	if got := resourceURL("https://dav.example/cal/home", "01a1@tresso/../x y"); got != "https://dav.example/cal/home/01a1-tresso-..-x-y.ics" {
		t.Fatalf("got %s", got)
	}
}
