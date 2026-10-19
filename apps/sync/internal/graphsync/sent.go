package graphsync

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/providerhttp"
	"tresso/sync/internal/providersend"
)

// ErrFilesSentItself is returned by SentStore.Append: Graph files mail sent through sendMail in Sent Items by itself.
var ErrFilesSentItself = errors.New("graph files sent mail itself")

// SentStore answers the outbox's question whether an earlier attempt already sent a message, through Graph instead of
// IMAP. It satisfies outbox.SentStore and outbox.OutgoingFinder.
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

// SentBefore reports whether an earlier attempt sent message. Nothing promises that Exchange keeps the Message-ID of
// a message sent as MIME, so after the Message-ID the Sent Items sent to the message's first recipient since since
// (the earliest attempt) are looked through for the marker the transport put in providersend.OutgoingIDHeader. Should
// Graph refuse the recipient filter, all Sent Items since since are read instead. An error means the question stays
// open, never "not sent".
func (s *SentStore) SentBefore(message api.OutgoingMessage, since time.Time) (bool, error) {
	if found, err := s.Contains(message.MessageID); err != nil || found {
		return found, err
	}
	marker := providersend.OutgoingMarker(message.ID, message.MessageID)
	filter := "sentDateTime ge " + graphTime(since)
	narrowed := filter
	if len(message.Recipients) > 0 {
		address := strings.ReplaceAll(message.Recipients[0], "'", "''")
		var any []string
		for _, field := range []string{"toRecipients", "ccRecipients", "bccRecipients"} {
			any = append(any, field+"/any(r:r/emailAddress/address eq '"+address+"')")
		}
		narrowed += " and (" + strings.Join(any, " or ") + ")"
	}
	found, complete, err := s.client.findByHeader(s.ctx, "sentitems", narrowed, providersend.OutgoingIDHeader, marker)
	if providerhttp.IsStatus(err, http.StatusBadRequest) && narrowed != filter {
		found, complete, err = s.client.findByHeader(s.ctx, "sentitems", filter, providersend.OutgoingIDHeader, marker)
	}
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

func (s *SentStore) Append([]byte, time.Time) error { return ErrFilesSentItself }

func (s *SentStore) Close() {}
