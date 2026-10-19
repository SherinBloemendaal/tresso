// Package netguard dials mail servers safely. Mail server addresses are user input, so without checks a user could
// make the worker connect to internal services (server-side request forgery). The API validates hosts when they are
// saved; this package checks again at every connection, against the address it actually connects to, so a DNS
// record changed after validation cannot redirect the worker.
package netguard

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/netip"
	"time"
)

// ErrBlockedAddress means the host resolves to a private, loopback or otherwise non-public address.
var ErrBlockedAddress = errors.New("mail server address is not public")

type Resolver interface {
	LookupNetIP(ctx context.Context, network, host string) ([]netip.Addr, error)
}

type Dialer struct {
	// AllowUnsafe permits private addresses; only for local test servers.
	AllowUnsafe bool
	Resolver    Resolver
	Timeout     time.Duration
}

func New(allowUnsafe bool) *Dialer {
	return &Dialer{AllowUnsafe: allowUnsafe, Resolver: net.DefaultResolver, Timeout: 20 * time.Second}
}

// DialContext resolves the host, rejects non-public addresses and connects to a checked address.
func (d *Dialer) DialContext(ctx context.Context, address string) (net.Conn, error) {
	host, port, err := net.SplitHostPort(address)
	if err != nil {
		return nil, err
	}

	addrs, err := d.Resolver.LookupNetIP(ctx, "ip", host)
	if err != nil {
		return nil, fmt.Errorf("resolve %s: %w", host, err)
	}
	if len(addrs) == 0 {
		return nil, fmt.Errorf("resolve %s: no addresses", host)
	}

	dialer := net.Dialer{Timeout: d.Timeout}
	var lastErr error
	for _, addr := range addrs {
		addr = addr.Unmap()
		if !d.AllowUnsafe && !IsPublic(addr) {
			return nil, fmt.Errorf("%w: %s resolves to %s", ErrBlockedAddress, host, addr)
		}
	}
	for _, addr := range addrs {
		conn, err := dialer.DialContext(ctx, "tcp", net.JoinHostPort(addr.Unmap().String(), port))
		if err == nil {
			return conn, nil
		}
		lastErr = err
	}
	return nil, lastErr
}

var blockedPrefixes = mustPrefixes(
	"0.0.0.0/8",       // "this network"
	"100.64.0.0/10",   // carrier-grade NAT
	"192.0.0.0/24",    // IETF protocol assignments
	"192.0.2.0/24",    // documentation
	"198.18.0.0/15",   // benchmarking
	"198.51.100.0/24", // documentation
	"203.0.113.0/24",  // documentation
	"240.0.0.0/4",     // reserved
	"64:ff9b::/96",    // NAT64 can reach IPv4-internal hosts
	"64:ff9b:1::/48",  // local-use NAT64
	"2001:db8::/32",   // documentation
	"2002::/16",       // 6to4 embeds arbitrary IPv4
)

// IsPublic reports whether a mail server may live at this address.
func IsPublic(addr netip.Addr) bool {
	addr = addr.Unmap()
	if !addr.IsValid() || addr.IsLoopback() || addr.IsPrivate() || addr.IsLinkLocalUnicast() ||
		addr.IsLinkLocalMulticast() || addr.IsInterfaceLocalMulticast() || addr.IsMulticast() || addr.IsUnspecified() {
		return false
	}
	if addr == netip.AddrFrom4([4]byte{255, 255, 255, 255}) {
		return false
	}
	for _, prefix := range blockedPrefixes {
		if prefix.Contains(addr) {
			return false
		}
	}
	return true
}

func mustPrefixes(cidrs ...string) []netip.Prefix {
	out := make([]netip.Prefix, 0, len(cidrs))
	for _, cidr := range cidrs {
		out = append(out, netip.MustParsePrefix(cidr))
	}
	return out
}
