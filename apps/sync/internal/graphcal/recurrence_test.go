package graphcal

import (
	"strings"
	"testing"
	"time"

	"tresso/sync/internal/ical"
)

func TestRulesGoBothWaysBetweenRRuleAndGraphPatterns(t *testing.T) {
	start := ical.Time{T: time.Date(2026, 10, 19, 7, 0, 0, 0, time.UTC), Zone: "Europe/Amsterdam"} // a Monday, 09:00
	for _, rule := range []string{
		"FREQ=DAILY;INTERVAL=2",
		"FREQ=WEEKLY;BYDAY=MO,WE,FR;WKST=SU;COUNT=12",
		"FREQ=MONTHLY;BYMONTHDAY=19;UNTIL=20271019T070000Z",
		"FREQ=MONTHLY;BYDAY=MO;BYSETPOS=-1",
		"FREQ=YEARLY;BYMONTH=10;BYMONTHDAY=19",
		"FREQ=YEARLY;BYMONTH=10;BYDAY=MO;BYSETPOS=3",
	} {
		pattern, err := fromRRule(rule, start, "Europe/Amsterdam")
		if err != nil {
			t.Fatalf("%s: %v", rule, err)
		}
		back, err := toRRule(*pattern, start)
		if err != nil {
			t.Fatalf("%s: %v", rule, err)
		}
		if !sameRule(back, rule) {
			t.Errorf("%s came back as %s", rule, back)
		}
	}
	// Equivalent spellings of the same rule.
	for rule, want := range map[string]string{
		"FREQ=MONTHLY;BYDAY=2TU": "FREQ=MONTHLY;BYDAY=TU;BYSETPOS=2",
		"FREQ=WEEKLY":            "FREQ=WEEKLY;BYDAY=MO;WKST=MO",
		"FREQ=DAILY;BYDAY=MO,TU": "FREQ=WEEKLY;BYDAY=MO,TU;WKST=MO",
		"FREQ=MONTHLY":           "FREQ=MONTHLY;BYMONTHDAY=19",
		"FREQ=YEARLY;INTERVAL=2": "FREQ=YEARLY;BYMONTH=10;BYMONTHDAY=19;INTERVAL=2",
	} {
		pattern, err := fromRRule(rule, start, "Europe/Amsterdam")
		if err != nil {
			t.Fatalf("%s: %v", rule, err)
		}
		if back, _ := toRRule(*pattern, start); !sameRule(back, want) {
			t.Errorf("%s came back as %s, want %s", rule, back, want)
		}
	}
}

func sameRule(a, b string) bool {
	parts := func(s string) map[string]string {
		out := map[string]string{}
		for _, part := range strings.Split(s, ";") {
			key, value, _ := strings.Cut(part, "=")
			out[key] = value
		}
		return out
	}
	pa, pb := parts(a), parts(b)
	if len(pa) != len(pb) {
		return false
	}
	for key, value := range pa {
		if pb[key] != value {
			return false
		}
	}
	return true
}

func TestRulesOutlookCannotRepeatAreRefused(t *testing.T) {
	start := ical.Time{T: time.Date(2026, 10, 19, 7, 0, 0, 0, time.UTC), Zone: "Europe/Amsterdam"}
	for _, rule := range []string{
		"FREQ=HOURLY",
		"FREQ=MONTHLY;BYMONTHDAY=-1",
		"FREQ=MONTHLY;BYMONTHDAY=1,15",
		"FREQ=MONTHLY;BYDAY=1MO,3WE",
		"FREQ=WEEKLY;BYDAY=MO;BYHOUR=9,17",
		"FREQ=MONTHLY;BYMONTH=1,7",
	} {
		if _, err := fromRRule(rule, start, "Europe/Amsterdam"); err == nil {
			t.Errorf("%s was taken", rule)
		} else if !strings.HasPrefix(err.Error(), "Outlook cannot repeat an event this way") {
			t.Errorf("%s: %v", rule, err)
		}
	}
}

func TestTheEndOfASeriesIsItsLastDayInItsZone(t *testing.T) {
	start := ical.Time{T: time.Date(2026, 10, 19, 22, 30, 0, 0, time.UTC), Zone: "Europe/Amsterdam"} // 00:30 on the 20th there
	pattern, err := fromRRule("FREQ=DAILY;UNTIL=20261024T223000Z", start, "Europe/Amsterdam")
	if err != nil {
		t.Fatal(err)
	}
	if pattern.Range.StartDate != "2026-10-20" || pattern.Range.EndDate != "2026-10-25" {
		t.Fatalf("range = %+v", pattern.Range)
	}
	if back, _ := toRRule(*pattern, start); back != "FREQ=DAILY;UNTIL=20261024T223000Z" {
		t.Errorf("rule = %s", back)
	}
	// A series at 09:00 that ends on the day winter time starts ends at 09:00 winter time, an hour later in UTC.
	morning := ical.Time{T: time.Date(2026, 10, 19, 7, 0, 0, 0, time.UTC), Zone: "Europe/Amsterdam"}
	pattern.Range.EndDate = "2026-10-25"
	if back, _ := toRRule(*pattern, morning); back != "FREQ=DAILY;UNTIL=20261025T080000Z" {
		t.Errorf("rule = %s", back)
	}
}
