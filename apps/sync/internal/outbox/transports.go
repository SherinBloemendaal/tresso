package outbox

import (
	"context"

	"tresso/sync/internal/api"
	"tresso/sync/internal/gmailsync"
	"tresso/sync/internal/graphsync"
	"tresso/sync/internal/imapsync"
	"tresso/sync/internal/providerauth"
	"tresso/sync/internal/smtpsend"
)

// IMAPTransport sends through the account's SMTP server with its password; the Sent copy, drafts and changes to
// messages go over IMAP.
func IMAPTransport(dialer imapsync.Dialer) Transport {
	return imapTransport{dialer: dialer}
}

type imapTransport struct{ dialer imapsync.Dialer }

func (t imapTransport) Open(credentials api.Credentials) (Sender, error) {
	password, err := credentials.Password()
	if err != nil {
		return nil, err
	}
	return &imapSender{dialer: t.dialer, credentials: credentials, password: password}, nil
}

type imapSender struct {
	dialer      imapsync.Dialer
	credentials api.Credentials
	password    string
}

func (s *imapSender) Send(ctx context.Context, envelope smtpsend.Envelope, raw []byte) error {
	return smtpsend.Send(ctx, s.dialer, s.credentials.Connection.SMTP, s.credentials.Connection.Username, s.password, envelope, raw)
}

func (s *imapSender) OpenSent(ctx context.Context) (SentStore, error) {
	return imapsync.OpenSent(ctx, s.dialer, s.credentials)
}

func (s *imapSender) OpenDrafts(ctx context.Context) (DraftsStore, error) {
	return imapsync.OpenDrafts(ctx, s.dialer, s.credentials)
}

func (s *imapSender) OpenMailbox(ctx context.Context) (MailboxStore, error) {
	return imapsync.OpenMailbox(ctx, s.dialer, s.credentials)
}

// GmailTransport sends through the Gmail API with the account's access token. A run shares one client, so a refused
// token is renewed once (rejected=1) for all its messages, and one rate limit covers them. Gmail files the sent copy
// itself; earlier attempts are looked up, drafts kept and changes to messages made through the Gmail API.
func GmailTransport(client providerauth.CredentialsAPI, workerID string, opts gmailsync.ClientOptions) Transport {
	return gmailTransport{api: client, workerID: workerID, opts: opts}
}

type gmailTransport struct {
	api      providerauth.CredentialsAPI
	workerID string
	opts     gmailsync.ClientOptions
}

func (t gmailTransport) Open(credentials api.Credentials) (Sender, error) {
	tokens, err := providerauth.FromCredentials(t.api, credentials, t.workerID)
	if err != nil {
		return nil, err
	}
	return &gmailSender{client: gmailsync.NewClient(tokens, t.opts)}, nil
}

type gmailSender struct{ client *gmailsync.Client }

func (s *gmailSender) Send(ctx context.Context, envelope smtpsend.Envelope, raw []byte) error {
	return gmailsync.Send(ctx, s.client, raw, envelope.Recipients)
}

func (s *gmailSender) OpenSent(ctx context.Context) (SentStore, error) {
	return gmailsync.OpenSent(ctx, s.client), nil
}

func (s *gmailSender) OpenDrafts(ctx context.Context) (DraftsStore, error) {
	return gmailsync.OpenDrafts(ctx, s.client), nil
}

func (s *gmailSender) OpenMailbox(ctx context.Context) (MailboxStore, error) {
	return gmailsync.OpenMailbox(ctx, s.client), nil
}

// GraphTransport sends through Microsoft Graph with the account's access token. A run shares one client, so a refused
// token is renewed once (rejected=1) for all its messages, and one rate limit covers them. Graph files the sent copy
// itself; earlier attempts are looked up, drafts kept and changes to messages made through Graph.
func GraphTransport(client providerauth.CredentialsAPI, workerID string, opts graphsync.ClientOptions) Transport {
	return graphTransport{api: client, workerID: workerID, opts: opts}
}

type graphTransport struct {
	api      providerauth.CredentialsAPI
	workerID string
	opts     graphsync.ClientOptions
}

func (t graphTransport) Open(credentials api.Credentials) (Sender, error) {
	tokens, err := providerauth.FromCredentials(t.api, credentials, t.workerID)
	if err != nil {
		return nil, err
	}
	return &graphSender{client: graphsync.NewClient(tokens, t.opts)}, nil
}

type graphSender struct{ client *graphsync.Client }

func (s *graphSender) Send(ctx context.Context, envelope smtpsend.Envelope, raw []byte) error {
	return graphsync.Send(ctx, s.client, raw, envelope.Recipients)
}

func (s *graphSender) OpenSent(ctx context.Context) (SentStore, error) {
	return graphsync.OpenSent(ctx, s.client), nil
}

func (s *graphSender) OpenDrafts(ctx context.Context) (DraftsStore, error) {
	return graphsync.OpenDrafts(ctx, s.client), nil
}

func (s *graphSender) OpenMailbox(ctx context.Context) (MailboxStore, error) {
	return graphsync.OpenMailbox(ctx, s.client), nil
}
