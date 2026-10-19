package gmailsync

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"strings"
	"time"
	"unicode"

	"tresso/sync/internal/api"
	"tresso/sync/internal/mimeparse"
	"tresso/sync/internal/providersend"
)

// ErrFilesSentItself is returned by SentStore.Append: Gmail files mail sent through its API in Sent by itself.
var ErrFilesSentItself = errors.New("gmail files sent mail itself")

// SentStore answers the outbox's question whether an earlier attempt already sent a message, through the Gmail API
// instead of IMAP. It satisfies outbox.SentStore and outbox.OutgoingFinder.
type SentStore struct {
	ctx    context.Context
	client *Client
}

// OpenSent returns the account's sent mail lookup; it opens no connection.
func OpenSent(ctx context.Context, client *Client) *SentStore {
	return &SentStore{ctx: ctx, client: client}
}

// Contains reports whether a message with this Message-ID (without angle brackets) is in the mailbox, wherever it was
// moved since, Trash and Spam included.
func (s *SentStore) Contains(messageID string) (bool, error) {
	query := url.Values{"q": {"rfc822msgid:" + messageID}, "includeSpamTrash": {"true"}, "maxResults": {"1"}}
	var out struct {
		Messages []struct {
			ID string `json:"id"`
		} `json:"messages"`
	}
	if err := s.client.get(s.ctx, "/messages", query, costList, &out); err != nil {
		return false, err
	}
	return len(out.Messages) > 0, nil
}

// SentBefore reports whether an earlier attempt sent message. Gmail may give a sent message a Message-ID of its own,
// so after the Message-ID the mail sent since since (the earliest attempt) to the message's first recipient with its
// subject, Trash and Spam included, is read for the marker the transport put in providersend.OutgoingIDHeader. Gmail
// search cannot match custom headers, so the recipient and the subject are what keeps that set small: usually the one
// copy. The whole set is read, however large. An error means the question stays open, never "not sent".
func (s *SentStore) SentBefore(message api.OutgoingMessage, raw []byte, since time.Time) (bool, error) {
	if found, err := s.Contains(message.MessageID); err != nil || found {
		return found, err
	}
	// from:me, not in:sent: a sent copy the user moved to Trash keeps matching (with includeSpamTrash), where in:sent
	// would not find it.
	q := fmt.Sprintf("from:me after:%d", since.Unix())
	if len(message.Recipients) > 0 {
		r := searchValue(message.Recipients[0])
		q += fmt.Sprintf(` {to:"%s" cc:"%s" bcc:"%s"}`, r, r, r)
	}
	if subject := subjectOf(raw); subject != "" {
		q += fmt.Sprintf(` subject:"%s"`, subject)
	}
	query := url.Values{"q": {q}, "includeSpamTrash": {"true"}}
	found, err := s.client.findByHeader(s.ctx, query, providersend.OutgoingIDHeader, providersend.OutgoingMarker(message.ID, message.MessageID))
	if err != nil {
		return false, mapAccessError(err)
	}
	return len(found) > 0, nil
}

// subjectOf returns raw's decoded subject, made safe for a quoted Gmail search term. Gmail matches a subject by its
// words, so a subject without a letter or digit could match nothing: then "" (the search goes by recipient only), as
// for a message without a subject.
func subjectOf(raw []byte) string {
	parsed, err := mimeparse.Parse(raw)
	if err != nil {
		return ""
	}
	subject := strings.TrimSpace(searchValue(parsed.Subject))
	if !strings.ContainsFunc(subject, func(r rune) bool { return unicode.IsLetter(r) || unicode.IsDigit(r) }) {
		return ""
	}
	return subject
}

// searchValue makes an address safe inside a quoted Gmail search term.
func searchValue(s string) string {
	return strings.NewReplacer(`"`, "", `\`, "", "{", "", "}", "").Replace(s)
}

func (s *SentStore) Append([]byte, time.Time) error { return ErrFilesSentItself }

func (s *SentStore) Close() {}
