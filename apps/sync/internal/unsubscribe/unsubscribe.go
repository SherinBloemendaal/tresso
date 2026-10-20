// Package unsubscribe sends one-click unsubscribe requests (RFC 8058): a single POST of List-Unsubscribe=One-Click to
// the https address in a message's List-Unsubscribe header. The address comes from whoever sent the message, so the
// request carries nothing about the user (no cookies, no credentials), follows no redirect, and connects through the
// worker's netguard dialer, which refuses private and loopback addresses at every connection.
package unsubscribe

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/netguard"
)

// body is the one-click request body RFC 8058 prescribes.
const body = "List-Unsubscribe=One-Click"

// maxBody is how much of an answer is read; the answer itself means nothing, only its status.
const maxBody = 64 << 10

// User-safe texts of the outcomes.
const (
	notHTTPSText   = "The unsubscribe address is not a valid secure web address on the standard port."
	notPublicText  = "The unsubscribe address is not public."
	unreachedText  = "The sender's server could not be reached; Tresso will try again."
	timeoutText    = "The sender's server did not answer in time; Tresso will try again."
	busyTextFormat = "The sender's server is busy (HTTP %d); Tresso will try again."
	refusedFormat  = "The sender's server refused the request (HTTP %d)."
)

// Dialer connects to a host:port; *netguard.Dialer refuses addresses that are not public.
type Dialer interface {
	DialContext(ctx context.Context, address string) (net.Conn, error)
}

type Options struct {
	// Timeout bounds a whole request, from connecting to reading the answer.
	Timeout time.Duration
	// RootCAs verifies the server's certificate; nil uses the system's roots. Only tests set it.
	RootCAs *x509.CertPool
}

func DefaultOptions() Options {
	return Options{Timeout: 5 * time.Second}
}

// Sender posts one-click unsubscribe requests.
type Sender struct {
	client *http.Client
}

func New(dialer Dialer, opts Options) *Sender {
	transport := &http.Transport{
		Proxy: nil, // never through a proxy from the environment: the dialer has to see the real address
		DialContext: func(ctx context.Context, _, address string) (net.Conn, error) {
			return dialer.DialContext(ctx, address)
		},
		TLSClientConfig:        &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: opts.RootCAs},
		TLSHandshakeTimeout:    opts.Timeout,
		DisableKeepAlives:      true, // every request goes to another sender; keeping connections open serves nobody
		MaxResponseHeaderBytes: maxBody,
	}
	return &Sender{client: &http.Client{
		Transport: transport,
		Timeout:   opts.Timeout,
		// A one-click unsubscribe is the POST itself; a redirect would turn it into a GET of an address nobody checked.
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}}
}

// Post sends the one-click request to address and says how it went: api.UnsubscribeDone, api.OutcomeRetry or
// api.OutcomeFailed, with a text that is safe to show the user. Only an https address with a host is used; anything
// else fails without connecting.
func (s *Sender) Post(ctx context.Context, address string) (outcome, text string) {
	target, err := url.Parse(address)
	if err != nil || target.Scheme != "https" || target.Hostname() == "" || target.User != nil || (target.Port() != "" && target.Port() != "443") {
		// A user in the address would become an Authorization header.
		return api.OutcomeFailed, notHTTPSText
	}

	request, err := http.NewRequestWithContext(ctx, http.MethodPost, target.String(), strings.NewReader(body))
	if err != nil {
		return api.OutcomeFailed, notHTTPSText
	}
	request.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	request.Header.Set("User-Agent", "Tresso")

	response, err := s.client.Do(request)
	if err != nil {
		return failure(err)
	}
	defer response.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, maxBody))
	return status(response.StatusCode)
}

// status maps the server's answer. A redirect counts as done: it is not followed, and the server took the POST.
func status(code int) (outcome, text string) {
	switch {
	case code >= 200 && code < 400:
		return api.UnsubscribeDone, ""
	case code == http.StatusTooManyRequests || code >= 500:
		return api.OutcomeRetry, fmt.Sprintf(busyTextFormat, code)
	}
	return api.OutcomeFailed, fmt.Sprintf(refusedFormat, code)
}

// failure maps a request that got no answer. Only a refused address is final; anything else may pass.
func failure(err error) (outcome, text string) {
	if errors.Is(err, netguard.ErrBlockedAddress) {
		return api.OutcomeFailed, notPublicText
	}
	var timeout interface{ Timeout() bool }
	if errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &timeout) && timeout.Timeout()) {
		return api.OutcomeRetry, timeoutText
	}
	return api.OutcomeRetry, unreachedText
}
