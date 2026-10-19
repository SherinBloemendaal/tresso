package api

import (
	"context"
	"errors"
	"net/http"
	"net/url"
	"time"
)

// OutgoingMessage is mail the worker must send for an account it leases. When Attempt is above 1 an earlier attempt
// may already have reached the server: check the Sent folder for MessageID before sending again.
type OutgoingMessage struct {
	ID           string   `json:"id"`
	RawObjectKey string   `json:"rawObjectKey"`
	EnvelopeFrom string   `json:"envelopeFrom"`
	Recipients   []string `json:"recipients"`
	MessageID    string   `json:"messageId"`
	Attempt      int      `json:"attempt"`
}

// Outcomes of sending a message.
const (
	OutcomeSent   = "sent"
	OutcomeRetry  = "retry"
	OutcomeFailed = "failed"
)

// ErrClaimLost means another worker took over the message, or it is no longer being sent; drop it.
var ErrClaimLost = errors.New("claim on the outgoing message was lost")

// RealtimeAccess lets the worker listen to the outbox topic on the hub.
type RealtimeAccess struct {
	HubURL    string    `json:"hubUrl"`
	Topic     string    `json:"topic"`
	Token     string    `json:"token"`
	ExpiresAt time.Time `json:"expiresAt"`
}

// ClaimOutbox takes the account's mail that is due; nobody else sends it while the claim lasts.
func (c *Client) ClaimOutbox(ctx context.Context, accountID, workerID string) ([]OutgoingMessage, error) {
	var out struct {
		Messages []OutgoingMessage `json:"messages"`
	}
	err := c.post(ctx, "/internal/sync/accounts/"+url.PathEscape(accountID)+"/outbox/claim", map[string]any{"workerId": workerID}, &out)
	return out.Messages, err
}

// ReportOutbox tells the API how sending went. errorText is shown to the user.
func (c *Client) ReportOutbox(ctx context.Context, accountID, messageID, workerID, outcome, errorText string, copiedToSent bool) error {
	body := map[string]any{"workerId": workerID, "outcome": outcome, "copiedToSent": copiedToSent}
	if errorText != "" {
		body["error"] = errorText
	}
	err := c.post(ctx, "/internal/sync/accounts/"+url.PathEscape(accountID)+"/outbox/"+url.PathEscape(messageID)+"/result", body, nil)
	var status *HTTPError
	if errors.As(err, &status) && status.Status == http.StatusConflict {
		return errors.Join(ErrClaimLost, err)
	}
	return err
}

// RealtimeToken returns access to the outbox topic.
func (c *Client) RealtimeToken(ctx context.Context) (RealtimeAccess, error) {
	var out RealtimeAccess
	err := c.do(ctx, http.MethodGet, "/internal/sync/realtime-token", nil, &out)
	return out, err
}
