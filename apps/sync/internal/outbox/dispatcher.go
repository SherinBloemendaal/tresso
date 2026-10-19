// Package outbox does the account work users wait for: for each account this worker leases, it sends the mail that is
// due (SMTP, a copy in Sent, the outcome reported), keeps the Drafts folder in step with the user's drafts, and makes
// the user's changes to messages (read, flagged, moved) on the server.
package outbox

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"strings"
	"sync"
	"time"

	"github.com/emersion/go-imap/v2"

	"tresso/sync/internal/api"
	"tresso/sync/internal/imapsync"
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
	ClaimMailbox(ctx context.Context, accountID, workerID string) ([]api.MailboxChange, error)
	ReportMailbox(ctx context.Context, accountID, changeID, workerID, outcome, errorText, remoteUID string) error
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

// MailboxStore makes changes to the account's messages on the server.
type MailboxStore interface {
	SetFlags(location api.MailboxLocation, seen, flagged *bool) error
	Move(source api.MailboxLocation, target string) (string, error)
	Close()
}

// OpenMailboxFunc connects for making changes; imapsync.OpenMailbox in production.
type OpenMailboxFunc func(ctx context.Context, credentials api.Credentials) (MailboxStore, error)

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
	openMail   OpenMailboxFunc
	workerID   string
	logger     *slog.Logger

	mu      sync.Mutex
	running map[string]*run
	wg      sync.WaitGroup
}

type run struct{ again bool }

func NewDispatcher(client API, store storage.Reader, send SendFunc, openSent OpenSentFunc, openDrafts OpenDraftsFunc, openMail OpenMailboxFunc, workerID string, logger *slog.Logger) *Dispatcher {
	return &Dispatcher{api: client, store: store, send: send, openSent: openSent, openDrafts: openDrafts, openMail: openMail, workerID: workerID, logger: logger, running: map[string]*run{}}
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
	d.changeMailbox(ctx, logger, credentials)
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

// changeMailbox makes the user's changes to messages on the server, in the order they were made. A refusal is
// reported, so Tresso undoes the change; when the connection fails, the rest is left unreported and comes back once
// the claim expires, without counting as a failed attempt.
func (d *Dispatcher) changeMailbox(ctx context.Context, logger *slog.Logger, credentials api.Credentials) {
	changes, err := d.api.ClaimMailbox(ctx, credentials.AccountID, d.workerID)
	if err != nil {
		logger.Warn("claiming changes to messages failed", "error", err)
		return
	}
	if len(changes) == 0 {
		return
	}
	connection := &lazy[MailboxStore]{open: func() (MailboxStore, error) { return d.openMail(ctx, credentials) }}
	defer connection.close()
	mailbox, err := connection.get()
	if err != nil {
		logger.Warn("connecting to make changes to messages failed", "error", err)
		return
	}

	for _, change := range changes {
		changeLogger := logger.With("change", change.ID, "kind", change.Kind)
		remoteUID, err := applyChange(mailbox, change)
		outcome, text := api.MailboxDone, ""
		if err != nil {
			if !refused(err) {
				changeLogger.Warn("making a change to a message failed; it comes back later", "error", err)
				return
			}
			outcome, text = api.OutcomeFailed, err.Error()
			if retryable(change, err) {
				outcome = api.OutcomeRetry
			}
			changeLogger.Warn("the server did not make a change to a message", "outcome", outcome, "error", err)
		}
		if err := d.api.ReportMailbox(ctx, credentials.AccountID, change.ID, d.workerID, outcome, text, remoteUID); err != nil {
			changeLogger.Warn("reporting a change to a message failed", "error", err)
		}
	}
}

func applyChange(mailbox MailboxStore, change api.MailboxChange) (string, error) {
	switch change.Kind {
	case api.MailboxFlags:
		for _, location := range change.Locations {
			if err := mailbox.SetFlags(location, change.Seen, change.Flagged); err != nil {
				return "", err
			}
		}
		return "", nil
	case api.MailboxMove:
		if change.Source == nil || change.Target == nil {
			return "", fmt.Errorf("%w: a move without source or target", errInvalidChange)
		}
		return mailbox.Move(*change.Source, change.Target.Folder)
	}
	return "", fmt.Errorf("%w: unknown kind %q", errInvalidChange, change.Kind)
}

var errInvalidChange = errors.New("invalid change")

// refused reports whether the server answered (or the change could not be made as asked) rather than the connection
// failing: only an answer is worth reporting.
func refused(err error) bool {
	var response *imap.Error
	return errors.As(err, &response) || errors.Is(err, imapsync.ErrFolderReset) || errors.Is(err, imapsync.ErrMessageGone) || errors.Is(err, errInvalidChange)
}

// retryable reports whether a refusal may pass: the server is busy, or a flag change met a folder that was reset
// (the folder sync relearns its uids, after which the change applies to them).
func retryable(change api.MailboxChange, err error) bool {
	if errors.Is(err, imapsync.ErrFolderReset) {
		return change.Kind == api.MailboxFlags
	}
	var response *imap.Error
	if errors.As(err, &response) {
		switch response.Code {
		case imap.ResponseCodeUnavailable, imap.ResponseCodeInUse, imap.ResponseCodeLimit, imap.ResponseCodeServerBug, imap.ResponseCodeContactAdmin:
			return true
		}
	}
	return false
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
