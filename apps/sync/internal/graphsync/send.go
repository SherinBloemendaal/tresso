package graphsync

import (
	"context"
	"encoding/base64"
	"errors"
	"net/http"

	"tresso/sync/internal/providerhttp"
	"tresso/sync/internal/providersend"
)

// maxSendBody is Graph's request size limit for sendMail; the MIME travels base64 encoded in it. Larger messages
// would need a draft with attachment upload sessions, which Graph only offers for its JSON message format.
const maxSendBody = 4 << 20

// Send delivers raw through Graph (POST /me/sendMail with the MIME), which files the copy in Sent Items itself.
// recipients is the complete envelope recipient list (the outbox's smtpsend.Envelope.Recipients): those that the To
// and Cc headers do not name are added as Bcc, which Graph delivers to and strips. Failures are *providersend.Error.
//
// The outbox dispatcher sends for accounts of provider "microsoft" through outbox.GraphTransport, which calls Send with
// one Client per run.
func Send(ctx context.Context, client *Client, raw []byte, recipients []string) error {
	message, err := providersend.WithBcc(raw, recipients)
	var sendErr *providersend.Error
	if errors.As(err, &sendErr) {
		return err
	}
	if err != nil {
		return &providersend.Error{Permanent: true, Message: "The message could not be read.", Err: err}
	}
	body := []byte(base64.StdEncoding.EncodeToString(message))
	if len(body) > maxSendBody {
		return &providersend.Error{Permanent: true, Message: "The message is too large for Outlook, which sends at most 3 MB including attachments.", Err: errors.New("message exceeds the sendMail size limit")}
	}
	_, err = client.http.Do(ctx, providerhttp.Request{Method: http.MethodPost, URL: client.base + "/me/sendMail", ContentType: "text/plain", Body: body})
	return providersend.Classify("Outlook", mapAccessError(err))
}
