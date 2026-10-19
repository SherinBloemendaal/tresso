package gmailsync

import (
	"context"
	"errors"

	"tresso/sync/internal/providersend"
)

// Send delivers raw through Gmail, which files the sent copy in Sent itself. recipients is the complete envelope
// recipient list (the outbox's smtpsend.Envelope.Recipients): those that the To and Cc headers do not name are added
// as Bcc, which Gmail delivers to and strips. A reply goes into the conversation of the message it answers.
// Failures are *providersend.Error.
//
// The outbox dispatcher sends for accounts of provider "gmail" through outbox.GmailTransport, which calls Send with one
// Client per run and marks the message with providersend.OutgoingIDHeader first.
func Send(ctx context.Context, client *Client, raw []byte, recipients []string) error {
	message, err := providersend.WithBcc(raw, recipients)
	var sendErr *providersend.Error
	if errors.As(err, &sendErr) {
		return err
	}
	if err != nil {
		return &providersend.Error{Permanent: true, Message: "The message could not be read.", Err: err}
	}
	threadID, err := client.replyThread(ctx, message)
	if err != nil {
		return providersend.Classify("Gmail", mapAccessError(err))
	}
	req, err := client.messageRequest("/messages/send", message, threadID, costSend)
	if err != nil {
		return &providersend.Error{Permanent: true, Message: "The message could not be read.", Err: err}
	}
	return providersend.Classify("Gmail", mapAccessError(client.http.JSON(ctx, req, nil)))
}
