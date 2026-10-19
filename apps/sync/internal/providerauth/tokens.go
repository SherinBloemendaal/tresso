// Package providerauth hands out the access token of one Gmail or Microsoft account. The API keeps the refresh token
// and renews access tokens at the provider; the worker only ever sees short-lived access tokens.
package providerauth

import (
	"context"
	"sync"
	"time"

	"tresso/sync/internal/api"
)

// CredentialsAPI is the part of the internal API that hands out credentials.
type CredentialsAPI interface {
	Credentials(ctx context.Context, accountID, workerID string) (api.Credentials, error)
	RefreshCredentials(ctx context.Context, accountID, workerID string) (api.Credentials, error)
}

// expiryMargin is how long before its expiry a token is no longer used, so a request never starts with a token that
// expires on the way.
const expiryMargin = 2 * time.Minute

// TokenSource caches an account's access token until shortly before it expires. It is safe for concurrent use; a
// burst of requests that all find the token refused renews it once.
type TokenSource struct {
	api       CredentialsAPI
	accountID string
	workerID  string
	now       func() time.Time

	mu         sync.Mutex
	token      string
	validUntil time.Time
}

func New(client CredentialsAPI, accountID, workerID string) *TokenSource {
	return &TokenSource{api: client, accountID: accountID, workerID: workerID, now: time.Now}
}

// FromCredentials returns a TokenSource that starts with the access token of credentials the caller already fetched,
// so the first request does not ask the API again.
func FromCredentials(client CredentialsAPI, credentials api.Credentials, workerID string) (*TokenSource, error) {
	t := New(client, credentials.AccountID, workerID)
	t.mu.Lock()
	defer t.mu.Unlock()
	if err := t.useLocked(credentials); err != nil {
		return nil, err
	}
	return t, nil
}

// Token returns the cached access token, or fetches the current one from the API. Errors wrap api.ErrReauthorize when
// the user has to sign in with the provider again.
func (t *TokenSource) Token(ctx context.Context) (string, error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.token != "" && t.now().Before(t.validUntil) {
		return t.token, nil
	}
	return t.fetchLocked(ctx, false)
}

// Invalidate reports that the provider refused rejected. Unless another request already replaced that token, the API
// is asked to renew it at the provider.
func (t *TokenSource) Invalidate(ctx context.Context, rejected string) error {
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.token != "" && t.token != rejected && t.now().Before(t.validUntil) {
		return nil
	}
	_, err := t.fetchLocked(ctx, true)
	return err
}

func (t *TokenSource) fetchLocked(ctx context.Context, rejected bool) (string, error) {
	fetch := t.api.Credentials
	if rejected {
		fetch = t.api.RefreshCredentials
	}
	t.token, t.validUntil = "", time.Time{}
	creds, err := fetch(ctx, t.accountID, t.workerID)
	if err != nil {
		return "", err
	}
	if err := t.useLocked(creds); err != nil {
		return "", err
	}
	return t.token, nil
}

func (t *TokenSource) useLocked(creds api.Credentials) error {
	token, expiresAt, err := creds.AccessToken()
	if err != nil {
		return err
	}

	// A token the API hands out with less than the margin left is still used until it expires: asking again would
	// return the same token. A provider that refuses it leads to Invalidate, which renews it.
	validUntil := expiresAt.Add(-expiryMargin)
	if !t.now().Before(validUntil) {
		validUntil = expiresAt
	}
	t.token, t.validUntil = token, validUntil
	return nil
}
