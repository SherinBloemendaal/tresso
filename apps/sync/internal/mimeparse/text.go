package mimeparse

import (
	"strings"
	"unicode"

	"golang.org/x/net/html"
)

// Snippet collapses whitespace, drops quoted reply lines and cuts at a word boundary.
func Snippet(text string) string {
	var kept []string
	for _, line := range strings.Split(text, "\n") {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, ">") {
			continue
		}
		kept = append(kept, trimmed)
	}
	collapsed := strings.Join(strings.FieldsFunc(strings.Join(kept, " "), unicode.IsSpace), " ")

	runes := []rune(collapsed)
	if len(runes) <= snippetLength {
		return collapsed
	}
	cut := string(runes[:snippetLength])
	if i := strings.LastIndexByte(cut, ' '); i > snippetLength/2 {
		cut = cut[:i]
	}
	return strings.TrimRight(cut, " ,.;:") + "…"
}

// HTMLToText extracts visible text from an HTML body, skipping scripts, styles and head content.
func HTMLToText(source string) string {
	tokenizer := html.NewTokenizer(strings.NewReader(source))
	var out strings.Builder
	skip := 0

	for {
		switch tokenizer.Next() {
		case html.ErrorToken:
			return out.String()
		case html.StartTagToken:
			name, _ := tokenizer.TagName()
			switch string(name) {
			case "script", "style", "head", "title", "noscript":
				skip++
			case "br", "p", "div", "tr", "li", "h1", "h2", "h3", "h4", "h5", "h6":
				out.WriteByte('\n')
			}
		case html.EndTagToken:
			name, _ := tokenizer.TagName()
			switch string(name) {
			case "script", "style", "head", "title", "noscript":
				if skip > 0 {
					skip--
				}
			}
		case html.TextToken:
			if skip == 0 {
				out.Write(tokenizer.Text())
				out.WriteByte(' ')
			}
		}
	}
}
