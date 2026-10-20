// Package graphcal syncs the calendars of a Microsoft 365 or Outlook.com account through Microsoft Graph v1.0.
//
// Graph has no change feed of series: calendarView/delta reports changed occurrences within a time window. It is
// used as the signal that something changed (Graph reports changes outside the window too); the calendar is then
// listed with each event's changeKey, and the API names the events to fetch. A resource is a single event or a
// series master fetched with its exceptions (exceptionOccurrences) and cancelled occurrences; its ETag is the
// master's changeKey, which Exchange changes with any change to the series.
//
// Graph sends invitations, updates and cancellations itself, answers invitations through accept, tentativelyAccept
// and decline, and creates Teams meetings for events marked isOnlineMeeting.
package graphcal

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
	"strings"
	"sync"

	"tresso/sync/internal/api"
	"tresso/sync/internal/calsync"
	"tresso/sync/internal/providerhttp"
)

type ClientOptions struct {
	BaseURL string // https://graph.microsoft.com/v1.0; tests point it at a fake
	HTTP    providerhttp.Options
}

func DefaultClientOptions() ClientOptions {
	httpOpts := providerhttp.DefaultOptions()
	// Graph allows 10,000 requests per 10 minutes per mailbox; mail shares that budget.
	httpOpts.UnitsPerSecond = 4
	httpOpts.Burst = 20
	return ClientOptions{BaseURL: "https://graph.microsoft.com/v1.0", HTTP: httpOpts}
}

type Provider struct {
	opts ClientOptions
}

func NewProvider(opts ClientOptions) *Provider {
	return &Provider{opts: opts}
}

func (p *Provider) Name() string   { return "Microsoft" }
func (p *Provider) Source() string { return "microsoft" }

func (p *Provider) Open(tokens providerhttp.Tokens, httpClient *http.Client, self string) calsync.Session {
	return &session{http: providerhttp.New(httpClient, tokens, p.opts.HTTP), base: strings.TrimRight(p.opts.BaseURL, "/"), owner: self, zones: map[string]string{}}
}

// NotGranted: Graph refuses calendar access (403 ErrorAccessDenied) when the token lacks Calendars.ReadWrite.
func (p *Provider) NotGranted(err error) bool {
	var statusErr *providerhttp.StatusError
	return errors.As(err, &statusErr) && statusErr.StatusCode == http.StatusForbidden &&
		(statusErr.HasReason("ErrorAccessDenied") || statusErr.HasReason("AccessDenied") || statusErr.HasReason("Authorization_RequestDenied"))
}

type session struct {
	http  *providerhttp.Client
	base  string
	owner string // the account's address

	mu sync.Mutex
	// zones holds the zone each event was made in, from the last listing: it is read in that zone.
	zones map[string]string
}

func (s *session) zoneHint(id string) (string, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	zone, ok := s.zones[id]
	return zone, ok
}

func (s *session) remember(id, zone string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.zones[id] = zone
}

// request sends one Graph call; prefer holds Prefer header values.
func (s *session) request(ctx context.Context, method, rawURL string, body any, out any, prefer ...string) error {
	if !strings.HasPrefix(rawURL, "https://") && !strings.HasPrefix(rawURL, "http://") {
		rawURL = s.base + rawURL
	}
	req := providerhttp.Request{Method: method, URL: rawURL, Header: http.Header{}}
	for _, value := range prefer {
		req.Header.Add("Prefer", value)
	}
	if body != nil {
		data, err := json.Marshal(body)
		if err != nil {
			return err
		}
		req.Body, req.ContentType = data, "application/json"
	}
	return s.http.JSON(ctx, req, out)
}

// sameHost keeps followed nextLink and deltaLink URLs on Graph, so a page cannot send the token elsewhere.
func (s *session) sameHost(link string) bool {
	u, err := url.Parse(link)
	base, baseErr := url.Parse(s.base)
	return err == nil && baseErr == nil && u.Scheme == base.Scheme && u.Host == base.Host
}

var (
	errForeignLink = errors.New("graph: a page link points away from Graph")
	errNoDeltaLink = errors.New("graph: a delta round ended without a delta link")
)

func (s *session) self() string {
	return s.owner
}

func errorsAs(err error, target **providerhttp.StatusError) bool {
	return errors.As(err, target)
}

type calendarEntry struct {
	ID                            string   `json:"id"`
	Name                          string   `json:"name"`
	HexColor                      string   `json:"hexColor"`
	CanEdit                       bool     `json:"canEdit"`
	IsDefaultCalendar             bool     `json:"isDefaultCalendar"`
	AllowedOnlineMeetingProviders []string `json:"allowedOnlineMeetingProviders"`
	DefaultOnlineMeetingProvider  string   `json:"defaultOnlineMeetingProvider"`
}

// calendarInfo is what writes need to know about the calendar.
type calendarInfo struct {
	TeamsProvider string // the onlineMeetingProvider for Teams meetings, empty when the calendar has none
}

const maxPages = 2000

var errTooManyPages = errors.New("graph: the listing kept paging")

func (s *session) Calendars(ctx context.Context) ([]calsync.Calendar, error) {
	next := "/me/calendars?$top=100&$select=id,name,hexColor,canEdit,isDefaultCalendar,allowedOnlineMeetingProviders,defaultOnlineMeetingProvider"
	var out []calsync.Calendar
	for range maxPages {
		var page struct {
			Value    []calendarEntry `json:"value"`
			NextLink string          `json:"@odata.nextLink"`
		}
		if err := s.request(ctx, http.MethodGet, next, nil, &page); err != nil {
			return nil, err
		}
		for _, entry := range page.Value {
			remote := api.RemoteCalendar{RemoteID: entry.ID, Name: entry.Name, Writable: entry.CanEdit, IsDefault: entry.IsDefaultCalendar}
			if len(entry.HexColor) == 7 && strings.HasPrefix(entry.HexColor, "#") {
				color := strings.ToLower(entry.HexColor)
				remote.Color = &color
			}
			calInfo := calendarInfo{}
			for _, provider := range entry.AllowedOnlineMeetingProviders {
				if provider == "teamsForBusiness" && entry.CanEdit {
					calInfo.TeamsProvider = provider
					remote.ConferenceProviders = []string{"teams"}
				}
			}
			out = append(out, calsync.Calendar{RemoteCalendar: remote, Data: calInfo})
		}
		if page.NextLink == "" {
			return out, nil
		}
		if !s.sameHost(page.NextLink) {
			return nil, errForeignLink
		}
		next = page.NextLink
	}
	return nil, errTooManyPages
}

func info(calendar calsync.Calendar) calendarInfo {
	if data, ok := calendar.Data.(calendarInfo); ok {
		return data
	}
	return calendarInfo{}
}

func eventsPath(calendarID string) string {
	return "/me/calendars/" + url.PathEscape(calendarID) + "/events"
}

func eventPath(id string, action ...string) string {
	path := "/me/events/" + url.PathEscape(id)
	for _, part := range action {
		path += "/" + part
	}
	return path
}
