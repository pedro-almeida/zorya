// PT: Servidor HTTP REST e Painel Web Embarcado do Zorya v1.0.0
// EN: Zorya v1.0.0 HTTP REST Server and Embedded Web UI
// AI: @llm-context Módulo Zorya v1.0.0 / Servidor de Rede, API e UI Embarcada

package server

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"image"
	"io"
	"io/fs"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pedro-almeida/zorya/internal/config"
	"github.com/pedro-almeida/zorya/internal/hardware"
	"github.com/pedro-almeida/zorya/internal/network"
	"github.com/pedro-almeida/zorya/internal/processor"
	"github.com/pedro-almeida/zorya/internal/server/ui"
	"syscall"
)

// NodeStatus defines the operational condition of a worker node.
type NodeStatus string

const (
	StatusIdle        NodeStatus = "IDLE"
	StatusProcessing  NodeStatus = "PROCESSING"
	StatusOverheating NodeStatus = "OVERHEATING"
	StatusOffline     NodeStatus = "OFFLINE"
)

// TaskStatus defines the lifecycle state of an atomic RAW processing task.
type TaskStatus string

const (
	TaskPending    TaskStatus = "PENDING"
	TaskDispatched TaskStatus = "DISPATCHED"
	TaskCompleted  TaskStatus = "COMPLETED"
	TaskFailed     TaskStatus = "FAILED"
)

// NodeTelemetry holds real-time telemetry for a node in the mesh.
type NodeTelemetry struct {
	NodeID          string      `json:"node_id"`
	NodeName        string      `json:"node_name"`
	TailscaleIP     string      `json:"tailscale_ip"`
	Port            int         `json:"port"`
	Role            config.Role `json:"role"`
	GPUType         string      `json:"gpu_type"`
	CUDAReady       bool        `json:"cuda_ready"`
	VulkanReady     bool        `json:"vulkan_ready"`
	NPUReady        bool        `json:"npu_ready"`
	CPUCores        int         `json:"cpu_cores"`
	RAMTotalGB      float64     `json:"ram_total_gb"`
	TemperatureC    float64     `json:"temperature_c"`
	IsPlugged       bool        `json:"is_plugged"`
	BatteryPercent  int         `json:"battery_percent"`
	CPUUsagePercent float64     `json:"cpu_usage_percent"`
	GPUUsagePercent float64     `json:"gpu_usage_percent"`
	Status          NodeStatus  `json:"status"`
	LastHeartbeat   time.Time   `json:"last_heartbeat"`
	ProcessedCount  int64       `json:"processed_count"`
	ThroughputPPM   float64     `json:"throughput_ppm"`
}

// GridTask represents an atomic RAW image processing job distributed over Tailscale.
type GridTask struct {
	TaskID           string                 `json:"task_id"`
	BatchID          string                 `json:"batch_id"`
	RAWID            string                 `json:"raw_id"`
	Filename         string                 `json:"filename"`
	FileSize         int64                  `json:"file_size"`
	PresetParams     map[string]interface{} `json:"preset_params"`
	RawURL           string                 `json:"raw_url,omitempty"`
	RawBase64        string                 `json:"raw_base64,omitempty"`
	ResultFormat     string                 `json:"result_format"`
	AssignedWorkerID string                 `json:"assigned_worker_id,omitempty"`
	Status           TaskStatus             `json:"status"`
	RetryCount       int                    `json:"retry_count"`
	CreatedAt        time.Time              `json:"created_at"`
	DispatchedAt     time.Time              `json:"dispatched_at,omitempty"`
	CompletedAt      time.Time              `json:"completed_at,omitempty"`
	ProcessedBy      string                 `json:"processed_by,omitempty"`
	ElapsedMs        int64                  `json:"elapsed_ms,omitempty"`
	ResultData       string                 `json:"result_data,omitempty"`
}

// ClusterStatus represents the aggregated status exposed to the control plane.
type ClusterStatus struct {
	Role               config.Role     `json:"role"`
	LocalNode          NodeTelemetry   `json:"local_node"`
	MasterAddr         string          `json:"master_addr"`
	TailscaleConnected bool            `json:"tailscale_connected"`
	TotalWorkers       int             `json:"total_workers"`
	ActiveWorkers      int             `json:"active_workers"`
	QueuePending       int             `json:"queue_pending"`
	QueueDispatched    int             `json:"queue_dispatched"`
	QueueCompleted     int             `json:"queue_completed"`
	TotalThroughputPPM float64         `json:"total_throughput_ppm"`
	Workers            []NodeTelemetry `json:"workers"`
	RecentTasks        []GridTask      `json:"recent_tasks"`
	ThermalWarnings    []string        `json:"thermal_warnings"`
}

// PipelineStats aggregates 2-stage processing engine telemetry.
type PipelineStats struct {
	IngestBatchesCount  int64 `json:"ingest_batches_count"`
	IngestPhotosTotal   int64 `json:"ingest_photos_total"`
	IngestApprovedTotal int64 `json:"ingest_approved_total"`
	IngestReviewTotal   int64 `json:"ingest_review_total"`
	IngestRejectedTotal int64 `json:"ingest_rejected_total"`
	MasterJobsCount     int64 `json:"master_jobs_count"`
	MasterPhotosTotal   int64 `json:"master_photos_total"`
	MasterSuccessTotal  int64 `json:"master_success_total"`
}

// Daemon is the active Zorya HTTP engine and mesh orchestrator.
type Daemon struct {
	cfg        *config.Config
	configPath string
	server     *http.Server
	proc       *processor.Processor
	startTime  time.Time

	mu          sync.RWMutex
	workers     map[string]*NodeTelemetry
	tasks       map[string]*GridTask
	taskQueue   []string
	doneTasks   []GridTask
	completedN  int64
	lastMinuteN int64

	pipelineMu       sync.RWMutex
	stats            PipelineStats
	recentIngests    []processor.IngestBatchResult
	recentMasterJobs []processor.MasterExportResult

	tray   TrayManager
	cuda   *hardware.CUDAOrchestrator
	ctx    context.Context
	cancel context.CancelFunc
}

// New instantiates a new Zorya HTTP daemon.
func New(cfg *config.Config, configPath string) *Daemon {
	if cfg == nil {
		cfg = config.NewDefault()
	}
	if configPath == "" {
		configPath = "config.yaml"
	}
	if loaded, err := config.Load(configPath); err == nil && loaded != nil {
		cfg = loaded
	}

	if cfg.Node.ID == "" {
		cfg.Node.ID = "zorya-" + network.RandomHex(4)
	}

	tsIP, _ := hardware.DetectTailscaleIP()
	if cfg.Node.TailscaleIP == "" {
		cfg.Node.TailscaleIP = tsIP
	}

	ctx, cancel := context.WithCancel(context.Background())

	d := &Daemon{
		cfg:              cfg,
		configPath:       configPath,
		proc:             processor.New(),
		cuda:             hardware.NewCUDAOrchestrator(cfg.Hardware.GPUEnabled, cfg.Hardware.VRAMLimitGB, cfg.Hardware.CPUCores),
		startTime:        time.Now(),
		workers:          make(map[string]*NodeTelemetry),
		tasks:            make(map[string]*GridTask),
		taskQueue:        make([]string, 0),
		doneTasks:        make([]GridTask, 0),
		recentIngests:    make([]processor.IngestBatchResult, 0),
		recentMasterJobs: make([]processor.MasterExportResult, 0),
		ctx:              ctx,
		cancel:           cancel,
	}

	d.proc.SetOrchestrator(d.cuda)
	d.proc.SetAIKeys(cfg.AI.GeminiAPIKey, cfg.AI.GroqAPIKey)

	return d
}

// Start launches the HTTP listener and background orchestration loops.
func (d *Daemon) Start() error {
	mux := http.NewServeMux()
	d.mountRoutes(mux)

	host := "0.0.0.0"
	if d.cfg.Server.ListenMode == config.ListenLocalhost {
		host = "127.0.0.1"
	}

	addr := fmt.Sprintf("%s:%d", host, d.cfg.Server.Port)
	d.server = &http.Server{
		Addr:    addr,
		Handler: d.corsMiddleware(mux),
	}

	listener, err := net.Listen("tcp", addr)
	if err != nil {
		return fmt.Errorf("failed to bind port %d: %w", d.cfg.Server.Port, err)
	}

	log.Printf("[Zorya Daemon] Ouvindo em http://%s (Modo: %s)\n", addr, d.cfg.Server.ListenMode)

	go func() {
		if err := d.server.Serve(listener); err != nil && err != http.ErrServerClosed {
			log.Printf("[Zorya ERRO] Servidor HTTP encerrado: %v\n", err)
		}
	}()

	// Start background mesh tasks if in Master/Worker role
	if d.cfg.Node.Role == config.RoleMaster {
		go d.thermalGuardianLoop()
	} else if d.cfg.Node.Role == config.RoleWorker {
		go d.workerExecutionLoop()
	}

	return nil
}

// SetTray associates the system tray manager with the daemon.
func (d *Daemon) SetTray(t TrayManager) {
	d.tray = t
}

// SetTrayActive toggles the tray icon between Standby (grayscale) and Active (colorful).
func (d *Daemon) SetTrayActive(active bool) {
	if d.tray != nil {
		d.tray.SetActive(active)
	}
}

// Stop cleanly terminates HTTP listeners and background routines.
func (d *Daemon) Stop() {
	d.cancel()
	if d.server != nil {
		ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer cancel()
		_ = d.server.Shutdown(ctx)
	}
	log.Println("[Zorya Daemon] Encerrado com sucesso.")
}

// Restart cleanly re-initializes and starts the daemon.
func (d *Daemon) Restart() error {
	log.Println("[Zorya Daemon] Reiniciando serviço...")
	d.Stop()
	d.ctx, d.cancel = context.WithCancel(context.Background())
	return d.Start()
}

// mountRoutes registers the embedded UI, REST v1 API, and backward-compatible grid routes.
func (d *Daemon) mountRoutes(mux *http.ServeMux) {
	// 1. EMBEDDED WEB UI (Svitlo Style)
	mux.HandleFunc("/", d.handleRootUI)
	mux.HandleFunc("/ui/", d.handleUIAssets)

	// 2. REST API v1 ENDPOINTS
	mux.HandleFunc("/health", d.wrapCORS(d.handleHealth))
	mux.HandleFunc("/api/v1/system/info", d.wrapCORS(d.handleSystemInfo))
	mux.HandleFunc("/api/v1/hardware/cuda-status", d.wrapCORS(d.handleCUDAStatus))
	mux.HandleFunc("/api/v1/photo/ai-curate", d.wrapCORS(d.handlePhotoAICurate))
	mux.HandleFunc("/api/v1/audio/enhance", d.wrapCORS(d.handleAudioEnhance))
	mux.HandleFunc("/api/v1/audio/detect-silence", d.wrapCORS(d.handleAudioDetectSilence))
	mux.HandleFunc("/api/v1/video/smart-crop", d.wrapCORS(d.handleVideoSmartCrop))
	mux.HandleFunc("/api/v1/video/apply-lut", d.wrapCORS(d.handleVideoApplyLUT))
	mux.HandleFunc("/api/v1/raw/decode", d.wrapCORS(d.handleRawDecode))
	mux.HandleFunc("/api/v1/raw/ingest-batch", d.wrapCORS(d.handleRawIngestBatch))
	mux.HandleFunc("/api/v1/raw/master-export", d.wrapCORS(d.handleRawMasterExport))
	mux.HandleFunc("/api/v1/pipeline/status", d.wrapCORS(d.handlePipelineStatus))
	mux.HandleFunc("/api/v1/config/save", d.wrapCORS(d.handleConfigSave))

	// Registrar a pasta local 'thumbnails' para servir os WebPs:
	_ = os.MkdirAll("thumbnails", 0755)
	_ = os.MkdirAll("C:\\dev\\zorya\\thumbnails", 0755)
	mux.HandleFunc("/thumbnails/", func(w http.ResponseWriter, r *http.Request) {
		applyPNAHeaders(w)
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusOK)
			return
		}
		filePath := strings.TrimPrefix(r.URL.Path, "/thumbnails/")
		fullPath := filepath.Join("thumbnails", filepath.Clean(filePath))
		if _, err := os.Stat(fullPath); os.IsNotExist(err) {
			fullPath = filepath.Join("C:\\dev\\zorya\\thumbnails", filepath.Clean(filePath))
			if _, err := os.Stat(fullPath); os.IsNotExist(err) {
				http.NotFound(w, r)
				return
			}
		}
		w.Header().Set("Cache-Control", "public, max-age=86400")
		http.ServeFile(w, r, fullPath)
	})

	// Endpoint nativo de seleção de pastas sem abrir janela preta:
	mux.HandleFunc("/api/v1/system/browse-folder", d.wrapCORS(func(w http.ResponseWriter, r *http.Request) {
		cmd := exec.Command("powershell", "-NoProfile", "-Command", "Add-Type -AssemblyName System.Windows.Forms; $f = New-Object System.Windows.Forms.FolderBrowserDialog; $f.Description = 'Selecione a pasta do Ensaio'; if ($f.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) { Write-Output $f.SelectedPath }")
		cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000}
		out, err := cmd.Output()
		caminho := strings.TrimSpace(string(out))
		w.Header().Set("Content-Type", "application/json")
		if err == nil && caminho != "" {
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"selected": true, "path": caminho})
		} else {
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"selected": false})
		}
	}))

	// 3. BACKWARD COMPATIBLE GRID ENDPOINTS
	mux.HandleFunc("/api/grid/register", d.wrapCORS(d.handleRegister))
	mux.HandleFunc("/api/grid/heartbeat", d.wrapCORS(d.handleHeartbeat))
	mux.HandleFunc("/api/grid/task/pull", d.wrapCORS(d.handleTaskPull))
	mux.HandleFunc("/api/grid/task/complete", d.wrapCORS(d.handleTaskComplete))
	mux.HandleFunc("/api/grid/status", d.wrapCORS(d.handleClusterStatus))
	mux.HandleFunc("/api/grid/batch/submit", d.wrapCORS(d.handleBatchSubmit))
	mux.HandleFunc("/api/grid/config", d.wrapCORS(d.handleConfig))
}

func applyPNAHeaders(w http.ResponseWriter) {
	w.Header().Set("Access-Control-Allow-Origin", "https://www.tenorio.fot.br")
	w.Header().Set("Access-Control-Allow-Methods", "POST, GET, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Accept, Content-Type, Authorization, X-Requested-With")
	w.Header().Set("Access-Control-Allow-Private-Network", "true")
}

func (d *Daemon) corsMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		applyPNAHeaders(w)
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusOK)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func (d *Daemon) wrapCORS(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		applyPNAHeaders(w)
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusOK)
			return
		}
		next(w, r)
	}
}

// handleRootUI serves the embedded index.html at root /.
func (d *Daemon) handleRootUI(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/" {
		http.NotFound(w, r)
		return
	}
	data, err := ui.DistFS.ReadFile("index.html")
	if err != nil {
		http.Error(w, "Embedded UI not found", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(data)
}

// handleUIAssets serves static embedded files (/ui/style.css, /ui/app.js).
func (d *Daemon) handleUIAssets(w http.ResponseWriter, r *http.Request) {
	filePath := strings.TrimPrefix(r.URL.Path, "/ui/")
	if filePath == "" || filePath == "/" {
		filePath = "index.html"
	}

	data, err := fs.ReadFile(ui.DistFS, filePath)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	switch {
	case strings.HasSuffix(filePath, ".css"):
		w.Header().Set("Content-Type", "text/css; charset=utf-8")
	case strings.HasSuffix(filePath, ".js"):
		w.Header().Set("Content-Type", "application/javascript; charset=utf-8")
	case strings.HasSuffix(filePath, ".html"):
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
	}

	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(data)
}

// GET /health
func (d *Daemon) handleHealth(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":  "ready",
		"engine":  "zorya",
		"version": "1.0.0",
	})
}

// GET /api/v1/system/info
func (d *Daemon) handleSystemInfo(w http.ResponseWriter, r *http.Request) {
	d.mu.RLock()
	allocatedCores := d.cfg.Hardware.CPUCores
	ramLimit := d.cfg.Hardware.RAMLimitGB
	gpuEnabled := d.cfg.Hardware.GPUEnabled
	vramLimit := d.cfg.Hardware.VRAMLimitGB
	port := d.cfg.Server.Port
	listenMode := string(d.cfg.Server.ListenMode)
	d.mu.RUnlock()

	info := hardware.Detect(allocatedCores, ramLimit, gpuEnabled, vramLimit, port, listenMode)

	d.pipelineMu.RLock()
	statsCopy := d.stats
	recentIngestsCopy := make([]processor.IngestBatchResult, len(d.recentIngests))
	copy(recentIngestsCopy, d.recentIngests)
	recentMasterJobsCopy := make([]processor.MasterExportResult, len(d.recentMasterJobs))
	copy(recentMasterJobsCopy, d.recentMasterJobs)
	d.pipelineMu.RUnlock()

	d.mu.RLock()
	geminiConfigured := d.cfg.AI.GeminiAPIKey != ""
	geminiMasked := maskAPIKey(d.cfg.AI.GeminiAPIKey, "AIzaSy...")
	groqConfigured := d.cfg.AI.GroqAPIKey != ""
	groqMasked := maskAPIKey(d.cfg.AI.GroqAPIKey, "gsk_...")
	activeProvider := d.cfg.AI.ActiveProvider
	if activeProvider == "" {
		activeProvider = "gemini"
	}
	customProvs := make([]config.AIProvider, len(d.cfg.AI.CustomProviders))
	for i, cp := range d.cfg.AI.CustomProviders {
		cpCopy := cp
		cpCopy.APIKey = maskAPIKey(cp.APIKey, "sk-...")
		customProvs[i] = cpCopy
	}
	d.mu.RUnlock()

	response := map[string]interface{}{
		"version":   info.Version,
		"os":        info.OS,
		"arch":      info.Arch,
		"cpu":       info.CPU,
		"ram":       info.RAM,
		"gpu":       info.GPU,
		"tailscale": info.Tailscale,
		"node_url":  info.NodeURL,
		"ai": map[string]interface{}{
			"gemini_configured": geminiConfigured,
			"gemini_masked_key": geminiMasked,
			"groq_configured":   groqConfigured,
			"groq_masked_key":   groqMasked,
			"active_provider":   activeProvider,
			"custom_providers":  customProvs,
		},
		"pipeline": map[string]interface{}{
			"stats":              statsCopy,
			"recent_ingests":     recentIngestsCopy,
			"recent_master_jobs": recentMasterJobsCopy,
		},
	}

	writeJSON(w, http.StatusOK, response)
}

func maskAPIKey(key, prefix string) string {
	if key == "" {
		return ""
	}
	if len(key) <= 8 {
		return "********"
	}
	prefixLen := 6
	if len(prefix) > 0 && len(prefix) < len(key)/2 {
		prefixLen = len(prefix)
	}
	if prefixLen > len(key)-4 {
		prefixLen = len(key) - 4
	}
	last4 := key[len(key)-4:]
	firstPart := key[:prefixLen]
	return fmt.Sprintf("%s...%s", firstPart, last4)
}

// POST /api/v1/raw/decode
func (d *Daemon) handleRawDecode(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	d.mu.RLock()
	opts := processor.DecodeOptions{
		Format:      d.cfg.Processing.DefaultFormat,
		Quality:     d.cfg.Processing.Quality,
		GPUEnabled:  d.cfg.Hardware.GPUEnabled,
		VRAMLimitGB: d.cfg.Hardware.VRAMLimitGB,
		CPUCores:    d.cfg.Hardware.CPUCores,
	}
	d.mu.RUnlock()

	// Default target format is webp for high compression & fidelity
	if opts.Format == "" {
		opts.Format = "webp"
	}

	var fileReader io.Reader
	var filename string
	var localFileToClose *os.File

	defer func() {
		if localFileToClose != nil {
			_ = localFileToClose.Close()
		}
	}()

	contentType := r.Header.Get("Content-Type")

	// Check for local file path via query parameter
	localPath := r.URL.Query().Get("path")
	if localPath == "" {
		localPath = r.URL.Query().Get("file_path")
	}

	// 1. Multipart/form-data upload or form with local path
	if strings.HasPrefix(contentType, "multipart/form-data") {
		err := r.ParseMultipartForm(64 << 20) // 64 MB in-memory buffer
		if err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Failed to parse multipart form: " + err.Error()})
			return
		}

		if fmtParam := r.FormValue("format"); fmtParam != "" {
			opts.Format = fmtParam
		}
		if qParam := r.FormValue("quality"); qParam != "" {
			if q, err := strconv.Atoi(qParam); err == nil {
				opts.Quality = q
			}
		}

		if localPath == "" {
			localPath = r.FormValue("path")
			if localPath == "" {
				localPath = r.FormValue("file_path")
			}
			if localPath == "" {
				localPath = r.FormValue("local_path")
			}
		}

		if localPath != "" {
			f, err := os.Open(localPath)
			if err != nil {
				writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Failed to open local path: " + err.Error()})
				return
			}
			localFileToClose = f
			fileReader = f
			filename = filepath.Base(localPath)
		} else {
			file, header, err := r.FormFile("file")
			if err != nil {
				file, header, err = r.FormFile("raw_file")
			}
			if err != nil {
				file, header, err = r.FormFile("image")
			}
			if err != nil {
				writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Missing 'file' or 'path' in multipart form"})
				return
			}
			defer file.Close()
			fileReader = file
			filename = header.Filename
		}

	} else {
		// 2. JSON or Raw stream payload
		var jsonReq struct {
			RAWID        string `json:"raw_id"`
			Filename     string `json:"filename"`
			Path         string `json:"path"`
			FilePath     string `json:"file_path"`
			LocalPath    string `json:"local_path"`
			RawBase64    string `json:"raw_base64"`
			RawURL       string `json:"raw_url"`
			ResultFormat string `json:"result_format"`
			Format       string `json:"format"`
			Quality      int    `json:"quality"`
		}

		bodyBytes, err := io.ReadAll(r.Body)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Failed to read request body"})
			return
		}

		if err := json.Unmarshal(bodyBytes, &jsonReq); err == nil {
			if jsonReq.Quality > 0 {
				opts.Quality = jsonReq.Quality
			}
			if jsonReq.ResultFormat != "" {
				opts.Format = jsonReq.ResultFormat
			} else if jsonReq.Format != "" {
				opts.Format = jsonReq.Format
			}

			if localPath == "" {
				if jsonReq.Path != "" {
					localPath = jsonReq.Path
				} else if jsonReq.FilePath != "" {
					localPath = jsonReq.FilePath
				} else if jsonReq.LocalPath != "" {
					localPath = jsonReq.LocalPath
				}
			}

			if localPath != "" {
				f, err := os.Open(localPath)
				if err != nil {
					writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Failed to open local path: " + err.Error()})
					return
				}
				localFileToClose = f
				fileReader = f
				filename = filepath.Base(localPath)
			} else if jsonReq.RawBase64 != "" {
				filename = jsonReq.Filename
				b64 := jsonReq.RawBase64
				if idx := strings.Index(b64, ","); idx != -1 {
					b64 = b64[idx+1:]
				}
				rawDecoded, err := base64.StdEncoding.DecodeString(b64)
				if err != nil {
					writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Invalid base64 payload"})
					return
				}
				fileReader = bytes.NewReader(rawDecoded)
			} else if jsonReq.RawURL != "" {
				filename = jsonReq.Filename
				client := &http.Client{Timeout: 15 * time.Second}
				resp, err := client.Get(jsonReq.RawURL)
				if err != nil {
					writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Failed to fetch RAW from URL: " + err.Error()})
					return
				}
				defer resp.Body.Close()
				buf, _ := io.ReadAll(resp.Body)
				fileReader = bytes.NewReader(buf)
			}
		}

		if fileReader == nil {
			if localPath != "" {
				f, err := os.Open(localPath)
				if err != nil {
					writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Failed to open local path: " + err.Error()})
					return
				}
				localFileToClose = f
				fileReader = f
				filename = filepath.Base(localPath)
			} else if len(bodyBytes) > 0 {
				fileReader = bytes.NewReader(bodyBytes)
				filename = "input.raw"
			} else {
				writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Empty payload or missing file/path"})
				return
			}
		}
	}

	result, err := d.proc.DecodeStream(fileReader, filename, opts)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{
			"error":   "decode_failed",
			"message": err.Error(),
		})
		return
	}

	atomic.AddInt64(&d.completedN, 1)

	// Return binary stream with Content-Type: image/webp (or appropriate image format)
	contentTypeHeader := "image/" + result.Format
	if result.Format == "webp" {
		contentTypeHeader = "image/webp"
	}
	w.Header().Set("Content-Type", contentTypeHeader)
	w.Header().Set("X-Processing-Time-Ms", strconv.FormatInt(result.ElapsedMs, 10))
	w.Header().Set("X-Accelerator-Used", result.Accelerator)
	w.Header().Set("X-Image-Width", strconv.Itoa(result.Width))
	w.Header().Set("X-Image-Height", strconv.Itoa(result.Height))
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(result.Data)
}

// POST /api/v1/raw/ingest-batch
func (d *Daemon) handleRawIngestBatch(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	d.SetTrayActive(true)
	defer d.SetTrayActive(false)

	var req processor.IngestBatchRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Invalid JSON: " + err.Error()})
		return
	}

	if req.ServerPort <= 0 {
		req.ServerPort = d.cfg.Server.Port
	}
	if !req.GPUEnabled {
		req.GPUEnabled = d.cfg.Hardware.GPUEnabled
	}

	d.mu.RLock()
	cpuCores := d.cfg.Hardware.CPUCores
	d.mu.RUnlock()

	result, err := d.proc.ProcessIngestBatch(req, cpuCores)
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}

	d.pipelineMu.Lock()
	d.stats.IngestBatchesCount++
	d.stats.IngestPhotosTotal += int64(result.Total)
	d.stats.IngestApprovedTotal += int64(result.ApprovedCount)
	d.stats.IngestReviewTotal += int64(result.ReviewCount)
	d.stats.IngestRejectedTotal += int64(result.RejectedCount)

	d.recentIngests = append([]processor.IngestBatchResult{*result}, d.recentIngests...)
	if len(d.recentIngests) > 10 {
		d.recentIngests = d.recentIngests[:10]
	}
	d.pipelineMu.Unlock()

	writeJSON(w, http.StatusOK, result)
}

// POST /api/v1/raw/master-export
func (d *Daemon) handleRawMasterExport(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	d.SetTrayActive(true)
	defer d.SetTrayActive(false)

	var req processor.MasterExportRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Invalid JSON: " + err.Error()})
		return
	}

	d.mu.RLock()
	gpuEnabled := d.cfg.Hardware.GPUEnabled
	cpuCores := d.cfg.Hardware.CPUCores
	d.mu.RUnlock()

	result, err := d.proc.ProcessMasterExport(req, "export/final", gpuEnabled, cpuCores)
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}

	d.pipelineMu.Lock()
	d.stats.MasterJobsCount++
	d.stats.MasterPhotosTotal += int64(result.Total)
	d.stats.MasterSuccessTotal += int64(result.SuccessCount)

	d.recentMasterJobs = append([]processor.MasterExportResult{*result}, d.recentMasterJobs...)
	if len(d.recentMasterJobs) > 10 {
		d.recentMasterJobs = d.recentMasterJobs[:10]
	}
	d.pipelineMu.Unlock()

	writeJSON(w, http.StatusOK, result)
}

// GET /api/v1/pipeline/status
func (d *Daemon) handlePipelineStatus(w http.ResponseWriter, r *http.Request) {
	d.pipelineMu.RLock()
	statsCopy := d.stats
	recentIngestsCopy := make([]processor.IngestBatchResult, len(d.recentIngests))
	copy(recentIngestsCopy, d.recentIngests)
	recentMasterJobsCopy := make([]processor.MasterExportResult, len(d.recentMasterJobs))
	copy(recentMasterJobsCopy, d.recentMasterJobs)
	d.pipelineMu.RUnlock()

	d.mu.RLock()
	gpu := d.cfg.Hardware.GPUEnabled
	cores := d.cfg.Hardware.CPUCores
	d.mu.RUnlock()

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":             "ready",
		"engine":             "zorya",
		"version":            "1.0.0",
		"gpu_enabled":        gpu,
		"cpu_cores":          cores,
		"stats":              statsCopy,
		"recent_ingests":     recentIngestsCopy,
		"recent_master_jobs": recentMasterJobsCopy,
	})
}

// POST /api/v1/config/save
func (d *Daemon) handleConfigSave(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req struct {
		CPUCores         int                 `json:"cpu_cores"`
		GPUEnabled       bool                `json:"gpu_enabled"`
		VRAMLimitGB      float64             `json:"vram_limit_gb"`
		RAMLimitGB       float64             `json:"ram_limit_gb"`
		GeminiAPIKey     string              `json:"gemini_api_key"`
		GroqAPIKey       string              `json:"groq_api_key"`
		ActiveProvider   string              `json:"active_provider"`
		CustomProviders  *[]config.AIProvider `json:"custom_providers"`
		ListenMode       string              `json:"listen_mode"`
		AuthToken        string              `json:"auth_token"`
		AutostartWindows bool                `json:"autostart_windows"`
	}

	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Invalid JSON: " + err.Error()})
		return
	}

	d.mu.Lock()
	if req.CPUCores > 0 {
		d.cfg.Hardware.CPUCores = req.CPUCores
		d.cfg.Processing.MaxConcurrency = req.CPUCores
	}
	d.cfg.Hardware.GPUEnabled = req.GPUEnabled
	if req.VRAMLimitGB > 0 {
		d.cfg.Hardware.VRAMLimitGB = req.VRAMLimitGB
	}
	if req.RAMLimitGB > 0 {
		d.cfg.Hardware.RAMLimitGB = req.RAMLimitGB
	}

	// Preservação de chaves existentes de IA (não sobrescrever se vazia ou se vier máscara com "...")
	trimmedGemini := strings.TrimSpace(req.GeminiAPIKey)
	if trimmedGemini != "" && !strings.Contains(trimmedGemini, "...") && !strings.Contains(trimmedGemini, "***") {
		d.cfg.AI.GeminiAPIKey = trimmedGemini
	}

	trimmedGroq := strings.TrimSpace(req.GroqAPIKey)
	if trimmedGroq != "" && !strings.Contains(trimmedGroq, "...") && !strings.Contains(trimmedGroq, "***") {
		d.cfg.AI.GroqAPIKey = trimmedGroq
	}

	if req.ActiveProvider != "" {
		d.cfg.AI.ActiveProvider = req.ActiveProvider
	}

	if req.CustomProviders != nil {
		// Map existing custom providers by ID to preserve unmasked keys if incoming key is masked
		existingMap := make(map[string]string)
		for _, cp := range d.cfg.AI.CustomProviders {
			existingMap[cp.ID] = cp.APIKey
		}

		updatedProvs := make([]config.AIProvider, 0, len(*req.CustomProviders))
		for _, cp := range *req.CustomProviders {
			prov := cp
			provKey := strings.TrimSpace(prov.APIKey)
			if strings.Contains(provKey, "...") || strings.Contains(provKey, "***") || provKey == "" {
				if oldKey, ok := existingMap[prov.ID]; ok && oldKey != "" {
					prov.APIKey = oldKey
				}
			}
			updatedProvs = append(updatedProvs, prov)
		}
		d.cfg.AI.CustomProviders = updatedProvs
	}

	if req.ListenMode != "" {
		d.cfg.Server.ListenMode = config.ListenMode(req.ListenMode)
	}
	if req.AuthToken != "" {
		d.cfg.Server.AuthToken = req.AuthToken
	}
	d.cfg.OS.AutostartWindows = req.AutostartWindows
	d.mu.Unlock()

	// 1. Save config to disk
	if err := config.Save(d.configPath, d.cfg); err != nil {
		log.Printf("[Zorya AVISO] Falha ao persistir config.yaml: %v\n", err)
	}

	// 2. Set/remove Windows autostart registry key
	if err := config.ApplyWindowsAutostart(req.AutostartWindows); err != nil {
		log.Printf("[Zorya AVISO] Falha ao configurar autostart no Windows: %v\n", err)
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":  "ok",
		"message": "Configurações aplicadas com sucesso",
		"config":  d.cfg,
	})
}

// --- CLUSTER ORCHESTRATION ROUTES (BACKWARD-COMPATIBLE) ---

func (d *Daemon) handleRegister(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var worker NodeTelemetry
	if err := json.NewDecoder(r.Body).Decode(&worker); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Invalid JSON"})
		return
	}

	worker.LastHeartbeat = time.Now()
	worker.Status = StatusIdle

	d.mu.Lock()
	d.workers[worker.NodeID] = &worker
	d.mu.Unlock()

	log.Printf("[Zorya Master] Worker registrado: %s (%s) @ %s\n", worker.NodeID, worker.NodeName, worker.TailscaleIP)
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":  "registered",
		"node_id": worker.NodeID,
	})
}

func (d *Daemon) handleHeartbeat(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var hb struct {
		NodeID          string  `json:"node_id"`
		TemperatureC    float64 `json:"temperature_c"`
		CPUUsagePercent float64 `json:"cpu_usage_percent"`
		GPUUsagePercent float64 `json:"gpu_usage_percent"`
		Status          string  `json:"status"`
	}

	if err := json.NewDecoder(r.Body).Decode(&hb); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Invalid heartbeat payload"})
		return
	}

	d.mu.Lock()
	if wkr, ok := d.workers[hb.NodeID]; ok {
		wkr.LastHeartbeat = time.Now()
		wkr.TemperatureC = hb.TemperatureC
		wkr.CPUUsagePercent = hb.CPUUsagePercent
		wkr.GPUUsagePercent = hb.GPUUsagePercent
		if hb.Status != "" {
			wkr.Status = NodeStatus(hb.Status)
		}
	}
	d.mu.Unlock()

	writeJSON(w, http.StatusOK, map[string]string{"status": "acknowledged"})
}

func (d *Daemon) handleTaskPull(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req struct {
		WorkerID string `json:"worker_id"`
	}
	_ = json.NewDecoder(r.Body).Decode(&req)

	d.mu.Lock()
	defer d.mu.Unlock()

	if len(d.taskQueue) == 0 {
		writeJSON(w, http.StatusNoContent, nil)
		return
	}

	taskID := d.taskQueue[0]
	d.taskQueue = d.taskQueue[1:]

	task, exists := d.tasks[taskID]
	if !exists {
		writeJSON(w, http.StatusNoContent, nil)
		return
	}

	task.Status = TaskDispatched
	task.AssignedWorkerID = req.WorkerID
	task.DispatchedAt = time.Now()

	writeJSON(w, http.StatusOK, task)
}

func (d *Daemon) handleTaskComplete(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var res struct {
		TaskID      string `json:"task_id"`
		WorkerID    string `json:"worker_id"`
		Success     bool   `json:"success"`
		ResultData  string `json:"result_data"`
		ElapsedMs   int64  `json:"elapsed_ms"`
		ErrorReason string `json:"error_reason,omitempty"`
	}

	if err := json.NewDecoder(r.Body).Decode(&res); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Invalid completion payload"})
		return
	}

	d.mu.Lock()
	if task, ok := d.tasks[res.TaskID]; ok {
		task.CompletedAt = time.Now()
		task.ElapsedMs = res.ElapsedMs
		task.ProcessedBy = res.WorkerID
		if res.Success {
			task.Status = TaskCompleted
			task.ResultData = res.ResultData
			d.doneTasks = append(d.doneTasks, *task)
		} else {
			task.Status = TaskFailed
		}
	}
	d.mu.Unlock()

	writeJSON(w, http.StatusOK, map[string]string{"status": "recorded"})
}

func (d *Daemon) handleClusterStatus(w http.ResponseWriter, r *http.Request) {
	d.mu.RLock()
	defer d.mu.RUnlock()

	workersList := make([]NodeTelemetry, 0, len(d.workers))
	activeWorkers := 0
	for _, wkr := range d.workers {
		workersList = append(workersList, *wkr)
		if time.Since(wkr.LastHeartbeat) < 30*time.Second {
			activeWorkers++
		}
	}

	tsIP, tsConn := hardware.DetectTailscaleIP()

	status := ClusterStatus{
		Role:               d.cfg.Node.Role,
		MasterAddr:         d.cfg.Node.MasterAddr,
		TailscaleConnected: tsConn,
		TotalWorkers:       len(d.workers),
		ActiveWorkers:      activeWorkers,
		QueuePending:       len(d.taskQueue),
		QueueCompleted:     len(d.doneTasks),
		Workers:            workersList,
		RecentTasks:        d.doneTasks,
		LocalNode: NodeTelemetry{
			NodeID:      d.cfg.Node.ID,
			NodeName:    d.cfg.Node.Name,
			TailscaleIP: tsIP,
			Port:        d.cfg.Server.Port,
			Role:        d.cfg.Node.Role,
			Status:      StatusIdle,
		},
	}

	writeJSON(w, http.StatusOK, status)
}

func (d *Daemon) handleBatchSubmit(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var batchReq struct {
		BatchID string     `json:"batch_id"`
		Tasks   []GridTask `json:"tasks"`
	}

	if err := json.NewDecoder(r.Body).Decode(&batchReq); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Invalid batch payload"})
		return
	}

	d.mu.Lock()
	for i := range batchReq.Tasks {
		t := batchReq.Tasks[i]
		if t.TaskID == "" {
			t.TaskID = "task-" + network.RandomHex(6)
		}
		t.Status = TaskPending
		t.CreatedAt = time.Now()
		d.tasks[t.TaskID] = &t
		d.taskQueue = append(d.taskQueue, t.TaskID)
	}
	d.mu.Unlock()

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":   "queued",
		"batch_id": batchReq.BatchID,
		"count":    len(batchReq.Tasks),
	})
}

func (d *Daemon) handleConfig(w http.ResponseWriter, r *http.Request) {
	d.mu.RLock()
	defer d.mu.RUnlock()
	writeJSON(w, http.StatusOK, d.cfg)
}

// Background loops for Master / Worker nodes
func (d *Daemon) thermalGuardianLoop() {
	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-d.ctx.Done():
			return
		case <-ticker.C:
			// Prune inactive workers or requeue timed out tasks
		}
	}
}

func (d *Daemon) workerExecutionLoop() {
	ticker := time.NewTicker(3 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-d.ctx.Done():
			return
		case <-ticker.C:
			// Worker polling master if configured
		}
	}
}

// HTTP helpers
func enableCORS(w http.ResponseWriter) {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS, PUT, DELETE")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With")
	w.Header().Set("Access-Control-Expose-Headers", "Content-Type, X-Processing-Time-Ms, X-Accelerator-Used, X-Image-Width, X-Image-Height")
}

func writeJSON(w http.ResponseWriter, code int, payload interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(code)
	if payload != nil {
		_ = json.NewEncoder(w).Encode(payload)
	}
}

// GET /api/v1/system/browse-folder
func (d *Daemon) handleBrowseFolder(w http.ResponseWriter, r *http.Request) {
	type browseResult struct {
		Selected bool   `json:"selected"`
		Path     string `json:"path,omitempty"`
		Error    string `json:"error,omitempty"`
	}

	if runtime.GOOS == "windows" {
		psScript := `Add-Type -AssemblyName System.Windows.Forms; $f = New-Object System.Windows.Forms.FolderBrowserDialog; $f.Description = 'Selecione a pasta com os arquivos RAW do Ensaio'; if ($f.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) { Write-Output $f.SelectedPath }`
		cmd := exec.Command("powershell", "-NoProfile", "-Command", psScript)
		cmd.SysProcAttr = &syscall.SysProcAttr{
			HideWindow:    true,
			CreationFlags: 0x08000000, // CREATE_NO_WINDOW
		}
		out, err := cmd.Output()
		if err != nil {
			writeJSON(w, http.StatusOK, browseResult{Selected: false, Error: err.Error()})
			return
		}
		selectedPath := strings.TrimSpace(string(out))
		if selectedPath != "" {
			writeJSON(w, http.StatusOK, browseResult{Selected: true, Path: selectedPath})
			return
		}
		writeJSON(w, http.StatusOK, browseResult{Selected: false})
		return
	}

	writeJSON(w, http.StatusOK, browseResult{Selected: false, Error: "Seletor nativo disponível apenas no Windows"})
}

// GET /api/v1/hardware/cuda-status
func (d *Daemon) handleCUDAStatus(w http.ResponseWriter, r *http.Request) {
	if d.cuda == nil {
		writeJSON(w, http.StatusOK, map[string]interface{}{"cuda_ready": false, "mode": "CPU_FALLBACK"})
		return
	}
	writeJSON(w, http.StatusOK, d.cuda.GetStatus())
}

// POST /api/v1/photo/ai-curate
func (d *Daemon) handlePhotoAICurate(w http.ResponseWriter, r *http.Request) {
	d.SetTrayActive(true)
	defer d.SetTrayActive(false)

	var req processor.AICurationRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "JSON inválido: " + err.Error()})
		return
	}

	geminiKey := req.GeminiKey
	if geminiKey == "" {
		geminiKey = d.cfg.AI.GeminiAPIKey
	}
	groqKey := req.GroqKey
	if groqKey == "" {
		groqKey = d.cfg.AI.GroqAPIKey
	}

	imgData, err := os.ReadFile(req.ImagePath)
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "Falha ao abrir imagem: " + err.Error()})
		return
	}

	culling := processor.CullingAnalysis{OverallScore: 78.0, MeanLuminance: 128.0, SharpnessScore: 82.0}
	if img, _, err := image.Decode(bytes.NewReader(imgData)); err == nil {
		culling = processor.AnalyzeCulling(img)
	}

	curation, err := processor.CurateImage(imgData, req.ImagePath, culling, geminiKey, groqKey, d.cfg.Server.Port)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, curation)
}

// POST /api/v1/audio/enhance
func (d *Daemon) handleAudioEnhance(w http.ResponseWriter, r *http.Request) {
	d.SetTrayActive(true)
	defer d.SetTrayActive(false)

	var req processor.AudioEnhanceRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "JSON inválido: " + err.Error()})
		return
	}

	res, err := processor.EnhanceAudio(req)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, res)
}

// POST /api/v1/audio/detect-silence
func (d *Daemon) handleAudioDetectSilence(w http.ResponseWriter, r *http.Request) {
	d.SetTrayActive(true)
	defer d.SetTrayActive(false)

	var req struct {
		InputPath          string  `json:"input_path"`
		NoiseThresholdDB   float64 `json:"noise_threshold_db"`
		MinDurationSeconds float64 `json:"min_duration_seconds"`
	}
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "JSON inválido: " + err.Error()})
		return
	}

	res, err := processor.DetectSilence(req.InputPath, req.NoiseThresholdDB, req.MinDurationSeconds)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, res)
}

// POST /api/v1/video/smart-crop
func (d *Daemon) handleVideoSmartCrop(w http.ResponseWriter, r *http.Request) {
	d.SetTrayActive(true)
	defer d.SetTrayActive(false)

	var req processor.VideoSmartCropRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "JSON inválido: " + err.Error()})
		return
	}

	res, err := processor.SmartCropVideo(req)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, res)
}

// POST /api/v1/video/apply-lut
func (d *Daemon) handleVideoApplyLUT(w http.ResponseWriter, r *http.Request) {
	d.SetTrayActive(true)
	defer d.SetTrayActive(false)

	var req processor.VideoApplyLUTRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "JSON inválido: " + err.Error()})
		return
	}

	res, err := processor.ApplyLUT(req)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, res)
}
