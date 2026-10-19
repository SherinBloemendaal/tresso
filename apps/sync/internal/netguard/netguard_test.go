package netguard

import (
	"context"
	"errors"
	"net"
	"net/netip"
	"testing"
)

type fakeResolver map[string][]string

func (f fakeResolver) LookupNetIP(_ context.Context, _, host string) ([]netip.Addr, error) {
	var out []netip.Addr
	for _, a := range f[host] {
		out = append(out, netip.MustParseAddr(a))
	}
	return out, nil
}

func TestIsPublic(t *testing.T) {
	cases := map[string]bool{
		"93.184.216.34":        true,
		"2606:2800:220:1::1":   true,
		"10.0.0.1":             false,
		"172.16.5.4":           false,
		"192.168.1.1":          false,
		"127.0.0.1":            false,
		"169.254.169.254":      false, // cloud metadata
		"100.64.0.1":           false,
		"0.0.0.0":              false,
		"255.255.255.255":      false,
		"::1":                  false,
		"fd00::1":              false,
		"fe80::1":              false,
		"::ffff:10.0.0.1":      false,
		"::ffff:93.184.216.34": true,
		"64:ff9b::a00:1":       false,
		"2002:a00:1::1":        false,
		"224.0.0.1":            false,
	}
	for address, want := range cases {
		if got := IsPublic(netip.MustParseAddr(address)); got != want {
			t.Errorf("IsPublic(%s) = %v, want %v", address, got, want)
		}
	}
}

func TestDialRefusesPrivateAddressesEvenIfOnlyOneIs(t *testing.T) {
	d := &Dialer{Resolver: fakeResolver{"imap.example": {"93.184.216.34", "10.0.0.5"}}}

	_, err := d.DialContext(context.Background(), "imap.example:993")

	if !errors.Is(err, ErrBlockedAddress) {
		t.Fatalf("err = %v, want ErrBlockedAddress", err)
	}
}

func TestAllowUnsafeConnectsToLocalServers(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	go func() {
		if c, err := listener.Accept(); err == nil {
			_ = c.Close()
		}
	}()
	_, port, _ := net.SplitHostPort(listener.Addr().String())

	blocked := &Dialer{Resolver: fakeResolver{"greenmail": {"127.0.0.1"}}}
	if _, err := blocked.DialContext(context.Background(), "greenmail:"+port); !errors.Is(err, ErrBlockedAddress) {
		t.Fatalf("expected a block without AllowUnsafe, got %v", err)
	}

	allowed := &Dialer{AllowUnsafe: true, Resolver: fakeResolver{"greenmail": {"127.0.0.1"}}}
	conn, err := allowed.DialContext(context.Background(), "greenmail:"+port)
	if err != nil {
		t.Fatal(err)
	}
	_ = conn.Close()
}
