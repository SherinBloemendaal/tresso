// Package realtime listens to the API's Mercure hub for accounts with mail to send, so it goes out within moments
// instead of at the next heartbeat.
package realtime

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/url"
	"strings"
	"sync/atomic"
	"time"

	"tresso/sync/internal/api"
)

// TokenSource hands out access to the outbox topic.
type TokenSource interface {
	RealtimeToken(ctx context.Context) (api.RealtimeAccess, error)
}

type Subscriber struct {
	tokens      TokenSource
	httpClient  *http.Client
	onOutbox    func(mailAccountID string)
	logger      *slog.Logger
	retryDelays []time.Duration
	renewBefore time.Duration
}

func NewSubscriber(tokens TokenSource, httpClient *http.Client, onOutbox func(mailAccountID string), logger *slog.Logger) *Subscriber {
	return &Subscriber{
		tokens:      tokens,
		httpClient:  httpClient,
		onOutbox:    onOutbox,
		logger:      logger,
		retryDelays: []time.Duration{time.Second, 2 * time.Second, 5 * time.Second, 10 * time.Second, 30 * time.Second},
		renewBefore: time.Minute,
	}
}

// Run listens until ctx ends, reconnecting after failures and renewing the token before it expires. Missed
// notifications are harmless: every heartbeat also lists the accounts with mail to send.
func (s *Subscriber) Run(ctx context.Context) {
	failures := 0
	for ctx.Err() == nil {
		connected, err := s.listen(ctx)
		if ctx.Err() != nil {
			return
		}
		if connected {
			failures = 0
		}
		if err == nil {
			continue
		}
		delay := s.retryDelays[min(failures, len(s.retryDelays)-1)]
		failures++
		s.logger.Warn("realtime connection failed", "error", err, "retryIn", delay)
		select {
		case <-ctx.Done():
			return
		case <-time.After(delay):
		}
	}
}

// listen returns nil when the token is due for renewal, an error when the connection failed or ended.
func (s *Subscriber) listen(parent context.Context) (connected bool, err error) {
	access, err := s.tokens.RealtimeToken(parent)
	if err != nil {
		return false, fmt.Errorf("realtime token: %w", err)
	}
	hub, err := url.Parse(access.HubURL)
	if err != nil {
		return false, fmt.Errorf("hub url: %w", err)
	}
	query := hub.Query()
	query.Set("match", access.Topic)
	hub.RawQuery = query.Encode()

	ctx, cancel := context.WithCancel(parent)
	defer cancel()
	var renewing atomic.Bool
	renewal := time.AfterFunc(max(time.Until(access.ExpiresAt)-s.renewBefore, 0), func() {
		renewing.Store(true)
		cancel()
	})
	defer renewal.Stop()

	request, err := http.NewRequestWithContext(ctx, http.MethodGet, hub.String(), nil)
	if err != nil {
		return false, err
	}
	request.Header.Set("Authorization", "Bearer "+access.Token)
	request.Header.Set("Accept", "text/event-stream")
	response, err := s.httpClient.Do(request)
	if err != nil {
		if renewing.Load() {
			return false, nil
		}
		return false, err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return false, fmt.Errorf("hub answered %d", response.StatusCode)
	}

	err = s.read(response.Body)
	if renewing.Load() || parent.Err() != nil {
		return true, nil
	}
	if err == nil {
		err = errors.New("the hub closed the connection")
	}
	return true, err
}

func (s *Subscriber) read(body io.Reader) error {
	reader := bufio.NewReader(body)
	var eventType string
	var data []string
	for {
		line, err := reader.ReadString('\n')
		if err != nil {
			if errors.Is(err, io.EOF) {
				return nil
			}
			return err
		}
		line = strings.TrimRight(line, "\r\n")
		switch {
		case line == "":
			if len(data) > 0 && eventType == "outbox" {
				s.dispatch(strings.Join(data, "\n"))
			}
			eventType, data = "", nil
		case strings.HasPrefix(line, ":"):
		case strings.HasPrefix(line, "event:"):
			eventType = strings.TrimPrefix(strings.TrimPrefix(line, "event:"), " ")
		case strings.HasPrefix(line, "data:"):
			data = append(data, strings.TrimPrefix(strings.TrimPrefix(line, "data:"), " "))
		}
	}
}

func (s *Subscriber) dispatch(payload string) {
	var update struct {
		MailAccountID string `json:"mailAccountId"`
	}
	if err := json.Unmarshal([]byte(payload), &update); err != nil || update.MailAccountID == "" {
		s.logger.Warn("ignoring an outbox update that could not be read")
		return
	}
	s.onOutbox(update.MailAccountID)
}
