package gmailsync

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net/http"

	"tresso/sync/internal/providerhttp"
	"tresso/sync/internal/providersend"
)

// uploadThreshold is the raw size above which a message is uploaded as media instead of inside a JSON body, which
// Gmail limits to a few megabytes. Media uploads take messages up to Gmail's 35 MB limit.
const uploadThreshold = 4 << 20

// Send delivers raw through Gmail, which files the sent copy in Sent itself. recipients is the complete envelope
// recipient list (the outbox's smtpsend.Envelope.Recipients): those that the To and Cc headers do not name are added
// as Bcc, which Gmail delivers to and strips. Failures are *providersend.Error.
//
// The outbox dispatcher sends for accounts of provider "gmail" through outbox.GmailTransport, which calls Send with one
// Client per run.
func Send(ctx context.Context, client *Client, raw []byte, recipients []string) error {
	message, err := providersend.WithBcc(raw, recipients)
	var sendErr *providersend.Error
	if errors.As(err, &sendErr) {
		return err
	}
	if err != nil {
		return &providersend.Error{Permanent: true, Message: "The message could not be read.", Err: err}
	}

	req := providerhttp.Request{Method: http.MethodPost, Cost: costSend}
	if len(message) > uploadThreshold {
		req.URL = client.root + "/upload/gmail/v1/users/me/messages/send?uploadType=media"
		req.ContentType = "message/rfc822"
		req.Body = message
	} else {
		body, err := json.Marshal(map[string]string{"raw": base64.URLEncoding.EncodeToString(message)})
		if err != nil {
			return &providersend.Error{Permanent: true, Message: "The message could not be read.", Err: err}
		}
		req.URL = client.base + "/messages/send"
		req.ContentType = "application/json"
		req.Body = body
	}
	return providersend.Classify("Gmail", mapAccessError(client.http.JSON(ctx, req, nil)))
}
