// Package providersend holds what sending through a provider's REST API (Gmail, Microsoft Graph) needs on top of the
// HTTP call: Bcc recipients in the message itself, and errors the outbox can act on.
package providersend

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/mail"
	"strings"

	"tresso/sync/internal/api"
	"tresso/sync/internal/mimeparse"
	"tresso/sync/internal/providerhttp"
	"tresso/sync/internal/providersync"
)

// Error is a failed send: Permanent when the provider refused the message for good, so sending it again would fail
// the same way. Message is safe to show the user. It has the shape of smtpsend.Error, so the outbox treats every
// provider alike.
type Error struct {
	Permanent bool
	Message   string
	Err       error
}

func (e *Error) Error() string { return e.Message + ": " + e.Err.Error() }
func (e *Error) Unwrap() error { return e.Err }

// Classify turns the error of a send request into an Error. provider is the name users know ("Gmail", "Outlook").
//
// Refusals (4xx) are permanent, except 429 (throttled) and a refused access token: once the user signed in again, the
// next attempt goes through. 5xx and network errors are temporary.
func Classify(provider string, err error) error {
	if err == nil {
		return nil
	}
	if errors.Is(err, api.ErrReauthorize) || errors.Is(err, providerhttp.ErrUnauthorized) {
		return &Error{Message: fmt.Sprintf("Tresso lost access to your %s account. Sign in again to send.", provider), Err: err}
	}
	// A token without the mail scope: the message itself is fine and goes out once the user signed in again and
	// allowed sending mail.
	if errors.Is(err, providersync.ErrAccessDenied) {
		return &Error{Message: fmt.Sprintf("%s does not allow Tresso to send mail. Sign in again and allow sending mail.", provider), Err: err}
	}
	if errors.Is(err, context.Canceled) || errors.Is(err, context.DeadlineExceeded) {
		return &Error{Message: fmt.Sprintf("Sending through %s was interrupted.", provider), Err: err}
	}

	var statusErr *providerhttp.StatusError
	if errors.As(err, &statusErr) {
		switch code := statusErr.StatusCode; {
		case statusErr.HasReason("insufficientPermissions") || statusErr.HasReason("ACCESS_TOKEN_SCOPE_INSUFFICIENT") || statusErr.HasReason("ErrorAccessDenied"):
			// The token lacks the right to send; it goes out once the user signed in again and allowed sending.
			return &Error{Message: fmt.Sprintf("%s does not allow Tresso to send mail. Sign in again and allow sending mail.", provider), Err: err}
		case statusErr.HasReason("ErrorSendAsDenied"):
			return &Error{Permanent: true, Message: fmt.Sprintf("%s does not allow this account to send as the sender address of this message.", provider), Err: err}
		case statusErr.HasReason("ErrorMessageSizeExceeded") || code == http.StatusRequestEntityTooLarge:
			return &Error{Permanent: true, Message: fmt.Sprintf("The message is too large for %s.", provider), Err: err}
		case statusErr.HasReason("domainPolicy"):
			return &Error{Permanent: true, Message: withDetail(fmt.Sprintf("Your organization's %s policy does not allow sending this message.", provider), provider, statusErr), Err: err}
		case statusErr.HasReason("failedPrecondition") || statusErr.HasReason("FAILED_PRECONDITION"):
			return &Error{Permanent: true, Message: withDetail(fmt.Sprintf("%s cannot send mail from this account.", provider), provider, statusErr), Err: err}
		case code == http.StatusTooManyRequests:
			return &Error{Message: fmt.Sprintf("%s is limiting how much mail Tresso sends right now.", provider), Err: err}
		case code >= 500:
			return &Error{Message: fmt.Sprintf("%s could not send the message right now.", provider), Err: err}
		default:
			// Any other refusal, a 403 Tresso does not know included: say what the provider said, so the user can act.
			return &Error{Permanent: true, Message: withDetail(fmt.Sprintf("%s refused the message.", provider), provider, statusErr), Err: err}
		}
	}

	var netErr net.Error
	if errors.As(err, &netErr) {
		return &Error{Message: fmt.Sprintf("Could not connect to %s.", provider), Err: err}
	}
	return &Error{Message: fmt.Sprintf("Sending through %s failed.", provider), Err: err}
}

// withDetail adds what the provider said (its error code and message, trimmed) to a user-facing message.
func withDetail(message, provider string, statusErr *providerhttp.StatusError) string {
	var parts []string
	if len(statusErr.Reasons) > 0 {
		parts = append(parts, statusErr.Reasons[0])
	}
	if detail := strings.TrimSpace(statusErr.Message); detail != "" {
		parts = append(parts, truncate(detail, 200))
	}
	if len(parts) == 0 {
		return message
	}
	return message + " " + provider + " said: " + strings.Join(parts, ": ")
}

// WithBcc returns raw with a Bcc header naming every envelope recipient that the To, Cc and Bcc headers do not list
// yet. The outbox keeps Bcc recipients out of the headers and only puts them on the SMTP envelope; Gmail and Graph
// take the recipients from the headers instead, deliver to Bcc and strip the header before the message leaves.
//
// recipients is the complete envelope recipient list. raw is returned unchanged when nothing is missing. A recipient
// that is not a plain address (a line break would inject a header) fails with a permanent *Error.
func WithBcc(raw []byte, recipients []string) ([]byte, error) {
	addresses := make([]string, 0, len(recipients))
	for _, recipient := range recipients {
		address, err := recipientAddress(recipient)
		if err != nil {
			return nil, err
		}
		addresses = append(addresses, address)
	}

	parsed, err := mimeparse.Parse(raw)
	if err != nil {
		return nil, fmt.Errorf("read message headers: %w", err)
	}
	listed := map[string]bool{}
	for _, list := range [][]mimeparse.Address{parsed.To, parsed.Cc, parsed.Bcc} {
		for _, a := range list {
			listed[strings.ToLower(a.Address)] = true
		}
	}

	var missing []string
	for _, address := range addresses {
		key := strings.ToLower(address)
		if listed[key] {
			continue
		}
		listed[key] = true
		missing = append(missing, address)
	}
	if len(missing) == 0 {
		return raw, nil
	}

	newline := []byte("\r\n")
	if !bytes.Contains(raw[:headerEnd(raw)], newline) {
		newline = []byte("\n")
	}

	// A message has at most one Bcc header: an existing one is replaced by one that lists its addresses as well.
	all := make([]string, 0, len(parsed.Bcc)+len(missing))
	for _, a := range parsed.Bcc {
		all = append(all, (&mail.Address{Name: a.Name, Address: a.Address}).String())
	}
	for _, address := range missing {
		all = append(all, (&mail.Address{Address: address}).String())
	}

	header := []byte("Bcc: " + strings.Join(all, ","+string(newline)+" "))
	header = append(header, newline...)
	return append(header, withoutHeader(raw, "Bcc")...), nil
}

// recipientAddress checks an envelope recipient: one bare address, nothing else.
func recipientAddress(recipient string) (string, error) {
	invalid := func(err error) error {
		return &Error{Permanent: true, Message: "A recipient address is not valid.", Err: err}
	}
	if strings.ContainsAny(recipient, "\r\n") {
		return "", invalid(errors.New("recipient contains a line break"))
	}
	parsed, err := mail.ParseAddress(recipient)
	if err != nil {
		return "", invalid(fmt.Errorf("recipient %q: %w", recipient, err))
	}
	if parsed.Name != "" || parsed.Address != strings.Trim(strings.TrimSpace(recipient), "<>") {
		return "", invalid(fmt.Errorf("recipient %q is not a bare address", recipient))
	}
	return parsed.Address, nil
}

// Headers the worker adds to what it hands Gmail and Graph, so it can find its own copies again: both providers may
// replace the Message-ID of a message they send, and nothing promises they keep it on a draft.
const (
	// OutgoingIDHeader carries the outbox message's id on a sent message.
	OutgoingIDHeader = "X-Tresso-Outgoing-Id"
	// DraftCopyHeader carries the Message-ID a draft copy was composed with, one per version of a draft.
	DraftCopyHeader = "X-Tresso-Draft-Copy"
)

// SetHeader returns raw with exactly one header field name, set to value; an existing one is replaced.
func SetHeader(raw []byte, name, value string) ([]byte, error) {
	if value == "" || strings.ContainsAny(value, "\r\n") || len(value) > 900 {
		return nil, fmt.Errorf("invalid value for header %s", name)
	}
	newline := "\r\n"
	if !bytes.Contains(raw[:headerEnd(raw)], []byte("\r\n")) {
		newline = "\n"
	}
	return append([]byte(name+": "+value+newline), withoutHeader(raw, name)...), nil
}

// HeaderValue returns the value of the first header field name in raw (unfolded and trimmed), or "".
func HeaderValue(raw []byte, name string) string {
	end := headerEnd(raw)
	lines := strings.Split(strings.ReplaceAll(string(raw[:end]), "\r\n", "\n"), "\n")
	for i, line := range lines {
		colon := strings.IndexByte(line, ':')
		if colon <= 0 || line[0] == ' ' || line[0] == '\t' || !strings.EqualFold(strings.TrimSpace(line[:colon]), name) {
			continue
		}
		value := line[colon+1:]
		for _, next := range lines[i+1:] {
			if next == "" || (next[0] != ' ' && next[0] != '\t') {
				break
			}
			value += " " + strings.TrimSpace(next)
		}
		return strings.TrimSpace(value)
	}
	return ""
}

// headerEnd is the offset of the blank line that ends the header section, or len(raw) without a body.
func headerEnd(raw []byte) int {
	for i := 0; i < len(raw); {
		end := bytes.IndexByte(raw[i:], '\n')
		if end < 0 {
			return len(raw)
		}
		line := bytes.TrimRight(raw[i:i+end], "\r")
		if len(line) == 0 {
			return i
		}
		i += end + 1
	}
	return len(raw)
}

// withoutHeader removes every occurrence of the header field name, including its folded continuation lines.
func withoutHeader(raw []byte, name string) []byte {
	end := headerEnd(raw)
	out := make([]byte, 0, len(raw))
	skipping := false
	for i := 0; i < end; {
		next := bytes.IndexByte(raw[i:end], '\n')
		lineEnd := end
		if next >= 0 {
			lineEnd = i + next + 1
		}
		line := raw[i:lineEnd]
		continuation := len(line) > 0 && (line[0] == ' ' || line[0] == '\t')
		if !continuation {
			colon := bytes.IndexByte(line, ':')
			skipping = colon > 0 && strings.EqualFold(strings.TrimSpace(string(line[:colon])), name)
		}
		if !skipping {
			out = append(out, line...)
		}
		i = lineEnd
	}
	return append(out, raw[end:]...)
}

func truncate(s string, max int) string {
	runes := []rune(s)
	if len(runes) <= max {
		return s
	}
	return string(runes[:max]) + "..."
}
