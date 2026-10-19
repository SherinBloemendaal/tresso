package gmailsync

import (
	"slices"
	"strings"

	"tresso/sync/internal/api"
	"tresso/sync/internal/ingestmsg"
)

// allMailFolder is a folder that Gmail does not have as a label: every message outside Trash and Spam. Archived mail
// has no label at all, so without it archiving would delete the message from Tresso.
const allMailFolder = "tresso:all"

const (
	labelInbox   = "INBOX"
	labelSent    = "SENT"
	labelDraft   = "DRAFT"
	labelTrash   = "TRASH"
	labelSpam    = "SPAM"
	labelUnread  = "UNREAD"
	labelStarred = "STARRED"
	labelChat    = "CHAT"
)

// The system labels that are folders, in the order users expect them. UNREAD and STARRED are flags; IMPORTANT,
// CATEGORY_* and the rest are Gmail's own sorting, not places mail is filed in.
var systemFolders = []api.Folder{
	{RemoteName: labelInbox, DisplayName: "Inbox", Role: "inbox"},
	{RemoteName: labelSent, DisplayName: "Sent", Role: "sent"},
	{RemoteName: labelDraft, DisplayName: "Drafts", Role: "drafts"},
	{RemoteName: labelTrash, DisplayName: "Trash", Role: "trash"},
	{RemoteName: labelSpam, DisplayName: "Spam", Role: "spam"},
}

// foldersFromLabels lists the account's folders: the system folders it has, All Mail, then the user labels by name.
// A folder's remote name is the label id, so renaming a label keeps its messages; nested labels keep their "/" path.
func foldersFromLabels(labels []label) []api.Folder {
	has := map[string]bool{}
	var user []api.Folder
	for _, l := range labels {
		has[l.ID] = true
		if l.Type == "user" {
			user = append(user, api.Folder{RemoteName: l.ID, DisplayName: ingestmsg.Truncate(l.Name, 255), Role: "custom"})
		}
	}
	slices.SortFunc(user, func(a, b api.Folder) int {
		return strings.Compare(strings.ToLower(a.DisplayName), strings.ToLower(b.DisplayName))
	})

	folders := make([]api.Folder, 0, len(systemFolders)+1+len(user))
	for _, f := range systemFolders {
		if has[f.RemoteName] {
			folders = append(folders, f)
		}
	}
	folders = append(folders, api.Folder{RemoteName: allMailFolder, DisplayName: "All Mail", Role: "all"})
	return append(folders, user...)
}

// foldersOf returns the folders a message with these labels is in. Chat messages are not mail and are in none.
func foldersOf(labelIDs []string, isFolder map[string]bool) []string {
	if slices.Contains(labelIDs, labelChat) {
		return nil
	}
	var out []string
	hidden := false
	for _, id := range labelIDs {
		if id == labelTrash || id == labelSpam {
			hidden = true
		}
		if isFolder[id] && id != allMailFolder {
			out = append(out, id)
		}
	}
	if !hidden {
		out = append(out, allMailFolder)
	}
	return out
}

// flagsOf maps labels to flags. Gmail has no answered state (it threads replies instead), so Answered stays false.
func flagsOf(labelIDs []string) api.Flags {
	return api.Flags{
		Seen:    !slices.Contains(labelIDs, labelUnread),
		Flagged: slices.Contains(labelIDs, labelStarred),
		Draft:   slices.Contains(labelIDs, labelDraft),
	}
}
