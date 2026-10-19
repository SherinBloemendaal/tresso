// Package caldavsync keeps the calendars of an IMAP account in sync over CalDAV: it finds the server, reports the
// calendars and their changed resources to the API, and writes the changes Tresso made back to the server. It runs
// next to the IMAP syncer of the account and fails on its own: a calendar problem never stops mail.
package caldavsync

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/url"
	"strings"
	"sync"
	"time"
	_ "time/tzdata" // calendar time zones are checked against the IANA database, also in minimal images

	"tresso/sync/internal/api"
	"tresso/sync/internal/caldav"
)

// API is the part of the API client the syncer needs.
type API interface {
	Credentials(ctx context.Context, accountID, workerID string) (api.Credentials, error)
	Calendars(ctx context.Context, accountID string, list api.CalendarList) ([]api.CalendarState, error)
	CalendarInventory(ctx context.Context, accountID, calendarID, workerID string, objects []api.RemoteObject) (api.InventoryResult, error)
	CalendarObjects(ctx context.Context, accountID, calendarID string, batch api.ObjectBatch) (api.ObjectBatchResult, error)
	ClaimCalendarOperations(ctx context.Context, accountID, workerID string) ([]api.CalendarOperation, error)
	ReportCalendarOperation(ctx context.Context, accountID, operationID string, result api.OperationResult) error
	CalendarStatus(ctx context.Context, accountID, workerID, status, detail string) error
}

type Options struct {
	// Interval between two syncs of all calendars.
	Interval time.Duration
	// NotFoundRetry is how long to wait before looking for CalDAV again on a server that has none.
	NotFoundRetry time.Duration
	MinBackoff    time.Duration
	MaxBackoff    time.Duration
	// AllowHTTP permits plain HTTP servers, for local tests only.
	AllowHTTP bool
	// Resolver looks up SRV and TXT records for discovery; nil skips them.
	Resolver *net.Resolver
}

func DefaultOptions() Options {
	return Options{Interval: 2 * time.Minute, NotFoundRetry: 24 * time.Hour, MinBackoff: 10 * time.Second, MaxBackoff: 30 * time.Minute, Resolver: net.DefaultResolver}
}

const (
	multigetBatch = 50
	objectBatch   = 100
)

type Syncer struct {
	api      API
	dialer   caldav.Dialer
	workerID string
	opts     Options
	logger   *slog.Logger

	mu   sync.Mutex
	wake map[string]chan struct{}
}

func New(client API, dialer caldav.Dialer, workerID string, opts Options, logger *slog.Logger) *Syncer {
	return &Syncer{api: client, dialer: dialer, workerID: workerID, opts: opts, logger: logger, wake: map[string]chan struct{}{}}
}

// Trigger makes the account's syncer write pending changes and sync now instead of at its next interval.
func (s *Syncer) Trigger(accountID string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if ch, ok := s.wake[accountID]; ok {
		select {
		case ch <- struct{}{}:
		default:
		}
	}
}

// Run syncs the account's calendars until ctx is cancelled. It only returns ctx's error: failures are retried with
// backoff, a server without CalDAV is asked again a day later.
func (s *Syncer) Run(ctx context.Context, account api.Account) error {
	wake := make(chan struct{}, 1)
	s.mu.Lock()
	s.wake[account.ID] = wake
	s.mu.Unlock()
	defer func() {
		s.mu.Lock()
		delete(s.wake, account.ID)
		s.mu.Unlock()
	}()

	logger := s.logger.With("account", account.ID, "part", "calendar")
	backoff := s.opts.MinBackoff
	failures := 0
	status := newStatusReporter(s, account.ID, logger)
	for {
		err := s.session(ctx, account, wake, logger, status)
		if ctx.Err() != nil {
			return ctx.Err()
		}
		wait := backoff
		var confirm *caldav.ConfirmError
		var untrusted *caldav.UntrustedError
		switch {
		case errors.As(err, &confirm):
			logger.Info("a calendar server needs the user's confirmation", "url", confirm.URL, "retryIn", s.opts.NotFoundRetry)
			status.report(ctx, api.CalendarUnconfirmed, "A calendar server was found at "+confirm.URL+". To sync your calendars there, confirm this address in the account settings.")
			wait = s.opts.NotFoundRetry
			failures = 0
		case errors.As(err, &untrusted):
			logger.Warn("a calendar server outside the account asked for the login", "url", untrusted.URL, "retryIn", s.opts.MaxBackoff)
			status.report(ctx, api.CalendarError, "The calendar server sent Tresso to "+untrusted.URL+", which does not get your password. Enter the right CalDAV address in the account settings.")
			wait = s.opts.MaxBackoff
			failures = 0
		case errors.Is(err, caldav.ErrNotCalDAV), errors.Is(err, errNoServer):
			logger.Info("no calendar server found for the account", "error", err, "retryIn", s.opts.NotFoundRetry)
			status.report(ctx, api.CalendarUnsupported, "No calendar server was found for this account. If it has one, enter its CalDAV address in the account settings.")
			wait = s.opts.NotFoundRetry
			failures = 0
		case errors.Is(err, caldav.ErrUnauthorized):
			logger.Warn("the calendar server refused the login", "retryIn", s.opts.MaxBackoff)
			status.report(ctx, api.CalendarAuthFailed, "The calendar server refused the login. Some providers need an app password for calendars.")
			wait = s.opts.MaxBackoff
			failures = 0
		default:
			failures++
			logger.Warn("calendar sync failed", "error", err, "retryIn", backoff)
			if failures >= maxQuietFailures {
				status.report(ctx, api.CalendarError, "Calendars could not be synced; trying again.")
			}
			backoff = min(backoff*2, s.opts.MaxBackoff)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(wait):
		case <-wake:
		}
	}
}

var errNoServer = errors.New("the account has no calendar server")

// maxQuietFailures is how many sessions in a row may fail for other reasons before the user is told.
const maxQuietFailures = 3

// statusReporter tells the API about a calendar problem once, not on every retry.
type statusReporter struct {
	syncer    *Syncer
	accountID string
	logger    *slog.Logger
	last      string
}

func newStatusReporter(s *Syncer, accountID string, logger *slog.Logger) *statusReporter {
	return &statusReporter{syncer: s, accountID: accountID, logger: logger}
}

func (r *statusReporter) report(ctx context.Context, status, detail string) {
	if r.last == status {
		return
	}
	if err := r.syncer.api.CalendarStatus(ctx, r.accountID, r.syncer.workerID, status, detail); err != nil {
		r.logger.Warn("reporting the calendar status failed", "error", err)
		return
	}
	r.last = status
}

func (r *statusReporter) reset() {
	r.last = ""
}

// session opens the server and syncs until something fails.
func (s *Syncer) session(ctx context.Context, account api.Account, wake <-chan struct{}, logger *slog.Logger, status *statusReporter) error {
	credentials, err := s.api.Credentials(ctx, account.ID, s.workerID)
	if err != nil {
		return err
	}
	password, err := credentials.Password()
	if err != nil {
		return fmt.Errorf("%w: %v", errNoServer, err)
	}
	client := caldav.New(s.dialer, credentials.Connection.Username, password, s.opts.AllowHTTP, caldav.NewTrust(credentials.Connection.IMAP.Host, credentials.Connection.CalDAV))

	var server caldav.Account
	if credentials.Connection.CalDAV != "" {
		server, err = client.Open(ctx, credentials.Connection.CalDAV)
	} else {
		server, err = client.Discover(ctx, credentials.EmailAddress, credentials.Connection.IMAP.Host, s.opts.Resolver)
	}
	if err != nil {
		return err
	}
	logger.Info("calendar server found", "url", server.ContextURL, "autoSchedule", server.AutoSchedule)

	run := &run{syncer: s, client: client, server: server, account: account, logger: logger}
	for {
		if err := run.writeAll(ctx); err != nil {
			return err
		}
		if err := run.syncAll(ctx); err != nil {
			return err
		}
		// A calendar list the API accepted makes the status active there.
		status.reset()
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(s.opts.Interval):
		case <-wake:
		}
	}
}

type run struct {
	syncer  *Syncer
	client  *caldav.Client
	server  caldav.Account
	account api.Account
	logger  *slog.Logger
	// The calendars as the API knows them, by remote id, from the last sync.
	states map[string]api.CalendarState
}

// cursor is what the API keeps per calendar for this syncer: the sync token, or the ctag for servers without one.
type cursor struct {
	SyncToken string `json:"syncToken,omitempty"`
	CTag      string `json:"ctag,omitempty"`
}

func (r *run) syncAll(ctx context.Context) error {
	calendars, err := r.client.Calendars(ctx, r.server)
	if err != nil {
		return err
	}
	list := api.CalendarList{WorkerID: r.syncer.workerID, Source: "caldav", ServerURL: &r.server.ContextURL, ProviderSchedules: r.server.AutoSchedule}
	for _, calendar := range calendars {
		remote := api.RemoteCalendar{RemoteID: calendar.URL, Name: calendar.Name, Writable: calendar.Writable, IsDefault: calendar.IsDefault}
		if calendar.Color != "" {
			remote.Color = &calendar.Color
		}
		if zone := ianaZone(calendar.TimeZone); zone != "" {
			remote.TimeZone = &zone
		}
		list.Calendars = append(list.Calendars, remote)
	}
	states, err := r.syncer.api.Calendars(ctx, r.account.ID, list)
	if err != nil {
		return err
	}
	r.states = make(map[string]api.CalendarState, len(states))
	for _, state := range states {
		r.states[state.RemoteID] = state
	}

	for _, calendar := range calendars {
		state, ok := r.states[calendar.URL]
		if !ok {
			continue
		}
		if err := r.syncCalendar(ctx, calendar, state); err != nil {
			if errors.Is(err, caldav.ErrUnauthorized) || ctx.Err() != nil {
				return err
			}
			// One broken calendar (a shared one that went away mid-sync) must not hold up the others.
			r.logger.Warn("calendar could not be synced", "calendar", calendar.URL, "error", err)
		}
	}
	return nil
}

func (r *run) syncCalendar(ctx context.Context, calendar caldav.Calendar, state api.CalendarState) error {
	var stored cursor
	if state.Cursor != nil {
		_ = json.Unmarshal([]byte(*state.Cursor), &stored)
	}

	if calendar.SyncToken != "" {
		if stored.SyncToken == calendar.SyncToken {
			return nil
		}
		if stored.SyncToken != "" {
			members, token, err := r.client.SyncCollection(ctx, calendar.URL, stored.SyncToken)
			if err == nil {
				return r.applyChanges(ctx, calendar, state, members, cursor{SyncToken: token})
			}
			if !errors.Is(err, caldav.ErrInvalidSyncToken) {
				return err
			}
		}
		members, token, err := r.client.SyncCollection(ctx, calendar.URL, "")
		if err != nil {
			return err
		}
		return r.applyListing(ctx, calendar, state, members, cursor{SyncToken: token})
	}

	if calendar.CTag != "" && stored.CTag == calendar.CTag {
		return nil
	}
	members, err := r.client.List(ctx, calendar.URL)
	if err != nil {
		return err
	}
	return r.applyListing(ctx, calendar, state, members, cursor{CTag: calendar.CTag})
}

// applyListing hands the complete listing to the API, which removes what is gone and names what to fetch.
func (r *run) applyListing(ctx context.Context, calendar caldav.Calendar, state api.CalendarState, members []caldav.Member, next cursor) error {
	objects := make([]api.RemoteObject, 0, len(members))
	for _, member := range members {
		if member.Removed {
			continue
		}
		etag := member.ETag
		objects = append(objects, api.RemoteObject{RemoteID: member.Href, ETag: &etag})
	}
	result, err := r.syncer.api.CalendarInventory(ctx, r.account.ID, state.ID, r.syncer.workerID, objects)
	if err != nil {
		return err
	}
	return r.upload(ctx, calendar, state, result.Fetch, nil, next)
}

// applyChanges uploads what sync-collection reported changed and removed.
func (r *run) applyChanges(ctx context.Context, calendar caldav.Calendar, state api.CalendarState, members []caldav.Member, next cursor) error {
	var changed, removed []string
	for _, member := range members {
		if member.Removed {
			removed = append(removed, member.Href)
		} else {
			changed = append(changed, member.Href)
		}
	}
	return r.upload(ctx, calendar, state, changed, removed, next)
}

// upload fetches the changed resources and reports them in batches; only the last batch carries the new cursor, so
// a sync that breaks off is repeated from the old one.
func (r *run) upload(ctx context.Context, calendar caldav.Calendar, state api.CalendarState, changed, removed []string, next cursor) error {
	encoded, err := json.Marshal(next)
	if err != nil {
		return err
	}
	cursorText := string(encoded)

	var pending []api.RemoteObject
	flush := func(last bool) error {
		batch := api.ObjectBatch{WorkerID: r.syncer.workerID, Changed: pending}
		if last {
			batch.Cursor = &cursorText
			batch.Removed = removed
		}
		if _, err := r.syncer.api.CalendarObjects(ctx, r.account.ID, state.ID, batch); err != nil {
			return err
		}
		pending = nil
		return nil
	}

	for start := 0; start < len(changed); start += multigetBatch {
		end := min(start+multigetBatch, len(changed))
		objects, err := r.client.MultiGet(ctx, calendar.URL, changed[start:end])
		if err != nil {
			return err
		}
		for _, object := range objects {
			if len(object.Data) > caldav.MaxObject {
				r.logger.Info("skipping a calendar resource that is too large", "href", object.Href, "bytes", len(object.Data))
				continue
			}
			etag, data := object.ETag, object.Data
			pending = append(pending, api.RemoteObject{RemoteID: object.Href, ETag: &etag, Ical: &data})
			if len(pending) == objectBatch {
				if err := flush(false); err != nil {
					return err
				}
			}
		}
	}
	return flush(true)
}

// writeAll makes the changes Tresso has for this account's calendars.
func (r *run) writeAll(ctx context.Context) error {
	for {
		operations, err := r.syncer.api.ClaimCalendarOperations(ctx, r.account.ID, r.syncer.workerID)
		if err != nil {
			return err
		}
		if len(operations) == 0 {
			return nil
		}
		for _, operation := range operations {
			result := r.write(ctx, operation)
			result.WorkerID = r.syncer.workerID
			if err := r.syncer.api.ReportCalendarOperation(ctx, r.account.ID, operation.ID, result); err != nil {
				return err
			}
		}
	}
}

func (r *run) write(ctx context.Context, operation api.CalendarOperation) api.OperationResult {
	ifMatch := ""
	if operation.IfMatch != nil {
		ifMatch = *operation.IfMatch
	}

	if operation.Kind == "delete" {
		if operation.RemoteID == nil {
			return api.OperationResult{Outcome: api.OperationDone}
		}
		err := r.client.Delete(ctx, *operation.RemoteID, ifMatch)
		if err == nil {
			return api.OperationResult{Outcome: api.OperationDone}
		}
		return r.failure(ctx, *operation.RemoteID, err)
	}

	if operation.Ical == nil {
		return api.OperationResult{Outcome: api.OperationFailed, Error: text("The event was removed before it could be saved.")}
	}
	href := ""
	create := operation.RemoteID == nil
	if create {
		href = resourceURL(operation.CalendarRemoteID, operation.UID)
		ifMatch = ""
	} else {
		href = *operation.RemoteID
	}
	if !create && ifMatch == "" {
		// The last write got no ETag back; the server's current one still guards against overwriting a change made
		// elsewhere since.
		ifMatch, _ = r.client.ETag(ctx, href)
	}
	etag, err := r.client.Put(ctx, href, *operation.Ical, ifMatch, create)
	if err != nil {
		return r.failure(ctx, href, err)
	}
	if etag == "" {
		// Some servers change the object on PUT and leave the ETag out; the next sync fetches their version.
		etag, _ = r.client.ETag(ctx, href)
	}
	result := api.OperationResult{Outcome: api.OperationDone, RemoteID: &href}
	if etag != "" {
		result.ETag = &etag
	}
	return result
}

// failure turns a failed write into the outcome the API needs.
func (r *run) failure(ctx context.Context, href string, err error) api.OperationResult {
	var status *caldav.StatusError
	if !errors.As(err, &status) {
		if errors.Is(err, caldav.ErrUnauthorized) {
			return api.OperationResult{Outcome: api.OperationRetry, Error: text("The calendar server refused the login.")}
		}
		return api.OperationResult{Outcome: api.OperationRetry, Error: text("The calendar server could not be reached.")}
	}
	switch {
	case status.Status == 412 || (status.Status == 404 && status.Method != "PUT"):
		current, getErr := r.client.Get(ctx, href)
		if getErr != nil {
			var missing *caldav.StatusError
			if errors.As(getErr, &missing) && missing.Status == 404 {
				return api.OperationResult{Outcome: api.OperationConflict}
			}
			return api.OperationResult{Outcome: api.OperationRetry, Error: text("The calendar server could not be reached.")}
		}
		etag, data := current.ETag, current.Data
		return api.OperationResult{Outcome: api.OperationConflict, Current: &api.RemoteObject{RemoteID: href, ETag: &etag, Ical: &data}}
	case status.Status == 404:
		// An update of a resource that is gone.
		return api.OperationResult{Outcome: api.OperationConflict}
	case status.Status == 403:
		return api.OperationResult{Outcome: api.OperationFailed, Error: text("The calendar is read-only at the provider.")}
	case status.Status == 413 || status.Status == 507:
		return api.OperationResult{Outcome: api.OperationFailed, Error: text("The calendar server has no room for this event.")}
	case status.Status >= 400 && status.Status < 500:
		return api.OperationResult{Outcome: api.OperationFailed, Error: text(fmt.Sprintf("The calendar server refused the event (HTTP %d).", status.Status))}
	default:
		return api.OperationResult{Outcome: api.OperationRetry, Error: text(fmt.Sprintf("The calendar server had a problem (HTTP %d).", status.Status))}
	}
}

// resourceURL is where a new event goes: the calendar collection plus a file name from the UID.
func resourceURL(calendarURL, uid string) string {
	name := strings.Map(func(r rune) rune {
		if r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r >= '0' && r <= '9' || r == '-' || r == '_' || r == '.' {
			return r
		}
		return '-'
	}, uid)
	if !strings.HasSuffix(calendarURL, "/") {
		calendarURL += "/"
	}
	return calendarURL + url.PathEscape(name) + ".ics"
}

func ianaZone(name string) string {
	if name == "" {
		return ""
	}
	if _, err := time.LoadLocation(name); err != nil || name == "Local" {
		return ""
	}
	return name
}

func text(s string) *string {
	return &s
}
