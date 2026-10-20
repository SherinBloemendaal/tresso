package blobcrypt

import (
	"bytes"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"testing"
)

// goFixture is what the API's BlobFormatTest decrypts to prove it reads what the worker writes. Regenerate it with
// WRITE_BLOB_FIXTURE=1 go test ./internal/blobcrypt -run TestWorkerFixture.
const goFixture = "../../../api/tests/Fixtures/go-object.json"

func TestWorkerFixture(t *testing.T) {
	if os.Getenv("WRITE_BLOB_FIXTURE") == "1" {
		blobKey, index := key(t), key(t)
		f := fixture{BlobKey: hex.EncodeToString(blobKey), Owner: owner, Version: 2, Pattern: "Written by the sync worker. ", Length: FrameBytes + 300}
		content := f.content()
		f.Fingerprint = Fingerprint(index, content)
		object, err := Encrypt(blobKey, f.Version, owner, f.Fingerprint, content)
		if err != nil {
			t.Fatal(err)
		}
		f.Object = base64.StdEncoding.EncodeToString(object)
		data, _ := json.MarshalIndent(f, "", "  ")
		if err := os.WriteFile(goFixture, append(data, '\n'), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	var f fixture
	data, err := os.ReadFile(goFixture)
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(data, &f); err != nil {
		t.Fatal(err)
	}
	if !f.opens() {
		t.Fatal("the fixture does not decrypt")
	}
}

// fixture is an object one side wrote for the other to read: two frames of a repeated pattern.
type fixture struct {
	BlobKey     string `json:"blobKey"`
	Owner       string `json:"owner"`
	Fingerprint string `json:"fingerprint"`
	Version     uint32 `json:"version"`
	Pattern     string `json:"pattern"`
	Length      int    `json:"length"`
	Object      string `json:"object"`
}

func (f fixture) content() []byte {
	return bytes.Repeat([]byte(f.Pattern), f.Length/len(f.Pattern)+1)[:f.Length]
}

func (f fixture) opens() bool {
	blobKey, err := hex.DecodeString(f.BlobKey)
	if err != nil {
		return false
	}
	object, err := base64.StdEncoding.DecodeString(f.Object)
	if err != nil {
		return false
	}
	opened, err := Decrypt(object, func(v uint32) ([]byte, error) { return blobKey, nil }, f.Owner, f.Fingerprint)
	return err == nil && bytes.Equal(opened, f.content())
}
