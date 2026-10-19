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
	ctx     context.Context
	client  *Client
	folders map[string]string // well-known folder ids
}

// OpenSent returns the mailbox's sent mail lookup; it opens no connection.
func OpenSent(ctx context.Context, client *Client) *SentStore {
	return &SentStore{ctx: ctx, client: client}
}

// Contains reports whether a sent copy with this Message-ID (without angle brackets) is in the mailbox, in any folder
// but Drafts and the Outbox, Trash included.
func (s *SentStore) Contains(messageID string) (bool, error) {
	copies, err := s.byMessageID(messageID)
	if err != nil {
		return false, err
	}
	for _, m := range copies {
		if s.isSentCopy(m) {
			return true, nil
		}
	}
	return false, nil
}

func (s *SentStore) byMessageID(messageID string) ([]markedMessage, error) {
	filter := "internetMessageId eq '<" + strings.ReplaceAll(messageID, "'", "''") + ">'"
	found, err := s.client.listMarked(s.ctx, "/me/messages", filter)
	return found, mapAccessError(err)
}

// draftGrace is how old a leftover draft of an earlier attempt must be before it counts as stopped. Exchange accepts a
// draft's send (202) and sends it a little later; until then the item is still a draft, in Drafts or in the Outbox.
const draftGrace = 15 * time.Minute

// SentBefore reports whether an earlier attempt sent message.
//
// Exchange may keep neither the Message-ID nor the X- headers of a message sent as MIME, so Send marks the draft it
// sends with the TressoOutgoing extended property, which stays on the Sent Items copy: one filtered request finds it.
// Copies sent before that, or whose draft could not be marked, are found by Message-ID, then by the marker in
// providersend.OutgoingIDHeader on the Sent Items sent to the first recipient since since (the earliest attempt);
// should Graph refuse the recipient filter, all Sent Items since since are read. Drafts never count as sent.
//
// An earlier attempt may also have left its draft: marked, or with our Message-ID. One in Drafts that has not changed
// for draftGrace stopped before it was sent: it is deleted, and the message is not sent (false). A younger one, or one
// in the Outbox, may still be on its way: providersend.ErrMaybeSending, and the next attempt looks again. Any other
// error leaves the question open as well, never "not sent".
func (s *SentStore) SentBefore(message api.OutgoingMessage, _ []byte, since time.Time) (bool, error) {
	marker := providersend.OutgoingMarker(message.ID, message.MessageID)

	marked, err := s.client.findByProperty(s.ctx, "/me/messages", outgoingProperty, marker)
	if err != nil {
		return false, mapAccessError(err)
	}
	byID, err := s.byMessageID(message.MessageID)
	if err != nil {
		return false, err
	}
	drafts := map[string]markedMessage{}
	for _, m := range append(marked, byID...) {
		if s.isSentCopy(m) {
			return true, nil
		}
		if m.IsDraft {
			drafts[m.ID] = m
		}
	}

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
	found, err := s.client.findByHeader(s.ctx, "sentitems", narrowed, providersend.OutgoingIDHeader, marker)
	if providerhttp.IsStatus(err, http.StatusBadRequest) && narrowed != filter {
		found, err = s.client.findByHeader(s.ctx, "sentitems", filter, providersend.OutgoingIDHeader, marker)
	}
	if err != nil {
		return false, mapAccessError(err)
	}
	if len(found) > 0 {
		return true, nil
	}

	return false, s.clearLeftovers(drafts)
}

// clearLeftovers deletes an earlier attempt's drafts that stopped before being sent, or says it is too early to tell.
func (s *SentStore) clearLeftovers(drafts map[string]markedMessage) error {
	if len(drafts) == 0 {
		return nil
	}
	draftsFolder, err := s.folderID("drafts")
	if err != nil {
		return err
	}
	for _, d := range drafts {
		if d.ParentFolderID != draftsFolder || time.Since(d.LastModified) < draftGrace {
			return fmt.Errorf("%w (draft %s)", providersend.ErrMaybeSending, d.ID)
		}
	}
	for _, d := range drafts {
		if err := s.client.deleteMessage(s.ctx, d.ID); err != nil {
			return fmt.Errorf("%w: deleting the leftover draft: %w", providersend.ErrNotDecided, err)
		}
	}
	return nil
}

// isSentCopy reports whether m is a message that went out: not a draft, not in Drafts or the Outbox.
func (s *SentStore) isSentCopy(m markedMessage) bool {
	if m.IsDraft {
		return false
	}
	for _, name := range []string{"drafts", "outbox"} {
		if id, err := s.folderID(name); err == nil && id != "" && m.ParentFolderID == id {
			return false
		}
	}
	return true
}

// folderID returns the id of a well-known folder ("" when the mailbox has none), asked once.
func (s *SentStore) folderID(name string) (string, error) {
	if id, ok := s.folders[name]; ok {
		return id, nil
	}
	id, err := s.client.wellKnownID(s.ctx, name)
	if err != nil {
		return "", mapAccessError(err)
	}
	if s.folders == nil {
		s.folders = map[string]string{}
	}
	s.folders[name] = id
	return id, nil
}

func (s *SentStore) Append([]byte, time.Time) error { return ErrFilesSentItself }

func (s *SentStore) Close() {}
