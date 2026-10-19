// Package smtpsend delivers one message to the account's SMTP server and says whether a failure is worth retrying.
package smtpsend

import (
	"bytes"
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"net"
	"strconv"
	"strings"
	"time"

	"github.com/emersion/go-sasl"
	"github.com/emersion/go-smtp"

	"tresso/sync/internal/api"
	"tresso/sync/internal/netguard"
)

// Dialer opens connections; the netguard dialer refuses private addresses.
type Dialer interface {
	DialContext(ctx context.Context, address string) (net.Conn, error)
}

// Error is a failed delivery: Permanent when the server refused for good (a 5xx reply), so sending again would fail
// the same way. Message is safe to show the user.
type Error struct {
	Permanent bool
	Message   string
	Err       error
}

func (e *Error) Error() string { return e.Message + ": " + e.Err.Error() }
func (e *Error) Unwrap() error { return e.Err }

// Envelope is who the message is from and to, as the SMTP server sees it. Bcc recipients are only here.
type Envelope struct {
	From       string
	Recipients []string
}

const commandTimeout = 2 * time.Minute

// Send connects, authenticates and delivers raw to every recipient. Either every recipient is accepted or nothing is
// sent: a refused recipient aborts the transaction, so the user can correct the address and send it again.
func Send(ctx context.Context, d Dialer, server api.ServerSettings, username, password string, envelope Envelope, raw []byte) error {
	conn, err := d.DialContext(ctx, net.JoinHostPort(server.Host, strconv.Itoa(server.Port)))
	if err != nil {
		return temporary("Could not connect to the outgoing mail server.", err)
	}
	stop := context.AfterFunc(ctx, func() { _ = conn.Close() })
	defer stop()
	_ = conn.SetDeadline(time.Now().Add(commandTimeout))

	tlsConfig := &tls.Config{ServerName: server.Host, MinVersion: tls.VersionTLS12}
	var client *smtp.Client
	switch server.Security {
	case "tls":
		tlsConn := tls.Client(conn, tlsConfig)
		if err := tlsConn.HandshakeContext(ctx); err != nil {
			_ = conn.Close()
			return classify("The outgoing mail server's security certificate could not be verified.", err)
		}
		client = smtp.NewClient(tlsConn)
	case "starttls":
		client, err = smtp.NewClientStartTLS(conn, tlsConfig)
		if err != nil {
			_ = conn.Close()
			return classify("Could not set up a secure connection to the outgoing mail server.", err)
		}
	case "none":
		client = smtp.NewClient(conn)
	default:
		_ = conn.Close()
		return &Error{Permanent: true, Message: "The outgoing mail server settings are invalid.", Err: fmt.Errorf("unknown security %q", server.Security)}
	}
	defer client.Close()

	if err := client.Hello("localhost"); err != nil {
		return classify("The outgoing mail server did not accept the connection.", err)
	}
	if err := authenticate(client, username, password); err != nil {
		return err
	}
	if err := client.Mail(envelope.From, nil); err != nil {
		return classify(fmt.Sprintf("The outgoing mail server refused the sender %s.", envelope.From), err)
	}
	for _, recipient := range envelope.Recipients {
		if err := client.Rcpt(recipient, nil); err != nil {
			_ = client.Reset()
			return classify(fmt.Sprintf("The outgoing mail server refused the recipient %s.", recipient), err)
		}
	}
	writer, err := client.Data()
	if err != nil {
		return classify("The outgoing mail server refused the message.", err)
	}
	if _, err := writer.Write(normalizeLineEndings(raw)); err != nil {
		_ = writer.Close()
		return temporary("The connection to the outgoing mail server broke while sending.", err)
	}
	if err := writer.Close(); err != nil {
		return classify("The outgoing mail server refused the message.", err)
	}
	_ = client.Quit()
	return nil
}

// authenticate prefers PLAIN, which every server that offers authentication supports over TLS, and falls back to
// LOGIN for the servers that only list that.
func authenticate(client *smtp.Client, username, password string) error {
	ok, mechanisms := client.Extension("AUTH")
	if !ok {
		// A server that offers no authentication; sending may still work (for example inside a trusted network).
		return nil
	}
	var mechanism sasl.Client
	switch {
	case hasWord(mechanisms, "PLAIN"):
		mechanism = sasl.NewPlainClient("", username, password)
	case hasWord(mechanisms, "LOGIN"):
		mechanism = sasl.NewLoginClient(username, password)
	default:
		return &Error{Permanent: true, Message: "The outgoing mail server offers no sign-in method Tresso supports.", Err: fmt.Errorf("AUTH %s", mechanisms)}
	}
	if err := client.Auth(mechanism); err != nil {
		var smtpErr *smtp.SMTPError
		if errors.As(err, &smtpErr) && (smtpErr.Code == 535 || smtpErr.Code == 534) {
			// Not permanent: once the user fixes the password, the next attempt goes through.
			return &Error{Permanent: false, Message: "The outgoing mail server rejected the username or password.", Err: err}
		}
		return classify("Signing in to the outgoing mail server failed.", err)
	}
	return nil
}

func classify(message string, err error) error {
	var smtpErr *smtp.SMTPError
	if errors.As(err, &smtpErr) {
		detail := strings.TrimSpace(smtpErr.Message)
		if detail != "" {
			message = fmt.Sprintf("%s The server said: %d %s", message, smtpErr.Code, truncate(detail, 200))
		}
		return &Error{Permanent: smtpErr.Code >= 500, Message: message, Err: err}
	}
	if errors.Is(err, netguard.ErrBlockedAddress) {
		return &Error{Permanent: true, Message: "The outgoing mail server address is not allowed.", Err: err}
	}
	var certErr *tls.CertificateVerificationError
	if errors.As(err, &certErr) {
		return &Error{Permanent: false, Message: "The outgoing mail server's security certificate could not be verified.", Err: err}
	}
	return temporary(message, err)
}

func temporary(message string, err error) error {
	return &Error{Permanent: false, Message: message, Err: err}
}

func hasWord(list, word string) bool {
	for _, field := range strings.Fields(list) {
		if strings.EqualFold(field, word) {
			return true
		}
	}
	return false
}

func truncate(s string, max int) string {
	if len(s) <= max {
		return s
	}
	return s[:max] + "…"
}

// normalizeLineEndings makes every line end in CRLF, as SMTP requires; the DATA writer handles dot-stuffing.
func normalizeLineEndings(raw []byte) []byte {
	normalized := bytes.ReplaceAll(raw, []byte("\r\n"), []byte("\n"))
	return bytes.ReplaceAll(normalized, []byte("\n"), []byte("\r\n"))
}
