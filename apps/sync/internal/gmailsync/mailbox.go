package gmailsync

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/url"
	"slices"
	"strings"

	"tresso/sync/internal/api"
	"tresso/sync/internal/providerhttp"
)

// ErrMessageGone means the message is no longer in the folder a move was to take it from.
var ErrMessageGone = errors.New("the message is no longer in the folder")

// Mailbox makes the user's changes to messages through the Gmail API. Folders are labels, so a change is a label
// change: read is UNREAD removed, flagged is STARRED added, a move adds the target label and removes the source's. It
// satisfies outbox.MailboxStore.
//
// The next history round sees the same label changes. By then the API has moved the message's location itself, so
// the round finds the target known (a flag update, no download) and the source already empty; nothing is undone.
type Mailbox struct {
	ctx    context.Context
	client *Client
	// applied remembers flag changes made in this run: a message in several folders is one Gmail message.
	applied map[string]bool
}

// OpenMailbox returns the account's mailbox for changes; it opens no connection.
func OpenMailbox(ctx context.Context, client *Client) *Mailbox {
	return &Mailbox{ctx: ctx, client: client, applied: map[string]bool{}}
}

// SetFlags marks the message read or unread and flagged or not (nil leaves a flag alone). A message that is gone
// needs nothing.
func (m *Mailbox) SetFlags(location api.MailboxLocation, seen, flagged *bool) error {
	var add, remove []string
	if seen != nil {
		if *seen {
			remove = append(remove, labelUnread)
		} else {
			add = append(add, labelUnread)
		}
	}
	if flagged != nil {
		if *flagged {
			add = append(add, labelStarred)
		} else {
			remove = append(remove, labelStarred)
		}
	}
	if len(add)+len(remove) == 0 {
		return nil
	}
	key := location.RemoteUID + "+" + strings.Join(add, ",") + "-" + strings.Join(remove, ",")
	if m.applied[key] {
		return nil
	}
	err := m.modify(location.RemoteUID, add, remove)
	if providerhttp.IsStatus(err, http.StatusNotFound) {
		return nil
	}
	if err == nil {
		m.applied[key] = true
	}
	return err
}

// Move takes the message out of the source folder and into the target, and returns its id: a Gmail message keeps
// its id in every folder.
//
// The target All Mail (tresso:all) means archiving: only the source label goes (Inbox, for the archive button). Trash
// uses messages.trash; a message leaving Trash is untrashed first. Spam is a label like any other.
func (m *Mailbox) Move(source api.MailboxLocation, target, _ string) (string, error) {
	id := source.RemoteUID
	msg, err := m.client.getMessage(m.ctx, id, "minimal")
	if providerhttp.IsStatus(err, http.StatusNotFound) {
		return "", ErrMessageGone
	}
	if err != nil {
		return "", err
	}
	if !inFolder(msg.LabelIDs, source.Folder) {
		return "", ErrMessageGone
	}
	if target == source.Folder {
		return id, nil
	}

	if source.Folder == labelTrash {
		if err := m.post(id, "untrash"); err != nil {
			return "", err
		}
	}
	var add, remove []string
	if source.Folder != allMailFolder && source.Folder != labelTrash {
		remove = append(remove, source.Folder)
	}
	switch target {
	case labelTrash:
		if err := m.post(id, "trash"); err != nil {
			return "", err
		}
	case allMailFolder:
	default:
		add = append(add, target)
	}
	if len(add)+len(remove) > 0 {
		if err := m.modify(id, add, remove); err != nil {
			return "", err
		}
	}
	return id, nil
}

// inFolder reports whether a message with these labels is in the folder.
func inFolder(labelIDs []string, folder string) bool {
	if folder == allMailFolder {
		return !slices.Contains(labelIDs, labelTrash) && !slices.Contains(labelIDs, labelSpam) && !slices.Contains(labelIDs, labelChat)
	}
	return slices.Contains(labelIDs, folder)
}

func (m *Mailbox) modify(id string, add, remove []string) error {
	body, err := json.Marshal(map[string][]string{"addLabelIds": nonNil(add), "removeLabelIds": nonNil(remove)})
	if err != nil {
		return err
	}
	return mapAccessError(m.client.http.JSON(m.ctx, providerhttp.Request{
		Method: http.MethodPost, URL: m.client.base + "/messages/" + url.PathEscape(id) + "/modify", ContentType: "application/json", Body: body, Cost: costGet,
	}, nil))
}

// post calls messages.trash or messages.untrash.
func (m *Mailbox) post(id, action string) error {
	return mapAccessError(m.client.http.JSON(m.ctx, providerhttp.Request{
		Method: http.MethodPost, URL: m.client.base + "/messages/" + url.PathEscape(id) + "/" + action, Cost: costGet,
	}, nil))
}

func (m *Mailbox) Close() {}

func nonNil(s []string) []string {
	if s == nil {
		return []string{}
	}
	return s
}
