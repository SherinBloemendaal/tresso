package api

import (
	"context"
	"net/url"
	"time"
)

// Folder is one mailbox as the worker found it on the server.
type Folder struct {
	RemoteName  string  `json:"remoteName"`
	DisplayName string  `json:"displayName"`
	Role        string  `json:"role"` // inbox, sent, drafts, trash, spam, archive, all, custom
	UIDValidity *uint32 `json:"uidValidity"`
}

// FolderState is what the API stored for a folder; Reset means start the folder from scratch.
type FolderState struct {
	RemoteName  string  `json:"remoteName"`
	UIDValidity *uint32 `json:"uidValidity"`
	Cursor      *string `json:"cursor"`
	Reset       bool    `json:"reset"`
}

type Flags struct {
	Seen     bool `json:"seen"`
	Flagged  bool `json:"flagged"`
	Answered bool `json:"answered"`
	Draft    bool `json:"draft"`
}

type Address struct {
	Address string  `json:"address"`
	Name    *string `json:"name,omitempty"`
}

type Attachment struct {
	Filename    string  `json:"filename"`
	ContentType string  `json:"contentType"`
	Size        int64   `json:"size"`
	SHA256      string  `json:"sha256"`
	ContentID   *string `json:"contentId,omitempty"`
	Inline      bool    `json:"inline"`
}

type Message struct {
	RemoteUID    string       `json:"remoteUid"`
	ModSeq       *uint64      `json:"modSeq,omitempty"`
	RawObjectKey string       `json:"rawObjectKey"`
	Size         int64        `json:"size"`
	ReceivedAt   time.Time    `json:"receivedAt"`
	SentAt       *time.Time   `json:"sentAt,omitempty"`
	Flags        Flags        `json:"flags"`
	MessageID    *string      `json:"messageId,omitempty"`
	InReplyTo    *string      `json:"inReplyTo,omitempty"`
	References   []string     `json:"references"`
	Subject      string       `json:"subject"`
	From         Address      `json:"from"`
	To           []Address    `json:"to"`
	Cc           []Address    `json:"cc"`
	Bcc          []Address    `json:"bcc"`
	ReplyTo      []Address    `json:"replyTo"`
	Snippet      string       `json:"snippet"`
	BodyText     string       `json:"bodyText"` // plain text for search, at most 32768 characters
	Attachments  []Attachment `json:"attachments"`
}

type MessageBatch struct {
	WorkerID string    `json:"workerId"`
	Folder   string    `json:"folder"`
	Cursor   *string   `json:"cursor"`
	Messages []Message `json:"messages"`
}

type MessageBatchResult struct {
	Stored  int `json:"stored"`
	Linked  int `json:"linked"`
	Updated int `json:"updated"`
}

type FlagChange struct {
	RemoteUID string  `json:"remoteUid"`
	ModSeq    *uint64 `json:"modSeq,omitempty"`
	Flags     Flags   `json:"flags"`
}

// PresenceRange says: between FromUID and ToUID only UIDs exist; everything else there was removed.
type PresenceRange struct {
	FromUID uint32   `json:"fromUid"`
	ToUID   uint32   `json:"toUid"`
	UIDs    []uint32 `json:"uids"`
}

type ChangeBatch struct {
	WorkerID string          `json:"workerId"`
	Folder   string          `json:"folder"`
	Cursor   *string         `json:"cursor"`
	Flags    []FlagChange    `json:"flags"`
	Presence []PresenceRange `json:"presence"`
	Vanished []string        `json:"vanished"`
}

type ChangeBatchResult struct {
	Updated int `json:"updated"`
	Removed int `json:"removed"`
	Unknown int `json:"unknown"`
	Deleted int `json:"deleted"`
}

const (
	StatusActive     = "active"
	StatusAuthFailed = "auth_failed"
	StatusError      = "error"
)

// Folders reports the complete folder list and returns each folder's stored state.
func (c *Client) Folders(ctx context.Context, accountID, workerID string, folders []Folder) ([]FolderState, error) {
	var out struct {
		Folders []FolderState `json:"folders"`
	}
	err := c.post(ctx, accountPath(accountID, "folders"), map[string]any{"workerId": workerID, "folders": folders}, &out)
	return out.Folders, err
}

func (c *Client) Messages(ctx context.Context, accountID string, batch MessageBatch) (MessageBatchResult, error) {
	batch.Messages = nonNil(batch.Messages)
	for i := range batch.Messages {
		m := &batch.Messages[i]
		m.References, m.To, m.Cc, m.Bcc, m.ReplyTo, m.Attachments = nonNil(m.References), nonNil(m.To), nonNil(m.Cc), nonNil(m.Bcc), nonNil(m.ReplyTo), nonNil(m.Attachments)
	}
	var out MessageBatchResult
	err := c.post(ctx, accountPath(accountID, "messages"), batch, &out)
	return out, err
}

func (c *Client) Changes(ctx context.Context, accountID string, batch ChangeBatch) (ChangeBatchResult, error) {
	batch.Flags, batch.Presence, batch.Vanished = nonNil(batch.Flags), nonNil(batch.Presence), nonNil(batch.Vanished)
	for i := range batch.Presence {
		batch.Presence[i].UIDs = nonNil(batch.Presence[i].UIDs)
	}
	var out ChangeBatchResult
	err := c.post(ctx, accountPath(accountID, "changes"), batch, &out)
	return out, err
}

// Status reports that the account works, that its login was refused, or that syncing failed.
func (c *Client) Status(ctx context.Context, accountID, workerID, status, detail string) error {
	body := map[string]any{"workerId": workerID, "status": status}
	if detail != "" {
		body["detail"] = detail
	}
	return c.post(ctx, accountPath(accountID, "status"), body, nil)
}

func accountPath(accountID, action string) string {
	return "/internal/sync/accounts/" + url.PathEscape(accountID) + "/" + action
}

// nonNil turns nil slices into empty ones: the API expects JSON arrays, never null.
func nonNil[T any](s []T) []T {
	if s == nil {
		return []T{}
	}
	return s
}
