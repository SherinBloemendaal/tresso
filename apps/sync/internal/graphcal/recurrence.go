package graphcal

import (
	"fmt"
	"strconv"
	"strings"
	"time"

	"tresso/sync/internal/ical"
)

// patternedRecurrence is Graph's recurrence: a pattern and a range, instead of an RRULE.
type patternedRecurrence struct {
	Pattern recurrencePattern `json:"pattern"`
	Range   recurrenceRange   `json:"range"`
}

type recurrencePattern struct {
	Type           string   `json:"type"`
	Interval       int      `json:"interval"`
	Month          int      `json:"month,omitempty"`
	DayOfMonth     int      `json:"dayOfMonth,omitempty"`
	DaysOfWeek     []string `json:"daysOfWeek,omitempty"`
	FirstDayOfWeek string   `json:"firstDayOfWeek,omitempty"`
	Index          string   `json:"index,omitempty"`
}

type recurrenceRange struct {
	Type                string `json:"type"`
	StartDate           string `json:"startDate"`
	EndDate             string `json:"endDate,omitempty"`
	RecurrenceTimeZone  string `json:"recurrenceTimeZone,omitempty"`
	NumberOfOccurrences int    `json:"numberOfOccurrences,omitempty"`
}

var (
	graphDays = map[string]string{"sunday": "SU", "monday": "MO", "tuesday": "TU", "wednesday": "WE", "thursday": "TH", "friday": "FR", "saturday": "SA"}
	icalDays  = map[string]string{"SU": "sunday", "MO": "monday", "TU": "tuesday", "WE": "wednesday", "TH": "thursday", "FR": "friday", "SA": "saturday"}
	// Graph's week indexes and RFC 5545's BYSETPOS (or BYDAY ordinal) values.
	graphIndex = map[string]int{"first": 1, "second": 2, "third": 3, "fourth": 4, "last": -1}
	icalIndex  = map[int]string{1: "first", 2: "second", 3: "third", 4: "fourth", -1: "last"}
)

// toRRule writes a Graph recurrence as an RRULE. start is the series' first start in its own zone; UNTIL is the
// start's wall clock on the range's end date (the last occurrence may begin then), in UTC for timed series.
func toRRule(r patternedRecurrence, start ical.Time) (string, error) {
	p := r.Pattern
	parts := []string{}
	interval := max(p.Interval, 1)
	days := func() (string, error) {
		var out []string
		for _, day := range p.DaysOfWeek {
			code, ok := graphDays[strings.ToLower(day)]
			if !ok {
				return "", fmt.Errorf("graph: unknown day %q", day)
			}
			out = append(out, code)
		}
		if len(out) == 0 {
			return "", fmt.Errorf("graph: a %s pattern without days", p.Type)
		}
		return strings.Join(out, ","), nil
	}
	setPos := func() (string, error) {
		index, ok := graphIndex[strings.ToLower(p.Index)]
		if p.Index == "" {
			index, ok = 1, true
		}
		if !ok {
			return "", fmt.Errorf("graph: unknown index %q", p.Index)
		}
		return strconv.Itoa(index), nil
	}
	switch p.Type {
	case "daily":
		parts = append(parts, "FREQ=DAILY")
	case "weekly":
		byDay, err := days()
		if err != nil {
			return "", err
		}
		parts = append(parts, "FREQ=WEEKLY", "BYDAY="+byDay)
		if code, ok := graphDays[strings.ToLower(p.FirstDayOfWeek)]; ok {
			parts = append(parts, "WKST="+code)
		}
	case "absoluteMonthly":
		parts = append(parts, "FREQ=MONTHLY", "BYMONTHDAY="+strconv.Itoa(p.DayOfMonth))
	case "relativeMonthly", "relativeYearly":
		byDay, err := days()
		if err != nil {
			return "", err
		}
		pos, err := setPos()
		if err != nil {
			return "", err
		}
		if p.Type == "relativeMonthly" {
			parts = append(parts, "FREQ=MONTHLY")
		} else {
			parts = append(parts, "FREQ=YEARLY", "BYMONTH="+strconv.Itoa(p.Month))
		}
		parts = append(parts, "BYDAY="+byDay, "BYSETPOS="+pos)
	case "absoluteYearly":
		parts = append(parts, "FREQ=YEARLY", "BYMONTH="+strconv.Itoa(p.Month), "BYMONTHDAY="+strconv.Itoa(p.DayOfMonth))
	default:
		return "", fmt.Errorf("graph: unknown recurrence %q", p.Type)
	}
	if interval > 1 {
		parts = append(parts, "INTERVAL="+strconv.Itoa(interval))
	}
	switch r.Range.Type {
	case "numbered":
		if r.Range.NumberOfOccurrences > 0 {
			parts = append(parts, "COUNT="+strconv.Itoa(r.Range.NumberOfOccurrences))
		}
	case "endDate":
		end, err := time.Parse("2006-01-02", r.Range.EndDate)
		if err != nil {
			return "", fmt.Errorf("graph: end date %q: %w", r.Range.EndDate, err)
		}
		parts = append(parts, "UNTIL="+until(end, start))
	}
	return strings.Join(parts, ";"), nil
}

// until is the UNTIL of a series ending on date: the date for all-day series, else the start's wall clock that day.
func until(date time.Time, start ical.Time) string {
	switch {
	case start.Date:
		return date.Format("20060102")
	case start.Floating:
		return time.Date(date.Year(), date.Month(), date.Day(), start.T.Hour(), start.T.Minute(), start.T.Second(), 0, time.UTC).Format("20060102T150405")
	default:
		loc := time.UTC
		if start.Zone != "" {
			if l, err := time.LoadLocation(start.Zone); err == nil {
				loc = l
			}
		}
		wall := start.T.In(loc)
		return time.Date(date.Year(), date.Month(), date.Day(), wall.Hour(), wall.Minute(), wall.Second(), 0, loc).UTC().Format("20060102T150405Z")
	}
}

// errUnsupported: the rule has no Graph pattern.
type errUnsupported struct{ reason string }

func (e errUnsupported) Error() string {
	return "Outlook cannot repeat an event this way: " + e.reason + "."
}

// fromRRule turns an RRULE into Graph's pattern and range; rules Graph cannot express are refused.
func fromRRule(rule string, start ical.Time, zone string) (*patternedRecurrence, error) {
	values := map[string]string{}
	for _, part := range strings.Split(rule, ";") {
		key, value, ok := strings.Cut(part, "=")
		if !ok {
			return nil, errUnsupported{"the rule is unreadable"}
		}
		values[strings.ToUpper(key)] = strings.ToUpper(value)
	}
	for key := range values {
		switch key {
		case "FREQ", "INTERVAL", "BYDAY", "BYMONTHDAY", "BYMONTH", "BYSETPOS", "COUNT", "UNTIL", "WKST":
		default:
			return nil, errUnsupported{"it uses " + key}
		}
	}
	wall := start.T
	if start.Zone != "" {
		if loc, err := time.LoadLocation(start.Zone); err == nil {
			wall = start.T.In(loc)
		}
	}
	r := &patternedRecurrence{}
	r.Pattern.Interval = 1
	if v := values["INTERVAL"]; v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 || n > 99 {
			return nil, errUnsupported{"its interval is out of range"}
		}
		r.Pattern.Interval = n
	}

	type ordinalDay struct {
		ordinal int
		day     string
	}
	var byDay []ordinalDay
	if v := values["BYDAY"]; v != "" {
		for _, item := range strings.Split(v, ",") {
			code := item[max(len(item)-2, 0):]
			day, ok := icalDays[code]
			if !ok {
				return nil, errUnsupported{"a day is unreadable"}
			}
			ordinal := 0
			if prefix := item[:len(item)-2]; prefix != "" {
				n, err := strconv.Atoi(strings.TrimPrefix(prefix, "+"))
				if err != nil {
					return nil, errUnsupported{"a day is unreadable"}
				}
				ordinal = n
			}
			byDay = append(byDay, ordinalDay{ordinal, day})
		}
	}
	days := func() []string {
		out := make([]string, 0, len(byDay))
		for _, d := range byDay {
			out = append(out, d.day)
		}
		return out
	}
	// relative reads "the second Monday" from BYDAY=2MO, or "the first of Monday to Friday" from
	// BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1. Graph's index picks one of all the days listed, so BYDAY=1MO,1WE (the first
	// Monday and the first Wednesday, two occurrences) has no Graph pattern.
	relative := func() (string, bool) {
		ordinal := 0
		for _, d := range byDay {
			if d.ordinal != 0 {
				if len(byDay) > 1 {
					return "", false
				}
				ordinal = d.ordinal
			}
		}
		if pos := values["BYSETPOS"]; pos != "" {
			n, err := strconv.Atoi(pos)
			if err != nil || ordinal != 0 {
				return "", false
			}
			ordinal = n
		}
		index, ok := icalIndex[ordinal]
		return index, ok
	}
	monthDay := wall.Day()
	if v := values["BYMONTHDAY"]; v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 || n > 31 {
			return nil, errUnsupported{"it repeats on a day counted from the month's end, or on several days"}
		}
		monthDay = n
	}
	month := int(wall.Month())
	if v := values["BYMONTH"]; v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 || n > 12 {
			return nil, errUnsupported{"it repeats in several months"}
		}
		month = n
	}

	switch values["FREQ"] {
	case "DAILY":
		if len(byDay) > 0 {
			if r.Pattern.Interval != 1 || values["BYSETPOS"] != "" {
				return nil, errUnsupported{"it repeats on some days of every few days"}
			}
			r.Pattern.Type, r.Pattern.DaysOfWeek, r.Pattern.FirstDayOfWeek = "weekly", days(), "monday"
		} else {
			r.Pattern.Type = "daily"
		}
	case "WEEKLY":
		if values["BYSETPOS"] != "" {
			return nil, errUnsupported{"it picks occurrences within the week"}
		}
		r.Pattern.Type = "weekly"
		if len(byDay) == 0 {
			r.Pattern.DaysOfWeek = []string{strings.ToLower(wall.Weekday().String())}
		} else {
			for _, d := range byDay {
				if d.ordinal != 0 {
					return nil, errUnsupported{"a weekly rule numbers its days"}
				}
			}
			r.Pattern.DaysOfWeek = days()
		}
		r.Pattern.FirstDayOfWeek = "monday"
		if wkst, ok := icalDays[values["WKST"]]; ok {
			r.Pattern.FirstDayOfWeek = wkst
		}
	case "MONTHLY", "YEARLY":
		yearly := values["FREQ"] == "YEARLY"
		if yearly {
			r.Pattern.Month = month
		} else if values["BYMONTH"] != "" {
			return nil, errUnsupported{"it repeats monthly in some months only"}
		}
		if len(byDay) > 0 {
			if values["BYMONTHDAY"] != "" {
				return nil, errUnsupported{"it combines days of the week and of the month"}
			}
			index, ok := relative()
			if !ok {
				return nil, errUnsupported{"it repeats on several numbered days"}
			}
			r.Pattern.Type, r.Pattern.DaysOfWeek, r.Pattern.Index = "relativeMonthly", days(), index
			if yearly {
				r.Pattern.Type = "relativeYearly"
			}
		} else {
			if values["BYSETPOS"] != "" {
				return nil, errUnsupported{"it picks occurrences within the month"}
			}
			r.Pattern.Type, r.Pattern.DayOfMonth = "absoluteMonthly", monthDay
			if yearly {
				r.Pattern.Type = "absoluteYearly"
			}
		}
	default:
		return nil, errUnsupported{"it repeats " + strings.ToLower(values["FREQ"])}
	}

	r.Range.StartDate = wall.Format("2006-01-02")
	r.Range.RecurrenceTimeZone = zone
	switch {
	case values["COUNT"] != "":
		n, err := strconv.Atoi(values["COUNT"])
		if err != nil || n < 1 {
			return nil, errUnsupported{"its count is unreadable"}
		}
		r.Range.Type, r.Range.NumberOfOccurrences = "numbered", n
	case values["UNTIL"] != "":
		end, err := untilDate(values["UNTIL"], start)
		if err != nil {
			return nil, errUnsupported{"its end is unreadable"}
		}
		r.Range.Type, r.Range.EndDate = "endDate", end
	default:
		r.Range.Type = "noEnd"
	}
	return r, nil
}

// untilDate is the date, in the series' zone, of an UNTIL.
func untilDate(value string, start ical.Time) (string, error) {
	t, err := ical.ParseTime(ical.Property{Name: "UNTIL", Value: value})
	if err != nil {
		return "", err
	}
	if t.Date || t.Floating || start.Zone == "" {
		return t.T.Format("2006-01-02"), nil
	}
	loc, err := time.LoadLocation(start.Zone)
	if err != nil {
		return t.T.UTC().Format("2006-01-02"), nil
	}
	return t.T.In(loc).Format("2006-01-02"), nil
}
