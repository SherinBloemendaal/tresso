package imapsync

import (
	"context"
	"errors"
	"fmt"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapclient"

	"tresso/sync/internal/api"
)

// ErrNoSentFolder means the account has no folder marked or named as Sent.
var ErrNoSentFolder = errors.New("no sent folder")

// SentFolder files copies of sent mail, over its own connection next to the one that syncs.
type SentFolder struct {
	client *imapclient.Client
	name   string
}

// OpenSent logs in and finds the Sent folder: the one marked \Sent, or else the one named like it.
func OpenSent(ctx context.Context, d Dialer, credentials api.Credentials) (*SentFolder, error) {
	password, err := credentials.Password()
	if err != nil {
		return nil, err
	}
	client, err := connect(ctx, d, credentials.Connection.IMAP, credentials.Connection.Username, password, make(chan struct{}, 1))
	if err != nil {
		return nil, err
	}
	folders, err := listFolders(client)
	if err != nil {
		_ = client.Close()
		return nil, fmt.Errorf("list folders: %w", err)
	}
	for _, folder := range folders {
		if folder.Role == "sent" {
			return &SentFolder{client: client, name: folder.RemoteName}, nil
		}
	}
	_ = client.Close()
	return nil, ErrNoSentFolder
}

// Contains reports whether a message with this Message-ID (without angle brackets) is in the folder already: an
// earlier attempt may have sent it before the worker could report back.
func (f *SentFolder) Contains(messageID string) (bool, error) {
	if _, err := f.client.Select(f.name, &imap.SelectOptions{ReadOnly: true}).Wait(); err != nil {
		return false, fmt.Errorf("select %s: %w", f.name, err)
	}
	data, err := f.client.UIDSearch(&imap.SearchCriteria{
		Header: []imap.SearchCriteriaHeaderField{{Key: "Message-ID", Value: "<" + messageID + ">"}},
	}, nil).Wait()
	if err != nil {
		return false, fmt.Errorf("search %s: %w", f.name, err)
	}
	return len(data.AllUIDs()) > 0, nil
}

// Append files the message as read.
func (f *SentFolder) Append(raw []byte, at time.Time) error {
	command := f.client.Append(f.name, int64(len(raw)), &imap.AppendOptions{Flags: []imap.Flag{imap.FlagSeen}, Time: at})
	if _, err := command.Write(raw); err != nil {
		_ = command.Close()
		return fmt.Errorf("append to %s: %w", f.name, err)
	}
	if err := command.Close(); err != nil {
		return fmt.Errorf("append to %s: %w", f.name, err)
	}
	if _, err := command.Wait(); err != nil {
		return fmt.Errorf("append to %s: %w", f.name, err)
	}
	return nil
}

func (f *SentFolder) Close() {
	_ = f.client.Logout().Wait()
	_ = f.client.Close()
}
