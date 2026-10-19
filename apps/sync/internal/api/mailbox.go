package api

import (
	"context"
	"net/url"
)

// Kinds of mailbox change.
const (
	MailboxFlags = "flags"
	MailboxMove  = "move"
)

// MailboxDone reports a mailbox change the server made; OutcomeRetry and OutcomeFailed report the others.
const MailboxDone = "done"

// MailboxLocation is one copy of a message on the server. The uid only counts while the folder's UIDVALIDITY is
// UIDValidity.
type MailboxLocation struct {
	Folder      string  `json:"folder"`
	UIDValidity *uint32 `json:"uidValidity"`
	RemoteUID   string  `json:"remoteUid"`
}

type MailboxTarget struct {
	Folder string `json:"folder"`
}

// MailboxChange is a change the user made in Tresso that the server still has to make: a flag change sets Seen and/or
// Flagged (nil leaves a flag alone) on every copy in Locations; a move moves the copy in Source to Target.
type MailboxChange struct {
	ID        string            `json:"id"`
	Kind      string            `json:"kind"`
	Seen      *bool             `json:"seen"`
	Flagged   *bool             `json:"flagged"`
	Locations []MailboxLocation `json:"locations"`
	Source    *MailboxLocation  `json:"source"`
	Target    *MailboxTarget    `json:"target"`
}

// ClaimMailbox takes the account's changes to make on the server, in the order they have to be made.
func (c *Client) ClaimMailbox(ctx context.Context, accountID, workerID string) ([]MailboxChange, error) {
	var out struct {
		Changes []MailboxChange `json:"changes"`
	}
	err := c.post(ctx, "/internal/sync/accounts/"+url.PathEscape(accountID)+"/mailbox-changes/claim", map[string]any{"workerId": workerID}, &out)
	return out.Changes, err
}

// ReportMailbox says how a change went. remoteUID is the moved copy's uid in the target folder, when the server said.
func (c *Client) ReportMailbox(ctx context.Context, accountID, changeID, workerID, outcome, errorText, remoteUID string) error {
	body := map[string]any{"workerId": workerID, "outcome": outcome}
	if errorText != "" {
		body["error"] = errorText
	}
	if remoteUID != "" {
		body["remoteUid"] = remoteUID
	}
	err := c.post(ctx, "/internal/sync/accounts/"+url.PathEscape(accountID)+"/mailbox-changes/"+url.PathEscape(changeID)+"/report", body, nil)
	return claimError(err)
}
