package imapsync

import (
	"context"
	"errors"
	"fmt"
	"strconv"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapclient"

	"tresso/sync/internal/api"
)

// ErrFolderReset means the folder's UIDVALIDITY changed since the uid was learnt: the uid may name another message.
var ErrFolderReset = errors.New("the folder was reset on the server")

// ErrMessageGone means the copy is no longer in the folder, e.g. another mail app moved or deleted it.
var ErrMessageGone = errors.New("the message is no longer in the folder")

// Mailbox makes the user's changes (flags, moves) on the server, over its own connection next to the one that syncs.
type Mailbox struct {
	client   *imapclient.Client
	selected string
	validity uint32
}

// OpenMailbox logs in for making changes.
func OpenMailbox(ctx context.Context, d Dialer, credentials api.Credentials) (*Mailbox, error) {
	password, err := credentials.Password()
	if err != nil {
		return nil, err
	}
	client, err := connect(ctx, d, credentials.Connection.IMAP, credentials.Connection.Username, password, make(chan struct{}, 1))
	if err != nil {
		return nil, err
	}
	return &Mailbox{client: client}, nil
}

// SetFlags sets \Seen and/or \Flagged on the copy (nil leaves a flag alone). A copy that is gone needs nothing.
func (m *Mailbox) SetFlags(location api.MailboxLocation, seen, flagged *bool) error {
	uid, err := m.open(location)
	if err != nil {
		return err
	}
	var add, remove []imap.Flag
	for _, flag := range []struct {
		want *bool
		name imap.Flag
	}{{seen, imap.FlagSeen}, {flagged, imap.FlagFlagged}} {
		switch {
		case flag.want == nil:
		case *flag.want:
			add = append(add, flag.name)
		default:
			remove = append(remove, flag.name)
		}
	}
	set := imap.UIDSetNum(uid)
	if len(add) > 0 {
		if err := m.client.Store(set, &imap.StoreFlags{Op: imap.StoreFlagsAdd, Silent: true, Flags: add}, nil).Close(); err != nil {
			return fmt.Errorf("set flags in %s: %w", location.Folder, err)
		}
	}
	if len(remove) > 0 {
		if err := m.client.Store(set, &imap.StoreFlags{Op: imap.StoreFlagsDel, Silent: true, Flags: remove}, nil).Close(); err != nil {
			return fmt.Errorf("clear flags in %s: %w", location.Folder, err)
		}
	}
	return nil
}

// Move moves the copy to the target folder and returns its uid there when the server says (UIDPLUS), else "".
//
// Servers without MOVE get COPY and \Deleted on the source; it is expunged only with UIDPLUS, so that only this copy
// goes: expunging the whole folder would also erase messages another mail app merely marked deleted.
func (m *Mailbox) Move(source api.MailboxLocation, target string) (string, error) {
	uid, err := m.open(source)
	if err != nil {
		return "", err
	}
	set := imap.UIDSetNum(uid)
	// A uid that is gone would make MOVE succeed without doing anything; say so instead.
	found, err := m.client.Fetch(set, &imap.FetchOptions{UID: true}).Collect()
	if err != nil {
		return "", fmt.Errorf("look up the message in %s: %w", source.Folder, err)
	}
	if len(found) == 0 {
		return "", ErrMessageGone
	}

	caps := m.client.Caps()
	var dest imap.NumSet
	if caps.Has(imap.CapMove) || caps.Has(imap.CapUIDPlus) {
		data, err := m.client.Move(set, target).Wait()
		if err != nil {
			return "", fmt.Errorf("move from %s to %s: %w", source.Folder, target, err)
		}
		dest = data.DestUIDs
	} else {
		data, err := m.client.Copy(set, target).Wait()
		if err != nil {
			return "", fmt.Errorf("copy from %s to %s: %w", source.Folder, target, err)
		}
		dest = data.DestUIDs
		if err := m.client.Store(set, &imap.StoreFlags{Op: imap.StoreFlagsAdd, Silent: true, Flags: []imap.Flag{imap.FlagDeleted}}, nil).Close(); err != nil {
			return "", fmt.Errorf("mark the moved message deleted in %s: %w", source.Folder, err)
		}
	}
	if uids, ok := dest.(imap.UIDSet); ok {
		if nums, ok := uids.Nums(); ok && len(nums) == 1 {
			return strconv.FormatUint(uint64(nums[0]), 10), nil
		}
	}
	return "", nil
}

func (m *Mailbox) open(location api.MailboxLocation) (imap.UID, error) {
	uid, err := strconv.ParseUint(location.RemoteUID, 10, 32)
	if err != nil || uid == 0 {
		return 0, fmt.Errorf("%q is not an IMAP uid", location.RemoteUID)
	}
	if m.selected != location.Folder {
		data, err := m.client.Select(location.Folder, nil).Wait()
		if err != nil {
			m.selected = ""
			return 0, fmt.Errorf("select %s: %w", location.Folder, err)
		}
		m.selected, m.validity = location.Folder, data.UIDValidity
	}
	if location.UIDValidity != nil && *location.UIDValidity != m.validity {
		return 0, ErrFolderReset
	}
	return imap.UID(uid), nil
}

func (m *Mailbox) Close() {
	_ = m.client.Logout().Wait()
	_ = m.client.Close()
}
