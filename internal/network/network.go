package network

import (
	"crypto/rand"
	"encoding/hex"
	"io"
	"net"
	"strings"
)

// DetectTailscaleIP finds the IPv4 assigned to Tailscale in the 100.64.0.0/10 CGNAT range.
func DetectTailscaleIP() string {
	ifaces, err := net.Interfaces()
	if err != nil {
		return "127.0.0.1"
	}

	for _, iface := range ifaces {
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, addr := range addrs {
			var ip net.IP
			switch v := addr.(type) {
			case *net.IPNet:
				ip = v.IP
			case *net.IPAddr:
				ip = v.IP
			}
			if ip == nil || ip.IsLoopback() {
				continue
			}
			ip = ip.To4()
			if ip == nil {
				continue
			}
			// CGNAT range dedicated to Tailscale: 100.64.0.0 - 100.127.255.255
			if ip[0] == 100 && ip[1] >= 64 && ip[1] <= 127 {
				return ip.String()
			}
		}
	}
	return "127.0.0.1"
}

// RandomHex generates n cryptographically random bytes encoded as hex string.
func RandomHex(n int) string {
	b := make([]byte, n)
	_, _ = io.ReadFull(rand.Reader, b)
	return hex.EncodeToString(b)
}

// SanitizeName normalizes a string into a clean hostname/identifier.
func SanitizeName(s string) string {
	s = strings.ToLower(strings.TrimSpace(s))
	var out []rune
	for _, r := range s {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			out = append(out, r)
		} else {
			out = append(out, '-')
		}
	}
	if len(out) == 0 {
		return "node"
	}
	return string(out)
}
