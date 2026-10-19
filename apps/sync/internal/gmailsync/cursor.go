package gmailsync

import (
	"encoding/json"
	"fmt"

	"tresso/sync/internal/providersync"
)

// Cursor is the account's sync position. Gmail's history is account-wide, so there is one cursor, stored on the All
// Mail folder; the label folders have none.
//
// HistoryID is where the next history.list starts. The backfill walks messages.list (newest first) page by page;
// BackfillPageToken is the next page to fetch.
type Cursor struct {
	Version           int    `json:"v"`
	HistoryID         string `json:"historyId"`
	BackfillPageToken string `json:"backfillPageToken,omitempty"`
	BackfillDone      bool   `json:"backfillDone"`
}

const cursorVersion = 1

func (c Cursor) Encode() (string, error) {
	c.Version = cursorVersion
	return providersync.EncodeCursor(c)
}

// DecodeCursor returns nil for an empty cursor or one from another format, which starts the account from scratch.
func DecodeCursor(raw *string) (*Cursor, error) {
	if raw == nil || *raw == "" {
		return nil, nil
	}
	var c Cursor
	if err := json.Unmarshal([]byte(*raw), &c); err != nil {
		return nil, fmt.Errorf("decode cursor: %w", err)
	}
	if c.Version != cursorVersion || c.HistoryID == "" {
		return nil, nil
	}
	return &c, nil
}
