// Package outbox does the account work users wait for: for each account this worker leases, it sends the mail that is
// due (SMTP, a copy in Sent, the outcome reported) and keeps the Drafts folder in step with the user's drafts.
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
	ClaimDrafts(ctx context.Context, accountID, workerID string) (api.DraftWork, error)
	ReportDraftFiled(ctx context.Context, accountID, draftID, workerID string, version int, messageID string) error
	ReportDraftRemoved(ctx context.Context, accountID, removalID, workerID string) error
}

// SentStore is the account's Sent folder.
type SentStore interface {
	Contains(messageID string) (bool, error)
	Append(raw []byte, at time.Time) error
	Close()
}

// DraftsStore is the account's Drafts folder.
type DraftsStore interface {
	AppendDraft(raw []byte, at time.Time) error
	Remove(messageID string) error
	Close()
}

// OpenDraftsFunc opens the account's Drafts folder, creating it when there is none.
type OpenDraftsFunc func(ctx context.Context, credentials api.Credentials) (DraftsStore, error)

// SendFunc delivers one message; smtpsend.Send in production.
type SendFunc func(ctx context.Context, server api.ServerSettings, username, password string, envelope smtpsend.Envelope, raw []byte) error

// OpenSentFunc opens the account's Sent folder; imapsync.OpenSent in production.
type OpenSentFunc func(ctx context.Context, credentials api.Credentials) (SentStore, error)

type Dispatcher struct {
	api        API
	store      storage.Reader
	send       SendFunc
	openSent   OpenSentFunc
	openDrafts OpenDraftsFunc
	workerID   string
	logger     *slog.Logger

	mu      sync.Mutex
	running map[string]*run
	wg      sync.WaitGroup
}

type run struct{ again bool }

func NewDispatcher(client API, store storage.Reader, send SendFunc, openSent OpenSentFunc, openDrafts OpenDraftsFunc, workerID string, logger *slog.Logger) *Dispatcher {
	return &Dispatcher{api: client, store: store, send: send, openSent: openSent, openDrafts: openDrafts, workerID: workerID, logger: logger, running: map[string]*run{}}
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
	d.sendOutbox(ctx, logger, credentials, password)
	d.fileDrafts(ctx, logger, credentials)
}

func (d *Dispatcher) sendOutbox(ctx context.Context, logger *slog.Logger, credentials api.Credentials, password string) {
	messages, err := d.api.ClaimOutbox(ctx, credentials.AccountID, d.workerID)
	if err != nil {
		logger.Warn("claiming outgoing mail failed", "error", err)
		return
	}

	sent := &lazy[SentStore]{open: func() (SentStore, error) { return d.openSent(ctx, credentials) }}
	defer sent.close()
	for _, message := range messages {
		d.deliver(ctx, logger.With("outgoing", message.ID), credentials, password, sent, message)
	}
}

// fileDrafts appends the newest copy of each draft and only then removes the copy it replaces, so a failure halfway
// leaves an extra copy rather than none. A failed step is not reported: the claim expires and the work comes back.
func (d *Dispatcher) fileDrafts(ctx context.Context, logger *slog.Logger, credentials api.Credentials) {
	work, err := d.api.ClaimDrafts(ctx, credentials.AccountID, d.workerID)
	if err != nil {
		logger.Warn("claiming draft work failed", "error", err)
		return
	}
	if len(work.Files) == 0 && len(work.Removals) == 0 {
		return
	}
	folder := &lazy[DraftsStore]{open: func() (DraftsStore, error) { return d.openDrafts(ctx, credentials) }}
	defer folder.close()
	drafts, err := folder.get()
	if err != nil {
		logger.Warn("opening the Drafts folder failed", "error", err)
		return
	}

	for _, file := range work.Files {
		fileLogger := logger.With("draft", file.DraftID, "version", file.Version)
		raw, err := d.store.Get(ctx, file.RawObjectKey)
		if err != nil {
			fileLogger.Warn("reading the draft copy failed", "error", err)
			continue
		}
		if err := drafts.AppendDraft(raw, time.Now()); err != nil {
			fileLogger.Warn("filing the draft failed", "error", err)
			continue
		}
		if file.PreviousMessageID != nil {
			if err := drafts.Remove(*file.PreviousMessageID); err != nil {
				fileLogger.Warn("removing the previous draft copy failed; it is removed with the next version", "error", err)
				continue
			}
		}
		if err := d.api.ReportDraftFiled(ctx, credentials.AccountID, file.DraftID, d.workerID, file.Version, file.MessageID); err != nil {
			fileLogger.Warn("reporting the filed draft failed", "error", err)
		}
	}
	for _, removal := range work.Removals {
		if err := drafts.Remove(removal.MessageID); err != nil {
			logger.Warn("removing a draft copy failed", "removal", removal.ID, "error", err)
			continue
		}
		if err := d.api.ReportDraftRemoved(ctx, credentials.AccountID, removal.ID, d.workerID); err != nil {
			logger.Warn("reporting the removed draft copy failed", "removal", removal.ID, "error", err)
		}
	}
}

func (d *Dispatcher) deliver(ctx context.Context, logger *slog.Logger, credentials api.Credentials, password string, sent *lazy[SentStore], message api.OutgoingMessage) {
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

// lazy opens a folder only when something needs it, once per run.
type lazy[T interface{ Close() }] struct {
	open   func() (T, error)
	store  T
	err    error
	opened bool
}

func (l *lazy[T]) get() (T, error) {
	if !l.opened {
		l.store, l.err = l.open()
		l.opened = true
	}
	return l.store, l.err
}

func (l *lazy[T]) close() {
	if l.opened && l.err == nil {
		l.store.Close()
	}
}
