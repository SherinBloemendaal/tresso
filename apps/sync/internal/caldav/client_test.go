package caldav

import (
	"context"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
)

// hostServer answers for several host names on one test server, recording which hosts received the password.
type hostServer struct {
	server *httptest.Server
	mu     sync.Mutex
	// withLogin and without count requests per Host that carried the login or not.
	withLogin map[string]int
	without   map[string]int
	redirects map[string]string // host to the URL it redirects to
	// open hosts answer without asking for the login.
	open map[string]bool
}

func newHostServer(t *testing.T) *hostServer {
	h := &hostServer{withLogin: map[string]int{}, without: map[string]int{}, redirects: map[string]string{}, open: map[string]bool{}}
	h.server = httptest.NewServer(http.HandlerFunc(h.handle))
	t.Cleanup(h.server.Close)
	return h
}

func (h *hostServer) handle(w http.ResponseWriter, r *http.Request) {
	host := r.Host
	if i := strings.LastIndex(host, ":"); i >= 0 {
		host = host[:i]
	}
	user, password, ok := r.BasicAuth()
	h.mu.Lock()
	if ok {
		h.withLogin[host]++
	} else {
		h.without[host]++
	}
	target, redirect := h.redirects[host]
	open := h.open[host]
	h.mu.Unlock()

	if redirect {
		http.Redirect(w, r, target, http.StatusTemporaryRedirect)
		return
	}
	if !open && (!ok || user != "kim@company.example" || password != "app-password") {
		w.Header().Set("WWW-Authenticate", `Basic realm="calendars"`)
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	w.Header().Set("Content-Type", "application/xml; charset=utf-8")
	w.WriteHeader(http.StatusMultiStatus)
	switch r.URL.Path {
	case "/dav/":
		_, _ = w.Write([]byte(`<d:multistatus xmlns:d="DAV:"><d:response><d:href>/dav/</d:href><d:propstat><d:prop><d:current-user-principal><d:href>/dav/principal/</d:href></d:current-user-principal></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>`))
	default:
		_, _ = w.Write([]byte(`<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:href>/dav/principal/</d:href><d:propstat><d:prop><c:calendar-home-set><d:href>/dav/home/</d:href></c:calendar-home-set></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>`))
	}
}

func (h *hostServer) logins(host string) int {
	h.mu.Lock()
	defer h.mu.Unlock()
	return h.withLogin[host]
}

// anyHost dials the test server for every address, so URLs can name any host.
type anyHost struct{ address string }

func (d anyHost) DialContext(ctx context.Context, _ string) (net.Conn, error) {
	var dialer net.Dialer
	return dialer.DialContext(ctx, "tcp", d.address)
}

func (h *hostServer) client(trust Trust) *Client {
	return New(anyHost{address: h.server.Listener.Addr().String()}, "kim@company.example", "app-password", true, trust)
}

func TestTheLoginGoesOnlyToATrustedHostThatAsksForIt(t *testing.T) {
	h := newHostServer(t)
	client := h.client(NewTrust("imap.company.example", "http://dav.company.example/dav/"))

	if _, err := client.Open(context.Background(), "http://dav.company.example/dav/"); err != nil {
		t.Fatalf("open: %v", err)
	}

	if h.without["dav.company.example"] != 1 {
		t.Fatalf("the first request must go without the login, got %d without", h.without["dav.company.example"])
	}
	// The principal lookup and the OPTIONS request after it carry the login straight away.
	if h.logins("dav.company.example") < 2 {
		t.Fatalf("a host that asked once gets the login from then on, got %d", h.logins("dav.company.example"))
	}
}

func TestAnUntrustedHostNeverGetsTheLogin(t *testing.T) {
	h := newHostServer(t)
	client := h.client(NewTrust("imap.company.example", ""))

	_, err := client.Open(context.Background(), "http://company.example/.well-known/caldav")

	var untrusted *UntrustedError
	if !errors.As(err, &untrusted) {
		t.Fatalf("want an UntrustedError, got %v", err)
	}
	if h.logins("company.example") != 0 {
		t.Fatal("the web host of the mail domain received the password")
	}
}

func TestARedirectToAnotherSiteDoesNotCarryTheLogin(t *testing.T) {
	h := newHostServer(t)
	h.redirects["dav.company.example"] = "http://collector.attacker.test/dav/"
	client := h.client(NewTrust("", "http://dav.company.example/dav/"))

	_, err := client.Open(context.Background(), "http://dav.company.example/dav/")

	var untrusted *UntrustedError
	if !errors.As(err, &untrusted) || !strings.Contains(untrusted.URL, "collector.attacker.test") {
		t.Fatalf("want an UntrustedError for the other site, got %v", err)
	}
	if h.logins("collector.attacker.test") != 0 {
		t.Fatal("the login followed a redirect to another site")
	}
}

func TestARedirectWithinTheTrustedSiteGetsTheLogin(t *testing.T) {
	h := newHostServer(t)
	h.redirects["company.example"] = "http://caldav.company.example/dav/"
	client := h.client(NewTrust("company.example", ""))

	if _, err := client.Open(context.Background(), "http://company.example/.well-known/caldav"); err != nil {
		t.Fatalf("open: %v", err)
	}
	if h.logins("caldav.company.example") == 0 {
		t.Fatal("the CalDAV host the trusted host sent Tresso to did not get the login")
	}
}

func TestDiscoveryAsksToConfirmAServerItDoesNotTrust(t *testing.T) {
	h := newHostServer(t)
	client := h.client(NewTrust("imap.mailhost.example", ""))

	_, err := client.discover(context.Background(), []string{"http://company.example/.well-known/caldav"})

	var confirm *ConfirmError
	if !errors.As(err, &confirm) || confirm.URL != "http://company.example/.well-known/caldav" {
		t.Fatalf("want a ConfirmError naming the server, got %v", err)
	}
	if h.logins("company.example") != 0 {
		t.Fatal("discovery sent the password to a guessed host")
	}
}

func TestDiscoveryNeverUsesAnUntrustedServerThatAnswersWithoutTheLogin(t *testing.T) {
	h := newHostServer(t)
	h.open["company.example"] = true
	client := h.client(NewTrust("imap.mailhost.example", ""))

	_, err := client.discover(context.Background(), []string{"http://company.example/dav/"})

	var confirm *ConfirmError
	if !errors.As(err, &confirm) || confirm.URL != "http://company.example/dav/" {
		t.Fatalf("an open untrusted server must be confirmed first, got %v", err)
	}
	if h.logins("company.example") != 0 {
		t.Fatal("the open server received the password")
	}
}

func TestDiscoveryUsesAnOpenServerItTrusts(t *testing.T) {
	h := newHostServer(t)
	h.open["imap.company.example"] = true
	client := h.client(NewTrust("imap.company.example", ""))

	account, err := client.discover(context.Background(), []string{"http://imap.company.example/dav/", "http://company.example/dav/"})
	if err != nil || account.ContextURL != "http://imap.company.example/dav/" {
		t.Fatalf("want the trusted server, got %+v, %v", account, err)
	}
}

func TestKnownProvidersAreTrustedOnTheirWholeSite(t *testing.T) {
	trust := NewTrust("imap.mail.me.com", "")

	if !trust.allows("p42-caldav.icloud.com") || !trust.allows("imap.mail.me.com") {
		t.Fatal("iCloud's CalDAV hosts are trusted for an iCloud account")
	}
	if trust.allows("me.com") || trust.allows("caldav.fastmail.com") {
		t.Fatal("only the provider's CalDAV site is trusted")
	}
}

// newTLSHostServer is a hostServer over TLS whose certificate the client accepts for any host name.
func newTLSHostServer(t *testing.T) *hostServer {
	h := &hostServer{withLogin: map[string]int{}, without: map[string]int{}, redirects: map[string]string{}, open: map[string]bool{}}
	h.server = httptest.NewTLSServer(http.HandlerFunc(h.handle))
	t.Cleanup(h.server.Close)
	return h
}

func (h *hostServer) secureClient(trust Trust) *Client {
	c := New(anyHost{address: h.server.Listener.Addr().String()}, "kim@company.example", "app-password", false, trust)
	c.http.Transport.(*http.Transport).TLSClientConfig.InsecureSkipVerify = true
	return c
}

func TestPlainHTTPIsRefusedOutsideLocalTests(t *testing.T) {
	h := newHostServer(t)
	client := New(anyHost{address: h.server.Listener.Addr().String()}, "kim@company.example", "app-password", false, NewTrust("", "http://dav.company.example/dav/"))

	_, err := client.Open(context.Background(), "http://dav.company.example/dav/")

	if !errors.Is(err, ErrInsecure) {
		t.Fatalf("want ErrInsecure, got %v", err)
	}
	if h.without["dav.company.example"]+h.logins("dav.company.example") != 0 {
		t.Fatal("a request went out over plain http")
	}
}

func TestAnHTTPHrefFromTheServerNeverGetsTheLogin(t *testing.T) {
	h := newTLSHostServer(t)
	client := h.secureClient(NewTrust("", "https://dav.company.example/dav/"))
	if _, err := client.Open(context.Background(), "https://dav.company.example/dav/"); err != nil {
		t.Fatalf("open: %v", err)
	}
	before := h.logins("dav.company.example")

	_, err := client.Get(context.Background(), "http://dav.company.example/dav/home/event.ics")

	if !errors.Is(err, ErrInsecure) {
		t.Fatalf("want ErrInsecure, got %v", err)
	}
	if h.logins("dav.company.example") != before {
		t.Fatal("the login went out over plain http")
	}
}

func TestARedirectToPlainHTTPIsRefused(t *testing.T) {
	h := newTLSHostServer(t)
	h.redirects["dav.company.example"] = "http://dav.company.example/dav/"
	client := h.secureClient(NewTrust("", "https://dav.company.example/dav/"))

	_, err := client.Open(context.Background(), "https://dav.company.example/dav/")

	if !errors.Is(err, ErrInsecure) {
		t.Fatalf("want ErrInsecure, got %v", err)
	}
}

func TestTheLoginIsRememberedPerSchemeHostAndPort(t *testing.T) {
	client := New(anyHost{}, "kim", "pw", true, NewTrust("", ""))
	parse := func(raw string) *url.URL {
		u, err := url.Parse(raw)
		if err != nil {
			t.Fatal(err)
		}
		return u
	}

	client.markAuthed(parse("https://dav.company.example/dav/"))

	if !client.hasAuthed(parse("https://DAV.company.example:443/other/")) {
		t.Fatal("the same origin is remembered")
	}
	for _, other := range []string{"http://dav.company.example/", "http://dav.company.example:443/", "https://dav.company.example:8443/"} {
		if client.hasAuthed(parse(other)) {
			t.Fatalf("%s is another origin", other)
		}
	}
}

func TestATrustedServerSendingTressoElsewhereIsNotUsed(t *testing.T) {
	h := newHostServer(t)
	h.open["calendars.attacker.test"] = true
	h.redirects["dav.company.example"] = "http://calendars.attacker.test/dav/"
	client := h.client(NewTrust("", "http://dav.company.example/dav/"))

	_, err := client.Open(context.Background(), "http://dav.company.example/dav/")

	var untrusted *UntrustedError
	if !errors.As(err, &untrusted) || !strings.Contains(untrusted.URL, "calendars.attacker.test") {
		t.Fatalf("calendars from another site must not be used, got %v", err)
	}
}

func TestDiscoveryTriesAgainSoonAfterAServerError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	t.Cleanup(server.Close)
	client := New(anyHost{address: server.Listener.Addr().String()}, "kim@company.example", "app-password", true, NewTrust("company.example", ""))

	_, err := client.discover(context.Background(), []string{"http://company.example/.well-known/caldav"})

	if errors.Is(err, ErrNotCalDAV) || !IsTemporary(err) {
		t.Fatalf("a 503 is no proof there is no server, got %v", err)
	}
}

func TestTemporaryErrors(t *testing.T) {
	cases := map[string]struct {
		err  error
		want bool
	}{
		"server error":      {&StatusError{Status: 503}, true},
		"too many requests": {&StatusError{Status: 429}, true},
		"not found":         {&StatusError{Status: 404}, false},
		"deadline":          {context.DeadlineExceeded, true},
		"timeout":           {&net.DNSError{IsTimeout: true}, true},
		"refused login":     {ErrUnauthorized, false},
	}
	for name, c := range cases {
		if got := IsTemporary(c.err); got != c.want {
			t.Errorf("%s: IsTemporary = %v", name, got)
		}
	}
}

func TestADiscoveryAnswerIsLimited(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusMultiStatus)
		_, _ = w.Write([]byte(`<d:multistatus xmlns:d="DAV:"><d:response><d:href>/` + strings.Repeat("x", maxDiscovery) + `</d:href></d:response></d:multistatus>`))
	}))
	t.Cleanup(server.Close)
	client := New(anyHost{address: server.Listener.Addr().String()}, "kim@company.example", "app-password", true, NewTrust("", "http://dav.company.example/"))

	if _, err := client.Open(context.Background(), "http://dav.company.example/"); err == nil || !strings.Contains(err.Error(), "invalid multistatus") {
		t.Fatalf("want the oversized answer refused, got %v", err)
	}
}
