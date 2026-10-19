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
	body := []byte(base64.StdEncoding.EncodeToString(raw))
	if len(body) > maxSendBody {
		return &providersend.Error{Permanent: true, Message: "The draft is too large for Outlook, which takes at most 3 MB including attachments.", Err: errors.New("draft exceeds the request size limit")}
	}
	_, err := s.client.http.Do(s.ctx, providerhttp.Request{Method: http.MethodPost, URL: s.client.base + "/me/messages", ContentType: "text/plain", Body: body})
	return mapAccessError(err)
}

// Remove deletes every message in Drafts with this Message-ID (without angle brackets) for good, not into Deleted
// Items. A copy that is gone already counts as removed.
func (s *DraftsStore) Remove(messageID string) error {
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
			_, err := s.client.http.Do(s.ctx, providerhttp.Request{Method: http.MethodPost, URL: s.client.base + "/me/messages/" + url.PathEscape(draft.ID) + "/permanentDelete"})
			if err != nil && !providerhttp.IsStatus(err, http.StatusNotFound) {
				return mapAccessError(err)
			}
		}
		link = page.NextLink
	}
	return nil
}

func (s *DraftsStore) Close() {}
