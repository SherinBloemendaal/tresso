package providersync

import (
	"sync"

	"tresso/sync/internal/api"
)

// MessageCache remembers messages built during a round, so a message that several folders lack (a new message is
// unknown in Inbox and All Mail) is downloaded once. It holds at most limit messages and drops the oldest, so a
// round over a whole mailbox (a full resync) keeps its memory bounded. It is safe for concurrent use.
type MessageCache struct {
	mu    sync.Mutex
	limit int
	byID  map[string]api.Message
	order []string
}

func NewMessageCache(limit int) *MessageCache {
	return &MessageCache{limit: max(1, limit), byID: map[string]api.Message{}}
}

func (c *MessageCache) Get(id string) (api.Message, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	msg, ok := c.byID[id]
	return msg, ok
}

func (c *MessageCache) Put(id string, msg api.Message) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if _, ok := c.byID[id]; !ok {
		c.order = append(c.order, id)
	}
	c.byID[id] = msg
	for len(c.order) > c.limit {
		delete(c.byID, c.order[0])
		c.order = c.order[1:]
	}
}

func (c *MessageCache) Len() int {
	c.mu.Lock()
	defer c.mu.Unlock()
	return len(c.byID)
}

// DefaultCacheSize bounds a round's MessageCache: a few thousand descriptions (headers and a snippet each, the raw
// MIME is already in object storage) take a few megabytes.
const DefaultCacheSize = 2000
