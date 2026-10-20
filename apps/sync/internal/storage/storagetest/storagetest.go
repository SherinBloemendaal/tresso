// Package storagetest gives tests a storage.Vault on in-memory objects, with the same keys for every account.
package storagetest

import (
	"bytes"
	"context"
	"sync/atomic"

	"tresso/sync/internal/api"
	"tresso/sync/internal/blobcrypt"
	"tresso/sync/internal/storage"
)

// OwnerID owns every account in tests.
const OwnerID = "0192d7a0-0000-7000-8000-000000000001"

// Vault is a storage.Vault for tests: Objects shows what is stored, Keys are what the API handed out.
type Vault struct {
	*storage.Vault
	Objects *storage.Memory
	Keys    api.StorageKeys

	fetches atomic.Int64
}

// The same keys in every test, so tests can name where content is stored (KeyOf) before storing it.
var (
	indexKey = bytes.Repeat([]byte{1}, 32)
	blobKey  = bytes.Repeat([]byte{2}, 32)
)

func New() *Vault {
	v := &Vault{Objects: storage.NewMemory(), Keys: api.StorageKeys{
		OwnerID:        OwnerID,
		IndexKey:       indexKey,
		CurrentVersion: 1,
		BlobKeys:       []api.BlobKey{{Version: 1, Key: blobKey}},
	}}
	v.Vault = storage.NewVault(v.Objects, v)
	return v
}

func (v *Vault) StorageKeys(_ context.Context, accountID string) (api.StorageKeys, error) {
	v.fetches.Add(1)
	keys := v.Keys
	keys.AccountID = accountID
	return keys, nil
}

// Fetches is how often the keys were asked for.
func (v *Vault) Fetches() int {
	return int(v.fetches.Load())
}

// KeyOf is where content of a kind is stored for OwnerID.
func KeyOf(kind string, content []byte) string {
	return blobcrypt.Key(kind, OwnerID, blobcrypt.Fingerprint(indexKey, content))
}

// PutAsAPI stores content the way the API does (outgoing messages, Drafts folder copies) and returns its key.
func (v *Vault) PutAsAPI(kind string, content []byte) string {
	fingerprint := blobcrypt.Fingerprint(v.Keys.IndexKey, content)
	key := blobcrypt.Key(kind, OwnerID, fingerprint)
	sealed, err := blobcrypt.Encrypt(v.Keys.BlobKeys[len(v.Keys.BlobKeys)-1].Key, v.Keys.CurrentVersion, OwnerID, fingerprint, content)
	if err != nil {
		panic(err)
	}
	v.Objects.Put(key, sealed)
	return key
}

// Plaintext reads an object back decrypted, or nil when it cannot.
func (v *Vault) Plaintext(key string) []byte {
	data, err := v.Read(context.Background(), "test-account", key)
	if err != nil {
		return nil
	}
	return data
}
