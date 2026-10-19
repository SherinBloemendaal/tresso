// Package ingestmsg turns a raw message into what the ingest API takes. It is shared by every provider: the raw MIME
// and its attachments go to object storage, the API gets the metadata.
package ingestmsg

import (
	"context"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/mimeparse"
	"tresso/sync/internal/storage"
)

// Build stores raw and its attachments, then describes the message for the ingest API. remoteUID is the provider's id
// of the message in the folder; receivedAt is when the provider received it (zero falls back to the Date header,
// then to now).
func Build(ctx context.Context, store storage.Store, raw []byte, remoteUID string, receivedAt time.Time, flags api.Flags) (api.Message, error) {
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

	if receivedAt.IsZero() {
		if parsed.Date != nil {
			receivedAt = *parsed.Date
		} else {
			receivedAt = time.Now()
		}
	}

	msg := api.Message{
		RemoteUID:    remoteUID,
		RawObjectKey: storage.RawKey(sha),
		Size:         int64(len(raw)),
		ReceivedAt:   receivedAt.UTC(),
		SentAt:       parsed.Date,
		Flags:        flags,
		References:   limit(parsed.References, 200),
		Subject:      truncate(parsed.Subject, 998),
		From:         address(parsed.From),
		To:           addresses(parsed.To, 1000),
		Cc:           addresses(parsed.Cc, 1000),
		Bcc:          addresses(parsed.Bcc, 1000),
		ReplyTo:      addresses(parsed.ReplyTo, 100),
		Snippet:      parsed.Snippet,
		BodyText:     parsed.BodyText,
		Attachments:  attachments,
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

// Truncate shortens s to at most max characters (runes), for the API's length limits.
func Truncate(s string, max int) string {
	return truncate(s, max)
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
