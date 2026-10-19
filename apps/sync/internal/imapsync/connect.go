package imapsync

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"net"
	"strconv"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapclient"

	"tresso/sync/internal/api"
	"tresso/sync/internal/netguard"
)

// ErrAuth means the server refused the credentials; retrying will not help until the user changes them.
var ErrAuth = errors.New("authentication failed")

// Dialer opens connections; the netguard dialer refuses private addresses.
type Dialer interface {
	DialContext(ctx context.Context, address string) (net.Conn, error)
}

// connect dials through the guarded dialer, sets up TLS or STARTTLS, waits for the greeting and logs in. updates
// receives a signal whenever the server reports something unasked (new mail, expunges, flag changes).
func connect(ctx context.Context, d Dialer, server api.ServerSettings, username, password string, updates chan<- struct{}) (*imapclient.Client, error) {
	conn, err := d.DialContext(ctx, net.JoinHostPort(server.Host, strconv.Itoa(server.Port)))
	if err != nil {
		return nil, err
	}

	notify := func() {
		select {
		case updates <- struct{}{}:
		default:
		}
	}
	options := &imapclient.Options{
		TLSConfig: &tls.Config{ServerName: server.Host, MinVersion: tls.VersionTLS12},
		UnilateralDataHandler: &imapclient.UnilateralDataHandler{
			Mailbox: func(data *imapclient.UnilateralDataMailbox) {
				if data.NumMessages != nil {
					notify()
				}
			},
			Expunge: func(uint32) { notify() },
			Fetch: func(msg *imapclient.FetchMessageData) {
				// The data must be read completely, or the connection stalls.
				_, _ = msg.Collect()
				notify()
			},
		},
	}

	var client *imapclient.Client
	switch server.Security {
	case "tls":
		tlsConn := tls.Client(conn, options.TLSConfig)
		if err := tlsConn.HandshakeContext(ctx); err != nil {
			_ = conn.Close()
			return nil, fmt.Errorf("tls handshake: %w", err)
		}
		client = imapclient.New(tlsConn, options)
	case "starttls":
		client, err = imapclient.NewStartTLS(conn, options)
		if err != nil {
			_ = conn.Close()
			return nil, fmt.Errorf("starttls: %w", err)
		}
	case "none":
		client = imapclient.New(conn, options)
	default:
		_ = conn.Close()
		return nil, fmt.Errorf("unknown connection security %q", server.Security)
	}

	// Close the connection when the context ends, so a lost lease interrupts blocking commands.
	stop := context.AfterFunc(ctx, func() { _ = client.Close() })

	if err := client.WaitGreeting(); err != nil {
		stop()
		_ = client.Close()
		return nil, fmt.Errorf("greeting: %w", err)
	}
	if err := client.Login(username, password).Wait(); err != nil {
		stop()
		_ = client.Close()
		var imapErr *imap.Error
		if errors.As(err, &imapErr) && (imapErr.Type == imap.StatusResponseTypeNo || imapErr.Code == imap.ResponseCodeAuthenticationFailed) {
			return nil, fmt.Errorf("%w: %s", ErrAuth, imapErr.Text)
		}
		return nil, fmt.Errorf("login: %w", err)
	}
	return client, nil
}

// userFacingDetail turns an error into a short message the user can act on. Never server output or secrets.
func userFacingDetail(err error) string {
	var netErr net.Error
	switch {
	case errors.Is(err, ErrAuth):
		return "The mail server rejected the username or password."
	case errors.Is(err, netguard.ErrBlockedAddress):
		return "The mail server address is not allowed."
	case errors.As(err, &netErr):
		return "Could not connect to the mail server."
	default:
		var tlsErr *tls.CertificateVerificationError
		if errors.As(err, &tlsErr) {
			return "The mail server's security certificate could not be verified."
		}
		return "Syncing failed. Tresso keeps retrying."
	}
}
