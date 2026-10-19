package graphsync

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/url"

	"tresso/sync/internal/api"
	"tresso/sync/internal/providerhttp"
)

// ErrMessageGone means the message is no longer in the folder a move was to take it from.
var ErrMessageGone = errors.New("the message is no longer in the folder")

// Mailbox makes the user's changes to messages through Graph: flags with PATCH, moves with the move action. It
// satisfies outbox.MailboxStore.
//
// Immutable ids stay the same across a move, so the moved copy's remote uid in the target is the id it had. The next
// delta round reports the move as a removal in the source and an addition in the target; the API has moved the
// location already, so the addition is a known message (a flag update, no download) and the removal changes nothing.
type Mailbox struct {
	ctx    context.Context
	client *Client
}

// OpenMailbox returns the mailbox for changes; it opens no connection.
func OpenMailbox(ctx context.Context, client *Client) *Mailbox {
	return &Mailbox{ctx: ctx, client: client}
}

// SetFlags marks the message read or unread and flagged or not (nil leaves a flag alone). A message that is gone
// needs nothing.
func (m *Mailbox) SetFlags(location api.MailboxLocation, seen, flagged *bool) error {
	patch := map[string]any{}
	if seen != nil {
		patch["isRead"] = *seen
	}
	if flagged != nil {
		status := "notFlagged"
		if *flagged {
			status = "flagged"
		}
		patch["flag"] = map[string]string{"flagStatus": status}
	}
	if len(patch) == 0 {
		return nil
	}
	body, err := json.Marshal(patch)
	if err != nil {
		return err
	}
	_, err = m.client.http.Do(m.ctx, providerhttp.Request{
		Method: http.MethodPatch, URL: m.client.base + "/me/messages/" + url.PathEscape(location.RemoteUID), ContentType: "application/json", Body: body,
	})
	if providerhttp.IsStatus(err, http.StatusNotFound) {
		return nil
	}
	return mapAccessError(err)
}

// Move moves the message from the source folder to the target folder (a folder id) and returns its id there.
func (m *Mailbox) Move(source api.MailboxLocation, target, _ string) (string, error) {
	msg, err := m.client.message(m.ctx, source.RemoteUID)
	if providerhttp.IsStatus(err, http.StatusNotFound) {
		return "", ErrMessageGone
	}
	if err != nil {
		return "", err
	}
	if msg.ParentFolderID != source.Folder {
		return "", ErrMessageGone
	}
	if target == source.Folder {
		return msg.ID, nil
	}

	body, err := json.Marshal(map[string]string{"destinationId": target})
	if err != nil {
		return "", err
	}
	var moved struct {
		ID string `json:"id"`
	}
	err = m.client.http.JSON(m.ctx, providerhttp.Request{
		Method: http.MethodPost, URL: m.client.base + "/me/messages/" + url.PathEscape(source.RemoteUID) + "/move", ContentType: "application/json", Body: body,
	}, &moved)
	if providerhttp.IsStatus(err, http.StatusNotFound) {
		return "", ErrMessageGone
	}
	if err != nil {
		return "", mapAccessError(err)
	}
	if moved.ID == "" {
		moved.ID = source.RemoteUID
	}
	return moved.ID, nil
}

func (m *Mailbox) Close() {}
