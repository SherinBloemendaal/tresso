package blobcrypt

import (
	"bytes"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"testing"
)

const owner = "0192d7a0-0000-7000-8000-000000000001"

func key(t *testing.T) []byte {
	t.Helper()
	k := make([]byte, 32)
	if _, err := rand.Read(k); err != nil {
		t.Fatal(err)
	}
	return k
}

func keyFor(k []byte) func(uint32) ([]byte, error) {
	return func(uint32) ([]byte, error) { return k, nil }
}

func TestRoundTripAtFrameBoundaries(t *testing.T) {
	blobKey, index := key(t), key(t)
	for _, size := range []int{0, 1, FrameBytes - 1, FrameBytes, FrameBytes + 1, 3*FrameBytes + 17} {
		content := make([]byte, size)
		_, _ = rand.Read(content)
		fp := Fingerprint(index, content)
		sealed, err := Encrypt(blobKey, 3, owner, fp, content)
		if err != nil {
			t.Fatal(err)
		}
		frames := size/FrameBytes + 1
		if size > 0 && size%FrameBytes == 0 {
			frames--
		}
		if len(sealed) != HeaderBytes+size+frames*16 {
			t.Errorf("size %d: %d bytes sealed, want %d", size, len(sealed), HeaderBytes+size+frames*16)
		}
		if v, _ := Version(sealed); v != 3 {
			t.Errorf("size %d: version %d", size, v)
		}
		opened, err := Decrypt(sealed, keyFor(blobKey), owner, fp)
		if err != nil || !bytes.Equal(opened, content) {
			t.Errorf("size %d: round trip failed: %v", size, err)
		}
	}
}

func TestTamperingIsDetected(t *testing.T) {
	blobKey := key(t)
	content := bytes.Repeat([]byte("mail "), FrameBytes/2)
	fp := Fingerprint(key(t), content)
	sealed, err := Encrypt(blobKey, 1, owner, fp, content)
	if err != nil {
		t.Fatal(err)
	}
	frame := FrameBytes + 16

	cases := map[string][]byte{
		"flipped bit":       flip(sealed, HeaderBytes+100),
		"flipped header":    flip(sealed, 10),
		"cut off":           sealed[:HeaderBytes+frame],
		"last byte dropped": sealed[:len(sealed)-1],
		"frames swapped":    swapFrames(sealed, frame),
		"frame appended":    append(append([]byte{}, sealed...), sealed[HeaderBytes:HeaderBytes+frame]...),
		"header only":       sealed[:HeaderBytes],
	}
	for name, object := range cases {
		if _, err := Decrypt(object, keyFor(blobKey), owner, fp); !errors.Is(err, ErrAuthentication) {
			t.Errorf("%s: err = %v, want ErrAuthentication", name, err)
		}
	}
}

func TestBoundToOwnerFingerprintAndKey(t *testing.T) {
	blobKey := key(t)
	content := []byte("Subject: hi\r\n\r\nhello")
	fp := Fingerprint(key(t), content)
	sealed, err := Encrypt(blobKey, 1, owner, fp, content)
	if err != nil {
		t.Fatal(err)
	}
	other := Fingerprint(key(t), content)
	if _, err := Decrypt(sealed, keyFor(blobKey), "0192d7a0-0000-7000-8000-000000000002", fp); !errors.Is(err, ErrAuthentication) {
		t.Errorf("another owner: %v", err)
	}
	if _, err := Decrypt(sealed, keyFor(blobKey), owner, other); !errors.Is(err, ErrAuthentication) {
		t.Errorf("another fingerprint: %v", err)
	}
	if _, err := Decrypt(sealed, keyFor(key(t)), owner, fp); !errors.Is(err, ErrAuthentication) {
		t.Errorf("another key: %v", err)
	}
}

func TestFingerprintsDifferPerOwner(t *testing.T) {
	content := []byte("the same invoice")
	a, b := Fingerprint(key(t), content), Fingerprint(key(t), content)
	if a == b {
		t.Error("two owners' fingerprints of the same content are equal")
	}
	index := key(t)
	if Fingerprint(index, content) != Fingerprint(index, append([]byte(nil), content...)) {
		t.Error("one owner's fingerprint is not stable")
	}
}

func TestKeys(t *testing.T) {
	fp := Fingerprint(key(t), []byte("x"))
	k := Key(KindRaw, owner, fp)
	parsed, ok := ParseKey(k)
	if !ok || parsed != (ParsedKey{Kind: KindRaw, OwnerID: owner, Fingerprint: fp}) {
		t.Errorf("ParseKey(%q) = %+v, %v", k, parsed, ok)
	}
	if IsLegacy(k) {
		t.Errorf("%q is not a legacy key", k)
	}
	legacy := "raw/" + fp[:2] + "/" + fp
	if !IsLegacy(legacy) {
		t.Errorf("%q is a legacy key", legacy)
	}
	if _, ok := ParseKey(legacy); ok {
		t.Errorf("%q parsed as encrypted", legacy)
	}
	if _, ok := ParseKey("raw/" + owner + "/zz/" + fp); ok {
		t.Error("a key whose prefix does not match its fingerprint parsed")
	}
}

// The API encrypted this object (apps/api/tests/Unit/Storage/BlobFormatTest); the worker must read it.
func TestReadsWhatTheAPIWrites(t *testing.T) {
	var f fixture
	data, err := os.ReadFile("testdata/php-object.json")
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(data, &f); err != nil {
		t.Fatal(err)
	}
	if v, _ := base64Version(f.Object); v != f.Version {
		t.Errorf("version %d, want %d", v, f.Version)
	}
	if !f.opens() {
		t.Fatal("the API's object does not decrypt")
	}
}

func base64Version(object string) (uint32, error) {
	raw, err := base64.StdEncoding.DecodeString(object)
	if err != nil {
		return 0, err
	}
	return Version(raw)
}

func swapFrames(sealed []byte, frame int) []byte {
	out := append([]byte{}, sealed[:HeaderBytes]...)
	out = append(out, sealed[HeaderBytes+frame:HeaderBytes+2*frame]...)
	out = append(out, sealed[HeaderBytes:HeaderBytes+frame]...)
	return append(out, sealed[HeaderBytes+2*frame:]...)
}

func flip(b []byte, i int) []byte {
	out := append([]byte(nil), b...)
	out[i] ^= 1
	return out
}

func benchmarkContent(b *testing.B, size int) ([]byte, []byte, string) {
	b.Helper()
	blobKey := make([]byte, 32)
	content := make([]byte, size)
	_, _ = rand.Read(content)
	return blobKey, content, Fingerprint(blobKey, content)
}

func BenchmarkEncrypt10MiB(b *testing.B) {
	blobKey, content, fp := benchmarkContent(b, 10<<20)
	b.SetBytes(int64(len(content)))
	for b.Loop() {
		if _, err := Encrypt(blobKey, 1, owner, fp, content); err != nil {
			b.Fatal(err)
		}
	}
}

func BenchmarkDecrypt10MiB(b *testing.B) {
	blobKey, content, fp := benchmarkContent(b, 10<<20)
	sealed, err := Encrypt(blobKey, 1, owner, fp, content)
	if err != nil {
		b.Fatal(err)
	}
	b.SetBytes(int64(len(content)))
	for b.Loop() {
		if _, err := Decrypt(sealed, keyFor(blobKey), owner, fp); err != nil {
			b.Fatal(err)
		}
	}
}
