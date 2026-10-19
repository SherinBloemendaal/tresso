// Package outbox sends the mail users queue: for each account this worker leases, it claims what is due, delivers it
// over SMTP, files a copy in the Sent folder and reports the outcome.
package outbox

import (
	"context"
	"errors"
	"log/slog"
	"strings"
	"sync"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/smtpsend"
	"tresso/sync/internal/storage"
)

// API is the part of the internal API the dispatcher uses.
type API interface {
	Credentials(ctx context.Context, accountID, workerID string) (api.Credentials, error)
	ClaimOutbox(ctx context.Context, accountID, workerID string) ([]api.OutgoingMessage, error)
	ReportOutbox(ctx context.Context, accountID, messageID, workerID, outcome, errorText string, copiedToSent bool) error
}

// SentStore is the account's Sent folder.
type SentStore interface {
	Contains(messageID string) (bool, error)
	Append(raw []byte, at time.Time) error
	Close()
}

// SendFunc delivers one message; smtpsend.Send in production.
type SendFunc func(ctx context.Context, server api.ServerSettings, username, password string, envelope smtpsend.Envelope, raw []byte) error

// OpenSentFunc opens the account's Sent folder; imapsync.OpenSent in production.
type OpenSentFunc func(ctx context.Context, credentials api.Credentials) (SentStore, error)

type Dispatcher struct {
	api      API
	store    storage.Reader
	send     SendFunc
	openSent OpenSentFunc
	workerID string
	logger   *slog.Logger

	mu      sync.Mutex
	running map[string]*run
	wg      sync.WaitGroup
}

type run struct{ again bool }

func NewDispatcher(client API, store storage.Reader, send SendFunc, openSent OpenSentFunc, workerID string, logger *slog.Logger) *Dispatcher {
	return &Dispatcher{api: client, store: store, send: send, openSent: openSent, workerID: workerID, logger: logger, running: map[string]*run{}}
}

// Trigger makes the account's due mail go out. Runs for one account never overlap: a trigger during a run makes that
// run go round once more, so nothing is missed and nothing is sent twice by this worker.
func (d *Dispatcher) Trigger(ctx context.Context, accountID string) {
	d.mu.Lock()
	defer d.mu.Unlock()
	if r, ok := d.running[accountID]; ok {
		r.again = true
		return
	}
	r := &run{again: true}
	d.running[accountID] = r
	d.wg.Add(1)
	go func() {
		defer d.wg.Done()
		for {
			d.mu.Lock()
			if !r.again || ctx.Err() != nil {
				delete(d.running, accountID)
				d.mu.Unlock()
				return
			}
			r.again = false
			d.mu.Unlock()
			d.process(ctx, accountID)
		}
	}()
}

// Wait blocks until every run has finished.
func (d *Dispatcher) Wait() {
	d.wg.Wait()
}

func (d *Dispatcher) process(ctx context.Context, accountID string) {
	logger := d.logger.With("account", accountID)
	// Credentials first: a message is only claimed once this worker can actually send it.
	credentials, err := d.api.Credentials(ctx, accountID, d.workerID)
	if err != nil {
		logger.Warn("fetching credentials for sending failed", "error", err)
		return
	}
	password, err := credentials.Password()
	if err != nil {
		logger.Error("account has no password to send with", "error", err)
		return
	}
	messages, err := d.api.ClaimOutbox(ctx, accountID, d.workerID)
	if err != nil {
		logger.Warn("claiming outgoing mail failed", "error", err)
		return
	}

	sent := &lazySent{open: func() (SentStore, error) { return d.openSent(ctx, credentials) }}
	defer sent.close()
	for _, message := range messages {
		d.deliver(ctx, logger.With("outgoing", message.ID), credentials, password, sent, message)
	}
}

func (d *Dispatcher) deliver(ctx context.Context, logger *slog.Logger, credentials api.Credentials, password string, sent *lazySent, message api.OutgoingMessage) {
	raw, err := d.store.Get(ctx, message.RawObjectKey)
	if err != nil {
		if errors.Is(err, storage.ErrNotFound) {
			d.report(ctx, logger, credentials.AccountID, message.ID, api.OutcomeFailed, "The message could not be found any more.", false)
			return
		}
		d.report(ctx, logger, credentials.AccountID, message.ID, api.OutcomeRetry, "The message could not be read; trying again later.", false)
		return
	}

	// An earlier attempt may have reached the server before the worker could report it; never send twice.
	if message.Attempt > 1 {
		if store, err := sent.get(); err == nil {
			if found, err := store.Contains(message.MessageID); err == nil && found {
				logger.Info("outgoing message was sent by an earlier attempt")
				d.report(ctx, logger, credentials.AccountID, message.ID, api.OutcomeSent, "", true)
				return
			}
		}
	}

	envelope := smtpsend.Envelope{From: message.EnvelopeFrom, Recipients: message.Recipients}
	if err := d.send(ctx, credentials.Connection.SMTP, credentials.Connection.Username, password, envelope, raw); err != nil {
		var sendErr *smtpsend.Error
		outcome, text := api.OutcomeRetry, "Sending failed; trying again later."
		if errors.As(err, &sendErr) {
			text = sendErr.Message
			if sendErr.Permanent {
				outcome = api.OutcomeFailed
			}
		}
		logger.Warn("sending failed", "outcome", outcome, "error", err)
		d.report(ctx, logger, credentials.AccountID, message.ID, outcome, text, false)
		return
	}

	copied := true
	if !AutoSavesSent(credentials.Connection.SMTP.Host) {
		copied = false
		if store, err := sent.get(); err != nil {
			logger.Warn("opening the Sent folder failed", "error", err)
		} else if err := store.Append(raw, time.Now()); err != nil {
			logger.Warn("filing the sent copy failed", "error", err)
		} else {
			copied = true
		}
	}
	logger.Info("outgoing message sent", "copiedToSent", copied)
	d.report(ctx, logger, credentials.AccountID, message.ID, api.OutcomeSent, "", copied)
}

func (d *Dispatcher) report(ctx context.Context, logger *slog.Logger, accountID, messageID, outcome, text string, copied bool) {
	if err := d.api.ReportOutbox(ctx, accountID, messageID, d.workerID, outcome, text, copied); err != nil {
		if errors.Is(err, api.ErrClaimLost) {
			logger.Warn("claim on the outgoing message was lost before reporting", "outcome", outcome)
			return
		}
		logger.Error("reporting the outgoing message failed", "outcome", outcome, "error", err)
	}
}

// AutoSavesSent reports whether the provider files mail sent through its SMTP server in Sent by itself, so a copy
// from Tresso would show up twice.
func AutoSavesSent(smtpHost string) bool {
	host := strings.ToLower(strings.TrimSuffix(smtpHost, "."))
	switch host {
	case "smtp.gmail.com", "smtp.googlemail.com", "smtp.office365.com", "smtp-mail.outlook.com":
		return true
	}
	return false
}

// lazySent opens the Sent folder only when a message needs it, once per run.
type lazySent struct {
	open   func() (SentStore, error)
	store  SentStore
	err    error
	opened bool
}

func (l *lazySent) get() (SentStore, error) {
	if !l.opened {
		l.store, l.err = l.open()
		l.opened = true
	}
	return l.store, l.err
}

func (l *lazySent) close() {
	if l.store != nil {
		l.store.Close()
	}
}
