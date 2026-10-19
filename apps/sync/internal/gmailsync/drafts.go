package gmailsync

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/url"
	"time"

	"tresso/sync/internal/providerhttp"
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

// AppendDraft creates a draft from raw. Gmail dates the draft itself, so at is not used. Messages above the size a
// JSON body takes are uploaded as media, as Send does.
func (s *DraftsStore) AppendDraft(raw []byte, _ time.Time) error {
	req := providerhttp.Request{Method: http.MethodPost, Cost: costDraft}
	if len(raw) > uploadThreshold {
		req.URL = s.client.root + "/upload/gmail/v1/users/me/drafts?uploadType=media"
		req.ContentType = "message/rfc822"
		req.Body = raw
	} else {
		body, err := json.Marshal(map[string]any{"message": map[string]string{"raw": base64.URLEncoding.EncodeToString(raw)}})
		if err != nil {
			return err
		}
		req.URL = s.client.base + "/drafts"
		req.ContentType = "application/json"
		req.Body = body
	}
	return mapAccessError(s.client.http.JSON(s.ctx, req, nil))
}

// Remove deletes every draft whose message has this Message-ID (without angle brackets). A draft that is gone already
// counts as removed.
func (s *DraftsStore) Remove(messageID string) error {
	query := url.Values{"q": {"rfc822msgid:" + messageID}, "maxResults": {"100"}}
	for {
		var page struct {
			Drafts []struct {
				ID string `json:"id"`
			} `json:"drafts"`
			NextPageToken string `json:"nextPageToken"`
		}
		if err := s.client.get(s.ctx, "/drafts", query, costList, &page); err != nil {
			return err
		}
		for _, draft := range page.Drafts {
			_, err := s.client.http.Do(s.ctx, providerhttp.Request{Method: http.MethodDelete, URL: s.client.base + "/drafts/" + url.PathEscape(draft.ID), Cost: costDraft})
			if err != nil && !providerhttp.IsStatus(err, http.StatusNotFound) {
				return mapAccessError(err)
			}
		}
		if page.NextPageToken == "" {
			return nil
		}
		query.Set("pageToken", page.NextPageToken)
	}
}

func (s *DraftsStore) Close() {}
