//go:build integration

package storage_test

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"net/url"
	"os"
	"testing"
	"time"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"

	"tresso/sync/internal/storage"
)

// s3 connects to the S3 server of S3_TEST_ENDPOINT (RustFS in CI and the dev stack) with a fresh bucket.
func s3(t *testing.T) (*storage.S3, *minio.Client, string) {
	t.Helper()
	endpoint := os.Getenv("S3_TEST_ENDPOINT")
	if endpoint == "" {
		t.Skip("S3_TEST_ENDPOINT is not set")
	}
	bucket := fmt.Sprintf("storage-test-%d", time.Now().UnixNano())
	store, err := storage.NewS3(endpoint, os.Getenv("S3_TEST_ACCESS_KEY"), os.Getenv("S3_TEST_SECRET_KEY"), bucket, "us-east-1")
	if err != nil {
		t.Fatal(err)
	}
	if err := store.EnsureBucket(context.Background()); err != nil {
		t.Fatal(err)
	}
	parsed, _ := url.Parse(endpoint)
	client, err := minio.New(parsed.Host, &minio.Options{Creds: credentials.NewStaticV4(os.Getenv("S3_TEST_ACCESS_KEY"), os.Getenv("S3_TEST_SECRET_KEY"), ""), Secure: parsed.Scheme == "https", Region: "us-east-1"})
	if err != nil {
		t.Fatal(err)
	}
	return store, client, bucket
}

func TestS3NeverReplacesANewerKeyVersion(t *testing.T) {
	store, _, _ := s3(t)
	ctx := context.Background()

	for _, step := range []struct {
		data    string
		version uint32
		want    string
	}{{"v1", 1, "v1"}, {"v1 again", 1, "v1"}, {"v2", 2, "v2"}, {"v1 late", 1, "v2"}} {
		if err := store.PutUnlessCurrent(ctx, "raw/x", []byte(step.data), step.version); err != nil {
			t.Fatal(err)
		}
		got, err := store.Get(ctx, "raw/x")
		if err != nil || string(got) != step.want {
			t.Errorf("after writing %q (version %d): %q, %v; want %q", step.data, step.version, got, err, step.want)
		}
	}
}

func TestS3ReadsTheVersionTheAPIWrites(t *testing.T) {
	store, client, bucket := s3(t)
	ctx := context.Background()
	// The API (AsyncAws) sends the metadata name in lower case.
	if _, err := client.PutObject(ctx, bucket, "outgoing/x", bytes.NewReader([]byte("from the API")), 12, minio.PutObjectOptions{UserMetadata: map[string]string{"tresso-key-version": "3"}}); err != nil {
		t.Fatal(err)
	}

	if err := store.PutUnlessCurrent(ctx, "outgoing/x", []byte("older"), 2); err != nil {
		t.Fatal(err)
	}

	if got, _ := store.Get(ctx, "outgoing/x"); string(got) != "from the API" {
		t.Errorf("an object of version 3 was replaced by version 2: %q", got)
	}
}

func TestS3HonoursTheConditionsTheWritesRelyOn(t *testing.T) {
	_, client, bucket := s3(t)
	ctx := context.Background()
	if _, err := client.PutObject(ctx, bucket, "k", bytes.NewReader([]byte("a")), 1, minio.PutObjectOptions{}); err != nil {
		t.Fatal(err)
	}

	create := minio.PutObjectOptions{}
	create.SetMatchETagExcept("*")
	_, err := client.PutObject(ctx, bucket, "k", bytes.NewReader([]byte("b")), 1, create)
	var response minio.ErrorResponse
	if !errors.As(err, &response) || response.StatusCode != 412 {
		t.Errorf("If-None-Match: * over an existing object: %v", err)
	}

	stale := minio.PutObjectOptions{}
	stale.SetMatchETag("0123456789abcdef0123456789abcdef")
	_, err = client.PutObject(ctx, bucket, "k", bytes.NewReader([]byte("c")), 1, stale)
	if !errors.As(err, &response) || response.StatusCode != 412 {
		t.Errorf("If-Match with another ETag: %v", err)
	}
}
