// Package googlecal syncs the calendars of a Gmail account through the Google Calendar API v3: calendarList for the
// calendars, events.list with syncToken for changes, and events.insert, patch and delete for writes. Google sends
// invitations, updates and replies itself (sendUpdates), and creates Google Meet meetings (conferenceData).
//
// One resource is a single event, or a recurring event with its exceptions: Google keeps exceptions as events of
// their own (recurringEventId), Tresso keeps them in one iCalendar object as RFC 5545 does. Its remote id is the
// master's event id and its ETag combines the ETags of the master and every exception.
package googlecal

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"net/url"
	"strconv"
	"strings"

	"tresso/sync/internal/calsync"
	"tresso/sync/internal/providerhttp"
)

type ClientOptions struct {
	BaseURL string // https://www.googleapis.com/calendar/v3; tests point it at a fake
	HTTP    providerhttp.Options
}

func DefaultClientOptions() ClientOptions {
	httpOpts := providerhttp.DefaultOptions()
	httpOpts.Throttled = func(e *providerhttp.StatusError) bool {
		return e.StatusCode == http.StatusForbidden && (e.HasReason("rateLimitExceeded") || e.HasReason("userRateLimitExceeded") || e.HasReason("quotaExceeded"))
	}
	return ClientOptions{BaseURL: "https://www.googleapis.com/calendar/v3", HTTP: httpOpts}
}

// Provider is the Google side of calsync.
type Provider struct {
	opts ClientOptions
}

func NewProvider(opts ClientOptions) *Provider {
	return &Provider{opts: opts}
}

func (p *Provider) Name() string   { return "Google" }
func (p *Provider) Source() string { return "google" }

func (p *Provider) Open(tokens providerhttp.Tokens, httpClient *http.Client, self string, logger *slog.Logger) calsync.Session {
	return &session{http: providerhttp.New(httpClient, tokens, p.opts.HTTP), base: strings.TrimRight(p.opts.BaseURL, "/"), self: self, logger: logger}
}

// NotGranted: the token lacks the calendar scopes (the user unticked them, or withdrew them at Google). Other 403s
// are about one calendar (taken back, read-only) and say nothing about the account.
func (p *Provider) NotGranted(err error) bool {
	var statusErr *providerhttp.StatusError
	return errors.As(err, &statusErr) && statusErr.StatusCode == http.StatusForbidden &&
		(statusErr.HasReason("insufficientPermissions") || statusErr.HasReason("ACCESS_TOKEN_SCOPE_INSUFFICIENT"))
}

type session struct {
	http   *providerhttp.Client
	base   string
	self   string // the account's address
	logger *slog.Logger
}

func (s *session) calendarURL(calendarID string, parts ...string) string {
	u := s.base + "/calendars/" + url.PathEscape(calendarID) + "/events"
	for _, part := range parts {
		u += "/" + url.PathEscape(part)
	}
	return u
}

func (s *session) get(ctx context.Context, rawURL string, query url.Values, out any) error {
	if len(query) > 0 {
		rawURL += "?" + query.Encode()
	}
	return s.http.JSON(ctx, providerhttp.Request{Method: http.MethodGet, URL: rawURL}, out)
}

func (s *session) send(ctx context.Context, method, rawURL string, query url.Values, ifMatch string, body any, out any) error {
	if len(query) > 0 {
		rawURL += "?" + query.Encode()
	}
	req := providerhttp.Request{Method: method, URL: rawURL, Header: http.Header{}}
	if body != nil {
		data, err := json.Marshal(body)
		if err != nil {
			return err
		}
		req.Body, req.ContentType = data, "application/json"
	}
	if ifMatch != "" {
		req.Header.Set("If-Match", ifMatch)
	}
	return s.http.JSON(ctx, req, out)
}

type calendarListEntry struct {
	ID                   string     `json:"id"`
	Summary              string     `json:"summary"`
	SummaryOverride      string     `json:"summaryOverride"`
	BackgroundColor      string     `json:"backgroundColor"`
	TimeZone             string     `json:"timeZone"`
	AccessRole           string     `json:"accessRole"`
	Primary              bool       `json:"primary"`
	Deleted              bool       `json:"deleted"`
	DefaultReminders     []reminder `json:"defaultReminders"`
	ConferenceProperties *struct {
		AllowedConferenceSolutionTypes []string `json:"allowedConferenceSolutionTypes"`
	} `json:"conferenceProperties"`
}

type eventsPage struct {
	Items         []event `json:"items"`
	NextPageToken string  `json:"nextPageToken"`
	NextSyncToken string  `json:"nextSyncToken"`
	TimeZone      string  `json:"timeZone"`
}

// maxPages bounds one listing (250 events a page), so a provider that keeps paging cannot hold the sync forever.
const maxPages = 2000

var errTooManyPages = errors.New("google: the listing kept paging")

// listEvents follows the pages of an events.list call.
func (s *session) listEvents(ctx context.Context, calendarID string, query url.Values) ([]event, string, error) {
	query.Set("maxResults", "250")
	query.Set("singleEvents", "false")
	query.Set("showDeleted", "true")
	var items []event
	for range maxPages {
		var page eventsPage
		if err := s.get(ctx, s.calendarURL(calendarID), query, &page); err != nil {
			return nil, "", err
		}
		items = append(items, page.Items...)
		if page.NextPageToken == "" {
			return items, page.NextSyncToken, nil
		}
		query.Set("pageToken", page.NextPageToken)
	}
	return nil, "", errTooManyPages
}

func itoa(n int) string {
	return strconv.Itoa(n)
}
