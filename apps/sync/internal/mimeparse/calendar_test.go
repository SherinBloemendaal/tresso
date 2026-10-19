package mimeparse

import (
	"encoding/base64"
	"strings"
	"testing"
)

const invitation = `BEGIN:VCALENDAR
METHOD:REQUEST
BEGIN:VEVENT
UID:budget-review@finance.example
SUMMARY:Budget review at Café Loos
END:VEVENT
END:VCALENDAR
`

func TestReportsTheCalendarPartOnceWithItsMethod(t *testing.T) {
	ics := strings.ReplaceAll(invitation, "\n", "\r\n")
	raw := crlf(`
From: anna@finance.example
To: kim@company.example
Subject: Invitation: Budget review
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="outer"

--outer
Content-Type: multipart/alternative; boundary="alt"

--alt
Content-Type: text/plain; charset=utf-8

You are invited.
--alt
Content-Type: text/calendar; charset=iso-8859-1; method=REQUEST
Content-Transfer-Encoding: quoted-printable

BEGIN:VCALENDAR
METHOD:REQUEST
BEGIN:VEVENT
UID:budget-review@finance.example
SUMMARY:Budget review at Caf=E9 Loos
END:VEVENT
END:VCALENDAR
--alt--
--outer
Content-Type: application/ics; name="invite.ics"
Content-Disposition: attachment; filename="invite.ics"
Content-Transfer-Encoding: base64

` + b64(ics) + `
--outer--
`)

	msg, err := Parse(raw)
	if err != nil {
		t.Fatal(err)
	}
	if len(msg.CalendarParts) != 2 {
		t.Fatalf("want the decoded alternative part and the attachment (different bytes), got %d", len(msg.CalendarParts))
	}
	if msg.CalendarParts[0].Method != "REQUEST" {
		t.Errorf("method = %q", msg.CalendarParts[0].Method)
	}
	if !strings.Contains(msg.CalendarParts[0].Data, "SUMMARY:Budget review at Café Loos") {
		t.Errorf("the part was not decoded from its charset: %q", msg.CalendarParts[0].Data)
	}
	if msg.CalendarParts[1].Method != "" {
		t.Errorf("the attachment has no method parameter, got %q", msg.CalendarParts[1].Method)
	}
	if len(msg.Attachments) != 1 || msg.Attachments[0].Filename != "invite.ics" {
		t.Errorf("the .ics file stays downloadable as an attachment: %+v", msg.Attachments)
	}
	if msg.Snippet != "You are invited." {
		t.Errorf("snippet = %q", msg.Snippet)
	}
}

func TestKeepsIdenticalCalendarObjectsOnce(t *testing.T) {
	ics := strings.ReplaceAll(invitation, "\n", "\r\n")
	raw := crlf(`
From: anna@finance.example
Subject: Invitation
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="outer"

--outer
Content-Type: text/calendar; charset=utf-8; method=REQUEST
Content-Transfer-Encoding: base64

` + b64(ics) + `
--outer
Content-Type: text/calendar; charset=utf-8; method=REQUEST; name="invite.ics"
Content-Disposition: attachment; filename="invite.ics"
Content-Transfer-Encoding: base64

` + b64(ics) + `
--outer--
`)

	msg, err := Parse(raw)
	if err != nil {
		t.Fatal(err)
	}
	if len(msg.CalendarParts) != 1 {
		t.Fatalf("want one calendar part, got %d", len(msg.CalendarParts))
	}
}

func TestIgnoresCalendarPartsThatAreNoCalendar(t *testing.T) {
	raw := crlf(`
From: anna@finance.example
Subject: Not really
MIME-Version: 1.0
Content-Type: text/calendar; method=REQUEST

this is no calendar
`)

	msg, err := Parse(raw)
	if err != nil {
		t.Fatal(err)
	}
	if len(msg.CalendarParts) != 0 {
		t.Fatalf("want no calendar parts, got %d", len(msg.CalendarParts))
	}
}

func b64(s string) string {
	return base64.StdEncoding.EncodeToString([]byte(s))
}
