package imapsync

import (
	"strings"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapclient"

	"tresso/sync/internal/api"
)

// listFolders returns every selectable mailbox with its role and UIDVALIDITY.
func listFolders(c *imapclient.Client) ([]api.Folder, error) {
	options := &imap.ListOptions{}
	if c.Caps().Has(imap.CapSpecialUse) {
		options.ReturnSpecialUse = true
	}
	mailboxes, err := c.List("", "*", options).Collect()
	if err != nil {
		return nil, err
	}

	folders := make([]api.Folder, 0, len(mailboxes))
	for _, mailbox := range mailboxes {
		if hasAttr(mailbox.Attrs, imap.MailboxAttrNoSelect) || hasAttr(mailbox.Attrs, imap.MailboxAttrNonExistent) {
			continue
		}
		status, err := c.Status(mailbox.Mailbox, &imap.StatusOptions{UIDValidity: true}).Wait()
		if err != nil {
			return nil, err
		}
		uidValidity := status.UIDValidity
		folders = append(folders, api.Folder{
			RemoteName:  mailbox.Mailbox,
			DisplayName: displayName(mailbox.Mailbox, mailbox.Delim),
			Role:        roleOf(mailbox.Mailbox, mailbox.Attrs),
			UIDValidity: &uidValidity,
		})
	}

	// INBOX first: it is what users look at, so it syncs first.
	for i, f := range folders {
		if f.Role == "inbox" && i > 0 {
			folders[0], folders[i] = folders[i], folders[0]
			break
		}
	}
	return folders, nil
}

var roleByAttr = map[imap.MailboxAttr]string{
	imap.MailboxAttrSent:    "sent",
	imap.MailboxAttrDrafts:  "drafts",
	imap.MailboxAttrTrash:   "trash",
	imap.MailboxAttrJunk:    "spam",
	imap.MailboxAttrArchive: "archive",
	imap.MailboxAttrAll:     "all",
}

// Names servers use without SPECIAL-USE, in the languages Tresso's users have their mailboxes in.
var roleByName = map[string]string{
	"sent": "sent", "sent items": "sent", "sent messages": "sent", "sent mail": "sent", "verzonden items": "sent", "verzonden": "sent", "gesendet": "sent",
	"drafts": "drafts", "draft": "drafts", "concepten": "drafts", "entwürfe": "drafts",
	"trash": "trash", "deleted items": "trash", "deleted messages": "trash", "bin": "trash", "prullenbak": "trash", "verwijderde items": "trash", "papierkorb": "trash",
	"junk": "spam", "spam": "spam", "junk e-mail": "spam", "ongewenste e-mail": "spam",
	"archive": "archive", "archief": "archive", "archiv": "archive",
}

func roleOf(name string, attrs []imap.MailboxAttr) string {
	if strings.EqualFold(name, "INBOX") {
		return "inbox"
	}
	for _, attr := range attrs {
		for known, role := range roleByAttr {
			if strings.EqualFold(string(known), string(attr)) {
				return role
			}
		}
	}
	leaf := strings.ToLower(lastSegment(name, '/'))
	leaf = strings.ToLower(lastSegment(leaf, '.'))
	if role, ok := roleByName[leaf]; ok {
		return role
	}
	return "custom"
}

func displayName(name string, delim rune) string {
	if strings.EqualFold(name, "INBOX") {
		return "Inbox"
	}
	if delim == 0 {
		return name
	}
	return lastSegment(name, delim)
}

func lastSegment(name string, delim rune) string {
	if i := strings.LastIndex(name, string(delim)); i >= 0 && i < len(name)-1 {
		return name[i+1:]
	}
	return name
}

func hasAttr(attrs []imap.MailboxAttr, want imap.MailboxAttr) bool {
	for _, attr := range attrs {
		if strings.EqualFold(string(attr), string(want)) {
			return true
		}
	}
	return false
}
