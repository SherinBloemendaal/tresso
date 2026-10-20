// Package blobcrypt is the encrypted object format the API and the sync worker share (the API's App\Storage\BlobFormat
// and App\Storage\BlobKey):
//
//	header: "TRB1" | key version (uint32, big endian) | salt (32 random bytes)
//	frames: XChaCha20-Poly1305 of 64 KiB of content each, the last one possibly shorter (or empty)
//
// Frame key: HKDF-SHA256(blob key, salt, "tresso:blob-frame:v1"). Frame nonce: 15 zero bytes, the frame number (uint64,
// big endian), then 1 on the last frame and 0 on the others. Additional data: the header, the owner's id (16 bytes)
// and the content fingerprint (32 bytes). Frames cannot be dropped, reordered, cut off or moved to another object,
// owner or key version without failing authentication.
//
// Objects live at "<kind>/<owner id>/<fp[0:2]>/<fp>", where the fingerprint is hex(HMAC-SHA256(index key, SHA-256 of
// the content)): stable within one owner, unrelated between owners.
package blobcrypt

import (
	"crypto/hkdf"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"fmt"
	"regexp"

	"golang.org/x/crypto/chacha20poly1305"
)

const (
	magic       = "TRB1"
	HeaderBytes = 40
	FrameBytes  = 64 << 10
	tagBytes    = chacha20poly1305.Overhead
)

// Object kinds: the first part of an object's key.
const (
	KindRaw         = "raw"
	KindAttachments = "attachments"
	KindOutgoing    = "outgoing"
	KindDrafts      = "drafts"
)

// ErrAuthentication means an object failed authentication: tampered with, cut off, or not the object it claims to be.
var ErrAuthentication = errors.New("object could not be authenticated")

var (
	encryptedKey = regexp.MustCompile(`^([a-z]+)/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/([0-9a-f]{2})/([0-9a-f]{64})$`)
	legacyKey    = regexp.MustCompile(`^([a-z]+)/([0-9a-f]{2})/([0-9a-f]{64})$`)
)

// Fingerprint names content within one owner: hex(HMAC-SHA256(index key, SHA-256 of the content)).
func Fingerprint(indexKey, content []byte) string {
	sum := sha256.Sum256(content)
	mac := hmac.New(sha256.New, indexKey)
	mac.Write(sum[:])
	return hex.EncodeToString(mac.Sum(nil))
}

// Key is where an owner's content of a kind is stored.
func Key(kind, ownerID, fingerprint string) string {
	return kind + "/" + ownerID + "/" + fingerprint[:2] + "/" + fingerprint
}

// ParsedKey is an encrypted object's key taken apart.
type ParsedKey struct {
	Kind, OwnerID, Fingerprint string
}

// ParseKey takes an encrypted object's key apart; ok is false for any other key.
func ParseKey(key string) (ParsedKey, bool) {
	m := encryptedKey.FindStringSubmatch(key)
	if m == nil || m[4][:2] != m[3] {
		return ParsedKey{}, false
	}
	return ParsedKey{Kind: m[1], OwnerID: m[2], Fingerprint: m[4]}, true
}

// IsLegacy tells whether a key names plaintext content stored before encryption, under its plain SHA-256.
func IsLegacy(key string) bool {
	m := legacyKey.FindStringSubmatch(key)
	return m != nil && m[3][:2] == m[2]
}

// Encrypt encrypts content with an owner's blob key of a key version.
func Encrypt(blobKey []byte, version uint32, ownerID, fingerprint string, content []byte) ([]byte, error) {
	header := make([]byte, HeaderBytes)
	copy(header, magic)
	binary.BigEndian.PutUint32(header[4:8], version)
	if _, err := rand.Read(header[8:]); err != nil {
		return nil, err
	}
	aead, ad, err := frameCipher(blobKey, header, ownerID, fingerprint)
	if err != nil {
		return nil, err
	}

	frames := len(content)/FrameBytes + 1
	if len(content) > 0 && len(content)%FrameBytes == 0 {
		frames--
	}
	out := make([]byte, 0, HeaderBytes+len(content)+frames*tagBytes)
	out = append(out, header...)
	for i := 0; i < frames; i++ {
		end := min((i+1)*FrameBytes, len(content))
		out = aead.Seal(out, nonce(uint64(i), i == frames-1), content[i*FrameBytes:end], ad)
	}
	return out, nil
}

// Decrypt decrypts an object; keyFor gives the owner's blob key of the version the object names.
func Decrypt(object []byte, keyFor func(version uint32) ([]byte, error), ownerID, fingerprint string) ([]byte, error) {
	version, err := Version(object)
	if err != nil {
		return nil, err
	}
	blobKey, err := keyFor(version)
	if err != nil {
		return nil, err
	}
	header := object[:HeaderBytes]
	aead, ad, err := frameCipher(blobKey, header, ownerID, fingerprint)
	if err != nil {
		return nil, err
	}

	sealed := object[HeaderBytes:]
	out := make([]byte, 0, len(sealed))
	for i := uint64(0); ; i++ {
		last := len(sealed) <= FrameBytes+tagBytes
		end := len(sealed)
		if !last {
			end = FrameBytes + tagBytes
		}
		out, err = aead.Open(out, nonce(i, last), sealed[:end], ad)
		if err != nil {
			return nil, fmt.Errorf("%w: frame %d", ErrAuthentication, i)
		}
		if last {
			return out, nil
		}
		sealed = sealed[end:]
	}
}

// Version is the key version an object was written with, from its header.
func Version(object []byte) (uint32, error) {
	if len(object) < HeaderBytes || string(object[:4]) != magic {
		return 0, fmt.Errorf("%w: not an encrypted object", ErrAuthentication)
	}
	return binary.BigEndian.Uint32(object[4:8]), nil
}

func frameCipher(blobKey, header []byte, ownerID, fingerprint string) (cipherAEAD, []byte, error) {
	owner, err := uuidBytes(ownerID)
	if err != nil {
		return nil, nil, err
	}
	fp, err := hex.DecodeString(fingerprint)
	if err != nil || len(fp) != sha256.Size {
		return nil, nil, fmt.Errorf("invalid fingerprint %q", fingerprint)
	}
	frameKey, err := hkdf.Key(sha256.New, blobKey, header[8:HeaderBytes], "tresso:blob-frame:v1", chacha20poly1305.KeySize)
	if err != nil {
		return nil, nil, err
	}
	aead, err := chacha20poly1305.NewX(frameKey)
	if err != nil {
		return nil, nil, err
	}
	ad := make([]byte, 0, HeaderBytes+len(owner)+len(fp))
	ad = append(append(append(ad, header...), owner...), fp...)
	return aead, ad, nil
}

type cipherAEAD interface {
	Seal(dst, nonce, plaintext, additionalData []byte) []byte
	Open(dst, nonce, ciphertext, additionalData []byte) ([]byte, error)
}

func nonce(frame uint64, last bool) []byte {
	n := make([]byte, chacha20poly1305.NonceSizeX)
	binary.BigEndian.PutUint64(n[15:23], frame)
	if last {
		n[23] = 1
	}
	return n
}

func uuidBytes(id string) ([]byte, error) {
	if len(id) != 36 || id[8] != '-' || id[13] != '-' || id[18] != '-' || id[23] != '-' {
		return nil, fmt.Errorf("invalid owner id %q", id)
	}
	b, err := hex.DecodeString(id[0:8] + id[9:13] + id[14:18] + id[19:23] + id[24:])
	if err != nil {
		return nil, fmt.Errorf("invalid owner id %q", id)
	}
	return b, nil
}
