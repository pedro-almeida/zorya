package network

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"
)

type PeerInfo struct {
	HostName     string   `json:"hostname"`
	DNSName      string   `json:"dns_name"`
	TailscaleIPs []string `json:"tailscale_ips"`
	Online       bool     `json:"online"`
	OS           string   `json:"os"`
}

type TailscaleStatus struct {
	Detected     bool       `json:"detected"`
	BackendState string     `json:"backend_state"`
	SelfIP       string     `json:"self_ip"`
	SelfHostName string     `json:"self_hostname"`
	Peers        []PeerInfo `json:"peers"`
	Method       string     `json:"method"`
	CheckedAt    string     `json:"checked_at"`
}

type rawTSStatus struct {
	BackendState string   `json:"BackendState"`
	TailscaleIPs []string `json:"TailscaleIPs"`
	Self         struct {
		HostName     string   `json:"HostName"`
		DNSName      string   `json:"DNSName"`
		TailscaleIPs []string `json:"TailscaleIPs"`
		Online       bool     `json:"Online"`
	} `json:"Self"`
	Peer map[string]struct {
		HostName     string   `json:"HostName"`
		DNSName      string   `json:"DNSName"`
		TailscaleIPs []string `json:"TailscaleIPs"`
		Online       bool     `json:"Online"`
		OS           string   `json:"OS"`
	} `json:"Peer"`
}

func GetTailscaleStatus() TailscaleStatus {
	status := TailscaleStatus{
		Detected:     false,
		BackendState: "Stopped",
		Peers:        []PeerInfo{},
		CheckedAt:    time.Now().Format(time.RFC3339),
	}

	if raw, method, ok := queryTailscaleCLI(); ok {
		return parseRawStatus(raw, method)
	}

	if raw, method, ok := queryTailscaleLocalAPI(); ok {
		return parseRawStatus(raw, method)
	}

	if ip := findTailscaleInterfaceIP(); ip != "" {
		hostname, _ := os.Hostname()
		status.Detected = true
		status.BackendState = "Running"
		status.SelfIP = ip
		status.SelfHostName = hostname
		status.Method = "net_interface_cgnat"
	}

	return status
}

func GetTailscaleIP() string {
	st := GetTailscaleStatus()
	return st.SelfIP
}

func queryTailscaleCLI() (*rawTSStatus, string, bool) {
	candidates := []string{"tailscale"}
	if runtime.GOOS == "windows" {
		candidates = append(candidates,
			`C:\Program Files\Tailscale\tailscale.exe`,
			`C:\Program Files (x86)\Tailscale\tailscale.exe`,
		)
	} else {
		candidates = append(candidates, "/usr/bin/tailscale", "/usr/sbin/tailscale", "/snap/bin/tailscale")
	}

	for _, bin := range candidates {
		ctx, cancel := context.WithTimeout(context.Background(), 2500*time.Millisecond)
		cmd := exec.CommandContext(ctx, bin, "status", "--json")
		out, err := cmd.Output()
		cancel()
		if err == nil && len(out) > 0 {
			var parsed rawTSStatus
			if json.Unmarshal(out, &parsed) == nil {
				return &parsed, "cli:" + bin, true
			}
		}
	}
	return nil, "", false
}

func queryTailscaleLocalAPI() (*rawTSStatus, string, bool) {
	if runtime.GOOS == "windows" {
		pipes := []string{
			`\\.\pipe\ProtectedPrefix\Administrators\Tailscale\tailscaled`,
			`\\.\pipe\ProtectedPrefix\Tailscale`,
		}
		for _, p := range pipes {
			if _, err := os.Stat(p); err == nil {
				break
			}
		}
	}

	client := &http.Client{Timeout: 1500 * time.Millisecond}
	req, err := http.NewRequest("GET", "http://100.100.100.100/localapi/v0/status", nil)
	if err != nil {
		return nil, "", false
	}
	req.Host = "local-tailscaled.sock"
	resp, err := client.Do(req)
	if err != nil {
		return nil, "", false
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusOK {
		var parsed rawTSStatus
		if json.NewDecoder(resp.Body).Decode(&parsed) == nil {
			return &parsed, "localapi_http", true
		}
	}
	return nil, "", false
}

func parseRawStatus(raw *rawTSStatus, method string) TailscaleStatus {
	st := TailscaleStatus{
		Detected:     raw.BackendState == "Running" || len(raw.Self.TailscaleIPs) > 0 || len(raw.TailscaleIPs) > 0,
		BackendState: raw.BackendState,
		SelfHostName: raw.Self.HostName,
		Peers:        []PeerInfo{},
		Method:       method,
		CheckedAt:    time.Now().Format(time.RFC3339),
	}

	ips := raw.Self.TailscaleIPs
	if len(ips) == 0 {
		ips = raw.TailscaleIPs
	}
	for _, ip := range ips {
		if strings.HasPrefix(ip, "100.") {
			st.SelfIP = ip
			break
		}
	}
	if st.SelfIP == "" && len(ips) > 0 {
		st.SelfIP = ips[0]
	}

	for _, p := range raw.Peer {
		st.Peers = append(st.Peers, PeerInfo{
			HostName:     p.HostName,
			DNSName:      strings.TrimSuffix(p.DNSName, "."),
			TailscaleIPs: p.TailscaleIPs,
			Online:       p.Online,
			OS:           p.OS,
		})
	}
	return st
}

func findTailscaleInterfaceIP() string {
	_, tsNet, _ := net.ParseCIDR("100.64.0.0/10")
	ifaces, err := net.Interfaces()
	if err != nil {
		return ""
	}
	for _, iface := range ifaces {
		if iface.Flags&net.FlagUp == 0 {
			continue
		}
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
			if ip != nil && ip.To4() != nil && tsNet.Contains(ip) {
				if ip.String() != "100.100.100.100" {
					return ip.String()
				}
			}
		}
	}
	return ""
}

// RandomHex generates n cryptographically random bytes encoded as hex string.
func RandomHex(n int) string {
	b := make([]byte, n)
	_, _ = io.ReadFull(rand.Reader, b)
	return hex.EncodeToString(b)
}
