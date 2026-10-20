package unsubscribe

import (
	"context"
	"crypto/x509"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/netguard"
)

// dialerFunc is a Dialer made of a function.
type dialerFunc func(ctx context.Context, address string) (net.Conn, error)

func (f dialerFunc) DialContext(ctx context.Context, address string) (net.Conn, error) {
	return f(ctx, address)
}

// localSender reaches srv as https://example.com, the name its certificate holds, on the standard port: the dialer
// sends example.com:443 to the test server and refuses anything else.
func localSender(srv *httptest.Server, timeout time.Duration) (*Sender, string) {
	roots := x509.NewCertPool()
	roots.AddCert(srv.Certificate())
	target := srv.Listener.Addr().String()
	dial := dialerFunc(func(ctx context.Context, address string) (net.Conn, error) {
		if address != "example.com:443" {
			return nil, fmt.Errorf("unexpected dial to %s", address)
		}
		var dialer net.Dialer
		return dialer.DialContext(ctx, "tcp", target)
	})
	return New(dial, Options{Timeout: timeout, RootCAs: roots}), "https://example.com"
}

func TestPostIsAOneClickRequestWithoutContext(t *testing.T) {
	var got *http.Request
	var gotBody string
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r
		raw, _ := io.ReadAll(r.Body)
		gotBody = string(raw)
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()

	sender, base := localSender(srv, 5*time.Second)
	outcome, text := sender.Post(context.Background(), base+"/unsub?u=1")

	if outcome != api.UnsubscribeDone || text != "" {
		t.Fatalf("outcome = %q %q", outcome, text)
	}
	if got.Method != http.MethodPost || got.URL.RequestURI() != "/unsub?u=1" || gotBody != "List-Unsubscribe=One-Click" {
		t.Errorf("request = %s %s %q", got.Method, got.URL.RequestURI(), gotBody)
	}
	if got.Header.Get("Content-Type") != "application/x-www-form-urlencoded" || got.Header.Get("User-Agent") != "Tresso" {
		t.Errorf("headers = %v", got.Header)
	}
	for _, header := range []string{"Authorization", "Cookie", "Referer"} {
		if got.Header.Get(header) != "" {
			t.Errorf("%s sent: %q", header, got.Header.Get(header))
		}
	}
}

func TestRedirectIsNotFollowed(t *testing.T) {
	var followed atomic.Bool
	mux := http.NewServeMux()
	mux.HandleFunc("/unsub", func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "/elsewhere", http.StatusFound)
	})
	mux.HandleFunc("/elsewhere", func(http.ResponseWriter, *http.Request) { followed.Store(true) })
	srv := httptest.NewTLSServer(mux)
	defer srv.Close()

	sender, base := localSender(srv, 5*time.Second)
	outcome, _ := sender.Post(context.Background(), base+"/unsub")

	if outcome != api.UnsubscribeDone {
		t.Errorf("outcome = %q, want done: the server took the POST", outcome)
	}
	if followed.Load() {
		t.Error("the redirect was followed")
	}
}

func TestStatusOutcomes(t *testing.T) {
	cases := map[int]string{
		http.StatusOK:                  api.UnsubscribeDone,
		http.StatusAccepted:            api.UnsubscribeDone,
		http.StatusNoContent:           api.UnsubscribeDone,
		http.StatusMovedPermanently:    api.UnsubscribeDone,
		http.StatusTooManyRequests:     api.OutcomeRetry,
		http.StatusInternalServerError: api.OutcomeRetry,
		http.StatusServiceUnavailable:  api.OutcomeRetry,
		http.StatusBadRequest:          api.OutcomeFailed,
		http.StatusForbidden:           api.OutcomeFailed,
		http.StatusNotFound:            api.OutcomeFailed,
		http.StatusMethodNotAllowed:    api.OutcomeFailed,
	}
	for code, want := range cases {
		srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			if code >= 300 && code < 400 {
				w.Header().Set("Location", "/elsewhere")
			}
			w.WriteHeader(code)
			_, _ = w.Write([]byte(strings.Repeat("x", 200<<10))) // longer than what is read
		}))
		sender, base := localSender(srv, 5*time.Second)
		outcome, text := sender.Post(context.Background(), base)
		srv.Close()
		if outcome != want {
			t.Errorf("HTTP %d: outcome = %q, want %q", code, outcome, want)
		}
		if want != api.UnsubscribeDone && !strings.Contains(text, "HTTP "+strconv.Itoa(code)) {
			t.Errorf("HTTP %d: text %q does not name the status", code, text)
		}
	}
}

func TestOnlyHTTPSAddressesAreUsed(t *testing.T) {
	var hits atomic.Int32
	plain := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) { hits.Add(1) }))
	defer plain.Close()
	sender := New(netguard.New(true), DefaultOptions())

	for _, address := range []string{
		plain.URL + "/unsub",
		"mailto:unsub@news.example",
		"javascript:alert(1)",
		"https:///unsub",
		"https://user:secret@news.example/unsub",
		"https://news.example:8443/unsub",
		"ftp://news.example/unsub",
		"news.example/unsub",
		"",
	} {
		outcome, text := sender.Post(context.Background(), address)
		if outcome != api.OutcomeFailed || text == "" {
			t.Errorf("%q: outcome = %q %q, want failed", address, outcome, text)
		}
	}
	if hits.Load() != 0 {
		t.Errorf("the plain http server was reached %d times", hits.Load())
	}
}

func TestPrivateAddressesAreRefusedWithoutConnecting(t *testing.T) {
	var hits atomic.Int32
	srv := httptest.NewTLSServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) { hits.Add(1) }))
	defer srv.Close()
	roots := x509.NewCertPool()
	roots.AddCert(srv.Certificate())
	sender := New(netguard.New(false), Options{Timeout: 5 * time.Second, RootCAs: roots})

	outcome, text := sender.Post(context.Background(), "https://127.0.0.1/unsub")

	if outcome != api.OutcomeFailed || text != notPublicText {
		t.Errorf("outcome = %q %q, want failed: not public", outcome, text)
	}
	if hits.Load() != 0 {
		t.Error("the server on 127.0.0.1 was reached")
	}
}

func TestNoAnswerIsRetried(t *testing.T) {
	release := make(chan struct{})
	slow := httptest.NewTLSServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) { <-release }))
	defer slow.Close()
	defer close(release)

	slowSender, slowBase := localSender(slow, 200*time.Millisecond)
	outcome, text := slowSender.Post(context.Background(), slowBase)
	if outcome != api.OutcomeRetry || text != timeoutText {
		t.Errorf("timeout: outcome = %q %q", outcome, text)
	}

	gone := httptest.NewTLSServer(http.NotFoundHandler())
	sender, address := localSender(gone, 5*time.Second)
	gone.Close()
	outcome, text = sender.Post(context.Background(), address)
	if outcome != api.OutcomeRetry || text != unreachedText {
		t.Errorf("connection refused: outcome = %q %q", outcome, text)
	}
}

func TestTheDefaultTimeoutIsShort(t *testing.T) {
	if DefaultOptions().Timeout > 5*time.Second {
		t.Errorf("timeout = %s; a claim's requests must fit well inside the five-minute claim", DefaultOptions().Timeout)
	}
}
