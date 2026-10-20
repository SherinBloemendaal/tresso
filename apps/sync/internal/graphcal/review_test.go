package graphcal

import (
	"strings"
	"testing"

	"tresso/sync/internal/api"
	"tresso/sync/internal/calsync/calsynctest"
)

const sharedCalendar = "AAMkShared"

func TestASharedCalendarIsReadAndWrittenUnderItsOwnPath(t *testing.T) {
	graph := newFakeGraph(t)
	graph.calendars = append(graph.calendars, calendarEntry{ID: sharedCalendar, Name: "Lisa", CanEdit: true})
	graph.putIn(sharedCalendar, single("ev-shared", "Lisa's planning"))
	fake := calsynctest.NewAPI(owner)
	run(t, graph, fake)
	calsynctest.Eventually(t, "the shared calendar", func() bool { _, ok := fake.Objects(sharedCalendar)["ev-shared"]; return ok })
	object := fake.Objects(sharedCalendar)["ev-shared"]

	remoteID, etag := "ev-shared", object.ETag
	renamed := strings.Replace(object.Ical, "SUMMARY:Lisa's planning", "SUMMARY:Planning", 1)
	fake.Queue(api.CalendarOperation{ID: "rename", Kind: "put", CalendarRemoteID: sharedCalendar, UID: "uid-ev-shared", RemoteID: &remoteID, IfMatch: &etag, Ical: &renamed, Notify: true})
	calsynctest.Eventually(t, "the write", func() bool { _, ok := fake.Result("rename"); return ok })

	if r, _ := fake.Result("rename"); r.Outcome != api.OperationDone {
		t.Fatalf("result = %+v", r)
	}
	writes := graph.writes()
	if len(writes) != 1 || writes[0].Path != "/v1.0/me/calendars/"+sharedCalendar+"/events/ev-shared" {
		t.Fatalf("writes = %+v", writes)
	}
	if _, ok := fake.Objects(sharedCalendar)["ev-shared"]; !ok {
		t.Fatal("the local copy stays")
	}
}

func TestEventsAreReadInTheirOwnZoneOrInUTC(t *testing.T) {
	graph := newFakeGraph(t)
	pacific := single("ev-pacific", "Standup in Seattle")
	pacific.OriginalStartTimeZone = "Pacific Standard Time"
	pacific.Start = &dateTimeTimeZone{DateTime: "2026-10-19T09:00:00.0000000", TimeZone: "Pacific Standard Time"}
	pacific.End = &dateTimeTimeZone{DateTime: "2026-10-19T09:30:00.0000000", TimeZone: "Pacific Standard Time"}
	graph.put(pacific)
	custom := single("ev-custom", "Made in an old Outlook")
	custom.OriginalStartTimeZone = "Customized Time Zone"
	custom.Start = &dateTimeTimeZone{DateTime: "2026-10-19T09:00:00.0000000", TimeZone: "Customized Time Zone"}
	custom.End = &dateTimeTimeZone{DateTime: "2026-10-19T10:00:00.0000000", TimeZone: "Customized Time Zone"}
	graph.put(custom)
	allDay := single("ev-day", "Off")
	allDay.IsAllDay = true
	allDay.Start = &dateTimeTimeZone{DateTime: "2026-10-20T00:00:00.0000000", TimeZone: "W. Europe Standard Time"}
	allDay.End = &dateTimeTimeZone{DateTime: "2026-10-22T00:00:00.0000000", TimeZone: "W. Europe Standard Time"}
	graph.put(allDay)
	fake := calsynctest.NewAPI(owner)
	run(t, graph, fake)
	calsynctest.Eventually(t, "the sync", func() bool { return len(fake.Objects(defaultCalendar)) == 3 })
	objects := fake.Objects(defaultCalendar)

	if start := parse(t, objects["ev-pacific"].Ical).Master().Get("DTSTART"); start.Param("TZID") != "America/Los_Angeles" || start.Value != "20261019T090000" {
		t.Errorf("Pacific: %+v", start)
	}
	if start := parse(t, objects["ev-custom"].Ical).Master().Get("DTSTART"); start.Value != "20261019T130000Z" {
		t.Errorf("a zone Tresso cannot place is read in UTC, at the right instant: %+v", start)
	}
	for _, request := range graph.gets("/events/ev-custom") {
		if request.Query.Get("$expand") != "" && !strings.Contains(strings.Join(request.Prefer, ","), `outlook.timezone="UTC"`) {
			t.Errorf("prefer = %v", request.Prefer)
		}
	}
	day := parse(t, objects["ev-day"].Ical).Master()
	if day.Get("DTSTART").Value != "20261020" || day.Get("DTEND").Value != "20261022" || day.Get("DTSTART").Param("VALUE") != "DATE" {
		t.Errorf("all day: %+v %+v", day.Get("DTSTART"), day.Get("DTEND"))
	}
}

func TestADescriptionEditKeepsTheTeamsJoinBlock(t *testing.T) {
	graph := newFakeGraph(t)
	meeting := single("ev-teams", "Planning")
	meeting.IsOnlineMeeting, meeting.OnlineMeetingProvider = true, "teamsForBusiness"
	meeting.OnlineMeeting = &onlineMeetingInfo{JoinURL: "https://teams.microsoft.com/l/meetup-join/abc"}
	meeting.Body = &itemBody{ContentType: "html", Content: `<html><body><div><b>Agenda</b></div><div style="color:#252424">________________<br>Microsoft Teams meeting<br><a href="https://teams.microsoft.com/l/meetup-join/abc">Join the meeting now</a></div></body></html>`}
	graph.put(meeting)
	fake := calsynctest.NewAPI(owner)
	run(t, graph, fake)
	calsynctest.Eventually(t, "the sync", func() bool { _, ok := fake.Objects(defaultCalendar)["ev-teams"]; return ok })
	object := fake.Objects(defaultCalendar)["ev-teams"]
	if !strings.Contains(object.Ical, "DESCRIPTION:Agenda") {
		t.Fatalf("ical:\n%s", object.Ical)
	}

	remoteID, etag := "ev-teams", object.ETag
	edited := strings.Replace(object.Ical, "DESCRIPTION:Agenda", "DESCRIPTION:Agenda & notes", 1)
	fake.Queue(api.CalendarOperation{ID: "edit", Kind: "put", CalendarRemoteID: defaultCalendar, UID: "uid-ev-teams", RemoteID: &remoteID, IfMatch: &etag, Ical: &edited, Notify: true})
	calsynctest.Eventually(t, "the write", func() bool { _, ok := fake.Result("edit"); return ok })

	writes := graph.writes()
	if len(writes) != 1 {
		t.Fatalf("writes = %+v", writes)
	}
	body := writes[0].Body["body"].(map[string]any)
	content := body["content"].(string)
	if body["contentType"] != "html" || !strings.Contains(content, "Agenda &amp; notes") || !strings.Contains(content, `<a href="https://teams.microsoft.com/l/meetup-join/abc">Join the meeting now</a>`) {
		t.Fatalf("body = %v", body)
	}
}
