// Package caldav is a CalDAV client (RFC 4791) with what Tresso needs: discovery (RFC 6764), calendars with their
// color, privileges, ctag and sync-token, changes by sync-collection (RFC 6578) or etag listing, calendar-multiget, and
// conditional writes. Objects stay raw iCalendar text both ways, so nothing is lost by re-encoding them; the API
// parses them. Every connection goes through the guarded dialer.
//
// The mail password is sent only where it belongs: after a server asks for it (401), and only to a host the account
// trusts (see Trust). Any other server that asks gets nothing and the request fails with an *UntrustedError.
package caldav

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"golang.org/x/net/publicsuffix"
)

// Dialer opens connections; the netguard dialer refuses private addresses.
type Dialer interface {
	DialContext(ctx context.Context, address string) (net.Conn, error)
}

var (
	// ErrUnauthorized means the server refused the credentials.
	ErrUnauthorized = errors.New("the calendar server refused the login")
	// ErrNotCalDAV means the address answers but is no CalDAV server.
	ErrNotCalDAV = errors.New("no CalDAV server at this address")
	// ErrInvalidSyncToken means the server no longer knows the sync token; a full listing is needed.
	ErrInvalidSyncToken = errors.New("the sync token is no longer valid")
)

// UntrustedError means a server asked for the login, but it is not a host the account trusts with its password.
type UntrustedError struct {
	URL string
}

func (e *UntrustedError) Error() string {
	return "the calendar server at " + e.URL + " asked for the login, which is only sent to confirmed servers"
}

// Trust is where the mail password may go: exact hosts (the IMAP host, the CalDAV address the user entered or
// confirmed) and the sites of known providers, whose CalDAV hosts vary (p42-caldav.icloud.com).
type Trust struct {
	hosts map[string]bool
	sites map[string]bool
}

// NewTrust trusts the IMAP host, the host of the configured CalDAV address, and the site of the known provider
// behind the IMAP host.
func NewTrust(imapHost, calDAVURL string) Trust {
	trust := Trust{hosts: map[string]bool{}, sites: map[string]bool{}}
	if host := strings.ToLower(strings.TrimSuffix(imapHost, ".")); host != "" {
		trust.hosts[host] = true
		if server := knownServers[host]; server != "" {
			if u, err := url.Parse(server); err == nil {
				trust.hosts[strings.ToLower(u.Hostname())] = true
				if site, err := siteOf(u.Hostname()); err == nil {
					trust.sites[site] = true
				}
			}
		}
	}
	if u, err := url.Parse(calDAVURL); err == nil && u.Hostname() != "" {
		trust.hosts[strings.ToLower(u.Hostname())] = true
	}
	return trust
}

func (t Trust) allows(host string) bool {
	host = strings.ToLower(host)
	if t.hosts[host] {
		return true
	}
	site, err := siteOf(host)
	return err == nil && t.sites[site]
}

const (
	maxMultistatus = 64 << 20
	// MaxObject is the largest calendar object Tresso takes; the API refuses larger ones.
	MaxObject = 1 << 20
)

// StatusError is an HTTP status the caller decides on (412 conflicts, 403 refusals, 5xx retries).
type StatusError struct {
	Method string
	URL    string
	Status int
}

func (e *StatusError) Error() string {
	return fmt.Sprintf("%s %s: HTTP %d", e.Method, e.URL, e.Status)
}

type Client struct {
	http     *http.Client
	username string
	password string
	trust    Trust

	mu sync.Mutex
	// authed holds the trusted hosts that asked for the login and took it; they get it right away from then on.
	authed map[string]bool
}

// New returns a client that authenticates with Basic auth over TLS, to trusted hosts that ask for it. allowHTTP
// permits plain HTTP, for local test servers only.
func New(dialer Dialer, username, password string, allowHTTP bool, trust Trust) *Client {
	transport := &http.Transport{
		DialContext: func(ctx context.Context, _, address string) (net.Conn, error) {
			return dialer.DialContext(ctx, address)
		},
		TLSClientConfig:       &tls.Config{MinVersion: tls.VersionTLS12},
		TLSHandshakeTimeout:   15 * time.Second,
		ResponseHeaderTimeout: 60 * time.Second,
		MaxIdleConnsPerHost:   2,
		IdleConnTimeout:       90 * time.Second,
	}
	client := &http.Client{
		Transport: transport,
		Timeout:   2 * time.Minute,
	}
	c := &Client{http: client, username: username, password: password, trust: trust, authed: map[string]bool{}}
	client.CheckRedirect = func(req *http.Request, via []*http.Request) error {
		if len(via) >= 5 {
			return errors.New("too many redirects")
		}
		if req.URL.Scheme != "https" && !allowHTTP {
			return fmt.Errorf("refusing a redirect to %s", req.URL.Scheme)
		}
		// http.Client drops the login on a redirect to another host. It goes along only to a host that took it
		// before; any other host asks first (see request).
		if c.hasAuthed(req.URL.Hostname()) {
			req.SetBasicAuth(username, password)
		}
		return nil
	}
	return c
}

func (c *Client) hasAuthed(host string) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.authed[strings.ToLower(host)]
}

func (c *Client) markAuthed(host string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.authed[strings.ToLower(host)] = true
}

// mayAuthenticate tells whether the login may go to a host that asked for it: a trusted host, or one a trusted host
// redirected to on its own site (example.com/.well-known/caldav to caldav.example.com).
func (c *Client) mayAuthenticate(origin, host string) bool {
	return c.trust.allows(host) || (c.trust.allows(origin) && sameSite(origin, host))
}

// request sends a request with the login when the host took it before. When the server (after redirects) asks for
// it, the request is sent once more with the login to where it ended up, if that host may have it.
func (c *Client) request(ctx context.Context, method, target string, body []byte, header map[string]string) (*http.Response, error) {
	origin, err := url.Parse(target)
	if err != nil {
		return nil, err
	}
	resp, err := c.send(ctx, method, target, body, header, c.hasAuthed(origin.Hostname()))
	if err != nil || resp.StatusCode != http.StatusUnauthorized {
		return resp, err
	}
	drain(resp)
	final := resp.Request.URL
	if resp.Request.Header.Get("Authorization") != "" {
		return nil, ErrUnauthorized
	}
	if !c.mayAuthenticate(origin.Hostname(), final.Hostname()) {
		return nil, &UntrustedError{URL: final.String()}
	}

	resp, err = c.send(ctx, method, final.String(), body, header, true)
	if err != nil {
		return nil, err
	}
	if resp.StatusCode == http.StatusUnauthorized {
		drain(resp)
		return nil, ErrUnauthorized
	}
	c.markAuthed(final.Hostname())
	return resp, nil
}

func (c *Client) send(ctx context.Context, method, target string, body []byte, header map[string]string, authenticate bool) (*http.Response, error) {
	var reader io.Reader
	if body != nil {
		reader = bytes.NewReader(body)
	}
	req, err := http.NewRequestWithContext(ctx, method, target, reader)
	if err != nil {
		return nil, err
	}
	if authenticate {
		req.SetBasicAuth(c.username, c.password)
	}
	req.Header.Set("User-Agent", "Tresso")
	for key, value := range header {
		req.Header.Set(key, value)
	}
	return c.http.Do(req)
}

// multistatusRequest sends PROPFIND or REPORT and parses the 207 answer.
func (c *Client) multistatusRequest(ctx context.Context, method, target, depth, body string) (*multistatus, *url.URL, error) {
	resp, err := c.request(ctx, method, target, []byte(xml.Header+body), map[string]string{"Content-Type": "application/xml; charset=utf-8", "Depth": depth})
	if err != nil {
		return nil, nil, err
	}
	defer drain(resp)
	if resp.StatusCode != http.StatusMultiStatus {
		data, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		if bytes.Contains(data, []byte("valid-sync-token")) {
			return nil, nil, ErrInvalidSyncToken
		}
		return nil, nil, &StatusError{Method: method, URL: target, Status: resp.StatusCode}
	}
	var ms multistatus
	if err := xml.NewDecoder(io.LimitReader(resp.Body, maxMultistatus)).Decode(&ms); err != nil {
		return nil, nil, fmt.Errorf("%s %s: invalid multistatus: %w", method, target, err)
	}
	return &ms, resp.Request.URL, nil
}

// Object is one calendar resource with its iCalendar text.
type Object struct {
	Href string
	ETag string
	Data string
}

// Get fetches one resource; a missing resource is a *StatusError with 404.
func (c *Client) Get(ctx context.Context, href string) (Object, error) {
	resp, err := c.request(ctx, http.MethodGet, href, nil, map[string]string{"Accept": "text/calendar"})
	if err != nil {
		return Object{}, err
	}
	defer drain(resp)
	if resp.StatusCode != http.StatusOK {
		return Object{}, &StatusError{Method: http.MethodGet, URL: href, Status: resp.StatusCode}
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, MaxObject+1))
	if err != nil {
		return Object{}, err
	}
	if len(data) > MaxObject {
		return Object{}, fmt.Errorf("%s is larger than %d bytes", href, MaxObject)
	}
	return Object{Href: href, ETag: resp.Header.Get("ETag"), Data: string(data)}, nil
}

// Put writes a resource. With ifMatch the write only happens when the server's version is still that one; without it
// the resource must not exist yet. Returns the new ETag when the server tells it.
func (c *Client) Put(ctx context.Context, href, data, ifMatch string) (string, error) {
	header := map[string]string{"Content-Type": "text/calendar; charset=utf-8"}
	if ifMatch != "" {
		header["If-Match"] = ifMatch
	} else {
		header["If-None-Match"] = "*"
	}
	resp, err := c.request(ctx, http.MethodPut, href, []byte(data), header)
	if err != nil {
		return "", err
	}
	defer drain(resp)
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return "", &StatusError{Method: http.MethodPut, URL: href, Status: resp.StatusCode}
	}
	return resp.Header.Get("ETag"), nil
}

// Delete removes a resource, only when the server's version is still ifMatch (when given). A resource that is
// already gone counts as deleted.
func (c *Client) Delete(ctx context.Context, href, ifMatch string) error {
	header := map[string]string{}
	if ifMatch != "" {
		header["If-Match"] = ifMatch
	}
	resp, err := c.request(ctx, http.MethodDelete, href, nil, header)
	if err != nil {
		return err
	}
	defer drain(resp)
	if resp.StatusCode == http.StatusNotFound || (resp.StatusCode >= 200 && resp.StatusCode <= 299) {
		return nil
	}
	return &StatusError{Method: http.MethodDelete, URL: href, Status: resp.StatusCode}
}

// ETag reads the current ETag of a resource, for servers that do not return it on PUT.
func (c *Client) ETag(ctx context.Context, href string) (string, error) {
	ms, _, err := c.multistatusRequest(ctx, "PROPFIND", href, "0", `<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>`)
	if err != nil {
		return "", err
	}
	for _, r := range ms.Responses {
		if p, ok := r.okProp(); ok && p.ETag != "" {
			return p.ETag, nil
		}
	}
	return "", nil
}

func siteOf(host string) (string, error) {
	return publicsuffix.EffectiveTLDPlusOne(strings.ToLower(host))
}

func sameSite(a, b string) bool {
	if strings.EqualFold(a, b) {
		return true
	}
	siteA, errA := publicsuffix.EffectiveTLDPlusOne(strings.ToLower(a))
	siteB, errB := publicsuffix.EffectiveTLDPlusOne(strings.ToLower(b))
	return errA == nil && errB == nil && siteA == siteB
}

func drain(resp *http.Response) {
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 1<<20))
	resp.Body.Close()
}

// resolve turns an href from a multistatus into an absolute URL against the URL it came from.
func resolve(base *url.URL, href string) string {
	ref, err := url.Parse(strings.TrimSpace(href))
	if err != nil {
		return href
	}
	return base.ResolveReference(ref).String()
}
