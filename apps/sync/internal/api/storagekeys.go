package api

import (
	"context"
	"net/http"
	"net/url"
)

// StorageKeys are what a worker needs to store and read one account's content, encrypted with the account owner's key.
type StorageKeys struct {
	AccountID      string    `json:"accountId"`
	OwnerID        string    `json:"ownerId"`
	IndexKey       []byte    `json:"indexKey"`
	CurrentVersion uint32    `json:"currentVersion"`
	BlobKeys       []BlobKey `json:"blobKeys"`
}

// BlobKey is the owner's blob key of one key version.
type BlobKey struct {
	Version uint32 `json:"version"`
	Key     []byte `json:"key"`
}

// StorageKeys fetches the storage keys of an account this worker currently leases. Never a master key.
func (c *Client) StorageKeys(ctx context.Context, accountID, workerID string) (StorageKeys, error) {
	var out StorageKeys
	path := "/internal/sync/accounts/" + url.PathEscape(accountID) + "/storage-keys?workerId=" + url.QueryEscape(workerID)
	err := c.do(ctx, http.MethodGet, path, nil, &out)
	return out, err
}
