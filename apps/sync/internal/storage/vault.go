package storage

import (
	"context"
	"fmt"
	"sync"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/blobcrypt"
)

// KeyCacheTTL is how long a worker keeps an account's keys. The API retires an older key version only once the newer
// one is 15 minutes old, so nothing is written with a version that is gone.
const KeyCacheTTL = 5 * time.Minute

// KeySource fetches an account's storage keys from the API (for an account this worker leases).
type KeySource interface {
	StorageKeys(ctx context.Context, accountID string) (api.StorageKeys, error)
}

// Blobs stores and reads an account's content, encrypted with its owner's key.
type Blobs interface {
	// Writer returns a writer with the account's current key; a message and its attachments share one, so they share
	// the key version the ingest call names.
	Writer(ctx context.Context, accountID string) (*Writer, error)
	// Read reads and decrypts an object of the account's owner; content from before encryption is read as it is.
	Read(ctx context.Context, accountID, key string) ([]byte, error)
}

// Vault is Blobs on object storage, with each account's keys fetched from the API and kept in memory for a while.
type Vault struct {
	objects Objects
	keys    KeySource
	now     func() time.Time

	mu    sync.Mutex
	cache map[string]cachedKeys
}

type cachedKeys struct {
	keys    api.StorageKeys
	fetched time.Time
}

func NewVault(objects Objects, keys KeySource) *Vault {
	return &Vault{objects: objects, keys: keys, now: time.Now, cache: map[string]cachedKeys{}}
}

// Writer encrypts content with one snapshot of an account's keys.
type Writer struct {
	objects Objects
	keys    api.StorageKeys
	blobKey []byte
}

func (v *Vault) Writer(ctx context.Context, accountID string) (*Writer, error) {
	keys, err := v.accountKeys(ctx, accountID, false)
	if err != nil {
		return nil, err
	}
	blobKey, ok := blobKeyOf(keys, keys.CurrentVersion)
	if !ok {
		return nil, fmt.Errorf("storage keys of account %s lack the current version %d", accountID, keys.CurrentVersion)
	}
	return &Writer{objects: v.objects, keys: keys, blobKey: blobKey}, nil
}

// KeyVersion is the key version everything this writer stores is encrypted with.
func (w *Writer) KeyVersion() uint32 {
	return w.keys.CurrentVersion
}

// Put encrypts and stores content of a kind and returns its key and fingerprint. Content the owner stored before
// with the same key version is not written again.
func (w *Writer) Put(ctx context.Context, kind string, content []byte) (key, fingerprint string, err error) {
	fingerprint = blobcrypt.Fingerprint(w.keys.IndexKey, content)
	key = blobcrypt.Key(kind, w.keys.OwnerID, fingerprint)
	sealed, err := blobcrypt.Encrypt(w.blobKey, w.keys.CurrentVersion, w.keys.OwnerID, fingerprint, content)
	if err != nil {
		return "", "", err
	}
	if err := w.objects.PutUnlessCurrent(ctx, key, sealed, w.keys.CurrentVersion); err != nil {
		return "", "", err
	}
	return key, fingerprint, nil
}

func (v *Vault) Read(ctx context.Context, accountID, key string) ([]byte, error) {
	if blobcrypt.IsLegacy(key) {
		return v.objects.Get(ctx, key)
	}
	parsed, ok := blobcrypt.ParseKey(key)
	if !ok {
		return nil, fmt.Errorf("%q is not an object key", key)
	}
	keys, err := v.accountKeys(ctx, accountID, false)
	if err != nil {
		return nil, err
	}
	if parsed.OwnerID != keys.OwnerID {
		return nil, fmt.Errorf("%w: object %s belongs to another user", blobcrypt.ErrAuthentication, key)
	}
	sealed, err := v.objects.Get(ctx, key)
	if err != nil {
		return nil, err
	}
	return blobcrypt.Decrypt(sealed, func(version uint32) ([]byte, error) {
		if blobKey, ok := blobKeyOf(keys, version); ok {
			return blobKey, nil
		}
		// Written with a version newer than the cached keys: the owner's keys were rotated meanwhile.
		fresh, err := v.accountKeys(ctx, accountID, true)
		if err != nil {
			return nil, err
		}
		if blobKey, ok := blobKeyOf(fresh, version); ok {
			return blobKey, nil
		}
		return nil, fmt.Errorf("%w: key version %d of object %s is unknown", blobcrypt.ErrAuthentication, version, key)
	}, parsed.OwnerID, parsed.Fingerprint)
}

func (v *Vault) accountKeys(ctx context.Context, accountID string, refresh bool) (api.StorageKeys, error) {
	v.mu.Lock()
	cached, ok := v.cache[accountID]
	v.mu.Unlock()
	if ok && !refresh && v.now().Sub(cached.fetched) < KeyCacheTTL {
		return cached.keys, nil
	}

	keys, err := v.keys.StorageKeys(ctx, accountID)
	if err != nil {
		return api.StorageKeys{}, fmt.Errorf("storage keys of account %s: %w", accountID, err)
	}
	now := v.now()
	v.mu.Lock()
	defer v.mu.Unlock()
	// Keys of accounts no longer synced here do not stay in memory.
	for id, entry := range v.cache {
		if now.Sub(entry.fetched) >= KeyCacheTTL {
			delete(v.cache, id)
		}
	}
	v.cache[accountID] = cachedKeys{keys: keys, fetched: now}
	return keys, nil
}

func blobKeyOf(keys api.StorageKeys, version uint32) ([]byte, bool) {
	for _, k := range keys.BlobKeys {
		if k.Version == version {
			return k.Key, true
		}
	}
	return nil, false
}
