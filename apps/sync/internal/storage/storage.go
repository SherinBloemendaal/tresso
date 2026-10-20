// Package storage keeps raw messages and attachments in S3-compatible object storage, encrypted with the key of the
// account's owner (see package blobcrypt), and reads what the API stored there.
package storage

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

// Objects is object storage without encryption: the Vault encrypts before writing and decrypts after reading.
type Objects interface {
	// PutUnlessCurrent writes an object unless one with that key already exists written with the same or a newer key
	// version. An older one is replaced. A newer one is never written over with an older version: re-encryption may
	// have moved it on, and the older key may be retired soon.
	PutUnlessCurrent(ctx context.Context, key string, data []byte, version uint32) error
	Get(ctx context.Context, key string) ([]byte, error)
}

// ErrNotFound means there is no object with that key.
var ErrNotFound = errors.New("object not found")

// KeyVersionMetadata names the key version an object is encrypted with, as S3 user metadata
// (x-amz-meta-tresso-key-version). The API writes the same name; header names are case-insensitive, and S3 clients
// change their case, so it is always looked up without regard to case.
const KeyVersionMetadata = "tresso-key-version"

// maxObjectBytes bounds what is read into memory: far above any message a mail server accepts.
const maxObjectBytes = 100 << 20

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

func (s *S3) PutUnlessCurrent(ctx context.Context, key string, data []byte, version uint32) error {
	// Conditional writes: another worker or the API may write the same object at the same time. Whoever loses the race
	// looks again, so an object is never replaced by an older version than the one it holds.
	for attempt := 0; attempt < 3; attempt++ {
		opts := minio.PutObjectOptions{
			ContentType:  "application/octet-stream",
			UserMetadata: map[string]string{KeyVersionMetadata: strconv.FormatUint(uint64(version), 10)},
		}
		info, err := s.client.StatObject(ctx, s.bucket, key, minio.StatObjectOptions{})
		switch {
		case err == nil:
			stored, ok := keyVersionOf(info.UserMetadata)
			if ok && stored >= version {
				return nil
			}
			opts.SetMatchETag(info.ETag)
		case isNoSuchKey(err):
			opts.SetMatchETagExcept("*")
		default:
			return fmt.Errorf("stat %s: %w", key, err)
		}

		_, err = s.client.PutObject(ctx, s.bucket, key, bytes.NewReader(data), int64(len(data)), opts)
		if err == nil {
			return nil
		}
		if !isPreconditionFailed(err) {
			return fmt.Errorf("put %s: %w", key, err)
		}
	}
	return fmt.Errorf("put %s: changed by another writer three times in a row", key)
}

// keyVersionOf reads the key version from an object's user metadata, whatever the case of its name.
func keyVersionOf(metadata map[string]string) (uint32, bool) {
	for name, value := range metadata {
		if strings.EqualFold(name, KeyVersionMetadata) {
			version, err := strconv.ParseUint(value, 10, 32)
			return uint32(version), err == nil
		}
	}
	return 0, false
}

func isNoSuchKey(err error) bool {
	var response minio.ErrorResponse
	return errors.As(err, &response) && response.Code == minio.NoSuchKey
}

func isPreconditionFailed(err error) bool {
	var response minio.ErrorResponse
	return errors.As(err, &response) && (response.StatusCode == http.StatusPreconditionFailed || response.Code == "PreconditionFailed")
}

func (s *S3) Get(ctx context.Context, key string) ([]byte, error) {
	object, err := s.client.GetObject(ctx, s.bucket, key, minio.GetObjectOptions{})
	if err != nil {
		return nil, fmt.Errorf("get %s: %w", key, err)
	}
	defer object.Close()
	data, err := io.ReadAll(io.LimitReader(object, maxObjectBytes+1))
	if err != nil {
		var response minio.ErrorResponse
		if errors.As(err, &response) && response.Code == minio.NoSuchKey {
			return nil, fmt.Errorf("%w: %s", ErrNotFound, key)
		}
		return nil, fmt.Errorf("read %s: %w", key, err)
	}
	if len(data) > maxObjectBytes {
		return nil, fmt.Errorf("object %s is larger than %d bytes", key, maxObjectBytes)
	}
	return data, nil
}

// Memory is in-process object storage for tests.
type Memory struct {
	mu       sync.Mutex
	Objects  map[string][]byte
	Versions map[string]uint32
	Puts     int
}

func NewMemory() *Memory {
	return &Memory{Objects: map[string][]byte{}, Versions: map[string]uint32{}}
}

func (m *Memory) PutUnlessCurrent(_ context.Context, key string, data []byte, version uint32) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if _, ok := m.Objects[key]; ok && m.Versions[key] >= version {
		return nil
	}
	m.Objects[key] = append([]byte(nil), data...)
	m.Versions[key] = version
	m.Puts++
	return nil
}

// Put stores an object as it is, as the API would.
func (m *Memory) Put(key string, data []byte) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.Objects[key] = append([]byte(nil), data...)
}

func (m *Memory) Get(_ context.Context, key string) ([]byte, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	data, ok := m.Objects[key]
	if !ok {
		return nil, fmt.Errorf("%w: %s", ErrNotFound, key)
	}
	return append([]byte(nil), data...), nil
}

func (m *Memory) Len() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	return len(m.Objects)
}
