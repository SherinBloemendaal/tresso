// Package storage keeps raw messages and attachments in S3-compatible object storage, addressed by their SHA-256 so
// each distinct blob is stored once, however many folders, accounts or users have it.
package storage

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"net/url"
	"sync"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

// Store writes a blob unless an object with that key already exists.
type Store interface {
	PutIfAbsent(ctx context.Context, key string, data []byte, contentType string) error
}

func SHA256(data []byte) string {
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:])
}

// RawKey is where a message's raw MIME lives; the API only accepts keys of this shape.
func RawKey(sha string) string {
	return "raw/" + sha[:2] + "/" + sha
}

// AttachmentKey is where a decoded attachment lives.
func AttachmentKey(sha string) string {
	return "attachments/" + sha[:2] + "/" + sha
}

type S3 struct {
	client *minio.Client
	bucket string
}

// NewS3 connects to an S3 endpoint such as "http://s3:9000" or "https://s3.eu-central-1.amazonaws.com".
func NewS3(endpoint, accessKey, secretKey, bucket, region string) (*S3, error) {
	parsed, err := url.Parse(endpoint)
	if err != nil || parsed.Host == "" {
		return nil, fmt.Errorf("invalid S3 endpoint %q", endpoint)
	}
	client, err := minio.New(parsed.Host, &minio.Options{
		Creds:  credentials.NewStaticV4(accessKey, secretKey, ""),
		Secure: parsed.Scheme == "https",
		Region: region,
	})
	if err != nil {
		return nil, err
	}
	return &S3{client: client, bucket: bucket}, nil
}

// EnsureBucket creates the bucket when it does not exist yet (local development; production provisions it).
func (s *S3) EnsureBucket(ctx context.Context) error {
	exists, err := s.client.BucketExists(ctx, s.bucket)
	if err != nil {
		return fmt.Errorf("check bucket %s: %w", s.bucket, err)
	}
	if exists {
		return nil
	}
	if err := s.client.MakeBucket(ctx, s.bucket, minio.MakeBucketOptions{}); err != nil {
		return fmt.Errorf("create bucket %s: %w", s.bucket, err)
	}
	return nil
}

func (s *S3) PutIfAbsent(ctx context.Context, key string, data []byte, contentType string) error {
	_, err := s.client.StatObject(ctx, s.bucket, key, minio.StatObjectOptions{})
	if err == nil {
		return nil
	}
	var response minio.ErrorResponse
	if !errors.As(err, &response) || response.Code != minio.NoSuchKey {
		return fmt.Errorf("stat %s: %w", key, err)
	}

	_, err = s.client.PutObject(ctx, s.bucket, key, bytes.NewReader(data), int64(len(data)), minio.PutObjectOptions{ContentType: contentType})
	if err != nil {
		return fmt.Errorf("put %s: %w", key, err)
	}
	return nil
}

// Memory is an in-process Store for tests.
type Memory struct {
	mu      sync.Mutex
	Objects map[string][]byte
	Puts    int
}

func NewMemory() *Memory {
	return &Memory{Objects: map[string][]byte{}}
}

func (m *Memory) PutIfAbsent(_ context.Context, key string, data []byte, _ string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if _, ok := m.Objects[key]; ok {
		return nil
	}
	m.Objects[key] = append([]byte(nil), data...)
	m.Puts++
	return nil
}

func (m *Memory) Len() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	return len(m.Objects)
}
