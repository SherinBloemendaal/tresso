package gmailsync

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"mime/multipart"
	"net/http"
	"net/textproto"
	"net/url"
	"strings"

	"tresso/sync/internal/mimeparse"
	"tresso/sync/internal/providerhttp"
)

// uploadThreshold is the raw size above which a message goes as a multipart upload instead of base64 inside a JSON
// body, which Gmail limits to a few megabytes. Uploads take messages up to Gmail's 35 MB limit.
const uploadThreshold = 4 << 20

// messageRequest builds the request that hands raw MIME to Gmail, for messages.send (path "/messages/send") or
// drafts.create (path "/drafts", where the message is wrapped as {"message": ...}). threadID, when not empty, puts
// the message in that conversation.
func (c *Client) messageRequest(path string, raw []byte, threadID string, cost int) (providerhttp.Request, error) {
	message := map[string]string{}
	if threadID != "" {
		message["threadId"] = threadID
	}
	wrap := func(m map[string]string) any {
		if path == "/drafts" {
			return map[string]any{"message": m}
		}
		return m
	}
	req := providerhttp.Request{Method: http.MethodPost, Cost: cost}

	if len(raw) <= uploadThreshold {
		message["raw"] = base64.URLEncoding.EncodeToString(raw)
		body, err := json.Marshal(wrap(message))
		if err != nil {
			return req, err
		}
		req.URL, req.ContentType, req.Body = c.base+path, "application/json", body
		return req, nil
	}

	// Large: the metadata (thread) as JSON, the message as message/rfc822, in one multipart/related upload.
	metadata, err := json.Marshal(wrap(message))
	if err != nil {
		return req, err
	}
	var body bytes.Buffer
	writer := multipart.NewWriter(&body)
	for _, part := range []struct {
		contentType string
		data        []byte
	}{{"application/json; charset=UTF-8", metadata}, {"message/rfc822", raw}} {
		w, err := writer.CreatePart(textproto.MIMEHeader{"Content-Type": {part.contentType}})
		if err != nil {
			return req, err
		}
		if _, err := w.Write(part.data); err != nil {
			return req, err
		}
	}
	if err := writer.Close(); err != nil {
		return req, err
	}
	req.URL = c.root + "/upload/gmail/v1/users/me" + path + "?uploadType=multipart"
	req.ContentType = "multipart/related; boundary=" + writer.Boundary()
	req.Body = body.Bytes()
	return req, nil
}

// replyThread returns the Gmail conversation a reply belongs to: the thread of the message it answers (In-Reply-To),
// or else of the last message it references. Gmail only threads a sent message into a conversation when it is given
// the thread id. "" when the message is no reply or Gmail does not have the message it answers.
func (c *Client) replyThread(ctx context.Context, raw []byte) (string, error) {
	parsed, err := mimeparse.Parse(raw)
	if err != nil {
		return "", nil // not readable as mail; it is sent without a thread
	}
	var candidates []string
	if parsed.InReplyTo != "" {
		candidates = append(candidates, parsed.InReplyTo)
	}
	if n := len(parsed.References); n > 0 && parsed.References[n-1] != parsed.InReplyTo {
		candidates = append(candidates, parsed.References[n-1])
	}
	for _, messageID := range candidates {
		messageID = strings.Trim(messageID, "<> ")
		if messageID == "" {
			continue
		}
		query := url.Values{"q": {"rfc822msgid:" + messageID}, "includeSpamTrash": {"true"}, "maxResults": {"1"}}
		var out struct {
			Messages []struct {
				ThreadID string `json:"threadId"`
			} `json:"messages"`
		}
		if err := c.get(ctx, "/messages", query, costList, &out); err != nil {
			return "", err
		}
		if len(out.Messages) > 0 && out.Messages[0].ThreadID != "" {
			return out.Messages[0].ThreadID, nil
		}
	}
	return "", nil
}

// errUndecided means a lookup would have had to go through more messages than it may; the caller must not take it
// as "not found".
var errUndecided = errors.New("too many messages to look through")

// maxScan is how many messages a header lookup goes through at most.
const maxScan = 2000

// findByHeader goes through the messages of a listing (query; label filters in it), newest first, and returns the ids
// of those whose header name has value. complete is false when it stopped at maxScan messages.
func (c *Client) findByHeader(ctx context.Context, query url.Values, name, value string) (found []string, complete bool, err error) {
	query.Set("maxResults", "500")
	scanned := 0
	for {
		var page struct {
			Messages []struct {
				ID string `json:"id"`
			} `json:"messages"`
			NextPageToken string `json:"nextPageToken"`
		}
		if err := c.get(ctx, "/messages", query, costList, &page); err != nil {
			return nil, false, err
		}
		for _, m := range page.Messages {
			if scanned++; scanned > maxScan {
				return found, false, nil
			}
			got, err := c.header(ctx, m.ID, name)
			if providerhttp.IsStatus(err, http.StatusNotFound) {
				continue
			}
			if err != nil {
				return nil, false, err
			}
			if got == value {
				found = append(found, m.ID)
			}
		}
		if page.NextPageToken == "" {
			return found, true, nil
		}
		query.Set("pageToken", page.NextPageToken)
	}
}

// header returns one header of a message (format=metadata, so no body is fetched).
func (c *Client) header(ctx context.Context, id, name string) (string, error) {
	var out struct {
		Payload struct {
			Headers []struct {
				Name  string `json:"name"`
				Value string `json:"value"`
			} `json:"headers"`
		} `json:"payload"`
	}
	query := url.Values{"format": {"metadata"}, "metadataHeaders": {name}}
	if err := c.get(ctx, "/messages/"+url.PathEscape(id), query, costGet, &out); err != nil {
		return "", err
	}
	for _, h := range out.Payload.Headers {
		if strings.EqualFold(h.Name, name) {
			return strings.TrimSpace(h.Value), nil
		}
	}
	return "", nil
}
