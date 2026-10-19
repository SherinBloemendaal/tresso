package providersend

import (
	"errors"
	"fmt"
	"net/http"
	"testing"

	"tresso/sync/internal/api"
	"tresso/sync/internal/providerhttp"
	"tresso/sync/internal/providersync"
)

func TestWithBccAddsOnlyEnvelopeOnlyRecipients(t *testing.T) {
	raw := []byte("From: kim@example.test\r\nTo: Anna <anna@example.test>\r\nCc: bo@example.test\r\nSubject: Hi\r\n\r\nBcc: not a header\r\n")
	out, err := WithBcc(raw, []string{"anna@example.test", "Bo@Example.test", "x@example.test", "x@example.test"})
	if err != nil {
		t.Fatal(err)
	}
	want := "Bcc: <x@example.test>\r\n" + string(raw)
	if string(out) != want {
		t.Errorf("got %q\nwant %q", out, want)
	}

	same, err := WithBcc(raw, []string{"anna@example.test"})
	if err != nil || string(same) != string(raw) {
		t.Errorf("unchanged message was rewritten: %q, %v", same, err)
	}
}

func TestWithBccMergesAnExistingFoldedBccHeader(t *testing.T) {
	raw := []byte("To: anna@example.test\nBcc: Old <old@example.test>,\n other@example.test\nSubject: Hi\n\nBody\n")
	out, err := WithBcc(raw, []string{"anna@example.test", "old@example.test", "new@example.test"})
	if err != nil {
		t.Fatal(err)
	}
	want := "Bcc: \"Old\" <old@example.test>,\n <other@example.test>,\n <new@example.test>\nTo: anna@example.test\nSubject: Hi\n\nBody\n"
	if string(out) != want {
		t.Errorf("got %q\nwant %q", out, want)
	}
}

func TestClassify(t *testing.T) {
	status := func(code int, message string, reasons ...string) error {
		return &providerhttp.StatusError{Method: http.MethodPost, URL: "u", StatusCode: code, Message: message, Reasons: reasons}
	}
	cases := []struct {
		err       error
		permanent bool
		message   string
	}{
		{status(400, "Invalid To header"), true, "Gmail refused the message. Gmail said: Invalid To header"},
		// Before, every 403 became the permanent "does not allow Tresso to send mail", whatever the provider said.
		{status(403, "Mail service not enabled", "failedPrecondition"), true, "Gmail cannot send mail from this account. Gmail said: failedPrecondition: Mail service not enabled"},
		{status(403, "Delegation denied", "domainPolicy"), true, "Your organization's Gmail policy does not allow sending this message. Gmail said: domainPolicy: Delegation denied"},
		{status(403, "", "insufficientPermissions"), false, "Gmail does not allow Tresso to send mail. Sign in again and allow sending mail."},
		{status(403, "The user account which was used to submit this request does not have the right to send mail on behalf of the specified sending account.", "ErrorSendAsDenied"), true, "Gmail does not allow this account to send as the sender address of this message."},
		{status(400, "", "ErrorMessageSizeExceeded"), true, "The message is too large for Gmail."},
		{status(403, "Something unusual happened.", "someNewReason"), true, "Gmail refused the message. Gmail said: someNewReason: Something unusual happened."},
		{status(403, ""), true, "Gmail refused the message."},
		{status(413, ""), true, "The message is too large for Gmail."},
		{status(429, ""), false, "Gmail is limiting how much mail Tresso sends right now."},
		{status(502, ""), false, "Gmail could not send the message right now."},
		{fmt.Errorf("%w: x", providerhttp.ErrUnauthorized), false, "Tresso lost access to your Gmail account. Sign in again to send."},
		{fmt.Errorf("%w: x", api.ErrReauthorize), false, "Tresso lost access to your Gmail account. Sign in again to send."},
		{fmt.Errorf("%w: %w", providersync.ErrAccessDenied, status(403, "")), false, "Gmail does not allow Tresso to send mail. Sign in again and allow sending mail."},
		{errors.New("dial tcp: refused"), false, "Sending through Gmail failed."},
	}
	for _, c := range cases {
		var sendErr *Error
		if !errors.As(Classify("Gmail", c.err), &sendErr) || sendErr.Permanent != c.permanent || sendErr.Message != c.message {
			t.Errorf("Classify(%v) = %+v", c.err, sendErr)
		}
	}
	if Classify("Gmail", nil) != nil {
		t.Error("nil must stay nil")
	}
}

func TestWithBccRefusesInvalidRecipients(t *testing.T) {
	raw := []byte("To: anna@example.test\r\n\r\nBody\r\n")
	for _, recipient := range []string{"evil@example.test\r\nX-Injected: yes", "evil@example.test\nBcc: other@example.test", "not an address", "Name <a@example.test>", "a@example.test, b@example.test"} {
		_, err := WithBcc(raw, []string{recipient})
		var sendErr *Error
		if !errors.As(err, &sendErr) || !sendErr.Permanent || sendErr.Message != "A recipient address is not valid." {
			t.Errorf("WithBcc(%q) = %v", recipient, err)
		}
	}
}

func TestSetHeaderReplacesAndValidates(t *testing.T) {
	raw := []byte("X-Tresso-Outgoing-Id: old\r\n folded\r\nSubject: Hi\r\n\r\nX-Tresso-Outgoing-Id: body\r\n")
	out, err := SetHeader(raw, OutgoingIDHeader, "o-1")
	if err != nil {
		t.Fatal(err)
	}
	if string(out) != "X-Tresso-Outgoing-Id: o-1\r\nSubject: Hi\r\n\r\nX-Tresso-Outgoing-Id: body\r\n" {
		t.Errorf("got %q", out)
	}
	if HeaderValue(out, "x-tresso-outgoing-id") != "o-1" || HeaderValue(raw, OutgoingIDHeader) != "old folded" || HeaderValue(out, "Missing") != "" {
		t.Error("HeaderValue is wrong")
	}
	for _, value := range []string{"", "a\r\nBcc: x@example.test", "a\nb"} {
		if _, err := SetHeader(raw, OutgoingIDHeader, value); err == nil {
			t.Errorf("SetHeader accepted %q", value)
		}
	}
}
