package graphsync

import (
	"context"
	"errors"
	"net/http"
	"net/url"

	"tresso/sync/internal/providerhttp"
	"tresso/sync/internal/providersend"
)

// maxSendBody is Graph's request size limit; the MIME travels base64 encoded in it. Larger messages would need a draft
// with attachment upload sessions, which Graph only offers for its JSON message format.
const maxSendBody = 4 << 20

// Send delivers raw through Graph by way of a draft: it creates the message from the MIME, marks it with the
// TressoOutgoing extended property (value marker, providersend.OutgoingMarker), and sends the draft. Graph files the
// copy in Sent Items itself, and the property stays on it, so SentStore.SentBefore finds an earlier attempt's copy by
// it even when Exchange replaced the Message-ID and dropped the X- headers. recipients is the complete envelope
// recipient list (the outbox's smtpsend.Envelope.Recipients): those that the To and Cc headers do not name are added
// as Bcc, which Graph delivers to and strips. Failures are *providersend.Error.
//
// A draft whose send was refused (4xx) is deleted again; after a failure that may have sent it (5xx, connection) it
// stays, and the duplicate check of the next attempt finds it: sent, or still a draft, which it deletes.
//
// Send a draft: https://learn.microsoft.com/en-us/graph/api/message-send
//
// The outbox dispatcher sends for accounts of provider "microsoft" through outbox.GraphTransport, which calls Send with
// one Client per run.
func Send(ctx context.Context, client *Client, raw []byte, recipients []string, marker string) error {
	message, err := providersend.WithBcc(raw, recipients)
	var sendErr *providersend.Error
	if errors.As(err, &sendErr) {
		return err
	}
	if err != nil {
		return &providersend.Error{Permanent: true, Message: "The message could not be read.", Err: err}
	}
	id, err := client.createDraft(ctx, message, outgoingProperty, marker)
	if errors.As(err, &sendErr) {
		return err
	}
	if err != nil {
		return providersend.Classify("Outlook", err)
	}
	_, err = client.http.Do(ctx, providerhttp.Request{Method: http.MethodPost, URL: client.base + "/me/messages/" + url.PathEscape(id) + "/send"})
	var statusErr *providerhttp.StatusError
	if errors.As(err, &statusErr) && statusErr.StatusCode >= 400 && statusErr.StatusCode < 500 && statusErr.StatusCode != http.StatusTooManyRequests {
		_ = client.deleteMessage(ctx, id)
	}
	return providersend.Classify("Outlook", mapAccessError(err))
}
