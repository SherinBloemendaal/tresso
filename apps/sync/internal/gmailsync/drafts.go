package gmailsync

import (
	"context"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"time"

	"tresso/sync/internal/providerhttp"
	"tresso/sync/internal/providersend"
)

// costDraft is the quota cost of drafts.create and drafts.delete.
const costDraft = 10

// DraftsStore keeps the outbox's draft copies in Gmail's Drafts through the Gmail API. It satisfies
// outbox.DraftsStore.
type DraftsStore struct {
	ctx    context.Context
	client *Client
}

// OpenDrafts returns the account's drafts; it opens no connection, and Gmail always has a Drafts folder.
func OpenDrafts(ctx context.Context, client *Client) *DraftsStore {
	return &DraftsStore{ctx: ctx, client: client}
}

// AppendDraft creates a draft from raw, in the conversation of the message it answers. The copy is marked with its
// Message-ID in providersend.DraftCopyHeader, as Gmail may give it another one; Remove finds it by either. Gmail dates
// the draft itself, so at is not used. Large copies are uploaded, as Send does.
func (s *DraftsStore) AppendDraft(raw []byte, _ time.Time) error {
	if messageID := messageIDOf(raw); messageID != "" {
		marked, err := providersend.SetHeader(raw, providersend.DraftCopyHeader, providersend.DraftCopyMarker(messageID))
		if err != nil {
			return err
		}
		raw = marked
	}
	threadID, err := s.client.replyThread(s.ctx, raw)
	if err != nil {
		return mapAccessError(err)
	}
	req, err := s.client.messageRequest("/drafts", raw, threadID, costDraft)
	if err != nil {
		return err
	}
	return mapAccessError(s.client.http.JSON(s.ctx, req, nil))
}

// Remove deletes every draft whose message has this Message-ID (without angle brackets), or carries its marker in
// providersend.DraftCopyHeader when Gmail replaced the Message-ID. A draft that is gone already counts as removed.
//
// The marker is only looked for among drafts that came after the copy could have been made (draftsSince), and at
// most a week back. Gmail search cannot match custom headers, so each of those drafts costs a metadata read (5 quota
// units); batching would only save round trips, not quota, so the window is the bound. A copy not found there was
// never filed, is gone, or is older than a week and had its Message-ID replaced by Gmail: it counts as removed (the
// last case leaves an extra draft in Gmail, nothing is lost), so the removal never comes back for ever.
func (s *DraftsStore) Remove(messageID string) error {
	targets, err := s.drafts(url.Values{"q": {"rfc822msgid:" + messageID}}, 0)
	if err != nil {
		return err
	}
	if len(targets) == 0 {
		recent, err := s.drafts(url.Values{"q": {fmt.Sprintf("after:%d", draftsSince(messageID, time.Now()).Unix())}}, 0)
		if err != nil {
			return err
		}
		marker := providersend.DraftCopyMarker(messageID)
		for _, d := range recent {
			value, err := s.client.header(s.ctx, d.messageID, providersend.DraftCopyHeader)
			if providerhttp.IsStatus(err, http.StatusNotFound) {
				continue
			}
			if err != nil {
				return mapAccessError(err)
			}
			if value == marker {
				targets = append(targets, d)
			}
		}
	}
	for _, d := range targets {
		_, err := s.client.http.Do(s.ctx, providerhttp.Request{Method: http.MethodDelete, URL: s.client.base + "/drafts/" + url.PathEscape(d.id), Cost: costDraft})
		if err != nil && !providerhttp.IsStatus(err, http.StatusNotFound) {
			return mapAccessError(err)
		}
	}
	return nil
}

type draftRef struct{ id, messageID string }

// draftCopyWindow is how far back a draft copy is looked for by its header.
const draftCopyWindow = 7 * 24 * time.Hour

// draftClockMargin allows for the clock of the device that chose the draft's id: clients make draft ids, so a device
// whose clock runs ahead would otherwise start the search after its copies were filed.
const draftClockMargin = 24 * time.Hour

// draftsSince is where the header search for a copy with this Message-ID starts: the earliest it can have been filed
// (the API composes a draft copy's Message-ID from the draft's id, <draft id>.<version>@domain, and a UUIDv7 id says
// when the draft was started), but never more than draftCopyWindow back.
func draftsSince(messageID string, now time.Time) time.Time {
	since := now.Add(-draftCopyWindow)
	draftID, _, _ := strings.Cut(messageID, ".")
	if started, ok := providersend.UUIDv7Time(draftID); ok && started.Add(-draftClockMargin).After(since) {
		return started.Add(-draftClockMargin)
	}
	return since
}

// drafts lists the drafts matching query, newest first; at most limit of them when limit is above 0.
func (s *DraftsStore) drafts(query url.Values, limit int) ([]draftRef, error) {
	query.Set("maxResults", "500")
	var out []draftRef
	for {
		var page struct {
			Drafts []struct {
				ID      string `json:"id"`
				Message struct {
					ID string `json:"id"`
				} `json:"message"`
			} `json:"drafts"`
			NextPageToken string `json:"nextPageToken"`
		}
		if err := s.client.get(s.ctx, "/drafts", query, costList, &page); err != nil {
			return nil, err
		}
		for _, d := range page.Drafts {
			if limit > 0 && len(out) >= limit {
				return out, nil
			}
			out = append(out, draftRef{id: d.ID, messageID: d.Message.ID})
		}
		if page.NextPageToken == "" {
			return out, nil
		}
		query.Set("pageToken", page.NextPageToken)
	}
}

// messageIDOf returns raw's Message-ID without angle brackets, or "".
func messageIDOf(raw []byte) string {
	return strings.Trim(providersend.HeaderValue(raw, "Message-ID"), "<> ")
}

func (s *DraftsStore) Close() {}
