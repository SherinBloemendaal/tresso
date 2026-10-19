package api

import (
	"context"
	"errors"
	"net/http"
	"net/url"
)

// DraftFile is a draft copy to put in the account's Drafts folder, replacing the copy with PreviousMessageID.
type DraftFile struct {
	DraftID           string  `json:"draftId"`
	Version           int     `json:"version"`
	RawObjectKey      string  `json:"rawObjectKey"`
	MessageID         string  `json:"messageId"`
	PreviousMessageID *string `json:"previousMessageId"`
}

// DraftRemoval is a draft copy to take out of the Drafts folder.
type DraftRemoval struct {
	ID        string `json:"id"`
	MessageID string `json:"messageId"`
}

type DraftWork struct {
	Files    []DraftFile    `json:"files"`
	Removals []DraftRemoval `json:"removals"`
}

// ClaimDrafts takes the account's draft copies to file and remove.
func (c *Client) ClaimDrafts(ctx context.Context, accountID, workerID string) (DraftWork, error) {
	var out DraftWork
	err := c.post(ctx, "/internal/sync/accounts/"+url.PathEscape(accountID)+"/drafts/claim", map[string]any{"workerId": workerID}, &out)
	return out, err
}

// ReportDraftFiled says the copy of version is in the Drafts folder and the one before it is gone.
func (c *Client) ReportDraftFiled(ctx context.Context, accountID, draftID, workerID string, version int, messageID string) error {
	err := c.post(ctx, "/internal/sync/accounts/"+url.PathEscape(accountID)+"/drafts/"+url.PathEscape(draftID)+"/filed", map[string]any{"workerId": workerID, "version": version, "messageId": messageID}, nil)
	return claimError(err)
}

// ReportDraftRemoved says the copy is out of the Drafts folder.
func (c *Client) ReportDraftRemoved(ctx context.Context, accountID, removalID, workerID string) error {
	err := c.post(ctx, "/internal/sync/accounts/"+url.PathEscape(accountID)+"/draft-removals/"+url.PathEscape(removalID)+"/done", map[string]any{"workerId": workerID}, nil)
	return claimError(err)
}

func claimError(err error) error {
	var status *HTTPError
	if errors.As(err, &status) && status.Status == http.StatusConflict {
		return errors.Join(ErrClaimLost, err)
	}
	return err
}
