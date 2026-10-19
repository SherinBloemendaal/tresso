// Package api is the sync worker's client for the internal API.
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"golang.org/x/oauth2/clientcredentials"
)

// Scopes the sync worker requests. The API grants nothing else to this client.
var Scopes = []string{"sync:lease", "sync:ingest", "sync:credentials"}

type Account struct {
	ID             string    `json:"id"`
	EmailAddress   string    `json:"emailAddress"`
	Provider       string    `json:"provider"`
	LeaseExpiresAt time.Time `json:"leaseExpiresAt"`
}

type LeaseResponse struct {
	LeaseSeconds int       `json:"leaseSeconds"`
	Accounts     []Account `json:"accounts"`
}

// ServerSettings is where an IMAP or SMTP server lives.
type ServerSettings struct {
	Host     string `json:"host"`
	Port     int    `json:"port"`
	Security string `json:"security"` // "tls", "starttls" or "none"
}

// Connection holds the non-secret settings of an account.
type Connection struct {
	IMAP     ServerSettings `json:"imap"`
	SMTP     ServerSettings `json:"smtp"`
	Username string         `json:"username"`
}

// Credentials are the decrypted secrets of one leased account. Keep them in memory only.
type Credentials struct {
	AccountID    string            `json:"accountId"`
	EmailAddress string            `json:"emailAddress"`
	Provider     string            `json:"provider"`
	Connection   Connection        `json:"connection"`
	Secrets      map[string]string `json:"credentials"`
}

// Password returns the password for password-based accounts.
func (c Credentials) Password() (string, error) {
	if c.Secrets["type"] != "password" || c.Secrets["password"] == "" {
		return "", fmt.Errorf("account %s has no password credentials", c.AccountID)
	}
	return c.Secrets["password"], nil
}

type Client struct {
	baseURL string
	http    *http.Client
}

// New returns a client that authenticates with the OAuth2 client credentials grant and refreshes tokens itself.
func New(ctx context.Context, baseURL, tokenURL, clientID, clientSecret string) *Client {
	cc := clientcredentials.Config{
		ClientID:     clientID,
		ClientSecret: clientSecret,
		TokenURL:     tokenURL,
		Scopes:       Scopes,
	}
	httpClient := cc.Client(ctx)
	httpClient.Timeout = 15 * time.Second

	return &Client{baseURL: strings.TrimRight(baseURL, "/"), http: httpClient}
}

// NewWithHTTPClient is used in tests and when the caller manages authentication.
func NewWithHTTPClient(baseURL string, httpClient *http.Client) *Client {
	return &Client{baseURL: strings.TrimRight(baseURL, "/"), http: httpClient}
}

// Lease renews the leases this worker holds and claims new accounts up to capacity.
func (c *Client) Lease(ctx context.Context, workerID string, capacity int) (LeaseResponse, error) {
	var out LeaseResponse
	err := c.post(ctx, "/internal/sync/leases", map[string]any{"workerId": workerID, "capacity": capacity}, &out)
	return out, err
}

// Release gives every account held by this worker back, so other workers take over without waiting for expiry.
func (c *Client) Release(ctx context.Context, workerID string) error {
	return c.post(ctx, "/internal/sync/leases/release", map[string]any{"workerId": workerID}, nil)
}

// Credentials fetches the decrypted credentials of an account this worker currently leases.
func (c *Client) Credentials(ctx context.Context, accountID, workerID string) (Credentials, error) {
	var out Credentials
	path := "/internal/sync/accounts/" + url.PathEscape(accountID) + "/credentials?workerId=" + url.QueryEscape(workerID)
	err := c.do(ctx, http.MethodGet, path, nil, &out)
	return out, err
}

func (c *Client) post(ctx context.Context, path string, body any, out any) error {
	return c.do(ctx, http.MethodPost, path, body, out)
}

func (c *Client) do(ctx context.Context, method, path string, body any, out any) error {
	var reader io.Reader
	if body != nil {
		payload, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("encode request: %w", err)
		}
		reader = bytes.NewReader(payload)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.baseURL+path, reader)
	if err != nil {
		return err
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("%s %s: %w", method, path, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		snippet, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("%s %s: unexpected status %d: %s", method, path, resp.StatusCode, bytes.TrimSpace(snippet))
	}

	if out == nil {
		return nil
	}
	if err := json.NewDecoder(resp.Body).Decode(out); err != nil {
		return fmt.Errorf("%s %s: decode response: %w", method, path, err)
	}
	return nil
}
