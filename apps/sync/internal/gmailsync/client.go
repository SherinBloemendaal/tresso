package gmailsync

import (
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"tresso/sync/internal/providerhttp"
	"tresso/sync/internal/providersync"
)

// Gmail quota costs per call, in units of the per-user limit (250 units per second).
const (
	costProfile = 1
	costLabels  = 1
	costList    = 5
	costGet     = 5
	costHistory = 2
	costSend    = 100
)

type ClientOptions struct {
	BaseURL string // https://gmail.googleapis.com; tests point it at a fake
	HTTP    providerhttp.Options
	// HTTPClient carries the requests; nil uses a default client.
	HTTPClient *http.Client
}

func DefaultClientOptions() ClientOptions {
	httpOpts := providerhttp.DefaultOptions()
	// Below Gmail's 250 units per user per second, so other clients of the same mailbox keep some room.
	httpOpts.UnitsPerSecond = 200
	httpOpts.Burst = 200
	httpOpts.Throttled = func(e *providerhttp.StatusError) bool {
		return e.StatusCode == http.StatusForbidden && (e.HasReason("rateLimitExceeded") || e.HasReason("userRateLimitExceeded"))
	}
	return ClientOptions{BaseURL: "https://gmail.googleapis.com", HTTP: httpOpts}
}

// Client calls the Gmail API v1 for one account.
type Client struct {
	http *providerhttp.Client
	root string // the API host, for upload URLs
	base string // the account's resources
}

func NewClient(tokens providerhttp.Tokens, opts ClientOptions) *Client {
	root := strings.TrimRight(opts.BaseURL, "/")
	return &Client{http: providerhttp.New(opts.HTTPClient, tokens, opts.HTTP), root: root, base: root + "/gmail/v1/users/me"}
}

type label struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Type string `json:"type"` // "system" or "user"
}

type message struct {
	ID           string   `json:"id"`
	LabelIDs     []string `json:"labelIds"`
	InternalDate string   `json:"internalDate"` // milliseconds since the epoch
	Raw          string   `json:"raw"`          // base64url, only with format=raw
}

func (m message) receivedAt() time.Time {
	ms, err := strconv.ParseInt(m.InternalDate, 10, 64)
	if err != nil || ms <= 0 {
		return time.Time{}
	}
	return time.UnixMilli(ms).UTC()
}

type historyMessage struct {
	ID       string   `json:"id"`
	LabelIDs []string `json:"labelIds"`
}

type labelChange struct {
	Message  historyMessage `json:"message"`
	LabelIDs []string       `json:"labelIds"`
}

type historyRecord struct {
	Messages      []historyMessage `json:"messages"`
	MessagesAdded []struct {
		Message historyMessage `json:"message"`
	} `json:"messagesAdded"`
	MessagesDeleted []struct {
		Message historyMessage `json:"message"`
	} `json:"messagesDeleted"`
	LabelsAdded   []labelChange `json:"labelsAdded"`
	LabelsRemoved []labelChange `json:"labelsRemoved"`
}

type historyPage struct {
	History       []historyRecord `json:"history"`
	NextPageToken string          `json:"nextPageToken"`
	HistoryID     string          `json:"historyId"`
}

func (c *Client) profile(ctx context.Context) (historyID string, err error) {
	var out struct {
		HistoryID string `json:"historyId"`
	}
	if err := c.get(ctx, "/profile", nil, costProfile, &out); err != nil {
		return "", err
	}
	if out.HistoryID == "" {
		return "", errors.New("gmail profile has no historyId")
	}
	return out.HistoryID, nil
}

func (c *Client) labels(ctx context.Context) ([]label, error) {
	var out struct {
		Labels []label `json:"labels"`
	}
	err := c.get(ctx, "/labels", nil, costLabels, &out)
	return out.Labels, err
}

type listQuery struct {
	labelID          string // empty for every message
	includeSpamTrash bool
	pageToken        string
	pageSize         int
}

// notChats leaves Hangouts and Chat messages out of listings: they are not mail, and foldersOf puts them in no folder.
// Every listing uses it, so the full resync and the backfill see the same messages as history does.
const notChats = "-in:chats"

// listMessages returns one page of message ids, newest first.
func (c *Client) listMessages(ctx context.Context, q listQuery) (ids []string, next string, err error) {
	query := url.Values{"maxResults": {strconv.Itoa(q.pageSize)}, "q": {notChats}}
	if q.labelID != "" {
		query.Set("labelIds", q.labelID)
	}
	if q.includeSpamTrash {
		query.Set("includeSpamTrash", "true")
	}
	if q.pageToken != "" {
		query.Set("pageToken", q.pageToken)
	}
	var out struct {
		Messages []struct {
			ID string `json:"id"`
		} `json:"messages"`
		NextPageToken string `json:"nextPageToken"`
	}
	if err := c.get(ctx, "/messages", query, costList, &out); err != nil {
		return nil, "", err
	}
	ids = make([]string, 0, len(out.Messages))
	for _, m := range out.Messages {
		ids = append(ids, m.ID)
	}
	return ids, out.NextPageToken, nil
}

// listAll returns every message id of a listing.
func (c *Client) listAll(ctx context.Context, labelID string, includeSpamTrash bool) ([]string, error) {
	var all []string
	q := listQuery{labelID: labelID, includeSpamTrash: includeSpamTrash, pageSize: 500}
	for {
		ids, next, err := c.listMessages(ctx, q)
		if err != nil {
			return nil, err
		}
		all = append(all, ids...)
		if next == "" {
			return all, nil
		}
		q.pageToken = next
	}
}

// getMessage fetches a message as "minimal" (labels only) or "raw" (with the MIME). A deleted message is a 404
// StatusError.
func (c *Client) getMessage(ctx context.Context, id, format string) (message, error) {
	var out message
	err := c.get(ctx, "/messages/"+url.PathEscape(id), url.Values{"format": {format}}, costGet, &out)
	return out, err
}

// history returns one page of changes since startHistoryID. An expired startHistoryID is a 404 StatusError.
func (c *Client) history(ctx context.Context, startHistoryID, pageToken string) (historyPage, error) {
	query := url.Values{"startHistoryId": {startHistoryID}, "maxResults": {"500"}}
	if pageToken != "" {
		query.Set("pageToken", pageToken)
	}
	var out historyPage
	err := c.get(ctx, "/history", query, costHistory, &out)
	return out, err
}

func (c *Client) get(ctx context.Context, path string, query url.Values, cost int, out any) error {
	target := c.base + path
	if len(query) > 0 {
		target += "?" + query.Encode()
	}
	return mapAccessError(c.http.JSON(ctx, providerhttp.Request{Method: http.MethodGet, URL: target, Cost: cost}, out))
}

// mapAccessError marks refused mail access (a token without the Gmail scope) as providersync.ErrAccessDenied.
func mapAccessError(err error) error {
	var statusErr *providerhttp.StatusError
	if errors.As(err, &statusErr) && statusErr.StatusCode == http.StatusForbidden &&
		(statusErr.HasReason("insufficientPermissions") || statusErr.HasReason("ACCESS_TOKEN_SCOPE_INSUFFICIENT")) {
		return fmt.Errorf("%w: %w", providersync.ErrAccessDenied, err)
	}
	return err
}

// decodeRaw reads Gmail's base64url, which may or may not be padded.
func decodeRaw(raw string) ([]byte, error) {
	return base64.RawURLEncoding.DecodeString(strings.TrimRight(raw, "="))
}
