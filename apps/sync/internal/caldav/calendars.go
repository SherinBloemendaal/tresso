package caldav

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"strings"
)

// Calendar is one calendar collection.
type Calendar struct {
	URL       string
	Name      string
	Color     string // #rrggbb, or empty
	TimeZone  string // TZID of the calendar's time zone, or empty
	Writable  bool
	CTag      string
	SyncToken string
	IsDefault bool
}

// Account is where a user's calendars live on a server.
type Account struct {
	// ContextURL is the address discovery started from; it is kept so discovery is not repeated.
	ContextURL string
	HomeURL    string
	// AutoSchedule tells that the server sends invitations and replies itself (RFC 6638).
	AutoSchedule bool
	defaultURL   string
}

// Open finds the principal and calendar home behind a context URL (a server root, a .well-known address or a
// principal URL).
func (c *Client) Open(ctx context.Context, contextURL string) (Account, error) {
	start, err := url.Parse(contextURL)
	if err != nil {
		return Account{}, err
	}
	ms, base, err := c.multistatusRequestUpTo(ctx, "PROPFIND", contextURL, "0", `<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>`, maxDiscovery)
	if err != nil {
		return Account{}, err
	}
	// Where the server sent Tresso (a redirect) must be the same trusted site, or calendars from elsewhere would be
	// synced into the account.
	if !c.mayAuthenticate(start.Hostname(), base.Hostname()) {
		return Account{}, &UntrustedError{URL: base.String()}
	}
	principal := ""
	for _, r := range ms.Responses {
		if p, ok := r.okProp(); ok && len(p.CurrentUserPrincipal.Hrefs) > 0 {
			principal = resolve(base, p.CurrentUserPrincipal.Hrefs[0])
		}
	}
	if principal == "" {
		return Account{}, ErrNotCalDAV
	}

	ms, base, err = c.multistatusRequestUpTo(ctx, "PROPFIND", principal, "0", `<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-home-set/><c:schedule-default-calendar-URL/></d:prop></d:propfind>`, maxDiscovery)
	if err != nil {
		return Account{}, err
	}
	account := Account{ContextURL: contextURL}
	for _, r := range ms.Responses {
		p, ok := r.okProp()
		if !ok {
			continue
		}
		if len(p.CalendarHomeSet.Hrefs) > 0 {
			account.HomeURL = resolve(base, p.CalendarHomeSet.Hrefs[0])
		}
		if len(p.ScheduleDefault.Hrefs) > 0 {
			account.defaultURL = resolve(base, p.ScheduleDefault.Hrefs[0])
		}
	}
	if account.HomeURL == "" {
		return Account{}, ErrNotCalDAV
	}
	if home, err := url.Parse(account.HomeURL); err != nil || !c.mayAuthenticate(start.Hostname(), home.Hostname()) {
		return Account{}, &UntrustedError{URL: account.HomeURL}
	}
	account.AutoSchedule = c.autoSchedule(ctx, account.HomeURL)
	return account, nil
}

func (c *Client) autoSchedule(ctx context.Context, home string) bool {
	resp, err := c.request(ctx, http.MethodOptions, home, nil, nil)
	if err != nil {
		return false
	}
	defer drain(resp)
	for _, value := range resp.Header.Values("DAV") {
		for _, feature := range strings.Split(value, ",") {
			if strings.EqualFold(strings.TrimSpace(feature), "calendar-auto-schedule") {
				return true
			}
		}
	}
	return false
}

// Calendars lists the event calendars in the home collection.
func (c *Client) Calendars(ctx context.Context, account Account) ([]Calendar, error) {
	ms, base, err := c.multistatusRequest(ctx, "PROPFIND", account.HomeURL, "1", `<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:a="http://apple.com/ns/ical/">
<d:prop><d:resourcetype/><d:displayname/><a:calendar-color/><cs:getctag/><d:sync-token/><c:supported-calendar-component-set/><c:calendar-timezone/><d:current-user-privilege-set/></d:prop>
</d:propfind>`)
	if err != nil {
		return nil, err
	}
	var calendars []Calendar
	for _, r := range ms.Responses {
		p, ok := r.okProp()
		if !ok || p.ResourceType.Calendar == nil || !p.supportsEvents() || len(r.Hrefs) == 0 {
			continue
		}
		href := resolve(base, r.Hrefs[0])
		name := strings.TrimSpace(p.DisplayName)
		if name == "" {
			name = lastSegment(href)
		}
		calendars = append(calendars, Calendar{
			URL:       href,
			Name:      truncate(name, 255),
			Color:     color(p.CalendarColor),
			TimeZone:  timeZoneID(p.CalendarTimezone),
			Writable:  p.writable(),
			CTag:      p.CTag,
			SyncToken: p.SyncToken,
			IsDefault: account.defaultURL != "" && sameURL(account.defaultURL, href),
		})
	}
	return calendars, nil
}

// Member is one resource in a calendar listing or a change from sync-collection.
type Member struct {
	Href    string
	ETag    string
	Removed bool
}

// SyncCollection returns what changed since token (everything for an empty token), the new token, and whether the
// server cut the answer short (RFC 6578 3.6: a 507 for the collection itself); then the caller asks again with the
// new token for the rest.
func (c *Client) SyncCollection(ctx context.Context, calendarURL, token string) ([]Member, string, bool, error) {
	body := fmt.Sprintf(`<d:sync-collection xmlns:d="DAV:"><d:sync-token>%s</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>`, escape(token))
	ms, base, err := c.multistatusRequest(ctx, "REPORT", calendarURL, "0", body)
	if err != nil {
		var status *StatusError
		if token != "" && errors.As(err, &status) && (status.Status == http.StatusForbidden || status.Status == http.StatusConflict) {
			return nil, "", false, ErrInvalidSyncToken
		}
		return nil, "", false, err
	}
	members := make([]Member, 0, len(ms.Responses))
	truncated := false
	for _, r := range ms.Responses {
		if len(r.Hrefs) == 0 {
			continue
		}
		href := resolve(base, r.Hrefs[0])
		if sameURL(href, calendarURL) {
			if statusCode(r.Status) == "507" {
				truncated = true
			}
			continue
		}
		if statusCode(r.Status) == "404" {
			members = append(members, Member{Href: href, Removed: true})
			continue
		}
		p, ok := r.okProp()
		if !ok {
			continue
		}
		members = append(members, Member{Href: href, ETag: p.ETag})
	}
	return members, ms.SyncToken, truncated, nil
}

// List returns every resource of a calendar with its ETag (for servers without sync-collection).
func (c *Client) List(ctx context.Context, calendarURL string) ([]Member, error) {
	ms, base, err := c.multistatusRequest(ctx, "PROPFIND", calendarURL, "1", `<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getetag/></d:prop></d:propfind>`)
	if err != nil {
		return nil, err
	}
	members := make([]Member, 0, len(ms.Responses))
	for _, r := range ms.Responses {
		if len(r.Hrefs) == 0 {
			continue
		}
		href := resolve(base, r.Hrefs[0])
		p, ok := r.okProp()
		if !ok || sameURL(href, calendarURL) || p.ResourceType.Collection != nil {
			continue
		}
		members = append(members, Member{Href: href, ETag: p.ETag})
	}
	return members, nil
}

// MultiGet fetches the iCalendar text of resources in one request.
func (c *Client) MultiGet(ctx context.Context, calendarURL string, hrefs []string) ([]Object, error) {
	var b strings.Builder
	b.WriteString(`<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop>`)
	for _, href := range hrefs {
		path := href
		if u, err := url.Parse(href); err == nil && u.IsAbs() {
			path = u.EscapedPath()
		}
		b.WriteString("<d:href>" + escape(path) + "</d:href>")
	}
	b.WriteString(`</c:calendar-multiget>`)

	ms, base, err := c.multistatusRequest(ctx, "REPORT", calendarURL, "1", b.String())
	if err != nil {
		return nil, err
	}
	objects := make([]Object, 0, len(ms.Responses))
	for _, r := range ms.Responses {
		p, ok := r.okProp()
		if !ok || len(r.Hrefs) == 0 || p.CalendarData == "" {
			continue
		}
		objects = append(objects, Object{Href: resolve(base, r.Hrefs[0]), ETag: p.ETag, Data: p.CalendarData})
	}
	return objects, nil
}

func sameURL(a, b string) bool {
	ua, errA := url.Parse(a)
	ub, errB := url.Parse(b)
	if errA != nil || errB != nil {
		return a == b
	}
	return strings.EqualFold(ua.Host, ub.Host) && strings.TrimSuffix(ua.EscapedPath(), "/") == strings.TrimSuffix(ub.EscapedPath(), "/")
}

func lastSegment(href string) string {
	u, err := url.Parse(href)
	if err != nil {
		return href
	}
	segments := strings.Split(strings.Trim(u.Path, "/"), "/")
	name, _ := url.PathUnescape(segments[len(segments)-1])
	return name
}

// color turns Apple's #RRGGBB or #RRGGBBAA into #rrggbb.
func color(value string) string {
	value = strings.TrimSpace(value)
	if (len(value) != 7 && len(value) != 9) || value[0] != '#' {
		return ""
	}
	for _, ch := range value[1:] {
		if !strings.ContainsRune("0123456789abcdefABCDEF", ch) {
			return ""
		}
	}
	return strings.ToLower(value[:7])
}

// timeZoneID reads the TZID of a calendar-timezone VCALENDAR.
func timeZoneID(vcalendar string) string {
	for _, line := range strings.Split(strings.ReplaceAll(vcalendar, "\r\n", "\n"), "\n") {
		if strings.HasPrefix(strings.ToUpper(line), "TZID:") {
			return strings.TrimSpace(line[5:])
		}
	}
	return ""
}

func escape(s string) string {
	var b strings.Builder
	for _, r := range s {
		switch r {
		case '&':
			b.WriteString("&amp;")
		case '<':
			b.WriteString("&lt;")
		case '>':
			b.WriteString("&gt;")
		default:
			b.WriteRune(r)
		}
	}
	return b.String()
}

func truncate(s string, max int) string {
	runes := []rune(s)
	if len(runes) <= max {
		return s
	}
	return string(runes[:max])
}

// ConfirmError means discovery found a server that wants the login but is not trusted with it (the web host of the
// mail domain, a host named in DNS): the user has to confirm its address first.
type ConfirmError struct {
	URL string
}

func (e *ConfirmError) Error() string {
	return "a calendar server was found at " + e.URL + "; its address needs to be confirmed"
}

// Discover finds the CalDAV context URL for an address (RFC 6764): known providers by the IMAP host, SRV and TXT
// records of the address's domain, its .well-known/caldav, then the IMAP host's. The first candidate that answers as
// CalDAV wins. Candidates the account does not trust never get the login and are never used, also when they answer
// without asking for it; when one of them answers and no trusted one works, the result is a *ConfirmError naming it.
func (c *Client) Discover(ctx context.Context, emailAddress, imapHost string, resolver *net.Resolver) (Account, error) {
	return c.discover(ctx, Candidates(ctx, emailAddress, imapHost, resolver))
}

func (c *Client) discover(ctx context.Context, candidates []string) (Account, error) {
	var lastErr error = ErrNotCalDAV
	var unconfirmed string
	var temporary error
	for _, candidate := range candidates {
		account, err := c.Open(ctx, candidate)
		if err == nil && c.trusts(candidate) {
			return account, nil
		}
		if err == nil {
			// It answered without asking for the login, so it never had to prove anything; using it would store it as
			// the account's server, which then gets the password. The user confirms it first.
			if unconfirmed == "" {
				unconfirmed = candidate
			}
			continue
		}
		if errors.Is(err, ErrUnauthorized) {
			// The right server, the wrong password (or an app password is required): no use trying others.
			return Account{}, err
		}
		var untrusted *UntrustedError
		if errors.As(err, &untrusted) && unconfirmed == "" {
			unconfirmed = candidate
		}
		if temporary == nil && IsTemporary(err) {
			temporary = err
		}
		if ctx.Err() != nil {
			return Account{}, ctx.Err()
		}
		lastErr = err
	}
	// A server the user can confirm is offered, even when another candidate could not answer now: confirming is
	// the user's way forward. Without one, a candidate that could not answer (a timeout, a 5xx) may be the right one:
	// try again soon rather than telling the user there is none.
	if unconfirmed != "" {
		return Account{}, &ConfirmError{URL: unconfirmed}
	}
	if temporary != nil {
		return Account{}, temporary
	}
	return Account{}, fmt.Errorf("%w (%v)", ErrNotCalDAV, lastErr)
}

// knownServers maps IMAP hosts of providers whose CalDAV address cannot be discovered from the mail domain.
var knownServers = map[string]string{
	"imap.mail.me.com":      "https://caldav.icloud.com/",
	"imap.fastmail.com":     "https://caldav.fastmail.com/",
	"imap.mail.yahoo.com":   "https://caldav.calendar.yahoo.com/",
	"imap.aol.com":          "https://caldav.aol.com/",
	"imap.mailbox.org":      "https://dav.mailbox.org/",
	"posteo.de":             "https://posteo.de:8443/",
	"imap.zoho.com":         "https://calendar.zoho.com/caldav/",
	"imap.zoho.eu":          "https://calendar.zoho.eu/caldav/",
	"imap.gmx.net":          "https://caldav.gmx.net/",
	"imap.gmx.com":          "https://caldav.gmx.com/",
	"imap.web.de":           "https://caldav.web.de/",
	"imap.yandex.com":       "https://caldav.yandex.com/",
	"mail.runbox.com":       "https://dav.runbox.com/",
	"imap.purelymail.com":   "https://purelymail.com/webdav/",
	"imap.migadu.com":       "https://cdav.migadu.com/",
	"imap.one.com":          "https://caldav.one.com/",
	"mail.infomaniak.com":   "https://sync.infomaniak.com/",
	"imap.kolabnow.com":     "https://apps.kolabnow.com/",
	"imap.strato.de":        "https://dav.strato.de/",
	"imap.ionos.de":         "https://caldav.ionos.de/",
	"imap.ionos.com":        "https://caldav.ionos.com/",
	"imap.transip.email":    "https://dav.transip.email/",
	"imap.tutanota.com":     "",
	"outlook.office365.com": "",
	"imap.gmail.com":        "",
	"imap-mail.outlook.com": "",
}

// Candidates lists the context URLs to try, most specific first, without duplicates.
func Candidates(ctx context.Context, emailAddress, imapHost string, resolver *net.Resolver) []string {
	var candidates []string
	add := func(candidate string) {
		for _, existing := range candidates {
			if existing == candidate {
				return
			}
		}
		candidates = append(candidates, candidate)
	}

	domain := ""
	if at := strings.LastIndex(emailAddress, "@"); at >= 0 {
		domain = strings.ToLower(emailAddress[at+1:])
	}
	imapHost = strings.ToLower(strings.TrimSuffix(imapHost, "."))
	if server, known := knownServers[imapHost]; known {
		if server == "" {
			// Providers without CalDAV for third parties (Google and Microsoft sync through their own APIs).
			return nil
		}
		add(server)
	}
	if domain != "" && resolver != nil {
		if _, records, err := resolver.LookupSRV(ctx, "caldavs", "tcp", domain); err == nil {
			path := "/.well-known/caldav"
			if texts, err := resolver.LookupTXT(ctx, "_caldavs._tcp."+domain); err == nil {
				for _, text := range texts {
					if strings.HasPrefix(text, "path=") && strings.HasPrefix(text[5:], "/") {
						path = text[5:]
					}
				}
			}
			for _, record := range records {
				target := strings.TrimSuffix(record.Target, ".")
				if target == "" {
					continue
				}
				host := target
				if record.Port != 443 {
					host = net.JoinHostPort(target, fmt.Sprint(record.Port))
				}
				add("https://" + host + path)
			}
		}
	}
	if domain != "" {
		add("https://" + domain + "/.well-known/caldav")
	}
	if imapHost != "" {
		if site, err := siteOf(imapHost); err == nil && site != domain {
			add("https://" + site + "/.well-known/caldav")
		}
		add("https://" + imapHost + "/.well-known/caldav")
	}
	return candidates
}

// IsTemporary tells whether an error may go away by itself: a timeout or a server error (5xx, 429).
func IsTemporary(err error) bool {
	var status *StatusError
	if errors.As(err, &status) {
		return status.Status >= 500 || status.Status == http.StatusTooManyRequests
	}
	var netErr net.Error
	return errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &netErr) && netErr.Timeout())
}
