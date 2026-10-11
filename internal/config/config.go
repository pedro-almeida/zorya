package config

import (
	"bufio"
	"fmt"
	"os"
	"strconv"
	"strings"
)

// Role defines the operation role of this node in the Tailscale grid.
type Role string

const (
	RoleMaster     Role = "MASTER"
	RoleWorker     Role = "WORKER"
	RoleStandalone Role = "STANDALONE"
)

// Config holds runtime configuration for the Zorya daemon.
type Config struct {
	Server struct {
		Host      string `json:"host"`
		Port      int    `json:"port"`
		AuthToken string `json:"auth_token"`
	} `json:"server"`

	Node struct {
		ID          string `json:"id"`
		Name        string `json:"name"`
		Role        Role   `json:"role"`
		MasterAddr  string `json:"master_addr"`
		TailscaleIP string `json:"tailscale_ip"`
	} `json:"node"`

	Hardware struct {
		Backend             string  `json:"backend"`
		MaxTemperatureC     float64 `json:"max_temperature_c"`
		CooldownTemperature float64 `json:"cooldown_temperature_c"`
	} `json:"hardware"`

	Processing struct {
		DefaultFormat  string `json:"default_format"`
		Quality        int    `json:"quality"`
		MaxConcurrency int    `json:"max_concurrency"`
	} `json:"processing"`
}

// NewDefault returns the default configuration.
func NewDefault() *Config {
	cfg := &Config{}
	cfg.Server.Host = "0.0.0.0"
	cfg.Server.Port = 9876
	cfg.Server.AuthToken = ""

	cfg.Node.Role = RoleStandalone
	cfg.Node.Port = 9876

	cfg.Hardware.Backend = "auto"
	cfg.Hardware.MaxTemperatureC = 85.0
	cfg.Hardware.CooldownTemperature = 75.0

	cfg.Processing.DefaultFormat = "webp"
	cfg.Processing.Quality = 90
	cfg.Processing.MaxConcurrency = 4
	return cfg
}

// Load attempts to load configuration from file (YAML-like key/values), or falls back to defaults.
func Load(path string) (*Config, error) {
	cfg := NewDefault()
	if path == "" {
		if _, err := os.Stat("config.yaml"); err == nil {
			path = "config.yaml"
		} else {
			return cfg, nil
		}
	}

	file, err := os.Open(path)
	if err != nil {
		return cfg, err
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	currentSection := ""

	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}

		if strings.HasSuffix(line, ":") && !strings.Contains(line, " ") {
			currentSection = strings.TrimSuffix(line, ":")
			continue
		}

		parts := strings.SplitN(line, ":", 2)
		if len(parts) != 2 {
			continue
		}

		key := strings.TrimSpace(parts[0])
		val := strings.Trim(strings.TrimSpace(parts[1]), "\"'")

		switch currentSection {
		case "server":
			switch key {
			case "host":
				cfg.Server.Host = val
			case "port":
				if p, err := strconv.Atoi(val); err == nil {
					cfg.Server.Port = p
				}
			case "auth_token":
				cfg.Server.AuthToken = val
			}
		case "node":
			switch key {
			case "name":
				cfg.Node.Name = val
			case "role":
				cfg.Node.Role = Role(strings.ToUpper(val))
			case "master_addr":
				cfg.Node.MasterAddr = val
			case "tailscale_ip":
				cfg.Node.TailscaleIP = val
			}
		case "hardware":
			switch key {
			case "backend":
				cfg.Hardware.Backend = val
			case "max_temperature_c":
				if t, err := strconv.ParseFloat(val, 64); err == nil {
					cfg.Hardware.MaxTemperatureC = t
				}
			case "cooldown_temperature_c":
				if t, err := strconv.ParseFloat(val, 64); err == nil {
					cfg.Hardware.CooldownTemperature = t
				}
			}
		case "processing":
			switch key {
			case "default_format":
				cfg.Processing.DefaultFormat = val
			case "quality":
				if q, err := strconv.Atoi(val); err == nil {
					cfg.Processing.Quality = q
				}
			case "max_concurrency":
				if mc, err := strconv.Atoi(val); err == nil {
					cfg.Processing.MaxConcurrency = mc
				}
			}
		}
	}

	return cfg, scanner.Err()
}
