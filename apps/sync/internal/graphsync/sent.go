package graphsync

import (
	"context"
	"errors"
	"strings"
	"time"
)

// ErrFilesSentItself is returned by SentStore.Append: Graph files mail sent through sendMail in Sent Items by itself.
var ErrFilesSentItself = errors.New("graph files sent mail itself")

// SentStore answers the outbox's question whether an earlier attempt already sent a message, through Graph instead of
// IMAP. It satisfies outbox.SentStore.
type SentStore struct {
	ctx    context.Context
	client *Client
}

// OpenSent returns the mailbox's sent mail lookup; it opens no connection.
func OpenSent(ctx context.Context, client *Client) *SentStore {
	return &SentStore{ctx: ctx, client: client}
}

// Contains reports whether a message with this Message-ID (without angle brackets) is in any folder of the mailbox.
func (s *SentStore) Contains(messageID string) (bool, error) {
	filter := "internetMessageId eq '<" + strings.ReplaceAll(messageID, "'", "''") + ">'"
	var out struct {
		Value []struct {
			ID string `json:"id"`
		} `json:"value"`
	}
	if err := s.client.get(s.ctx, "/me/messages?$select=id&$top=1&$filter="+queryEscape(filter), "", &out); err != nil {
		return false, err
	}
	return len(out.Value) > 0, nil
}

func (s *SentStore) Append([]byte, time.Time) error { return ErrFilesSentItself }

func (s *SentStore) Close() {}
