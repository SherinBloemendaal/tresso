package gmailsync

import (
	"context"
	"net/http"

	"tresso/sync/internal/api"
	"tresso/sync/internal/providerhttp"
)

// backfill ingests up to BackfillPagesPerPass pages of existing mail, newest first, into every folder each message is
// in. The cursor moves to the next page with the All Mail batch, after the label folders accepted theirs.
func (s *session) backfill(ctx context.Context) error {
	for page := 0; page < s.opts.BackfillPagesPerPass && !s.cursor.BackfillDone; page++ {
		ids, nextToken, err := s.gmail.listMessages(ctx, listQuery{includeSpamTrash: true, pageToken: s.cursor.BackfillPageToken, pageSize: s.opts.ListPageSize})
		if err != nil {
			if s.cursor.BackfillPageToken != "" && providerhttp.IsStatus(err, http.StatusBadRequest) {
				// Gmail no longer accepts the stored page token. Start over: messages the API has are only refreshed.
				s.logger.Warn("backfill page token refused, restarting the backfill", "error", err)
				restart := *s.cursor
				restart.BackfillPageToken = ""
				if err := s.storeCursor(ctx, &restart); err != nil {
					return err
				}
				s.cursor = &restart
				continue
			}
			return err
		}

		got, err := s.fetchRaw(ctx, ids)
		if err != nil {
			return err
		}
		byFolder := map[string][]api.Message{}
		for _, id := range ids { // listing order: newest first
			f, ok := got[id]
			if !ok {
				continue
			}
			for _, folder := range foldersOf(f.labels, s.isFolder) {
				byFolder[folder] = append(byFolder[folder], f.message)
			}
		}

		next := *s.cursor
		next.BackfillPageToken = nextToken
		next.BackfillDone = nextToken == ""
		encoded, err := next.Encode()
		if err != nil {
			return err
		}
		for _, f := range s.folders {
			if f.RemoteName == allMailFolder || len(byFolder[f.RemoteName]) == 0 {
				continue
			}
			if err := s.ingest.Messages(ctx, f.RemoteName, byFolder[f.RemoteName], nil); err != nil {
				return err
			}
		}
		if err := s.ingest.Messages(ctx, allMailFolder, byFolder[allMailFolder], &encoded); err != nil {
			return err
		}
		s.cursor = &next
	}
	if s.cursor.BackfillDone {
		s.logger.Info("gmail backfill complete")
	}
	return nil
}
