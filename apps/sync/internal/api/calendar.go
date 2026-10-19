package api

import (
	"context"
	"net/url"
)

// RemoteCalendar is one calendar collection at the provider.
type RemoteCalendar struct {
	RemoteID  string  `json:"remoteId"`
	Name      string  `json:"name"`
	Color     *string `json:"color"`
	TimeZone  *string `json:"timeZone"`
	Writable  bool    `json:"writable"`
	IsDefault bool    `json:"isDefault"`
}

// CalendarList is the complete calendar list of an account.
type CalendarList struct {
	WorkerID          string           `json:"workerId"`
	Source            string           `json:"source"`
	ServerURL         *string          `json:"serverUrl"`
	ProviderSchedules bool             `json:"providerSchedules"`
	Calendars         []RemoteCalendar `json:"calendars"`
}

// CalendarState is what the API keeps for a calendar: its id and the cursor the last batch ended with.
type CalendarState struct {
	RemoteID string  `json:"remoteId"`
	ID       string  `json:"id"`
	Cursor   *string `json:"cursor"`
}

// RemoteObject is one calendar resource; Ical is set when it was fetched.
type RemoteObject struct {
	RemoteID string  `json:"remoteId"`
	ETag     *string `json:"etag"`
	Ical     *string `json:"ical,omitempty"`
}

type InventoryResult struct {
	Fetch   []string `json:"fetch"`
	Removed int      `json:"removed"`
}

type ObjectBatch struct {
	WorkerID string         `json:"workerId"`
	Cursor   *string        `json:"cursor"`
	Changed  []RemoteObject `json:"changed"`
	Removed  []string       `json:"removed"`
}

type ObjectBatchResult struct {
	Stored  int `json:"stored"`
	Removed int `json:"removed"`
	Skipped int `json:"skipped"`
}

// CalendarOperation is a write the worker makes at the provider: put Ical (create when RemoteID is nil) or delete
// RemoteID, conditional on IfMatch.
type CalendarOperation struct {
	ID               string  `json:"id"`
	Kind             string  `json:"kind"`
	CalendarRemoteID string  `json:"calendarRemoteId"`
	UID              string  `json:"uid"`
	RemoteID         *string `json:"remoteId"`
	IfMatch          *string `json:"ifMatch"`
	Ical             *string `json:"ical"`
	Attempt          int     `json:"attempt"`
}

// OperationResult reports a calendar write: done, conflict (with the provider's current version, nil when gone),
// failed or retry.
type OperationResult struct {
	WorkerID string        `json:"workerId"`
	Outcome  string        `json:"outcome"`
	RemoteID *string       `json:"remoteId,omitempty"`
	ETag     *string       `json:"etag,omitempty"`
	Error    *string       `json:"error,omitempty"`
	Current  *RemoteObject `json:"current,omitempty"`
}

const (
	OperationDone     = "done"
	OperationConflict = "conflict"
	OperationFailed   = "failed"
	OperationRetry    = "retry"
)

func (c *Client) Calendars(ctx context.Context, accountID string, list CalendarList) ([]CalendarState, error) {
	list.Calendars = nonNil(list.Calendars)
	var out struct {
		Calendars []CalendarState `json:"calendars"`
	}
	err := c.post(ctx, accountPath(accountID, "calendars"), list, &out)
	return out.Calendars, err
}

func (c *Client) CalendarInventory(ctx context.Context, accountID, calendarID, workerID string, objects []RemoteObject) (InventoryResult, error) {
	var out InventoryResult
	err := c.post(ctx, calendarPath(accountID, calendarID, "inventory"), map[string]any{"workerId": workerID, "objects": nonNil(objects)}, &out)
	return out, err
}

func (c *Client) CalendarObjects(ctx context.Context, accountID, calendarID string, batch ObjectBatch) (ObjectBatchResult, error) {
	batch.Changed, batch.Removed = nonNil(batch.Changed), nonNil(batch.Removed)
	var out ObjectBatchResult
	err := c.post(ctx, calendarPath(accountID, calendarID, "objects"), batch, &out)
	return out, err
}

func (c *Client) ClaimCalendarOperations(ctx context.Context, accountID, workerID string) ([]CalendarOperation, error) {
	var out struct {
		Operations []CalendarOperation `json:"operations"`
	}
	err := c.post(ctx, accountPath(accountID, "calendar-operations/claim"), map[string]any{"workerId": workerID}, &out)
	return out.Operations, err
}

func (c *Client) ReportCalendarOperation(ctx context.Context, accountID, operationID string, result OperationResult) error {
	return c.post(ctx, accountPath(accountID, "calendar-operations/"+url.PathEscape(operationID)+"/result"), result, nil)
}

func calendarPath(accountID, calendarID, action string) string {
	return accountPath(accountID, "calendars/"+url.PathEscape(calendarID)+"/"+action)
}

// Calendar sync statuses the worker reports; a working sync is reported by the calendar list itself.
const (
	CalendarUnsupported = "unsupported"
	CalendarAuthFailed  = "auth_failed"
	CalendarError       = "error"
	// CalendarUnconfirmed: a server was found that the user has to confirm before it gets the password.
	CalendarUnconfirmed = "unconfirmed"
)

func (c *Client) CalendarStatus(ctx context.Context, accountID, workerID, status, detail string) error {
	return c.post(ctx, accountPath(accountID, "calendar-status"), map[string]any{"workerId": workerID, "status": status, "detail": detail}, nil)
}
