package calsync

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"sync"
	"testing"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/calsync/calsynctest"
	"tresso/sync/internal/netguard"
	"tresso/sync/internal/providerhttp"
)

// fakeProvider is a provider whose session answers what the test sets.
type fakeProvider struct {
	mu        sync.Mutex
	calendars func() ([]Calendar, error)
	changes   func(calendar Calendar) (Changes, error)
	write     func(operation api.CalendarOperation) (api.OperationResult, error)
	listedAt  []time.Time
	sessions  int
}

func (p *fakeProvider) Name() string   { return "Fake" }
func (p *fakeProvider) Source() string { return "google" }
func (p *fakeProvider) NotGranted(err error) bool {
	return errors.Is(err, errScope)
}

var errScope = errors.New("the token lacks the calendar scopes")

func (p *fakeProvider) Open(providerhttp.Tokens, *http.Client, string, *slog.Logger) Session {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.sessions++
	return fakeSession{p}
}

type fakeSession struct{ p *fakeProvider }

func (s fakeSession) Calendars(context.Context) ([]Calendar, error) {
	s.p.mu.Lock()
	s.p.listedAt = append(s.p.listedAt, time.Now())
	list := s.p.calendars
	s.p.mu.Unlock()
	return list()
}

func (s fakeSession) Changes(_ context.Context, calendar Calendar, _ string) (Changes, error) {
	if s.p.changes == nil {
		return Changes{Unchanged: true}, nil
	}
	return s.p.changes(calendar)
}

func (s fakeSession) Fetch(context.Context, Calendar, []string) ([]Object, error) {
	return nil, nil
}

func (s fakeSession) Write(_ context.Context, _ Calendar, operation api.CalendarOperation) (api.OperationResult, error) {
	return s.p.write(operation)
}

func (p *fakeProvider) listings() []time.Time {
	p.mu.Lock()
	defer p.mu.Unlock()
	return append([]time.Time(nil), p.listedAt...)
}

func calendarsOf(ids ...string) func() ([]Calendar, error) {
	return func() ([]Calendar, error) {
		var out []Calendar
		for _, id := range ids {
			out = append(out, Calendar{RemoteCalendar: api.RemoteCalendar{RemoteID: id, Name: id, Writable: true}})
		}
		return out, nil
	}
}

func start(t *testing.T, provider *fakeProvider, fake *calsynctest.API, opts Options) *Syncer {
	t.Helper()
	syncer := New(fake, provider, netguard.New(true), "w1", opts, slog.New(slog.NewTextHandler(io.Discard, nil)))
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		_ = syncer.Run(ctx, api.Account{ID: "a1", EmailAddress: "kim@example.com"})
	}()
	t.Cleanup(func() {
		cancel()
		<-done
	})
	return syncer
}

var quick = Options{Interval: 10 * time.Millisecond, NotGrantedRetry: time.Hour, MinBackoff: 10 * time.Millisecond, MaxBackoff: 40 * time.Millisecond}

func TestFailuresBackOffAndReachTheUserOnlyAfterSeveral(t *testing.T) {
	provider := &fakeProvider{calendars: func() ([]Calendar, error) { return nil, errors.New("connection reset") }}
	fake := calsynctest.NewAPI("kim@example.com")
	start(t, provider, fake, quick)

	calsynctest.Eventually(t, "six attempts", func() bool { return len(provider.listings()) >= 6 })
	listed := provider.listings()
	first, later := listed[1].Sub(listed[0]), listed[5].Sub(listed[4])
	if first >= 30*time.Millisecond || later < 35*time.Millisecond {
		t.Errorf("waits double up to the maximum: first %v, later %v", first, later)
	}
	if statuses := fake.Statuses(); len(statuses) != 1 || statuses[0] != api.CalendarError {
		t.Errorf("one error status, after the quiet failures: %v", statuses)
	}
}

func TestARefusedCalendarListAsksTheUserToAllowCalendars(t *testing.T) {
	refused := &providerhttp.StatusError{Method: "GET", URL: "/calendars", StatusCode: http.StatusForbidden, Reasons: []string{"ErrorAccessDenied"}}
	provider := &fakeProvider{calendars: func() ([]Calendar, error) { return nil, fmt.Errorf("list: %w", refused) }}
	fake := calsynctest.NewAPI("kim@example.com")
	syncer := start(t, provider, fake, quick)

	calsynctest.Eventually(t, "the status", func() bool { return len(fake.Statuses()) == 1 })
	syncer.Trigger("a1")
	calsynctest.Eventually(t, "the retry the trigger starts", func() bool { return len(provider.listings()) >= 2 })
	if statuses := fake.Statuses(); len(statuses) != 1 || statuses[0] != api.CalendarNotGranted {
		t.Fatalf("statuses = %v", statuses)
	}
}

func TestOneForbiddenCalendarIsSkippedAndTheOthersSync(t *testing.T) {
	forbidden := &providerhttp.StatusError{Method: "GET", URL: "/events", StatusCode: http.StatusForbidden, Reasons: []string{"ErrorAccessDenied"}}
	provider := &fakeProvider{
		calendars: calendarsOf("shared", "own"),
		changes: func(calendar Calendar) (Changes, error) {
			if calendar.RemoteID == "shared" {
				return Changes{}, forbidden
			}
			return Changes{Full: true, Objects: []Object{{RemoteID: "lunch", ETag: "1", Ical: "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"}}, Cursor: "c1"}, nil
		},
	}
	fake := calsynctest.NewAPI("kim@example.com")
	start(t, provider, fake, quick)

	calsynctest.Eventually(t, "the other calendar", func() bool { _, ok := fake.Objects("own")["lunch"]; return ok })
	calsynctest.Eventually(t, "more rounds in the same session", func() bool { return len(provider.listings()) >= 3 })
	if provider.sessions != 1 || len(fake.Statuses()) != 0 {
		t.Fatalf("one calendar is no account problem: sessions %d, statuses %v", provider.sessions, fake.Statuses())
	}
}

func TestAScopeTheTokenLacksStopsTheSession(t *testing.T) {
	provider := &fakeProvider{
		calendars: calendarsOf("own"),
		changes:   func(Calendar) (Changes, error) { return Changes{}, errScope },
	}
	fake := calsynctest.NewAPI("kim@example.com")
	start(t, provider, fake, quick)

	calsynctest.Eventually(t, "the status", func() bool { return len(fake.Statuses()) == 1 })
	if statuses := fake.Statuses(); statuses[0] != api.CalendarNotGranted {
		t.Fatalf("statuses = %v", statuses)
	}
}

func TestAWriteThatLostTheAccountsAccessIsReportedAndEndsTheSession(t *testing.T) {
	provider := &fakeProvider{
		calendars: calendarsOf("own"),
		write: func(api.CalendarOperation) (api.OperationResult, error) {
			message := "refused"
			return api.OperationResult{Outcome: api.OperationRetry, Error: &message}, fmt.Errorf("write: %w", providerhttp.ErrUnauthorized)
		},
	}
	fake := calsynctest.NewAPI("kim@example.com")
	text := "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"
	fake.Queue(api.CalendarOperation{ID: "op1", Kind: "put", CalendarRemoteID: "own", UID: "lunch", Ical: &text})
	start(t, provider, fake, quick)

	calsynctest.Eventually(t, "the report and the status", func() bool { _, ok := fake.Result("op1"); return ok && len(fake.Statuses()) == 1 })
	if result, _ := fake.Result("op1"); result.Outcome != api.OperationRetry {
		t.Errorf("result = %+v", result)
	}
	if statuses := fake.Statuses(); statuses[0] != api.CalendarAuthFailed {
		t.Errorf("statuses = %v", statuses)
	}
}
