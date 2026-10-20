package api

import (
	"bytes"
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestStorageKeysSendsWorkerAndDecodes(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet || r.URL.Path != "/internal/sync/accounts/acc-1/storage-keys" || r.URL.Query().Get("workerId") != "w 1" {
			t.Errorf("unexpected request %s %s?%s", r.Method, r.URL.Path, r.URL.RawQuery)
		}
		_, _ = w.Write([]byte(`{"accountId":"acc-1","ownerId":"0192d7a0-0000-7000-8000-000000000001","indexKey":"AQEBAQ==","currentVersion":2,` +
			`"blobKeys":[{"version":1,"key":"AgICAg=="},{"version":2,"key":"AwMDAw=="}]}`))
	}))
	defer srv.Close()

	keys, err := NewWithHTTPClient(srv.URL, srv.Client()).StorageKeys(context.Background(), "acc-1", "w 1")
	if err != nil {
		t.Fatal(err)
	}
	if keys.OwnerID != "0192d7a0-0000-7000-8000-000000000001" || keys.CurrentVersion != 2 || !bytes.Equal(keys.IndexKey, []byte{1, 1, 1, 1}) {
		t.Errorf("keys = %+v", keys)
	}
	if len(keys.BlobKeys) != 2 || keys.BlobKeys[1].Version != 2 || !bytes.Equal(keys.BlobKeys[1].Key, []byte{3, 3, 3, 3}) {
		t.Errorf("blob keys = %+v", keys.BlobKeys)
	}
}

func TestStorageKeysOfAnAccountNotLeasedIsAnError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "not found", http.StatusNotFound)
	}))
	defer srv.Close()

	if _, err := NewWithHTTPClient(srv.URL, srv.Client()).StorageKeys(context.Background(), "acc-1", "w1"); err == nil {
		t.Fatal("a 404 must be an error")
	}
}
