package ical

import (
	"strings"
	"testing"
	"time"
)

func TestParsesAndWritesContentLines(t *testing.T) {
	text := "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a@b\r\nSUMMARY:Lunch\\, with \\;friends\\nand more\r\n" +
		"ATTENDEE;CN=\"Kim, Doe\";PARTSTAT=ACCEPTED:mailto:Kim@Example.com\r\nDESCRIPTION:" + strings.Repeat("é", 60) + "\r\n END\r\n" +
		"BEGIN:VALARM\r\nTRIGGER:-PT15M\r\nACTION:DISPLAY\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
	cal, err := Parse(text)
	if err != nil {
		t.Fatal(err)
	}
	event := cal.Master()
	if event.Text("SUMMARY") != "Lunch, with ;friends\nand more" {
		t.Errorf("summary = %q", event.Text("SUMMARY"))
	}
	attendee := event.Get("ATTENDEE")
	if attendee.Param("CN") != "Kim, Doe" || Address(attendee.Value) != "kim@example.com" {
		t.Errorf("attendee = %+v", attendee)
	}
	if !strings.HasSuffix(event.Text("DESCRIPTION"), "éEND") {
		t.Errorf("a folded line is unfolded: %q", event.Text("DESCRIPTION"))
	}
	if alarms := Alarms(event); len(alarms) != 1 || alarms[0].Minutes != 15 || alarms[0].Email {
		t.Errorf("alarms = %+v", alarms)
	}

	out := cal.Serialize()
	for _, line := range strings.Split(strings.TrimSuffix(out, "\r\n"), "\r\n") {
		if len(line) > 75 {
			t.Errorf("line of %d octets: %q", len(line), line)
		}
	}
	if !strings.Contains(out, `ATTENDEE;CN="Kim, Doe";PARTSTAT=ACCEPTED:mailto:Kim@Example.com`) {
		t.Errorf("a parameter with a comma stays quoted:\n%s", out)
	}
	again, err := Parse(out)
	if err != nil || again.Master().Text("DESCRIPTION") != event.Text("DESCRIPTION") || again.Master().Text("SUMMARY") != event.Text("SUMMARY") {
		t.Fatalf("round trip: %v\n%s", err, out)
	}
}

func TestRefusesBrokenObjects(t *testing.T) {
	for _, text := range []string{
		"BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nEND:VCALENDAR\r\n",
		"SUMMARY:outside\r\n",
		"BEGIN:VCALENDAR\r\nATTENDEE;CN=\"open:mailto:a@b\r\nEND:VCALENDAR\r\n",
		"BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\nBEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
	} {
		if _, err := Parse(text); err == nil {
			t.Errorf("parsed %q", text)
		}
	}
}

func TestReadsTimesInTheirZones(t *testing.T) {
	cases := []struct {
		line string
		key  string
		zone string
	}{
		{"DTSTART;TZID=W. Europe Standard Time:20261019T090000", "20261019T070000Z", "Europe/Berlin"},
		{"DTSTART;TZID=Europe/Amsterdam:20261026T090000", "20261026T080000Z", "Europe/Amsterdam"},
		{"DTSTART:20261019T090000Z", "20261019T090000Z", ""},
		{"DTSTART;VALUE=DATE:20261019", "20261019", ""},
		{"DTSTART:20261019T090000", "20261019T090000Z", ""},
	}
	for _, c := range cases {
		p, err := ParseContentLine(c.line)
		if err != nil {
			t.Fatal(err)
		}
		got, err := ParseTime(p)
		if err != nil {
			t.Fatalf("%s: %v", c.line, err)
		}
		if RecurrenceKey(got) != c.key || got.Zone != c.zone {
			t.Errorf("%s: key %s zone %q", c.line, RecurrenceKey(got), got.Zone)
		}
	}
	if _, err := ParseTime(Property{Name: "DTSTART", Params: []Param{{Name: "TZID", Values: []string{"Nowhere/Land"}}}, Value: "20261019T090000"}); err == nil {
		t.Error("an unknown zone is an error, never a silent move")
	}
	zoned := TimeProperty("EXDATE", Time{T: time.Date(2026, 11, 2, 8, 0, 0, 0, time.UTC), Zone: "Europe/Amsterdam"})
	if zoned.Line() != "EXDATE;TZID=Europe/Amsterdam:20261102T090000" {
		t.Errorf("line = %s", zoned.Line())
	}
}

func TestReadsDurationsAndEnds(t *testing.T) {
	for value, want := range map[string]time.Duration{"-PT15M": -15 * time.Minute, "P1DT2H": 26 * time.Hour, "P2W": 14 * 24 * time.Hour, "+PT30S": 30 * time.Second} {
		got, err := ParseDuration(value)
		if err != nil || got != want {
			t.Errorf("%s = %v, %v", value, got, err)
		}
	}
	for _, value := range []string{"PT", "15M", "P1X", "PT1"} {
		if _, err := ParseDuration(value); err == nil {
			t.Errorf("%s parsed", value)
		}
	}
	cal, _ := Parse("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261019\r\nDURATION:P2D\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")
	start, _ := ParseTime(*cal.Master().Get("DTSTART"))
	end, err := End(cal.Master(), start)
	if err != nil || !end.Date || end.T.Format("20060102") != "20261021" {
		t.Errorf("end = %+v, %v", end, err)
	}
}
