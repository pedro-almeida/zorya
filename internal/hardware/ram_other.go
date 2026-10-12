//go:build !windows

package hardware

import (
	"os"
	"strings"
)

func detectPlatformRAM() (float64, float64) {
	if data, err := os.ReadFile("/proc/meminfo"); err == nil {
		var totalKB, availKB float64
		lines := strings.Split(string(data), "\n")
		for _, l := range lines {
			if strings.HasPrefix(l, "MemTotal:") {
				totalKB = parseMemKB(l)
			} else if strings.HasPrefix(l, "MemAvailable:") {
				availKB = parseMemKB(l)
			}
		}
		if totalKB > 0 {
			return round1(totalKB / (1024 * 1024)), round1(availKB / (1024 * 1024))
		}
	}
	return 16.0, 8.0
}
