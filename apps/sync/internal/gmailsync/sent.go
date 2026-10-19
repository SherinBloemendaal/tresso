package gmailsync

import (
	"context"
	"errors"
	"net/url"
	"time"
)

// ErrFilesSentItself is returned by SentStore.Append: Gmail files mail sent through its API in Sent by itself.
var ErrFilesSentItself = errors.New("gmail files sent mail itself")

// SentStore answers the outbox's question whether an earlier attempt already sent a message, through the Gmail API
// instead of IMAP. It satisfies outbox.SentStore.
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

func (s *SentStore) Append([]byte, time.Time) error { return ErrFilesSentItself }

func (s *SentStore) Close() {}
