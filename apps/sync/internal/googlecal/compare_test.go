package googlecal

import "testing"

func TestRecurrencesCompareByMeaning(t *testing.T) {
	same := [][2][]string{
		// Parts of a rule in another order.
		{{"RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=5"}, {"RRULE:COUNT=5;BYDAY=MO;FREQ=WEEKLY"}},
		// One EXDATE with two values, or two EXDATEs; a zoned value or the same instant in UTC.
		{{"RRULE:FREQ=DAILY", "EXDATE;TZID=Europe/Amsterdam:20261026T090000,20261027T090000"}, {"EXDATE:20261027T080000Z", "RRULE:FREQ=DAILY", "EXDATE;TZID=Europe/Amsterdam:20261026T090000"}},
		{{}, {}},
	}
	for _, pair := range same {
		if !equal(canonicalRecurrence(pair[0]), canonicalRecurrence(pair[1])) {
			t.Errorf("%v and %v are the same recurrence", pair[0], pair[1])
		}
	}
	different := [][2][]string{
		{{"RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=5"}, {"RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=6"}},
		{{"RRULE:FREQ=DAILY", "EXDATE:20261026T080000Z"}, {"RRULE:FREQ=DAILY"}},
		{{"RRULE:FREQ=DAILY", "EXDATE:20261026T080000Z"}, {"RRULE:FREQ=DAILY", "RDATE:20261026T080000Z"}},
	}
	for _, pair := range different {
		if equal(canonicalRecurrence(pair[0]), canonicalRecurrence(pair[1])) {
			t.Errorf("%v and %v differ", pair[0], pair[1])
		}
	}
}

func TestSameComparesTheRecurrenceByMeaningAndTheRestExactly(t *testing.T) {
	a := fields{Summary: "Standup", Recurrence: []string{"RRULE:FREQ=WEEKLY;BYDAY=MO", "EXDATE;TZID=Europe/Amsterdam:20261026T090000"}}
	b := fields{Summary: "Standup", Recurrence: []string{"EXDATE:20261026T080000Z", "RRULE:BYDAY=MO;FREQ=WEEKLY"}}
	if !same(a, b) {
		t.Error("the same series, spelled differently, is unchanged")
	}
	b.Summary = "Daily standup"
	if same(a, b) {
		t.Error("a changed summary is a change")
	}
	if a.Recurrence[0] != "RRULE:FREQ=WEEKLY;BYDAY=MO" {
		t.Error("comparing does not change what is sent")
	}
}
