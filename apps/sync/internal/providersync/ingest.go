package providersync

import (
	"context"
	"fmt"
	"log/slog"

	"tresso/sync/internal/api"
)

// MaxRemoteUIDLength is the longest remote uid the API stores. Provider ids are far shorter; a longer one is refused
// with an error instead of being cut, which would merge different messages.
const MaxRemoteUIDLength = 255

func checkRemoteUIDs[T any](items []T, uid func(T) string) error {
	for _, item := range items {
		if id := uid(item); len(id) > MaxRemoteUIDLength {
			return fmt.Errorf("remote uid of %d bytes is longer than the API's %d", len(id), MaxRemoteUIDLength)
		}
	}
	return nil
}

// Fetcher downloads and stores the raw messages with the given ids and returns them for the ingest API. Messages that
// no longer exist at the provider are left out.
type Fetcher func(ctx context.Context, ids []string) ([]api.Message, error)

// Ingest sends one account's changes to the API in batches the API accepts.
type Ingest struct {
	API       API
	AccountID string
	WorkerID  string
	// FetchBatch is how many raw messages are downloaded per ingest request (at most api.MaxMessagesPerBatch).
	FetchBatch int
	Logger     *slog.Logger
}

// ApplyFlags reports flags for messages in a folder. The messages the API has no location for in that folder are
// downloaded with fetch and ingested; a message the API already has in another folder is linked, not duplicated.
// Cursors are not touched: store them with StoreCursor or Vanish once all of a round's work is in.
func (in *Ingest) ApplyFlags(ctx context.Context, folder string, changes []api.FlagChange, fetch Fetcher) error {
	if err := checkRemoteUIDs(changes, func(c api.FlagChange) string { return c.RemoteUID }); err != nil {
		return err
	}
	for start := 0; start < len(changes); start += api.MaxFlagChanges {
		chunk := changes[start:min(start+api.MaxFlagChanges, len(changes))]
		result, err := in.API.Changes(ctx, in.AccountID, api.ChangeBatch{WorkerID: in.WorkerID, Folder: folder, Flags: chunk})
		if err != nil {
			return err
		}
		if err := in.fetchAndIngest(ctx, folder, result.UnknownUIDs, fetch); err != nil {
			return err
		}
	}
	return nil
}

func (in *Ingest) fetchAndIngest(ctx context.Context, folder string, ids []string, fetch Fetcher) error {
	size := max(1, min(in.FetchBatch, api.MaxMessagesPerBatch))
	for start := 0; start < len(ids); start += size {
		messages, err := fetch(ctx, ids[start:min(start+size, len(ids))])
		if err != nil {
			return err
		}
		if err := in.Messages(ctx, folder, messages, nil); err != nil {
			return err
		}
	}
	return nil
}

// Messages ingests messages into a folder; cursor (when not nil) is stored with the last batch.
func (in *Ingest) Messages(ctx context.Context, folder string, messages []api.Message, cursor *string) error {
	if err := checkRemoteUIDs(messages, func(m api.Message) string { return m.RemoteUID }); err != nil {
		return err
	}
	for start := 0; start < len(messages) || (start == 0 && cursor != nil); start += api.MaxMessagesPerBatch {
		end := min(start+api.MaxMessagesPerBatch, len(messages))
		batch := api.MessageBatch{WorkerID: in.WorkerID, Folder: folder, Messages: messages[start:end]}
		if end == len(messages) {
			batch.Cursor = cursor
		}
		result, err := in.API.Messages(ctx, in.AccountID, batch)
		if err != nil {
			return err
		}
		in.Logger.Debug("ingested", "folder", folder, "stored", result.Stored, "linked", result.Linked, "updated", result.Updated)
		if end == len(messages) {
			break
		}
	}
	return nil
}

// Vanish removes messages from a folder; cursor (when not nil) is stored with the last request, also when there is
// nothing to remove.
func (in *Ingest) Vanish(ctx context.Context, folder string, ids []string, cursor *string) error {
	if err := checkRemoteUIDs(ids, func(id string) string { return id }); err != nil {
		return err
	}
	for start := 0; start < len(ids) || (start == 0 && cursor != nil); start += api.MaxVanished {
		end := min(start+api.MaxVanished, len(ids))
		batch := api.ChangeBatch{WorkerID: in.WorkerID, Folder: folder, Vanished: ids[start:end]}
		if end == len(ids) {
			batch.Cursor = cursor
		}
		if _, err := in.API.Changes(ctx, in.AccountID, batch); err != nil {
			return err
		}
		if end == len(ids) {
			break
		}
	}
	return nil
}

// StoreCursor stores a folder's cursor on its own.
func (in *Ingest) StoreCursor(ctx context.Context, folder, cursor string) error {
	_, err := in.API.Changes(ctx, in.AccountID, api.ChangeBatch{WorkerID: in.WorkerID, Folder: folder, Cursor: &cursor})
	return err
}

// Stored returns every remote uid the API has in a folder.
func (in *Ingest) Stored(ctx context.Context, folder string) (map[string]bool, error) {
	stored := map[string]bool{}
	after := ""
	for {
		page, err := in.API.RemoteUIDs(ctx, in.AccountID, in.WorkerID, folder, after, api.MaxRemoteUIDPage)
		if err != nil {
			return nil, err
		}
		for _, id := range page.RemoteUIDs {
			stored[id] = true
		}
		if page.Next == nil || *page.Next == "" || len(page.RemoteUIDs) == 0 {
			return stored, nil
		}
		after = *page.Next
	}
}

// Missing returns the ids the API has in a folder that are not in present, sorted as the API listed them.
func (in *Ingest) Missing(ctx context.Context, folder string, present map[string]bool) ([]string, error) {
	var missing []string
	after := ""
	for {
		page, err := in.API.RemoteUIDs(ctx, in.AccountID, in.WorkerID, folder, after, api.MaxRemoteUIDPage)
		if err != nil {
			return nil, err
		}
		for _, id := range page.RemoteUIDs {
			if !present[id] {
				missing = append(missing, id)
			}
		}
		if page.Next == nil || *page.Next == "" || len(page.RemoteUIDs) == 0 {
			return missing, nil
		}
		after = *page.Next
	}
}
