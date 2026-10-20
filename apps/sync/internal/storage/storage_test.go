package storage_test

import (
	"bytes"
	"context"
	"errors"
	"strings"
	"testing"

	"tresso/sync/internal/api"
	"tresso/sync/internal/blobcrypt"
	"tresso/sync/internal/storage"
	"tresso/sync/internal/storage/storagetest"
)

func TestMemoryRewritesOnlyAnOlderKeyVersion(t *testing.T) {
	m := storage.NewMemory()
	ctx := context.Background()
	_ = m.PutUnlessCurrent(ctx, "a", []byte("1"), 1)
	_ = m.PutUnlessCurrent(ctx, "a", []byte("2"), 1)
	if m.Puts != 1 || string(m.Objects["a"]) != "1" {
		t.Errorf("same version: puts=%d value=%q", m.Puts, m.Objects["a"])
	}
	_ = m.PutUnlessCurrent(ctx, "a", []byte("3"), 2)
	if m.Puts != 2 || string(m.Objects["a"]) != "3" {
		t.Errorf("new version: puts=%d value=%q", m.Puts, m.Objects["a"])
	}
	// A worker whose cached keys are older never writes over what re-encryption moved on.
	_ = m.PutUnlessCurrent(ctx, "a", []byte("4"), 1)
	if m.Puts != 2 || string(m.Objects["a"]) != "3" {
		t.Errorf("older version: puts=%d value=%q", m.Puts, m.Objects["a"])
	}
}

func TestWriterStoresEncryptedUnderTheOwner(t *testing.T) {
	v := storagetest.New()
	ctx := context.Background()
	w, err := v.Writer(ctx, "acc-1")
	if err != nil {
		t.Fatal(err)
	}
	content := []byte("Subject: invoice\r\n\r\nPlease pay.")
	key, fp, err := w.Put(ctx, blobcrypt.KindRaw, content)
	if err != nil {
		t.Fatal(err)
	}
	if want := blobcrypt.Key(blobcrypt.KindRaw, storagetest.OwnerID, fp); key != want || w.KeyVersion() != 1 {
		t.Errorf("key %q version %d, want %q version 1", key, w.KeyVersion(), want)
	}
	if bytes.Contains(v.Objects.Objects[key], []byte("invoice")) {
		t.Error("the stored object holds plaintext")
	}
	if got, err := v.Read(ctx, "acc-1", key); err != nil || !bytes.Equal(got, content) {
		t.Errorf("read back %q, %v", got, err)
	}

	// The same content again is not uploaded again.
	puts := v.Objects.Puts
	if _, _, err := w.Put(ctx, blobcrypt.KindRaw, content); err != nil || v.Objects.Puts != puts {
		t.Errorf("stored twice: puts %d -> %d, %v", puts, v.Objects.Puts, err)
	}
}

func TestReadRefusesAnotherOwnersObject(t *testing.T) {
	v := storagetest.New()
	ctx := context.Background()
	other := storagetest.New()
	other.Keys.OwnerID = "0192d7a0-0000-7000-8000-000000000002"
	w, _ := other.Writer(ctx, "acc-2")
	key, _, err := w.Put(ctx, blobcrypt.KindAttachments, []byte("someone else's file"))
	if err != nil {
		t.Fatal(err)
	}
	v.Objects.Put(key, other.Objects.Objects[key])
	if _, err := v.Read(ctx, "acc-1", key); !errors.Is(err, blobcrypt.ErrAuthentication) {
		t.Errorf("err = %v, want ErrAuthentication", err)
	}

	// Copied under this owner's name, it still does not open: the owner is part of what is authenticated.
	parsed, _ := blobcrypt.ParseKey(key)
	moved := blobcrypt.Key(parsed.Kind, storagetest.OwnerID, parsed.Fingerprint)
	v.Objects.Put(moved, other.Objects.Objects[key])
	if _, err := v.Read(ctx, "acc-1", moved); !errors.Is(err, blobcrypt.ErrAuthentication) {
		t.Errorf("moved: err = %v, want ErrAuthentication", err)
	}
}

func TestReadsPlaintextFromBeforeEncryption(t *testing.T) {
	v := storagetest.New()
	legacy := "outgoing/ab/ab" + strings.Repeat("0", 62)
	v.Objects.Put(legacy, []byte("Subject: old"))
	if got, err := v.Read(context.Background(), "acc-1", legacy); err != nil || string(got) != "Subject: old" {
		t.Errorf("read %q, %v", got, err)
	}
}

func TestRotationRewritesAndRefreshesKeys(t *testing.T) {
	v := storagetest.New()
	ctx := context.Background()
	content := []byte("hello")
	w, _ := v.Writer(ctx, "acc-1")
	key, _, _ := w.Put(ctx, blobcrypt.KindRaw, content)

	// The API rotates the owner's key; an object it writes with the new version is read with fresh keys.
	v.Keys.BlobKeys = append(v.Keys.BlobKeys, api.BlobKey{Version: 2, Key: bytes.Repeat([]byte{7}, 32)})
	v.Keys.CurrentVersion = 2
	newer := v.PutAsAPI(blobcrypt.KindOutgoing, []byte("sent after the rotation"))
	fetches := v.Fetches()
	if got, err := v.Read(ctx, "acc-1", newer); err != nil || string(got) != "sent after the rotation" {
		t.Fatalf("read %q, %v", got, err)
	}
	if v.Fetches() != fetches+1 {
		t.Errorf("keys fetched %d times, want once more", v.Fetches()-fetches)
	}

	// A writer with the new keys writes the same content again, under the same name, with the new version.
	w, _ = v.Writer(ctx, "acc-1")
	again, _, err := w.Put(ctx, blobcrypt.KindRaw, content)
	if err != nil || again != key || v.Objects.Versions[key] != 2 {
		t.Errorf("rewritten as %q version %d, %v", again, v.Objects.Versions[key], err)
	}
	if version, _ := blobcrypt.Version(v.Objects.Objects[key]); version != 2 {
		t.Errorf("object header names version %d", version)
	}
}
