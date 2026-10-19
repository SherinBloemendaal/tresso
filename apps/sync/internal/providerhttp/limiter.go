package providerhttp

import (
	"context"
	"sync"
	"time"
)

// Limiter is a token bucket: it allows rate units per second on average, and bursts of up to burst units.
type Limiter struct {
	mu     sync.Mutex
	rate   float64
	burst  float64
	tokens float64
	last   time.Time
}

// NewLimiter returns nil (no limit) when rate is not positive.
func NewLimiter(rate float64, burst int) *Limiter {
	if rate <= 0 {
		return nil
	}
	b := float64(max(burst, 1))
	return &Limiter{rate: rate, burst: b, tokens: b, last: time.Now()}
}

// Wait blocks until n units are available, or ctx ends. A request costing more than the burst waits for a full bucket.
func (l *Limiter) Wait(ctx context.Context, n int) error {
	if l == nil {
		return nil
	}
	need := min(float64(n), l.burst)
	for {
		l.mu.Lock()
		now := time.Now()
		l.tokens = min(l.burst, l.tokens+now.Sub(l.last).Seconds()*l.rate)
		l.last = now
		if l.tokens >= need {
			l.tokens -= need
			l.mu.Unlock()
			return nil
		}
		wait := time.Duration((need - l.tokens) / l.rate * float64(time.Second))
		l.mu.Unlock()

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(wait):
		}
	}
}
