package graphcal

import (
	"fmt"
	"strings"
	"time"

	"tresso/sync/internal/ical"
)

type dateTimeTimeZone struct {
	DateTime string `json:"dateTime"`
	TimeZone string `json:"timeZone"`
}

type emailAddress struct {
	Name    string `json:"name,omitempty"`
	Address string `json:"address"`
}

type recipient struct {
	EmailAddress emailAddress `json:"emailAddress"`
}

type responseStatus struct {
	Response string `json:"response,omitempty"`
}

type attendee struct {
	Type         string          `json:"type"`
	Status       *responseStatus `json:"status,omitempty"`
	EmailAddress emailAddress    `json:"emailAddress"`
}

type itemBody struct {
	ContentType string `json:"contentType"`
	Content     string `json:"content"`
}

type location struct {
	DisplayName string `json:"displayName"`
}

type onlineMeetingInfo struct {
	JoinURL      string `json:"joinUrl"`
	ConferenceID string `json:"conferenceId,omitempty"`
	TollNumber   string `json:"tollNumber,omitempty"`
}

type extendedProperty struct {
	ID    string `json:"id"`
	Value string `json:"value"`
}

// event is a Microsoft Graph event, as far as Tresso reads and writes it.
type event struct {
	ID                            string               `json:"id,omitempty"`
	ChangeKey                     string               `json:"changeKey,omitempty"`
	ODataETag                     string               `json:"@odata.etag,omitempty"`
	ICalUID                       string               `json:"iCalUId,omitempty"`
	Type                          string               `json:"type,omitempty"`
	SeriesMasterID                string               `json:"seriesMasterId,omitempty"`
	OriginalStart                 string               `json:"originalStart,omitempty"`
	OriginalStartTimeZone         string               `json:"originalStartTimeZone,omitempty"`
	LastModifiedDateTime          string               `json:"lastModifiedDateTime,omitempty"`
	Subject                       string               `json:"subject,omitempty"`
	Body                          *itemBody            `json:"body,omitempty"`
	Start                         *dateTimeTimeZone    `json:"start,omitempty"`
	End                           *dateTimeTimeZone    `json:"end,omitempty"`
	IsAllDay                      bool                 `json:"isAllDay,omitempty"`
	IsCancelled                   bool                 `json:"isCancelled,omitempty"`
	IsOrganizer                   bool                 `json:"isOrganizer,omitempty"`
	Organizer                     *recipient           `json:"organizer,omitempty"`
	Attendees                     []attendee           `json:"attendees,omitempty"`
	Location                      *location            `json:"location,omitempty"`
	ShowAs                        string               `json:"showAs,omitempty"`
	Sensitivity                   string               `json:"sensitivity,omitempty"`
	IsReminderOn                  bool                 `json:"isReminderOn,omitempty"`
	ReminderMinutesBeforeStart    int                  `json:"reminderMinutesBeforeStart,omitempty"`
	Recurrence                    *patternedRecurrence `json:"recurrence,omitempty"`
	ResponseStatus                *responseStatus      `json:"responseStatus,omitempty"`
	IsOnlineMeeting               bool                 `json:"isOnlineMeeting,omitempty"`
	OnlineMeetingProvider         string               `json:"onlineMeetingProvider,omitempty"`
	OnlineMeeting                 *onlineMeetingInfo   `json:"onlineMeeting,omitempty"`
	CancelledOccurrences          []string             `json:"cancelledOccurrences,omitempty"`
	ExceptionOccurrences          []event              `json:"exceptionOccurrences,omitempty"`
	SingleValueExtendedProperties []extendedProperty   `json:"singleValueExtendedProperties,omitempty"`
}

// uidProperty keeps the iCalendar UID of events Tresso created: Graph gives every new event a UID of its own and
// lets no one set it, and the API keys events by their UID.
const uidProperty = "String {6f1e3b2a-4c5d-4e8f-9a0b-1c2d3e4f5a6b} Name TressoUid"

func (e event) uid() string {
	for _, p := range e.SingleValueExtendedProperties {
		if strings.EqualFold(p.ID, uidProperty) && p.Value != "" {
			return p.Value
		}
	}
	return e.ICalUID
}

// zone is the IANA zone an event's times are read in: the zone it was made in, else UTC.
func zone(name string) string {
	if name == "" || strings.HasPrefix(name, "tzone://") {
		return "UTC"
	}
	if _, iana, err := ical.ZoneOf(name); err == nil {
		return iana
	}
	return "UTC"
}

// graphTime reads a Graph time, which comes in the zone the request preferred.
func graphTime(t *dateTimeTimeZone, allDay bool) (ical.Time, error) {
	if t == nil {
		return ical.Time{}, fmt.Errorf("graph: missing time")
	}
	value := t.DateTime
	if i := strings.IndexByte(value, '.'); i >= 0 {
		value = value[:i]
	}
	if allDay {
		d, err := time.Parse("2006-01-02T15:04:05", value)
		if err != nil {
			return ical.Time{}, fmt.Errorf("graph: date %q: %w", t.DateTime, err)
		}
		return ical.Time{T: time.Date(d.Year(), d.Month(), d.Day(), 0, 0, 0, 0, time.UTC), Date: true}, nil
	}
	name := zone(t.TimeZone)
	loc, err := time.LoadLocation(name)
	if err != nil {
		return ical.Time{}, err
	}
	wall, err := time.ParseInLocation("2006-01-02T15:04:05", value, loc)
	if err != nil {
		return ical.Time{}, fmt.Errorf("graph: date-time %q: %w", t.DateTime, err)
	}
	if name == "UTC" {
		return ical.Time{T: wall.UTC()}, nil
	}
	return ical.Time{T: wall, Zone: name}, nil
}

// occurrenceTime is the original start of an occurrence (a UTC instant) in the form of the series' start. eventZone
// is the zone the series was made in: an all-day occurrence starts at midnight there.
func occurrenceTime(originalStart string, seriesStart ical.Time, eventZone string) (ical.Time, error) {
	instant, err := time.Parse(time.RFC3339, originalStart)
	if err != nil {
		return ical.Time{}, fmt.Errorf("graph: original start %q: %w", originalStart, err)
	}
	return inSeriesForm(instant, seriesStart, eventZone), nil
}

func inSeriesForm(instant time.Time, seriesStart ical.Time, eventZone string) ical.Time {
	switch {
	case seriesStart.Date:
		local := instant.UTC()
		if loc, err := time.LoadLocation(zone(eventZone)); err == nil {
			local = instant.In(loc)
		}
		// Midnight in a zone that was not the one the series was made in still lands within half a day of it.
		if local.Hour() >= 12 {
			local = local.Add(12 * time.Hour)
		}
		return ical.Time{T: time.Date(local.Year(), local.Month(), local.Day(), 0, 0, 0, 0, time.UTC), Date: true}
	case seriesStart.Zone != "":
		return ical.Time{T: instant, Zone: seriesStart.Zone}
	default:
		return ical.Time{T: instant.UTC()}
	}
}

// cancelledTime reads an occurrence id of Graph's cancelledOccurrences ("OID.<master id>.<yyyy-mm-dd>") as the
// start the series has that day.
func cancelledTime(occurrenceID string, seriesStart ical.Time) (ical.Time, bool) {
	i := strings.LastIndexByte(occurrenceID, '.')
	if i < 0 {
		return ical.Time{}, false
	}
	day, err := time.Parse("2006-01-02", occurrenceID[i+1:])
	if err != nil {
		return ical.Time{}, false
	}
	if seriesStart.Date {
		return ical.Time{T: day, Date: true}, true
	}
	loc := time.UTC
	if seriesStart.Zone != "" {
		if l, err := time.LoadLocation(seriesStart.Zone); err == nil {
			loc = l
		}
	}
	wall := seriesStart.T.In(loc)
	at := time.Date(day.Year(), day.Month(), day.Day(), wall.Hour(), wall.Minute(), wall.Second(), 0, loc)
	return ical.Time{T: at, Zone: seriesStart.Zone}, true
}

// toICalendar writes a single event or a series master with its exceptions and cancelled occurrences.
func toICalendar(master event, self string) (string, error) {
	cal := ical.Calendar()
	v, err := toVEvent(master, self, nil, master.uid())
	if err != nil {
		return "", err
	}
	cal.Children = append(cal.Children, v)
	if master.Type == "seriesMaster" {
		start, err := graphTime(master.Start, master.IsAllDay)
		if err != nil {
			return "", err
		}
		for _, occurrence := range master.CancelledOccurrences {
			if t, ok := cancelledTime(occurrence, start); ok {
				v.Props = append(v.Props, ical.TimeProperty("EXDATE", t))
			}
		}
		for _, exception := range master.ExceptionOccurrences {
			original, err := occurrenceTime(exception.OriginalStart, start, master.OriginalStartTimeZone)
			if err != nil {
				return "", err
			}
			override, err := toVEvent(exception, self, &original, master.uid())
			if err != nil {
				return "", err
			}
			cal.Children = append(cal.Children, override)
		}
	}
	return cal.Serialize(), nil
}

func toVEvent(e event, self string, recurrenceID *ical.Time, uid string) (*ical.Component, error) {
	v := &ical.Component{Name: "VEVENT"}
	if uid == "" {
		uid = e.ID
	}
	v.AddText("UID", uid)
	stamp := time.Now().UTC()
	if modified, err := time.Parse(time.RFC3339, e.LastModifiedDateTime); err == nil {
		stamp = modified.UTC()
		v.Add("LAST-MODIFIED", stamp.Format("20060102T150405Z"))
	}
	v.Props = append([]ical.Property{{Name: "DTSTAMP", Value: stamp.Format("20060102T150405Z")}}, v.Props...)
	if recurrenceID != nil {
		v.Props = append(v.Props, ical.TimeProperty("RECURRENCE-ID", *recurrenceID))
	}
	start, err := graphTime(e.Start, e.IsAllDay)
	if err != nil {
		return nil, err
	}
	end, err := graphTime(e.End, e.IsAllDay)
	if err != nil {
		return nil, err
	}
	v.Props = append(v.Props, ical.TimeProperty("DTSTART", start), ical.TimeProperty("DTEND", end))
	if recurrenceID == nil && e.Recurrence != nil {
		rule, err := toRRule(*e.Recurrence, start)
		if err != nil {
			return nil, err
		}
		v.Add("RRULE", rule)
	}
	v.AddText("SUMMARY", e.Subject)
	if e.Body != nil {
		v.AddText("DESCRIPTION", strings.TrimSpace(e.Body.Content))
	}
	if e.Location != nil {
		v.AddText("LOCATION", e.Location.DisplayName)
	}
	if e.IsCancelled {
		v.Add("STATUS", "CANCELLED")
	} else {
		v.Add("STATUS", "CONFIRMED")
	}
	if e.ShowAs == "free" {
		v.Add("TRANSP", "TRANSPARENT")
	} else {
		v.Add("TRANSP", "OPAQUE")
	}
	switch e.Sensitivity {
	case "private", "personal":
		v.Add("CLASS", "PRIVATE")
	case "confidential":
		v.Add("CLASS", "CONFIDENTIAL")
	}
	organizer := ""
	if e.Organizer != nil && e.Organizer.EmailAddress.Address != "" {
		organizer = ical.Address(e.Organizer.EmailAddress.Address)
		v.Add("ORGANIZER", "mailto:"+organizer, nameParam(e.Organizer.EmailAddress.Name)...)
	}
	listedSelf := false
	for _, a := range e.Attendees {
		address := ical.Address(a.EmailAddress.Address)
		if address == "" {
			continue
		}
		status := ""
		if a.Status != nil {
			status = a.Status.Response
		}
		if address == self {
			listedSelf = true
			// The calendar owner's own answer is in responseStatus, which is always current.
			if e.ResponseStatus != nil && e.ResponseStatus.Response != "" && e.ResponseStatus.Response != "none" {
				status = e.ResponseStatus.Response
			}
		}
		v.Add("ATTENDEE", "mailto:"+address, attendeeParams(a.EmailAddress.Name, a.Type, status)...)
	}
	if !listedSelf && !e.IsOrganizer && organizer != self && len(e.Attendees) > 0 && e.ResponseStatus != nil {
		v.Add("ATTENDEE", "mailto:"+self, attendeeParams("", "required", e.ResponseStatus.Response)...)
	}
	if e.IsReminderOn {
		alarm := &ical.Component{Name: "VALARM"}
		alarm.Add("ACTION", "DISPLAY")
		alarm.AddText("DESCRIPTION", "Reminder")
		alarm.Add("TRIGGER", fmt.Sprintf("-PT%dM", max(e.ReminderMinutesBeforeStart, 0)))
		v.Children = append(v.Children, alarm)
	}
	if e.OnlineMeeting != nil && strings.HasPrefix(e.OnlineMeeting.JoinURL, "https://") {
		label := "Online meeting"
		if strings.HasPrefix(e.OnlineMeetingProvider, "teams") {
			label = "Microsoft Teams"
			v.Add("X-MICROSOFT-SKYPETEAMSMEETINGURL", e.OnlineMeeting.JoinURL)
		}
		v.Add("CONFERENCE", e.OnlineMeeting.JoinURL, ical.Param{Name: "VALUE", Values: []string{"URI"}}, ical.Param{Name: "FEATURE", Values: []string{"VIDEO"}}, ical.Param{Name: "LABEL", Values: []string{label}})
		if number := strings.TrimSpace(e.OnlineMeeting.TollNumber); number != "" {
			v.Add("CONFERENCE", "tel:"+strings.ReplaceAll(number, " ", ""), ical.Param{Name: "VALUE", Values: []string{"URI"}}, ical.Param{Name: "FEATURE", Values: []string{"PHONE"}})
		}
	}
	return v, nil
}

func nameParam(name string) []ical.Param {
	if name == "" {
		return nil
	}
	return []ical.Param{{Name: "CN", Values: []string{name}}}
}

func attendeeParams(name, kind, status string) []ical.Param {
	params := nameParam(name)
	partstat := partstatOf(status)
	params = append(params, ical.Param{Name: "PARTSTAT", Values: []string{partstat}})
	switch kind {
	case "optional":
		params = append(params, ical.Param{Name: "ROLE", Values: []string{"OPT-PARTICIPANT"}})
	case "resource":
		params = append(params, ical.Param{Name: "ROLE", Values: []string{"NON-PARTICIPANT"}}, ical.Param{Name: "CUTYPE", Values: []string{"RESOURCE"}})
	default:
		params = append(params, ical.Param{Name: "ROLE", Values: []string{"REQ-PARTICIPANT"}})
	}
	if partstat == "NEEDS-ACTION" {
		params = append(params, ical.Param{Name: "RSVP", Values: []string{"TRUE"}})
	}
	return params
}

func partstatOf(response string) string {
	switch response {
	case "accepted", "organizer":
		return "ACCEPTED"
	case "tentativelyAccepted":
		return "TENTATIVE"
	case "declined":
		return "DECLINED"
	default:
		return "NEEDS-ACTION"
	}
}
