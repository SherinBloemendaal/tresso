package gmailsync

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"strings"
	"time"

	"tresso/sync/internal/api"
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
// so after the Message-ID the mail sent to the message's first recipient since since (the earliest attempt) is looked
// through for the marker the transport put in providersend.OutgoingIDHeader. Only that narrow set is read, so a busy
// account costs a few requests, not one per message it sent. An error means the question stays open, never "not
// sent".
func (s *SentStore) SentBefore(message api.OutgoingMessage, since time.Time) (bool, error) {
	if found, err := s.Contains(message.MessageID); err != nil || found {
		return found, err
	}
	q := fmt.Sprintf("in:sent after:%d", since.Unix())
	if len(message.Recipients) > 0 {
		r := searchValue(message.Recipients[0])
		q += fmt.Sprintf(` {to:"%s" cc:"%s" bcc:"%s"}`, r, r, r)
	}
	query := url.Values{"q": {q}, "includeSpamTrash": {"true"}}
	found, complete, err := s.client.findByHeader(s.ctx, query, providersend.OutgoingIDHeader, providersend.OutgoingMarker(message.ID, message.MessageID))
	switch {
	case err != nil:
		return false, mapAccessError(err)
	case len(found) > 0:
		return true, nil
	case !complete:
		return false, fmt.Errorf("%w: more than %d sent messages to check", errUndecided, maxScan)
	}
	return false, nil
}

// searchValue makes an address safe inside a quoted Gmail search term.
func searchValue(s string) string {
	return strings.NewReplacer(`"`, "", `\`, "", "{", "", "}", "").Replace(s)
}

func (s *SentStore) Append([]byte, time.Time) error { return ErrFilesSentItself }

func (s *SentStore) Close() {}
