package graphsync

import (
	"context"
	"encoding/base64"
	"errors"
	"net/http"
	"net/url"
	"strings"
	"time"

	"tresso/sync/internal/providerhttp"
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

// AppendDraft creates a draft from raw (POST /me/messages with the MIME, which Graph files in Drafts and whose
// internetMessageId is the Message-ID header). Graph dates the draft itself, so at is not used. Graph takes at most
// 4 MB per request; a larger copy fails permanently.
func (s *DraftsStore) AppendDraft(raw []byte, _ time.Time) error {
	// Marked with its Message-ID, should Exchange give the draft another one: Remove finds it by either.
	if messageID := strings.Trim(providersend.HeaderValue(raw, "Message-ID"), "<> "); messageID != "" {
		marked, err := providersend.SetHeader(raw, providersend.DraftCopyHeader, providersend.DraftCopyMarker(messageID))
		if err != nil {
			return err
		}
		raw = marked
	}
	body := []byte(base64.StdEncoding.EncodeToString(raw))
	if len(body) > maxSendBody {
		return &providersend.Error{Permanent: true, Message: "The draft is too large for Outlook, which takes at most 3 MB including attachments.", Err: errors.New("draft exceeds the request size limit")}
	}
	_, err := s.client.http.Do(s.ctx, providerhttp.Request{Method: http.MethodPost, URL: s.client.base + "/me/messages", ContentType: "text/plain", Body: body})
	return mapAccessError(err)
}

// Remove deletes every message in Drafts with this Message-ID (without angle brackets), or carrying its marker in
// providersend.DraftCopyHeader when Exchange replaced the Message-ID, for good, not into Deleted Items. A copy that is
// gone already counts as removed.
//
// The marker is only looked for among drafts created after the copy could have been made (draftsSince), at most
// maxScan of them. A copy not found there was never filed or is gone: it counts as removed, so the removal never
// comes back for ever.
func (s *DraftsStore) Remove(messageID string) error {
	filter := "internetMessageId eq '<" + strings.ReplaceAll(messageID, "'", "''") + ">'"
	var ids []string
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
		found, _, err := s.client.findByHeader(s.ctx, "drafts", since, providersend.DraftCopyHeader, providersend.DraftCopyMarker(messageID))
		if err != nil {
			return mapAccessError(err)
		}
		ids = found
	}
	for _, id := range ids {
		_, err := s.client.http.Do(s.ctx, providerhttp.Request{Method: http.MethodPost, URL: s.client.base + "/me/messages/" + url.PathEscape(id) + "/permanentDelete"})
		if err != nil && !providerhttp.IsStatus(err, http.StatusNotFound) {
			return mapAccessError(err)
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
