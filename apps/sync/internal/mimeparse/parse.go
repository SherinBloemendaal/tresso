// Package mimeparse turns a raw RFC 5322 message into the metadata Tresso stores: headers, a text snippet and the
// attachments. It is lenient: real-world mail breaks every rule, and a message must never be lost because of that.
package mimeparse

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"mime"
	"net/mail"
	"net/url"
	"path"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/emersion/go-message"
	_ "github.com/emersion/go-message/charset" // registers the non-UTF-8 charsets real mail uses
	gomail "github.com/emersion/go-message/mail"
)

const (
	snippetLength = 300
	// Text parts are read up to this size for the snippet; the rest is never needed for metadata.
	maxTextRead = 256 << 10
	// UnknownSender is used when a message has no usable From, Sender or Return-Path.
	UnknownSender = "unknown@unknown.invalid"
)

type Address struct {
	Address string
	Name    string
}

type Attachment struct {
	Filename    string
	ContentType string
	ContentID   string
	Inline      bool
	Size        int64
	SHA256      string
	Data        []byte
}

type Message struct {
	MessageID   string
	InReplyTo   string
	References  []string
	Subject     string
	From        Address
	To          []Address
	Cc          []Address
	Bcc         []Address
	ReplyTo     []Address
	Date        *time.Time
	Snippet     string
	Attachments []Attachment
}

// Parse never fails on malformed input: headers it cannot read stay empty. It only returns an error when the input
// is not a message at all.
func Parse(raw []byte) (*Message, error) {
	reader, err := gomail.CreateReader(bytes.NewReader(raw))
	if err != nil && !message.IsUnknownCharset(err) && !message.IsUnknownEncoding(err) {
		return parseHeadersOnly(raw)
	}

	msg := headersFrom(reader.Header)
	var plain, html string

	for {
		part, err := reader.NextPart()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			if message.IsUnknownCharset(err) || message.IsUnknownEncoding(err) {
				continue
			}
			// A broken part ends the walk; everything collected so far is kept.
			break
		}

		switch header := part.Header.(type) {
		case *gomail.InlineHeader:
			contentType, params, _ := header.ContentType()
			contentID := strings.Trim(header.Get("Content-Id"), "<> ")
			switch {
			case contentType == "text/plain" && plain == "":
				plain = readText(part.Body)
			case contentType == "text/html" && html == "":
				html = readText(part.Body)
			case strings.HasPrefix(contentType, "text/"):
				// Other text parts (calendar, vcard) carry no snippet text.
				_, _ = io.Copy(io.Discard, part.Body)
			default:
				// Inline non-text parts are embedded images and similar, referenced by Content-ID.
				attachment, err := readAttachment(part.Body, filenameFrom(params["name"], contentType), contentType, contentID, true)
				if err == nil {
					msg.Attachments = append(msg.Attachments, attachment)
				}
			}
		case *gomail.AttachmentHeader:
			contentType, _, _ := header.ContentType()
			filename, _ := header.Filename()
			contentID := strings.Trim(header.Get("Content-Id"), "<> ")
			disposition, _, _ := header.ContentDisposition()
			inline := disposition == "inline" && contentID != ""
			attachment, err := readAttachment(part.Body, filenameFrom(filename, contentType), contentType, contentID, inline)
			if err == nil {
				msg.Attachments = append(msg.Attachments, attachment)
			}
		}
	}

	markReferencedInline(msg.Attachments, html)

	switch {
	case strings.TrimSpace(plain) != "":
		msg.Snippet = Snippet(plain)
	case html != "":
		msg.Snippet = Snippet(HTMLToText(html))
	}

	return msg, nil
}

func headersFrom(h gomail.Header) *Message {
	msg := &Message{}

	msg.Subject, _ = h.Subject()
	msg.MessageID, _ = h.MessageID()
	if ids, err := h.MsgIDList("In-Reply-To"); err == nil && len(ids) > 0 {
		msg.InReplyTo = ids[0]
	}
	msg.References, _ = h.MsgIDList("References")
	if date, err := h.Date(); err == nil && !date.IsZero() {
		msg.Date = &date
	}

	msg.To = addressList(h, "To")
	msg.Cc = addressList(h, "Cc")
	msg.Bcc = addressList(h, "Bcc")
	msg.ReplyTo = addressList(h, "Reply-To")
	msg.From = firstAddress(h, "From", "Sender", "Return-Path")

	return msg
}

func addressList(h gomail.Header, key string) []Address {
	list, err := h.AddressList(key)
	if err != nil {
		return nil
	}
	out := make([]Address, 0, len(list))
	for _, a := range list {
		if a.Address == "" {
			continue
		}
		out = append(out, Address{Address: strings.ToLower(a.Address), Name: a.Name})
	}
	return out
}

func firstAddress(h gomail.Header, keys ...string) Address {
	for _, key := range keys {
		if list := addressList(h, key); len(list) > 0 {
			return list[0]
		}
	}
	return Address{Address: UnknownSender}
}

// parseHeadersOnly is the fallback for messages go-message refuses: net/mail is more forgiving with headers.
func parseHeadersOnly(raw []byte) (*Message, error) {
	parsed, err := mail.ReadMessage(bytes.NewReader(raw))
	if err != nil {
		return nil, fmt.Errorf("not a mail message: %w", err)
	}
	decoder := new(mime.WordDecoder)
	decode := func(s string) string {
		if out, err := decoder.DecodeHeader(s); err == nil {
			return out
		}
		return s
	}

	msg := &Message{
		Subject:   decode(parsed.Header.Get("Subject")),
		MessageID: strings.Trim(parsed.Header.Get("Message-Id"), "<> "),
		InReplyTo: strings.Trim(parsed.Header.Get("In-Reply-To"), "<> "),
		From:      Address{Address: UnknownSender},
	}
	for _, field := range strings.Fields(parsed.Header.Get("References")) {
		msg.References = append(msg.References, strings.Trim(field, "<> "))
	}
	if date, err := parsed.Header.Date(); err == nil {
		msg.Date = &date
	}
	if from, err := parsed.Header.AddressList("From"); err == nil && len(from) > 0 {
		msg.From = Address{Address: strings.ToLower(from[0].Address), Name: from[0].Name}
	}
	if to, err := parsed.Header.AddressList("To"); err == nil {
		for _, a := range to {
			msg.To = append(msg.To, Address{Address: strings.ToLower(a.Address), Name: a.Name})
		}
	}
	return msg, nil
}

func readText(body io.Reader) string {
	data, _ := io.ReadAll(io.LimitReader(body, maxTextRead))
	_, _ = io.Copy(io.Discard, body)
	if !utf8.Valid(data) {
		data = bytes.ToValidUTF8(data, []byte("�"))
	}
	return string(data)
}

func readAttachment(body io.Reader, filename, contentType, contentID string, inline bool) (Attachment, error) {
	data, err := io.ReadAll(body)
	if err != nil {
		return Attachment{}, err
	}
	sum := sha256.Sum256(data)
	return Attachment{
		Filename:    filename,
		ContentType: contentType,
		ContentID:   contentID,
		Inline:      inline,
		Size:        int64(len(data)),
		SHA256:      hex.EncodeToString(sum[:]),
		Data:        data,
	}, nil
}

func filenameFrom(name, contentType string) string {
	name = strings.TrimSpace(path.Base(strings.ReplaceAll(name, "\\", "/")))
	if name != "" && name != "." && name != "/" {
		return truncate(name, 255)
	}
	extension := ""
	if exts, _ := mime.ExtensionsByType(contentType); len(exts) > 0 {
		extension = exts[0]
	}
	return "attachment" + extension
}

func truncate(s string, max int) string {
	if utf8.RuneCountInString(s) <= max {
		return s
	}
	return string([]rune(s)[:max])
}

// markReferencedInline marks every part the HTML body shows through a cid: reference as inline. Mail clients disagree
// on the disposition of embedded images (some send them as attachments), so the reference is what counts.
func markReferencedInline(attachments []Attachment, html string) {
	if html == "" {
		return
	}
	lower := strings.ToLower(html)
	for i := range attachments {
		contentID := strings.ToLower(attachments[i].ContentID)
		if contentID == "" || attachments[i].Inline {
			continue
		}
		if strings.Contains(lower, "cid:"+contentID) || strings.Contains(lower, "cid:"+strings.ToLower(url.PathEscape(attachments[i].ContentID))) {
			attachments[i].Inline = true
		}
	}
}
