package graphsync

import (
	"context"
	"errors"
	"strings"
	"time"

	"tresso/sync/internal/providersend"
)

// DraftsStore keeps the outbox's draft copies in the mailbox's Drafts folder through Graph. It satisfies
// outbox.DraftsStore.
type DraftsStore struct {
	ctx    context.Context
	client *Client
}

// OpenDrafts returns the mailbox's drafts; it opens no connection, and every mailbox has a Drafts folder.
func OpenDrafts(ctx context.Context, client *Client) *DraftsStore {
	return &DraftsStore{ctx: ctx, client: client}
}

// AppendDraft creates a draft from raw (POST /me/messages with the MIME, which Graph files in Drafts) and marks it
// with the TressoDraftCopy extended property (providersend.DraftCopyMarker of its Message-ID), which Remove filters
// on. The marker also goes in providersend.DraftCopyHeader, as on copies filed before the property. Graph dates the
// draft itself, so at is not used. Graph takes at most 4 MB per request; a larger copy fails permanently.
func (s *DraftsStore) AppendDraft(raw []byte, _ time.Time) error {
	messageID := strings.Trim(providersend.HeaderValue(raw, "Message-ID"), "<> ")
	if messageID == "" {
		return &providersend.Error{Permanent: true, Message: "The draft has no Message-ID.", Err: errors.New("draft without Message-ID")}
	}
	marker := providersend.DraftCopyMarker(messageID)
	marked, err := providersend.SetHeader(raw, providersend.DraftCopyHeader, marker)
	if err != nil {
		return err
	}
	_, err = s.client.createDraft(s.ctx, marked, draftCopyProperty, marker)
	return err
}

// Remove deletes the copies in Drafts filed with this Message-ID (without angle brackets) for good, not into Deleted
// Items: found by the TressoDraftCopy property, and for copies filed before it by Message-ID or by the marker in
// providersend.DraftCopyHeader. A copy that is gone already counts as removed.
//
// The header is only read on drafts created after the copy could have been made (draftsSince). A copy not found there
// was never filed or is gone: it counts as removed, so the removal never comes back for ever.
func (s *DraftsStore) Remove(messageID string) error {
	// Copies filed since drafts are marked carry the TressoDraftCopy property: one filtered request.
	marked, err := s.client.findByProperty(s.ctx, "/me/mailFolders/drafts/messages", draftCopyProperty, providersend.DraftCopyMarker(messageID))
	if err != nil {
		return mapAccessError(err)
	}
	var ids []string
	for _, m := range marked {
		ids = append(ids, m.ID)
	}
	if len(ids) > 0 {
		return s.deleteAll(ids)
	}

	// Older copies: by Message-ID, then by the header among drafts since the draft started.
	filter := "internetMessageId eq '<" + strings.ReplaceAll(messageID, "'", "''") + ">'"
	link := "/me/mailFolders/drafts/messages?$select=id&$top=100&$filter=" + queryEscape(filter)
	for link != "" {
		var page struct {
			Value []struct {
				ID string `json:"id"`
			} `json:"value"`
			NextLink string `json:"@odata.nextLink"`
		}
		if err := s.client.get(s.ctx, link, "", &page); err != nil {
			return err
		}
		for _, draft := range page.Value {
			ids = append(ids, draft.ID)
		}
		link = page.NextLink
	}
	if len(ids) == 0 {
		since := "createdDateTime ge " + graphTime(draftsSince(messageID, time.Now()))
		found, err := s.client.findByHeader(s.ctx, "drafts", since, providersend.DraftCopyHeader, providersend.DraftCopyMarker(messageID))
		if err != nil {
			return mapAccessError(err)
		}
		ids = found
	}
	return s.deleteAll(ids)
}

func (s *DraftsStore) deleteAll(ids []string) error {
	for _, id := range ids {
		if err := s.client.deleteMessage(s.ctx, id); err != nil {
			return err
		}
	}
	return nil
}

func (s *DraftsStore) Close() {}

// draftCopyWindow bounds how far back a draft copy is looked for when its Message-ID does not say when the draft was
// started.
const draftCopyWindow = 30 * 24 * time.Hour

// draftClockMargin allows for the clock of the device that chose the draft's id: clients make draft ids, so a device
// whose clock runs ahead would otherwise start the search after its copies were filed.
const draftClockMargin = 24 * time.Hour

// draftsSince is the earliest a copy with this Message-ID can have been filed: the API composes a draft copy's
// Message-ID from the draft's id (<draft id>.<version>@domain), and a UUIDv7 id says when the draft was started.
func draftsSince(messageID string, now time.Time) time.Time {
	draftID, _, _ := strings.Cut(messageID, ".")
	if started, ok := providersend.UUIDv7Time(draftID); ok {
		return started.Add(-draftClockMargin)
	}
	return now.Add(-draftCopyWindow)
}
