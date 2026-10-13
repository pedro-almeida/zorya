// PT: Módulo de Configuração Persistente do Zorya (YAML / Runtime)
// EN: Zorya Persistent Configuration Module (YAML / Runtime)
// AI: @llm-context Módulo Zorya v1.0.0 / Configuração e Registro do Windows

package config

import (
	"bufio"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"

	"github.com/pedro-almeida/zorya/internal/sysproc"
)

// Role defines the operation role of this node in the Tailscale grid.
type Role string

const (
	RoleMaster     Role = "MASTER"
	RoleWorker     Role = "WORKER"
	RoleStandalone Role = "STANDALONE"
)

// ListenMode defines network binding policy.
type ListenMode string

const (
	ListenLocalhost    ListenMode = "localhost"     // 127.0.0.1
	ListenTailscaleAll ListenMode = "tailscale_all" // 0.0.0.0 / Tailscale Mesh
)

// AIProvider represents an OpenAI-compatible provider (DeepSeek, OpenRouter, OpenAI, Groq, etc.).
type AIProvider struct {
	ID       string `json:"id"`
	Name     string `json:"name"`     // ex: "DeepSeek V3", "OpenAI GPT-4o", "OpenRouter"
	BaseURL  string `json:"base_url"` // ex: "https://api.deepseek.com/v1"
	APIKey   string `json:"api_key"`
	Model    string `json:"model"` // ex: "deepseek-chat", "gpt-4o-mini"
	IsActive bool   `json:"is_active"`
}

// AIConfig holds API keys, provider configurations and routing choices.
type AIConfig struct {
	GeminiAPIKey    string       `json:"gemini_api_key"`
	GroqAPIKey      string       `json:"groq_api_key"`
	ActiveProvider  string       `json:"active_provider"` // "gemini", "groq" or custom provider ID
	CustomProviders []AIProvider `json:"custom_providers"`
}

// Config holds runtime and persistent settings for the Zorya daemon.
type Config struct {
	mu sync.RWMutex

	Server struct {
		Host       string     `json:"host"`
		Port       int        `json:"port"`
		AuthToken  string     `json:"auth_token"`
		ListenMode ListenMode `json:"listen_mode"`
	} `json:"server"`

	Node struct {
		ID          string `json:"id"`
		Name        string `json:"name"`
		Role        Role   `json:"role"`
		MasterAddr  string `json:"master_addr"`
		TailscaleIP string `json:"tailscale_ip"`
	} `json:"node"`

	Hardware struct {
		CPUCores            int     `json:"cpu_cores"`
		GPUEnabled          bool    `json:"gpu_enabled"`
		GPUType             string  `json:"gpu_type"` // auto, cuda, vulkan, cpu
		VRAMLimitGB         float64 `json:"vram_limit_gb"`
		RAMLimitGB          float64 `json:"ram_limit_gb"`
		MaxTemperatureC     float64 `json:"max_temperature_c"`
		CooldownTemperature float64 `json:"cooldown_temperature_c"`
	} `json:"hardware"`

	AI AIConfig `json:"ai"`

	OS struct {
		AutostartWindows bool `json:"autostart_windows"`
		BackgroundMode   bool `json:"background_mode"`
	} `json:"os"`

	Processing struct {
		DefaultFormat  string `json:"default_format"` // "webp" or "jpeg"
		Quality        int    `json:"quality"`
		MaxConcurrency int    `json:"max_concurrency"`
	} `json:"processing"`
}

// NewDefault returns the canonical v1.0.0 configuration defaults.
func NewDefault() *Config {
	totalCPUs := runtime.NumCPU()
	defaultCores := totalCPUs / 2
	if defaultCores < 1 {
		defaultCores = 1
	}

	cfg := &Config{}
	cfg.Server.Host = "0.0.0.0"
	cfg.Server.Port = 9876
	cfg.Server.AuthToken = "zorya-master-token-9876"
	cfg.Server.ListenMode = ListenTailscaleAll

	cfg.Node.Role = RoleStandalone
	cfg.Node.Name = "Zorya RAW Accelerator"

	cfg.Hardware.CPUCores = defaultCores
	cfg.Hardware.GPUEnabled = true
	cfg.Hardware.GPUType = "auto"
	cfg.Hardware.VRAMLimitGB = 4.0
	cfg.Hardware.RAMLimitGB = 8.0
	cfg.Hardware.MaxTemperatureC = 85.0
	cfg.Hardware.CooldownTemperature = 75.0

	cfg.AI.GeminiAPIKey = ""
	cfg.AI.GroqAPIKey = ""
	cfg.AI.ActiveProvider = "gemini"
	cfg.AI.CustomProviders = make([]AIProvider, 0)

	cfg.OS.AutostartWindows = true
	cfg.OS.BackgroundMode = true

	cfg.Processing.DefaultFormat = "webp"
	cfg.Processing.Quality = 90
	cfg.Processing.MaxConcurrency = defaultCores
	return cfg
}

// Load attempts to load configuration from YAML file, or returns default config.
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
			case "listen_mode":
				cfg.Server.ListenMode = ListenMode(val)
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
			case "cpu_cores":
				if c, err := strconv.Atoi(val); err == nil && c > 0 {
					cfg.Hardware.CPUCores = c
				}
			case "gpu_enabled":
				cfg.Hardware.GPUEnabled = (val == "true" || val == "1" || val == "yes")
			case "gpu_type":
				cfg.Hardware.GPUType = val
			case "vram_limit_gb":
				if v, err := strconv.ParseFloat(val, 64); err == nil {
					cfg.Hardware.VRAMLimitGB = v
				}
			case "ram_limit_gb":
				if r, err := strconv.ParseFloat(val, 64); err == nil {
					cfg.Hardware.RAMLimitGB = r
				}
			case "max_temperature_c":
				if t, err := strconv.ParseFloat(val, 64); err == nil {
					cfg.Hardware.MaxTemperatureC = t
				}
			case "cooldown_temperature_c":
				if t, err := strconv.ParseFloat(val, 64); err == nil {
					cfg.Hardware.CooldownTemperature = t
				}
			}
		case "ai":
			switch key {
			case "gemini_api_key":
				cfg.AI.GeminiAPIKey = val
			case "groq_api_key":
				cfg.AI.GroqAPIKey = val
			case "active_provider":
				cfg.AI.ActiveProvider = val
			case "custom_providers_json":
				var provs []AIProvider
				if err := json.Unmarshal([]byte(val), &provs); err == nil {
					cfg.AI.CustomProviders = provs
				}
			}
		case "os":
			switch key {
			case "autostart_windows":
				cfg.OS.AutostartWindows = (val == "true" || val == "1" || val == "yes")
			case "background_mode":
				cfg.OS.BackgroundMode = (val == "true" || val == "1" || val == "yes")
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

// Save serializes the current configuration into canonical YAML format.
func Save(path string, cfg *Config) error {
	if path == "" {
		path = "config.yaml"
	}

	customProvsJSON := "[]"
	if len(cfg.AI.CustomProviders) > 0 {
		if b, err := json.Marshal(cfg.AI.CustomProviders); err == nil {
			customProvsJSON = string(b)
		}
	}

	content := fmt.Sprintf(`# =============================================================================
# ZORYA RAW COMPUTE ENGINE - Configuration File v1.0.0
# Autonomous RAW Coprocessor & Distributed Tailscale Mesh Node
# =============================================================================

server:
  host: "%s"
  port: %d
  auth_token: "%s"
  listen_mode: "%s"

node:
  name: "%s"
  role: "%s"
  master_addr: "%s"
  tailscale_ip: "%s"

hardware:
  cpu_cores: %d
  gpu_enabled: %t
  gpu_type: "%s"
  vram_limit_gb: %.1f
  ram_limit_gb: %.1f
  max_temperature_c: %.1f
  cooldown_temperature_c: %.1f

ai:
  gemini_api_key: "%s"
  groq_api_key: "%s"
  active_provider: "%s"
  custom_providers_json: '%s'

os:
  autostart_windows: %t
  background_mode: %t

processing:
  default_format: "%s"
  quality: %d
  max_concurrency: %d
`,
		cfg.Server.Host,
		cfg.Server.Port,
		cfg.Server.AuthToken,
		string(cfg.Server.ListenMode),
		cfg.Node.Name,
		string(cfg.Node.Role),
		cfg.Node.MasterAddr,
		cfg.Node.TailscaleIP,
		cfg.Hardware.CPUCores,
		cfg.Hardware.GPUEnabled,
		cfg.Hardware.GPUType,
		cfg.Hardware.VRAMLimitGB,
		cfg.Hardware.RAMLimitGB,
		cfg.Hardware.MaxTemperatureC,
		cfg.Hardware.CooldownTemperature,
		cfg.AI.GeminiAPIKey,
		cfg.AI.GroqAPIKey,
		cfg.AI.ActiveProvider,
		customProvsJSON,
		cfg.OS.AutostartWindows,
		cfg.OS.BackgroundMode,
		cfg.Processing.DefaultFormat,
		cfg.Processing.Quality,
		cfg.Processing.MaxConcurrency,
	)

	return os.WriteFile(path, []byte(content), 0644)
}

// ApplyWindowsAutostart registers or unregisters Zorya in Windows CurrentVersion\Run.
func ApplyWindowsAutostart(enabled bool) error {
	if runtime.GOOS != "windows" {
		return nil
	}

	exePath, err := os.Executable()
	if err != nil {
		return err
	}
	exePath = filepath.Clean(exePath)

	regKey := `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`
	valName := "Zorya"

	if enabled {
		cmdVal := fmt.Sprintf(`"%s" --background`, exePath)
		cmd := exec.Command("reg", "add", regKey, "/v", valName, "/t", "REG_SZ", "/d", cmdVal, "/f")
		sysproc.PrepareCmd(cmd)
		return cmd.Run()
	} else {
		cmd := exec.Command("reg", "delete", regKey, "/v", valName, "/f")
		sysproc.PrepareCmd(cmd)
		_ = cmd.Run()
		return nil
	}
}
