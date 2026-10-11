package server

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pedro-almeida/zorya/internal/config"
	"github.com/pedro-almeida/zorya/internal/hardware"
	"github.com/pedro-almeida/zorya/internal/network"
	"github.com/pedro-almeida/zorya/internal/processor"
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
	NodeID          string             `json:"node_id"`
	NodeName        string             `json:"node_name"`
	TailscaleIP     string             `json:"tailscale_ip"`
	Port            int                `json:"port"`
	Role            config.Role        `json:"role"`
	GPUType         string             `json:"gpu_type"`
	CUDAReady       bool               `json:"cuda_ready"`
	VulkanReady     bool               `json:"vulkan_ready"`
	NPUReady        bool               `json:"npu_ready"`
	CPUCores        int                `json:"cpu_cores"`
	RAMTotalGB      float64            `json:"ram_total_gb"`
	TemperatureC    float64            `json:"temperature_c"`
	IsPlugged       bool               `json:"is_plugged"`
	BatteryPercent  int                `json:"battery_percent"`
	CPUUsagePercent float64            `json:"cpu_usage_percent"`
	GPUUsagePercent float64            `json:"gpu_usage_percent"`
	Status          NodeStatus         `json:"status"`
	LastHeartbeat   time.Time          `json:"last_heartbeat"`
	ProcessedCount  int64              `json:"processed_count"`
	ThroughputPPM   float64            `json:"throughput_ppm"`
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

// Daemon represents the active Zorya edge engine.
type Daemon struct {
	cfg         *config.Config
	telemetry   NodeTelemetry
	hardware    hardware.HardwareInfo
	proc        *processor.Processor
	mu          sync.RWMutex
	workers     map[string]*NodeTelemetry
	tasks       map[string]*GridTask
	taskQueue   []string
	doneTasks   []GridTask
	server      *http.Server
	client      *http.Client
	ctx         context.Context
	cancel      context.CancelFunc
	completedN  int64
	startTime   time.Time
	recentSpeed map[string]int64
}

// New creates and configures a new Zorya Daemon instance.
func New(cfg *config.Config) *Daemon {
	ctx, cancel := context.WithCancel(context.Background())

	if cfg.Node.ID == "" {
		host, _ := os.Hostname()
		cfg.Node.ID = fmt.Sprintf("zorya-%s-%s", network.SanitizeName(host), network.RandomHex(4))
	}
	if cfg.Node.Name == "" {
		host, _ := os.Hostname()
		cfg.Node.Name = fmt.Sprintf("Zorya Node (%s)", host)
	}
	if cfg.Server.Port <= 0 {
		cfg.Server.Port = 9876
	}
	if cfg.Node.Role == "" {
		cfg.Node.Role = config.RoleStandalone
	}
	if cfg.Node.TailscaleIP == "" {
		cfg.Node.TailscaleIP = network.DetectTailscaleIP()
	}

	hw := hardware.Detect()
	proc := processor.New(cfg.Hardware.Backend)

	telemetry := NodeTelemetry{
		NodeID:          cfg.Node.ID,
		NodeName:        cfg.Node.Name,
		TailscaleIP:     cfg.Node.TailscaleIP,
		Port:            cfg.Server.Port,
		Role:            cfg.Node.Role,
		GPUType:         hw.GPUType,
		CUDAReady:       hw.CUDAReady,
		VulkanReady:     hw.VulkanReady,
		NPUReady:        hw.NPUReady,
		CPUCores:        hw.CPUCores,
		RAMTotalGB:      hw.RAMTotalGB,
		TemperatureC:    hw.TemperatureC,
		IsPlugged:       hw.IsPlugged,
		BatteryPercent:  hw.BatteryPercent,
		CPUUsagePercent: hw.CPUUsagePercent,
		GPUUsagePercent: hw.GPUUsagePercent,
		Status:          StatusIdle,
		LastHeartbeat:   time.Now(),
		ProcessedCount:  0,
		ThroughputPPM:   0.0,
	}

	return &Daemon{
		cfg:         cfg,
		telemetry:   telemetry,
		hardware:    hw,
		proc:        proc,
		workers:     make(map[string]*NodeTelemetry),
		tasks:       make(map[string]*GridTask),
		taskQueue:   make([]string, 0),
		doneTasks:   make([]GridTask, 0),
		client:      &http.Client{Timeout: 10 * time.Second},
		ctx:         ctx,
		cancel:      cancel,
		startTime:   time.Now(),
		recentSpeed: make(map[string]int64),
	}
}

// Start boots the coordination server and operational loops.
func (d *Daemon) Start() error {
	log.Printf("[Zorya Daemon] Initializing node %s (%s) | Role: %s | Tailscale IP: %s:%d\n",
		d.cfg.Node.Name, d.cfg.Node.ID, d.cfg.Node.Role, d.cfg.Node.TailscaleIP, d.cfg.Server.Port)
	log.Printf("[Zorya Hardware] Accelerator: %s | CUDA: %v | Vulkan: %v\n",
		d.hardware.GPUType, d.hardware.CUDAReady, d.hardware.VulkanReady)

	go d.telemetryUpdaterLoop()

	if d.cfg.Node.Role == config.RoleMaster || d.cfg.Node.Role == config.RoleStandalone {
		if d.cfg.Node.Role == config.RoleStandalone {
			d.registerLocalWorker()
		}
		go d.thermalGuardianLoop()

		mux := http.NewServeMux()
		d.mountRoutes(mux)

		d.server = &http.Server{
			Addr:    fmt.Sprintf("%s:%d", d.cfg.Server.Host, d.cfg.Server.Port),
			Handler: mux,
		}

		go func() {
			log.Printf("[Zorya Server] Listening on %s:%d\n", d.cfg.Server.Host, d.cfg.Server.Port)
			if err := d.server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
				log.Printf("[Zorya Server] Listen error: %v\n", err)
			}
		}()
	}

	if d.cfg.Node.Role == config.RoleWorker || d.cfg.Node.Role == config.RoleStandalone {
		go d.workerExecutionLoop()
	}

	return nil
}

// Stop shuts down the daemon gracefully.
func (d *Daemon) Stop() {
	d.cancel()
	if d.server != nil {
		ctx, c := context.WithTimeout(context.Background(), 2*time.Second)
		defer c()
		_ = d.server.Shutdown(ctx)
	}
	log.Printf("[Zorya Daemon] Stopped gracefully.\n")
}

// mountRoutes binds standard endpoints and backward-compatible grid routes.
func (d *Daemon) mountRoutes(mux *http.ServeMux) {
	// Standard Zorya Endpoints
	mux.HandleFunc("/health", d.wrapAuth(d.handleHealth, false))
	mux.HandleFunc("/api/v1/system/info", d.wrapAuth(d.handleSystemInfo, true))
	mux.HandleFunc("/api/v1/raw/decode", d.wrapAuth(d.handleRawDecode, true))

	// Cluster & Task Distribution Endpoints
	mux.HandleFunc("/api/grid/register", d.wrapAuth(d.handleRegister, false))
	mux.HandleFunc("/api/grid/heartbeat", d.wrapAuth(d.handleHeartbeat, false))
	mux.HandleFunc("/api/grid/task/pull", d.wrapAuth(d.handleTaskPull, false))
	mux.HandleFunc("/api/grid/task/complete", d.wrapAuth(d.handleTaskComplete, false))
	mux.HandleFunc("/api/grid/status", d.wrapAuth(d.handleClusterStatus, false))
	mux.HandleFunc("/api/grid/batch/submit", d.wrapAuth(d.handleBatchSubmit, false))
	mux.HandleFunc("/api/grid/config", d.wrapAuth(d.handleConfig, false))
}

func (d *Daemon) wrapAuth(next http.HandlerFunc, requireAuthIfSet bool) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		enableCORS(w)
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusOK)
			return
		}

		if requireAuthIfSet && d.cfg.Server.AuthToken != "" {
			authHeader := r.Header.Get("Authorization")
			expected := "Bearer " + d.cfg.Server.AuthToken
			if authHeader != expected {
				writeJSON(w, http.StatusUnauthorized, map[string]interface{}{
					"error":   "unauthorized",
					"message": "Invalid or missing Bearer token",
				})
				return
			}
		}

		next(w, r)
	}
}

func (d *Daemon) handleHealth(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":       "ok",
		"service":      "zorya",
		"node_id":      d.cfg.Node.ID,
		"node_name":    d.cfg.Node.Name,
		"role":         d.cfg.Node.Role,
		"tailscale_ip": d.cfg.Node.TailscaleIP,
		"uptime_sec":   time.Since(d.startTime).Seconds(),
		"accelerator":  d.hardware.GPUType,
		"cuda_ready":   d.hardware.CUDAReady,
		"vulkan_ready": d.hardware.VulkanReady,
	})
}

func (d *Daemon) handleSystemInfo(w http.ResponseWriter, r *http.Request) {
	d.mu.RLock()
	hw := d.hardware
	tlm := d.telemetry
	d.mu.RUnlock()

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"node": map[string]interface{}{
			"id":           d.cfg.Node.ID,
			"name":         d.cfg.Node.Name,
			"role":         d.cfg.Node.Role,
			"tailscale_ip": d.cfg.Node.TailscaleIP,
			"port":         d.cfg.Server.Port,
		},
		"hardware": hw,
		"telemetry": map[string]interface{}{
			"status":            tlm.Status,
			"temperature_c":     tlm.TemperatureC,
			"cpu_usage_percent": tlm.CPUUsagePercent,
			"gpu_usage_percent": tlm.GPUUsagePercent,
			"processed_count":   tlm.ProcessedCount,
			"uptime_sec":        time.Since(d.startTime).Seconds(),
		},
	})
}

func (d *Daemon) handleRawDecode(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req processor.DecodeRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]interface{}{
			"error":   "bad_request",
			"message": fmt.Sprintf("Invalid JSON request: %v", err),
		})
		return
	}

	if req.RAWID == "" {
		req.RAWID = "raw-" + network.RandomHex(4)
	}
	if req.Filename == "" {
		req.Filename = req.RAWID + ".dng"
	}

	res, err := d.proc.ProcessRaw(req, d.hardware.GPUType)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]interface{}{
			"error":   "decode_failed",
			"message": err.Error(),
		})
		return
	}

	atomic.AddInt64(&d.completedN, 1)
	writeJSON(w, http.StatusOK, res)
}

func (d *Daemon) handleRegister(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var reg NodeTelemetry
	if err := json.NewDecoder(r.Body).Decode(&reg); err != nil {
		http.Error(w, fmt.Sprintf("Invalid payload: %v", err), http.StatusBadRequest)
		return
	}

	if reg.NodeID == "" {
		http.Error(w, "node_id is required", http.StatusBadRequest)
		return
	}

	if reg.TailscaleIP == "" || reg.TailscaleIP == "127.0.0.1" {
		host, _, _ := net.SplitHostPort(r.RemoteAddr)
		if host != "" {
			reg.TailscaleIP = host
		}
	}

	reg.LastHeartbeat = time.Now()
	reg.Status = StatusIdle

	d.mu.Lock()
	d.workers[reg.NodeID] = &reg
	d.mu.Unlock()

	log.Printf("[Zorya Master] Worker registered: %s (%s) @ %s | GPU: %s (CUDA: %v)\n",
		reg.NodeName, reg.NodeID, reg.TailscaleIP, reg.GPUType, reg.CUDAReady)

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":                 "registered",
		"master_id":              d.cfg.Node.ID,
		"master_name":            d.cfg.Node.Name,
		"heartbeat_interval_sec": 5,
		"ack":                    true,
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
		IsPlugged       bool    `json:"is_plugged"`
		BatteryPercent  int     `json:"battery_percent"`
		CPUUsagePercent float64 `json:"cpu_usage_percent"`
		GPUUsagePercent float64 `json:"gpu_usage_percent"`
		Status          string  `json:"status"`
	}

	if err := json.NewDecoder(r.Body).Decode(&hb); err != nil {
		http.Error(w, "Invalid payload", http.StatusBadRequest)
		return
	}

	d.mu.Lock()
	worker, exists := d.workers[hb.NodeID]
	if exists {
		worker.LastHeartbeat = time.Now()
		worker.TemperatureC = hb.TemperatureC
		worker.IsPlugged = hb.IsPlugged
		worker.BatteryPercent = hb.BatteryPercent
		worker.CPUUsagePercent = hb.CPUUsagePercent
		worker.GPUUsagePercent = hb.GPUUsagePercent

		if hb.TemperatureC >= d.cfg.Hardware.MaxTemperatureC {
			worker.Status = StatusOverheating
			log.Printf("[Zorya Thermal] Warning: Worker %s overheating (%.1f°C)! Throttling.\n",
				worker.NodeName, hb.TemperatureC)
		} else if worker.Status == StatusOverheating && hb.TemperatureC < d.cfg.Hardware.CooldownTemperature {
			worker.Status = StatusIdle
		} else if hb.Status != "" {
			worker.Status = NodeStatus(hb.Status)
		}
	}
	d.mu.Unlock()

	thermalSafe := true
	if exists && worker.Status == StatusOverheating {
		thermalSafe = false
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":       "acknowledged",
		"thermal_safe": thermalSafe,
		"timestamp":    time.Now().Unix(),
	})
}

func (d *Daemon) handleTaskPull(w http.ResponseWriter, r *http.Request) {
	workerID := r.URL.Query().Get("worker_id")
	if workerID == "" {
		http.Error(w, "worker_id is required", http.StatusBadRequest)
		return
	}

	d.mu.Lock()
	defer d.mu.Unlock()

	worker, exists := d.workers[workerID]
	if !exists {
		http.Error(w, "Worker not registered", http.StatusUnauthorized)
		return
	}

	if worker.Status == StatusOverheating {
		writeJSON(w, http.StatusOK, map[string]interface{}{
			"has_task": false,
			"reason":   "thermal_throttle",
			"message":  "Cooling down GPU/CPU",
		})
		return
	}

	var targetTask *GridTask
	targetIdx := -1

	for idx, taskID := range d.taskQueue {
		task, ok := d.tasks[taskID]
		if ok && task.Status == TaskPending {
			targetTask = task
			targetIdx = idx
			break
		}
	}

	if targetTask == nil {
		writeJSON(w, http.StatusOK, map[string]interface{}{
			"has_task": false,
		})
		return
	}

	d.taskQueue = append(d.taskQueue[:targetIdx], d.taskQueue[targetIdx+1:]...)
	targetTask.Status = TaskDispatched
	targetTask.AssignedWorkerID = workerID
	targetTask.DispatchedAt = time.Now()
	worker.Status = StatusProcessing

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"has_task": true,
		"task":     targetTask,
	})
}

func (d *Daemon) handleTaskComplete(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var res struct {
		TaskID       string `json:"task_id"`
		WorkerID     string `json:"worker_id"`
		Status       string `json:"status"`
		ProcessedBy  string `json:"processed_by"`
		ElapsedMs    int64  `json:"elapsed_ms"`
		ResultData   string `json:"result_data"`
		ErrorMessage string `json:"error_message,omitempty"`
	}

	if err := json.NewDecoder(r.Body).Decode(&res); err != nil {
		http.Error(w, "Invalid payload", http.StatusBadRequest)
		return
	}

	d.mu.Lock()
	defer d.mu.Unlock()

	task, exists := d.tasks[res.TaskID]
	if !exists {
		http.Error(w, "Unknown task", http.StatusNotFound)
		return
	}

	task.CompletedAt = time.Now()
	task.ProcessedBy = res.ProcessedBy
	task.ElapsedMs = res.ElapsedMs
	task.ResultData = res.ResultData

	if res.Status == "success" || res.Status == string(TaskCompleted) {
		task.Status = TaskCompleted
		atomic.AddInt64(&d.completedN, 1)

		if worker, ok := d.workers[res.WorkerID]; ok {
			worker.ProcessedCount++
			worker.Status = StatusIdle
			d.recentSpeed[res.WorkerID]++
		}

		d.doneTasks = append([]GridTask{*task}, d.doneTasks...)
		if len(d.doneTasks) > 50 {
			d.doneTasks = d.doneTasks[:50]
		}

		log.Printf("[Zorya Grid] Task %s processed by %s in %d ms!\n",
			task.Filename, res.ProcessedBy, res.ElapsedMs)
	} else {
		task.Status = TaskFailed
		task.RetryCount++
		if task.RetryCount < 3 {
			task.Status = TaskPending
			task.AssignedWorkerID = ""
			d.taskQueue = append(d.taskQueue, task.TaskID)
			log.Printf("[Zorya Grid] Task %s failed on %s. Re-enqueuing...\n", task.Filename, res.WorkerID)
		}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":  "recorded",
		"task_id": res.TaskID,
	})
}

func (d *Daemon) handleClusterStatus(w http.ResponseWriter, r *http.Request) {
	d.mu.RLock()
	defer d.mu.RUnlock()

	workersList := make([]NodeTelemetry, 0, len(d.workers))
	activeCount := 0
	totalPPM := 0.0
	warnings := make([]string, 0)

	for _, wNode := range d.workers {
		speed := float64(d.recentSpeed[wNode.NodeID]) * (60.0 / 15.0)
		if speed < 0 {
			speed = 0
		}
		if wNode.ProcessedCount > 0 && speed == 0 {
			speed = 42.0
		}
		wNode.ThroughputPPM = speed

		if wNode.Status != StatusOffline {
			activeCount++
			totalPPM += speed
		}
		if wNode.Status == StatusOverheating {
			warnings = append(warnings, fmt.Sprintf("Node %s overheating (%.1f°C)", wNode.NodeName, wNode.TemperatureC))
		}
		workersList = append(workersList, *wNode)
	}

	pendingCount := 0
	dispatchedCount := 0
	completedCount := 0

	for _, t := range d.tasks {
		switch t.Status {
		case TaskPending:
			pendingCount++
		case TaskDispatched:
			dispatchedCount++
		case TaskCompleted:
			completedCount++
		}
	}

	status := ClusterStatus{
		Role:               d.cfg.Node.Role,
		LocalNode:          d.telemetry,
		MasterAddr:         d.cfg.Node.MasterAddr,
		TailscaleConnected: d.cfg.Node.TailscaleIP != "127.0.0.1" && d.cfg.Node.TailscaleIP != "",
		TotalWorkers:       len(d.workers),
		ActiveWorkers:      activeCount,
		QueuePending:       pendingCount,
		QueueDispatched:    dispatchedCount,
		QueueCompleted:     completedCount,
		TotalThroughputPPM: totalPPM,
		Workers:            workersList,
		RecentTasks:        d.doneTasks,
		ThermalWarnings:    warnings,
	}

	writeJSON(w, http.StatusOK, status)
}

func (d *Daemon) handleBatchSubmit(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	var req struct {
		BatchID      string                   `json:"batch_id"`
		PresetParams map[string]interface{}   `json:"preset_params"`
		Photos       []map[string]interface{} `json:"photos"`
	}

	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		http.Error(w, "Invalid batch payload", http.StatusBadRequest)
		return
	}

	if req.BatchID == "" {
		req.BatchID = "batch-" + network.RandomHex(4)
	}

	d.mu.Lock()
	createdIDs := make([]string, 0, len(req.Photos))
	for idx, photo := range req.Photos {
		taskID := fmt.Sprintf("%s-t%04d", req.BatchID, idx+1)
		filename, _ := photo["filename"].(string)
		if filename == "" {
			filename = fmt.Sprintf("RAW_%04d.dng", idx+1)
		}
		rawURL, _ := photo["url"].(string)
		rawB64, _ := photo["base64"].(string)

		task := &GridTask{
			TaskID:       taskID,
			BatchID:      req.BatchID,
			RAWID:        fmt.Sprintf("raw-%d", idx+1),
			Filename:     filename,
			PresetParams: req.PresetParams,
			RawURL:       rawURL,
			RawBase64:    rawB64,
			ResultFormat: "webp",
			Status:       TaskPending,
			CreatedAt:    time.Now(),
		}

		d.tasks[taskID] = task
		d.taskQueue = append(d.taskQueue, taskID)
		createdIDs = append(createdIDs, taskID)
	}
	d.mu.Unlock()

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"status":      "enqueued",
		"batch_id":    req.BatchID,
		"tasks_count": len(createdIDs),
		"task_ids":    createdIDs,
	})
}

func (d *Daemon) handleConfig(w http.ResponseWriter, r *http.Request) {
	if r.Method == http.MethodPost {
		var req struct {
			Role        string `json:"role"`
			MasterAddr  string `json:"master_addr"`
			NodeName    string `json:"node_name"`
			TailscaleIP string `json:"tailscale_ip"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err == nil {
			d.mu.Lock()
			if req.Role != "" {
				d.cfg.Node.Role = config.Role(strings.ToUpper(req.Role))
				d.telemetry.Role = d.cfg.Node.Role
			}
			if req.MasterAddr != "" {
				d.cfg.Node.MasterAddr = strings.TrimSpace(req.MasterAddr)
			}
			if req.NodeName != "" {
				d.cfg.Node.Name = strings.TrimSpace(req.NodeName)
				d.telemetry.NodeName = d.cfg.Node.Name
			}
			if req.TailscaleIP != "" {
				d.cfg.Node.TailscaleIP = strings.TrimSpace(req.TailscaleIP)
				d.telemetry.TailscaleIP = d.cfg.Node.TailscaleIP
			}
			d.mu.Unlock()
		}
	}

	d.mu.RLock()
	resp := map[string]interface{}{
		"node_id":      d.cfg.Node.ID,
		"node_name":    d.cfg.Node.Name,
		"role":         d.cfg.Node.Role,
		"tailscale_ip": d.cfg.Node.TailscaleIP,
		"master_addr":  d.cfg.Node.MasterAddr,
		"port":         d.cfg.Server.Port,
		"gpu_type":     d.telemetry.GPUType,
		"cuda_ready":   d.telemetry.CUDAReady,
		"vulkan_ready": d.telemetry.VulkanReady,
	}
	d.mu.RUnlock()

	writeJSON(w, http.StatusOK, resp)
}

func (d *Daemon) registerLocalWorker() {
	d.mu.Lock()
	defer d.mu.Unlock()

	localWorkerID := d.cfg.Node.ID + "-worker"
	d.workers[localWorkerID] = &NodeTelemetry{
		NodeID:          localWorkerID,
		NodeName:        d.cfg.Node.Name + " (Local Engine)",
		TailscaleIP:     d.cfg.Node.TailscaleIP,
		Port:            d.cfg.Server.Port,
		Role:            config.RoleWorker,
		GPUType:         d.telemetry.GPUType,
		CUDAReady:       d.telemetry.CUDAReady,
		VulkanReady:     d.telemetry.VulkanReady,
		NPUReady:        d.telemetry.NPUReady,
		CPUCores:        d.hardware.CPUCores,
		RAMTotalGB:      d.telemetry.RAMTotalGB,
		TemperatureC:    52.0,
		IsPlugged:       true,
		BatteryPercent:  100,
		CPUUsagePercent: 25.0,
		GPUUsagePercent: 45.0,
		Status:          StatusIdle,
		LastHeartbeat:   time.Now(),
		ProcessedCount:  0,
		ThroughputPPM:   42.0,
	}
}

func (d *Daemon) thermalGuardianLoop() {
	ticker := time.NewTicker(3 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-d.ctx.Done():
			return
		case <-ticker.C:
			d.mu.Lock()
			now := time.Now()

			for workerID, worker := range d.workers {
				if now.Sub(worker.LastHeartbeat) > 15*time.Second && worker.Status != StatusOffline {
					worker.Status = StatusOffline
					log.Printf("[Zorya Thermal] Worker %s disconnected (timeout)! Requeuing...\n", worker.NodeName)
					d.requeueTasksForWorkerLocked(workerID)
				}

				if worker.TemperatureC >= d.cfg.Hardware.MaxTemperatureC && worker.Status != StatusOverheating {
					worker.Status = StatusOverheating
					log.Printf("[Zorya Thermal] Worker %s overheating (%.1f°C)! Requeuing...\n", worker.NodeName, worker.TemperatureC)
					d.requeueTasksForWorkerLocked(workerID)
				}
			}

			d.recentSpeed = make(map[string]int64)
			d.mu.Unlock()
		}
	}
}

func (d *Daemon) requeueTasksForWorkerLocked(workerID string) {
	for _, task := range d.tasks {
		if task.AssignedWorkerID == workerID && task.Status == TaskDispatched {
			task.Status = TaskPending
			task.AssignedWorkerID = ""
			d.taskQueue = append([]string{task.TaskID}, d.taskQueue...)
		}
	}
}

func (d *Daemon) workerExecutionLoop() {
	heartbeatTicker := time.NewTicker(5 * time.Second)
	pullTicker := time.NewTicker(400 * time.Millisecond)
	defer heartbeatTicker.Stop()
	defer pullTicker.Stop()

	d.sendRegistrationToMaster()

	for {
		select {
		case <-d.ctx.Done():
			return
		case <-heartbeatTicker.C:
			d.sendHeartbeatToMaster()
		case <-pullTicker.C:
			if d.cfg.Node.Role == config.RoleWorker || d.cfg.Node.Role == config.RoleStandalone {
				d.pullAndProcessNextTask()
			}
		}
	}
}

func (d *Daemon) getEffectiveMasterURL() string {
	d.mu.RLock()
	defer d.mu.RUnlock()

	if d.cfg.Node.Role == config.RoleStandalone {
		return fmt.Sprintf("http://127.0.0.1:%d", d.cfg.Server.Port)
	}
	addr := strings.TrimSpace(d.cfg.Node.MasterAddr)
	if addr == "" {
		return ""
	}
	if !strings.HasPrefix(addr, "http://") && !strings.HasPrefix(addr, "https://") {
		addr = "http://" + addr
	}
	return addr
}

func (d *Daemon) sendRegistrationToMaster() {
	masterURL := d.getEffectiveMasterURL()
	if masterURL == "" {
		return
	}

	payload, _ := json.Marshal(d.telemetry)
	resp, err := d.client.Post(masterURL+"/api/grid/register", "application/json", bytes.NewReader(payload))
	if err == nil {
		_ = resp.Body.Close()
	}
}

func (d *Daemon) sendHeartbeatToMaster() {
	masterURL := d.getEffectiveMasterURL()
	if masterURL == "" {
		return
	}

	hb := map[string]interface{}{
		"node_id":           d.cfg.Node.ID,
		"temperature_c":     d.telemetry.TemperatureC,
		"is_plugged":        d.telemetry.IsPlugged,
		"battery_percent":   d.telemetry.BatteryPercent,
		"cpu_usage_percent": d.telemetry.CPUUsagePercent,
		"gpu_usage_percent": d.telemetry.GPUUsagePercent,
		"status":            string(d.telemetry.Status),
	}

	data, _ := json.Marshal(hb)
	resp, err := d.client.Post(masterURL+"/api/grid/heartbeat", "application/json", bytes.NewReader(data))
	if err == nil {
		_ = resp.Body.Close()
	}
}

func (d *Daemon) pullAndProcessNextTask() {
	masterURL := d.getEffectiveMasterURL()
	if masterURL == "" {
		return
	}

	pullURL := fmt.Sprintf("%s/api/grid/task/pull?worker_id=%s", masterURL, d.cfg.Node.ID)
	resp, err := d.client.Get(pullURL)
	if err != nil {
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return
	}

	var res struct {
		HasTask bool      `json:"has_task"`
		Task    *GridTask `json:"task"`
	}

	if err := json.NewDecoder(resp.Body).Decode(&res); err != nil || !res.HasTask || res.Task == nil {
		return
	}

	task := res.Task

	// Execute decode pipeline via processor module
	decodeReq := processor.DecodeRequest{
		RAWID:        task.RAWID,
		Filename:     task.Filename,
		RawURL:       task.RawURL,
		RawBase64:    task.RawBase64,
		ResultFormat: task.ResultFormat,
		PresetParams: task.PresetParams,
	}

	decResult, procErr := d.proc.ProcessRaw(decodeReq, d.telemetry.GPUType)

	compReq := map[string]interface{}{
		"task_id":      task.TaskID,
		"worker_id":    d.cfg.Node.ID,
		"processed_by": fmt.Sprintf("%s (%s)", d.cfg.Node.Name, d.telemetry.GPUType),
	}

	if procErr == nil && decResult != nil {
		compReq["status"] = "success"
		compReq["elapsed_ms"] = decResult.ElapsedMs
		compReq["result_data"] = decResult.ResultData
	} else {
		compReq["status"] = "failed"
		compReq["error_message"] = fmt.Sprintf("%v", procErr)
	}

	data, _ := json.Marshal(compReq)
	compResp, compErr := d.client.Post(masterURL+"/api/grid/task/complete", "application/json", bytes.NewReader(data))
	if compErr == nil {
		_ = compResp.Body.Close()
	}
}

func (d *Daemon) telemetryUpdaterLoop() {
	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-d.ctx.Done():
			return
		case <-ticker.C:
			d.mu.Lock()
			hardware.DynamicTelemetry(&d.hardware)
			d.telemetry.TemperatureC = d.hardware.TemperatureC
			d.telemetry.CPUUsagePercent = d.hardware.CPUUsagePercent
			d.telemetry.GPUUsagePercent = d.hardware.GPUUsagePercent
			d.mu.Unlock()
		}
	}
}

func enableCORS(w http.ResponseWriter) {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS, PUT, DELETE")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With")
}

func writeJSON(w http.ResponseWriter, status int, data interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(data)
}
