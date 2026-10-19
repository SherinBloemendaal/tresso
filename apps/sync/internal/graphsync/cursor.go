package graphsync

import (
	"encoding/json"
	"fmt"

	"tresso/sync/internal/providersync"
)

// Cursor is a folder's sync position. DeltaLink is where the next delta query continues (relative to the Graph base
// URL). The backfill walks the folder newest first: BackfillBefore is the receive time it continues below ("" for the
// newest message).
type Cursor struct {
	Version        int    `json:"v"`
	DeltaLink      string `json:"deltaLink"`
	BackfillBefore string `json:"backfillBefore,omitempty"`
	BackfillDone   bool   `json:"backfillDone"`
}

const cursorVersion = 2

func (c Cursor) Encode() (string, error) {
	c.Version = cursorVersion
	return providersync.EncodeCursor(c)
}

// DecodeCursor returns nil for an empty cursor or one from another format, which starts the folder from scratch.
func DecodeCursor(raw *string) (*Cursor, error) {
	if raw == nil || *raw == "" {
		return nil, nil
	}
	var c Cursor
	if err := json.Unmarshal([]byte(*raw), &c); err != nil {
		return nil, fmt.Errorf("decode cursor: %w", err)
	}
	if c.Version != cursorVersion || c.DeltaLink == "" {
		return nil, nil
	}
	return &c, nil
}
