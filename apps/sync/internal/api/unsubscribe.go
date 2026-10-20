package api

import (
	"context"
	"net/url"
)

// UnsubscribeDone is the outcome of an unsubscribe request the sender's server accepted, besides OutcomeRetry and
// OutcomeFailed.
const UnsubscribeDone = "done"

// UnsubscribeRequest is a one-click unsubscribe (RFC 8058) the user asked for: a POST to URL, the https address of the
// message's List-Unsubscribe header.
type UnsubscribeRequest struct {
	ID  string `json:"id"`
	URL string `json:"url"`
}

// ClaimUnsubscribes takes the account's unsubscribe requests to send. A claim lasts five minutes.
func (c *Client) ClaimUnsubscribes(ctx context.Context, accountID, workerID string) ([]UnsubscribeRequest, error) {
	var out struct {
		Requests []UnsubscribeRequest `json:"requests"`
	}
	err := c.post(ctx, "/internal/sync/accounts/"+url.PathEscape(accountID)+"/unsubscribes/claim", map[string]any{"workerId": workerID}, &out)
	return out.Requests, err
}

// ReportUnsubscribe says how a request went. errorText is shown to the user, so it has to be safe to show.
func (c *Client) ReportUnsubscribe(ctx context.Context, accountID, requestID, workerID, outcome, errorText string) error {
	body := map[string]any{"workerId": workerID, "outcome": outcome}
	if errorText != "" {
		body["error"] = errorText
	}
	err := c.post(ctx, "/internal/sync/accounts/"+url.PathEscape(accountID)+"/unsubscribes/"+url.PathEscape(requestID)+"/report", body, nil)
	return claimError(err)
}
