package imapsync

import (
	"context"
	"fmt"
	"strconv"
	"strings"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapclient"

	"tresso/sync/internal/api"
	"tresso/sync/internal/mimeparse"
	"tresso/sync/internal/storage"
)

// buildMessage stores the raw message and its attachments, then describes it for the ingest API.
func buildMessage(ctx context.Context, store storage.Store, buf *imapclient.FetchMessageBuffer, condStore bool) (api.Message, error) {
	raw := buf.FindBodySection(&imap.FetchItemBodySection{Peek: true})
	if raw == nil {
		return api.Message{}, fmt.Errorf("uid %d: server returned no body", buf.UID)
	}

	sha := storage.SHA256(raw)
	if err := store.PutIfAbsent(ctx, storage.RawKey(sha), raw, "message/rfc822"); err != nil {
		return api.Message{}, err
	}

	parsed, err := mimeparse.Parse(raw)
	if err != nil {
		// Not parseable as mail at all: keep it with what the server knows.
		parsed = &mimeparse.Message{From: mimeparse.Address{Address: mimeparse.UnknownSender}}
	}

	attachments := make([]api.Attachment, 0, len(parsed.Attachments))
	for _, a := range parsed.Attachments {
		if err := store.PutIfAbsent(ctx, storage.AttachmentKey(a.SHA256), a.Data, a.ContentType); err != nil {
			return api.Message{}, err
		}
		attachment := api.Attachment{Filename: a.Filename, ContentType: a.ContentType, Size: a.Size, SHA256: a.SHA256, Inline: a.Inline}
		if a.ContentID != "" {
			contentID := a.ContentID
			attachment.ContentID = &contentID
		}
		attachments = append(attachments, attachment)
	}

	receivedAt := buf.InternalDate
	if receivedAt.IsZero() {
		if parsed.Date != nil {
			receivedAt = *parsed.Date
		} else {
			receivedAt = time.Now()
		}
	}

	msg := api.Message{
		RemoteUID:    strconv.FormatUint(uint64(buf.UID), 10),
		RawObjectKey: storage.RawKey(sha),
		Size:         int64(len(raw)),
		ReceivedAt:   receivedAt.UTC(),
		SentAt:       parsed.Date,
		Flags:        flagsOf(buf.Flags),
		References:   limit(parsed.References, 200),
		Subject:      truncate(parsed.Subject, 998),
		From:         address(parsed.From),
		To:           addresses(parsed.To, 1000),
		Cc:           addresses(parsed.Cc, 1000),
		Bcc:          addresses(parsed.Bcc, 1000),
		ReplyTo:      addresses(parsed.ReplyTo, 100),
		Snippet:      parsed.Snippet,
		Attachments:  attachments,
	}
	if condStore && buf.ModSeq > 0 {
		modSeq := buf.ModSeq
		msg.ModSeq = &modSeq
	}
	if parsed.MessageID != "" {
		id := truncate(parsed.MessageID, 998)
		msg.MessageID = &id
	}
	if parsed.InReplyTo != "" {
		id := truncate(parsed.InReplyTo, 998)
		msg.InReplyTo = &id
	}
	return msg, nil
}

func flagsOf(flags []imap.Flag) api.Flags {
	var f api.Flags
	for _, flag := range flags {
		switch strings.ToLower(string(flag)) {
		case strings.ToLower(string(imap.FlagSeen)):
			f.Seen = true
		case strings.ToLower(string(imap.FlagFlagged)):
			f.Flagged = true
		case strings.ToLower(string(imap.FlagAnswered)):
			f.Answered = true
		case strings.ToLower(string(imap.FlagDraft)):
			f.Draft = true
		}
	}
	return f
}

func address(a mimeparse.Address) api.Address {
	out := api.Address{Address: truncate(a.Address, 320)}
	if a.Name != "" {
		name := truncate(a.Name, 255)
		out.Name = &name
	}
	return out
}

// addresses applies the API's per-header limit.
func addresses(list []mimeparse.Address, max int) []api.Address {
	out := make([]api.Address, 0, len(list))
	for _, a := range limit(list, max) {
		out = append(out, address(a))
	}
	return out
}

func truncate(s string, max int) string {
	runes := []rune(s)
	if len(runes) <= max {
		return s
	}
	return string(runes[:max])
}

func limit[T any](s []T, max int) []T {
	if len(s) > max {
		return s[:max]
	}
	return s
}
