package smtpsend

import (
	"context"
	"errors"
	"io"
	"net"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/emersion/go-sasl"
	"github.com/emersion/go-smtp"

	"tresso/sync/internal/api"
)

type delivery struct {
	from       string
	recipients []string
	data       string
}

type backend struct {
	mu         sync.Mutex
	deliveries []delivery
}

func (b *backend) NewSession(*smtp.Conn) (smtp.Session, error) {
	return &session{backend: b}, nil
}

type session struct {
	backend *backend
	authed  bool
	current delivery
}

func (s *session) AuthMechanisms() []string { return []string{sasl.Plain} }

func (s *session) Auth(string) (sasl.Server, error) {
	return sasl.NewPlainServer(func(_, username, password string) error {
		if username != "kim@company.example" || password != "secret" {
			return &smtp.SMTPError{Code: 535, EnhancedCode: smtp.EnhancedCode{5, 7, 8}, Message: "Invalid credentials"}
		}
		s.authed = true
		return nil
	}), nil
}

func (s *session) Mail(from string, _ *smtp.MailOptions) error {
	if !s.authed {
		return smtp.ErrAuthRequired
	}
	s.current = delivery{from: from}
	return nil
}

func (s *session) Rcpt(to string, _ *smtp.RcptOptions) error {
	switch {
	case strings.HasPrefix(to, "nobody@"):
		return &smtp.SMTPError{Code: 550, EnhancedCode: smtp.EnhancedCode{5, 1, 1}, Message: "No such user"}
	case strings.HasPrefix(to, "busy@"):
		return &smtp.SMTPError{Code: 451, EnhancedCode: smtp.EnhancedCode{4, 3, 0}, Message: "Try again later"}
	}
	s.current.recipients = append(s.current.recipients, to)
	return nil
}

func (s *session) Data(r io.Reader) error {
	data, err := io.ReadAll(r)
	if err != nil {
		return err
	}
	s.current.data = string(data)
	s.backend.mu.Lock()
	s.backend.deliveries = append(s.backend.deliveries, s.current)
	s.backend.mu.Unlock()
	return nil
}

func (s *session) Reset()        { s.current = delivery{} }
func (s *session) Logout() error { return nil }

type plainDialer struct{}

func (plainDialer) DialContext(ctx context.Context, address string) (net.Conn, error) {
	var d net.Dialer
	return d.DialContext(ctx, "tcp", address)
}

func startServer(t *testing.T) (*backend, api.ServerSettings) {
	t.Helper()
	b := &backend{}
	server := smtp.NewServer(b)
	server.Domain = "localhost"
	server.AllowInsecureAuth = true
	server.ReadTimeout = 5 * time.Second
	server.WriteTimeout = 5 * time.Second
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	go func() { _ = server.Serve(listener) }()
	t.Cleanup(func() { _ = server.Close() })
	host, port, _ := net.SplitHostPort(listener.Addr().String())
	portNumber, _ := strconv.Atoi(port)
	return b, api.ServerSettings{Host: host, Port: portNumber, Security: "none"}
}

const raw = "From: Kim <kim@company.example>\nTo: Anna <anna@finance.example>\nSubject: Hi\n\nHello\n.leading dot\n"

func TestDeliversToEveryEnvelopeRecipient(t *testing.T) {
	b, server := startServer(t)

	err := Send(context.Background(), plainDialer{}, server, "kim@company.example", "secret", Envelope{From: "kim@company.example", Recipients: []string{"anna@finance.example", "archive@company.example"}}, []byte(raw))
	if err != nil {
		t.Fatal(err)
	}

	if len(b.deliveries) != 1 {
		t.Fatalf("deliveries = %d", len(b.deliveries))
	}
	got := b.deliveries[0]
	if got.from != "kim@company.example" || !slices.Equal(got.recipients, []string{"anna@finance.example", "archive@company.example"}) {
		t.Errorf("envelope = %+v", got)
	}
	if !strings.Contains(got.data, "Subject: Hi\r\n\r\nHello\r\n.leading dot\r\n") {
		t.Errorf("data = %q (lines must end in CRLF, a leading dot must survive)", got.data)
	}
}

func TestWrongPasswordIsRetriedNotFailed(t *testing.T) {
	_, server := startServer(t)

	err := Send(context.Background(), plainDialer{}, server, "kim@company.example", "wrong", Envelope{From: "kim@company.example", Recipients: []string{"anna@finance.example"}}, []byte(raw))

	var sendErr *Error
	if !errors.As(err, &sendErr) || sendErr.Permanent || sendErr.Message != "The outgoing mail server rejected the username or password." {
		t.Fatalf("err = %v", err)
	}
}

func TestRefusedRecipientFailsTheWholeMessage(t *testing.T) {
	b, server := startServer(t)

	err := Send(context.Background(), plainDialer{}, server, "kim@company.example", "secret", Envelope{From: "kim@company.example", Recipients: []string{"anna@finance.example", "nobody@finance.example"}}, []byte(raw))

	var sendErr *Error
	if !errors.As(err, &sendErr) || !sendErr.Permanent {
		t.Fatalf("err = %v", err)
	}
	if !strings.Contains(sendErr.Message, "refused the recipient nobody@finance.example") || !strings.Contains(sendErr.Message, "550 No such user") {
		t.Errorf("message = %q", sendErr.Message)
	}
	if len(b.deliveries) != 0 {
		t.Errorf("something was delivered: %+v", b.deliveries)
	}
}

func TestTemporaryRefusalIsRetried(t *testing.T) {
	_, server := startServer(t)

	err := Send(context.Background(), plainDialer{}, server, "kim@company.example", "secret", Envelope{From: "kim@company.example", Recipients: []string{"busy@finance.example"}}, []byte(raw))

	var sendErr *Error
	if !errors.As(err, &sendErr) || sendErr.Permanent {
		t.Fatalf("err = %v", err)
	}
}

func TestUnreachableServerIsRetried(t *testing.T) {
	listener, _ := net.Listen("tcp", "127.0.0.1:0")
	address := listener.Addr().(*net.TCPAddr)
	_ = listener.Close()

	err := Send(context.Background(), plainDialer{}, api.ServerSettings{Host: "127.0.0.1", Port: address.Port, Security: "none"}, "u", "p", Envelope{From: "a@b.example", Recipients: []string{"c@d.example"}}, []byte(raw))

	var sendErr *Error
	if !errors.As(err, &sendErr) || sendErr.Permanent || sendErr.Message != "Could not connect to the outgoing mail server." {
		t.Fatalf("err = %v", err)
	}
}
