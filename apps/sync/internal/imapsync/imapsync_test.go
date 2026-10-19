package imapsync

import (
	"errors"
	"fmt"
	"testing"

	"github.com/emersion/go-imap/v2"

	"tresso/sync/internal/netguard"
)

func TestCursorRoundTrip(t *testing.T) {
	in := Cursor{UIDValidity: 7, UIDNext: 120, BackfillBelow: 40, HighestModSeq: 99}
	encoded := in.Encode()

	out, err := DecodeCursor(&encoded)
	if err != nil || out == nil {
		t.Fatalf("decode: %v %v", out, err)
	}
	in.Version = cursorVersion
	if *out != in {
		t.Errorf("got %+v, want %+v", *out, in)
	}
}

func TestEmptyOrOldCursorRestartsTheFolder(t *testing.T) {
	empty := ""
	old := `{"v":0,"uidNext":5}`
	for _, raw := range []*string{nil, &empty, &old} {
		c, err := DecodeCursor(raw)
		if err != nil || c != nil {
			t.Errorf("DecodeCursor(%v) = %v, %v; want nil, nil", raw, c, err)
		}
	}
	broken := "{not json"
	if _, err := DecodeCursor(&broken); err == nil {
		t.Error("expected an error for a broken cursor")
	}
}

func TestBackfillDone(t *testing.T) {
	if (Cursor{BackfillBelow: 2}).BackfillDone() || !(Cursor{BackfillBelow: 1}).BackfillDone() || !(Cursor{}).BackfillDone() {
		t.Error("BackfillDone is wrong")
	}
}

func TestRoleOf(t *testing.T) {
	cases := []struct {
		name  string
		attrs []imap.MailboxAttr
		want  string
	}{
		{"INBOX", nil, "inbox"},
		{"inbox", nil, "inbox"},
		{"Whatever", []imap.MailboxAttr{imap.MailboxAttrSent}, "sent"},
		{"Bin", []imap.MailboxAttr{"\\trash"}, "trash"},
		{"[Gmail]/All Mail", []imap.MailboxAttr{imap.MailboxAttrAll, imap.MailboxAttrHasNoChildren}, "all"},
		{"INBOX.Sent Items", nil, "sent"},
		{"Verzonden items", nil, "sent"},
		{"Ongewenste e-mail", nil, "spam"},
		{"Projects/Acme", nil, "custom"},
	}
	for _, c := range cases {
		if got := roleOf(c.name, c.attrs); got != c.want {
			t.Errorf("roleOf(%q, %v) = %q, want %q", c.name, c.attrs, got, c.want)
		}
	}
}

func TestDisplayName(t *testing.T) {
	if displayName("INBOX", '/') != "Inbox" || displayName("Projects/Acme", '/') != "Acme" || displayName("INBOX.Sent", '.') != "Sent" || displayName("Flat", 0) != "Flat" {
		t.Error("displayName is wrong")
	}
}

func TestUserFacingDetailNeverLeaksServerText(t *testing.T) {
	cases := map[error]string{
		fmt.Errorf("%w: [AUTHENTICATIONFAILED] secret server text", ErrAuth): "The mail server rejected the username or password.",
		fmt.Errorf("dial: %w", netguard.ErrBlockedAddress):                   "The mail server address is not allowed.",
		errors.New("something internal with a token=abc"):                    "Syncing failed. Tresso keeps retrying.",
	}
	for err, want := range cases {
		if got := userFacingDetail(err); got != want {
			t.Errorf("userFacingDetail(%v) = %q, want %q", err, got, want)
		}
	}
}

func TestJunkKeywords(t *testing.T) {
	cases := []struct {
		flags []imap.Flag
		want  bool
	}{
		{nil, false},
		{[]imap.Flag{imap.FlagSeen, "$Junk"}, true},
		{[]imap.Flag{"Junk"}, true},
		{[]imap.Flag{"$Junk", "$NotJunk"}, false},
		{[]imap.Flag{"NonJunk", "$Junk"}, false},
	}
	for _, c := range cases {
		if got := isJunk(c.flags); got != c.want {
			t.Errorf("isJunk(%v) = %v, want %v", c.flags, got, c.want)
		}
	}
}
