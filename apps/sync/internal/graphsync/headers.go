package graphsync

import (
	"context"
	"errors"
	"strings"
)

// internetHeadersSet is the property set Exchange keeps a message's custom internet headers (X-...) in.
const internetHeadersSet = "{00020386-0000-0000-C000-000000000046}"

// errUndecided means a lookup would have had to go through more messages than it may; the caller must not take it
// as "not found".
var errUndecided = errors.New("too many messages to look through")

// maxScan is how many messages a header lookup goes through at most.
const maxScan = 2000

// findByHeader goes through a folder's messages (filter narrows them, "" for all) and returns the ids of those whose
// custom header name has value. Graph exposes the header two ways and both are read: the message's
// internetMessageHeaders, and the named property Exchange stores an X- header of a MIME message in. complete is false
// when it stopped at maxScan messages.
func (c *Client) findByHeader(ctx context.Context, folder, filter, name, value string) (found []string, complete bool, err error) {
	property := "String " + internetHeadersSet + " Name " + name
	link := "/me/mailFolders/" + folder + "/messages?$select=id,internetMessageHeaders&$top=100" +
		"&$expand=" + queryEscape("singleValueExtendedProperties($filter=id eq '"+property+"')")
	if filter != "" {
		link += "&$filter=" + queryEscape(filter)
	}
	scanned := 0
	for link != "" {
		var page struct {
			Value []struct {
				ID      string `json:"id"`
				Headers []struct {
					Name  string `json:"name"`
					Value string `json:"value"`
				} `json:"internetMessageHeaders"`
				Properties []struct {
					ID    string `json:"id"`
					Value string `json:"value"`
				} `json:"singleValueExtendedProperties"`
			} `json:"value"`
			NextLink string `json:"@odata.nextLink"`
		}
		if err := c.get(ctx, link, "", &page); err != nil {
			return nil, false, err
		}
		for _, m := range page.Value {
			if scanned++; scanned > maxScan {
				return found, false, nil
			}
			match := false
			for _, h := range m.Headers {
				match = match || (strings.EqualFold(h.Name, name) && strings.TrimSpace(h.Value) == value)
			}
			for _, p := range m.Properties {
				match = match || (strings.EqualFold(p.ID, property) && strings.TrimSpace(p.Value) == value)
			}
			if match {
				found = append(found, m.ID)
			}
		}
		link = page.NextLink
	}
	return found, true, nil
}
