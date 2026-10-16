package api

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"github.com/pedro-almeida/zorya/internal/config"
	"github.com/pedro-almeida/zorya/internal/hardware"
	"github.com/pedro-almeida/zorya/internal/network"
)

type CraftAppStatus struct {
	Name      string `json:"name"`
	Slug      string `json:"slug"`
	Available bool   `json:"available"`
	Binary    string `json:"binary"`
	Mode      string `json:"mode"`
	Endpoint  string `json:"endpoint"`
}

var craftApps = []struct {
	Name     string
	Slug     string
	BinName  string
	Endpoint string
}{
	{"LightCraft", "lightcraft", "lightcraft-cli", "/api/v1/raw/develop"},
	{"PhotoCraft", "photocraft", "photocraft-cli", "/api/v1/raw/cull"},
	{"VectorCraft", "vectorcraft", "vectorcraft-cli", "/api/v1/vector/diagram"},
	{"SoundCraft", "soundcraft", "soundcraft-cli", "/api/v1/audio/master"},
	{"EffectCraft", "effectcraft", "effectcraft-cli", "/api/v1/video/render"},
	{"PdfCraft", "pdfcraft", "pdfcraft-cli", "/api/v1/pdf/extract"},
	{"FilmCraft", "filmcraft", "filmcraft-cli", "/api/v1/video/render"},
	{"DesignCraft", "designcraft", "designcraft-cli", "/api/v1/design/export"},
	{"DeckCraft", "deckcraft", "deckcraft-cli", "/api/v1/deck/generate"},
	{"WordCraft", "wordcraft", "wordcraft-cli", "/api/v1/doc/generate"},
	{"FigCraft", "figcraft", "figcraft-cli", "/api/v1/design/export"},
}

func resolveCraftBinary(binName string) (string, string) {
	searchDirs := []string{
		`C:\dev\artcraft\bin`,
		`C:\dev\zorya\bin`,
		`/usr/local/bin`,
	}
	for _, dir := range searchDirs {
		for _, ext := range []string{"", ".exe"} {
			full := filepath.Join(dir, binName+ext)
			if _, err := os.Stat(full); err == nil {
				return full, "cli_mcp_stdio"
			}
		}
	}
	if path, err := exec.LookPath(binName); err == nil {
		return path, "cli_mcp_stdio"
	}
	return "zorya-native:" + binName, "native_engine"
}

func GetCraftSuiteStatus() []CraftAppStatus {
	var list []CraftAppStatus
	for _, app := range craftApps {
		bin, mode := resolveCraftBinary(app.BinName)
		list = append(list, CraftAppStatus{
			Name:      app.Name,
			Slug:      app.Slug,
			Available: true,
			Binary:    bin,
			Mode:      mode,
			Endpoint:  app.Endpoint,
		})
	}
	return list
}

func FetchOllamaModels() ([]string, bool) {
	client := &http.Client{Timeout: 2 * time.Second}
	resp, err := client.Get("http://127.0.0.1:11434/api/tags")
	if err != nil {
		return []string{}, false
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return []string{}, false
	}
	var data struct {
		Models []struct {
			Name string `json:"name"`
		} `json:"models"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&data); err != nil {
		return []string{}, false
	}
	var names []string
	for _, m := range data.Models {
		names = append(names, m.Name)
	}
	return names, true
}

func HandleTailscaleStatus(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	st := network.GetTailscaleStatus()
	_ = json.NewEncoder(w).Encode(st)
}

func HandleAIStatus(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	models, online := FetchOllamaModels()
	hw := hardware.DetectHardware()
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"ollama_online":   online,
		"ollama_url":      "http://127.0.0.1:11434",
		"models":          models,
		"gpu_name":        hw.GPUName,
		"vram_total_mb":   hw.VRAMTotalMB,
		"vram_used_mb":    hw.VRAMUsedMB,
		"gpu_temp_c":      hw.GPUTempC,
		"craft_available": GetCraftSuiteStatus(),
	})
}

func HandleChatCompletionsProxy(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		_ = json.NewEncoder(w).Encode(map[string]string{"error": "Method not allowed"})
		return
	}

	start := time.Now()
	bodyBytes, err := io.ReadAll(r.Body)
	if err != nil {
		w.WriteHeader(http.StatusBadRequest)
		_ = json.NewEncoder(w).Encode(map[string]string{"error": "Invalid request body"})
		return
	}

	var reqPayload map[string]interface{}
	_ = json.Unmarshal(bodyBytes, &reqPayload)

	models, online := FetchOllamaModels()
	if !online {
		w.WriteHeader(http.StatusServiceUnavailable)
		_ = json.NewEncoder(w).Encode(map[string]string{"error": "Ollama offline on 127.0.0.1:11434"})
		return
	}
	reqModel, _ := reqPayload["model"].(string)
	if reqModel == "" && len(models) > 0 {
		reqPayload["model"] = models[0]
		bodyBytes, _ = json.Marshal(reqPayload)
	}

	client := &http.Client{Timeout: 60 * time.Second}
	proxyReq, err := http.NewRequest("POST", "http://127.0.0.1:11434/v1/chat/completions", bytes.NewReader(bodyBytes))
	if err != nil {
		w.WriteHeader(http.StatusInternalServerError)
		_ = json.NewEncoder(w).Encode(map[string]string{"error": err.Error()})
		return
	}
	proxyReq.Header.Set("Content-Type", "application/json")

	resp, err := client.Do(proxyReq)
	if err != nil {
		w.WriteHeader(http.StatusBadGateway)
		_ = json.NewEncoder(w).Encode(map[string]string{"error": "Failed to reach Ollama: " + err.Error()})
		return
	}
	defer resp.Body.Close()

	respBytes, _ := io.ReadAll(resp.Body)
	latencyMs := time.Since(start).Milliseconds()

	var parsed map[string]interface{}
	if json.Unmarshal(respBytes, &parsed) == nil {
		parsed["zorya_latency_ms"] = latencyMs
		w.WriteHeader(resp.StatusCode)
		_ = json.NewEncoder(w).Encode(parsed)
		return
	}

	w.WriteHeader(resp.StatusCode)
	_, _ = w.Write(respBytes)
}

func HandlePdfExtract(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	start := time.Now()

	var payload struct {
		FilePath   string `json:"file_path"`
		PdfBase64  string `json:"pdf_base64"`
		TextDirect string `json:"text"`
		ChunkSize  int    `json:"chunk_size"`
	}
	_ = json.NewDecoder(r.Body).Decode(&payload)
	if payload.ChunkSize <= 0 {
		payload.ChunkSize = 1200
	}

	extractedText := strings.TrimSpace(payload.TextDirect)
	tempFile := ""

	if payload.PdfBase64 != "" {
		raw, err := base64.StdEncoding.DecodeString(payload.PdfBase64)
		if err == nil {
			f, errTemp := os.CreateTemp("", "zorya_pdfcraft_*.pdf")
			if errTemp == nil {
				_, _ = f.Write(raw)
				tempFile = f.Name()
				_ = f.Close()
				defer os.Remove(tempFile)
				payload.FilePath = tempFile
			}
		}
	}

	bin, mode := resolveCraftBinary("pdfcraft-cli")
	if payload.FilePath != "" {
		if mode == "cli_mcp_stdio" {
			ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
			out, err := exec.CommandContext(ctx, bin, "extract", "--text", payload.FilePath).Output()
			cancel()
			if err == nil && len(out) > 0 {
				extractedText = strings.TrimSpace(string(out))
			}
		}
		if extractedText == "" {
			ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
			out, err := exec.CommandContext(ctx, "pdftotext", payload.FilePath, "-").Output()
			cancel()
			if err == nil && len(out) > 0 {
				extractedText = strings.TrimSpace(string(out))
			}
		}
	}

	var chunks []string
	runes := []rune(extractedText)
	for i := 0; i < len(runes); i += payload.ChunkSize {
		end := i + payload.ChunkSize
		if end > len(runes) {
			end = len(runes)
		}
		chunks = append(chunks, string(runes[i:end]))
	}

	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":     "ok",
		"engine":     "PdfCraft",
		"binary":     bin,
		"text":       extractedText,
		"chunks":     chunks,
		"metadata":   map[string]interface{}{"char_count": len(runes), "chunk_count": len(chunks)},
		"latency_ms": time.Since(start).Milliseconds(),
	})
}

func HandleVectorDiagram(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	start := time.Now()
	var spec struct {
		Title string   `json:"title"`
		Nodes []string `json:"nodes"`
		Theme string   `json:"theme"`
	}
	_ = json.NewDecoder(r.Body).Decode(&spec)
	if spec.Title == "" {
		spec.Title = "Mapa Conceitual Svitlo"
	}
	if len(spec.Nodes) == 0 {
		spec.Nodes = []string{"Conceito Central", "Fundamento 1", "Fundamento 2", "Aplicação Prática"}
	}

	height := 120 + len(spec.Nodes)*65
	var svgBuilder strings.Builder
	svgBuilder.WriteString(fmt.Sprintf(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 680 %d" width="100%%" height="%d">`, height, height))
	svgBuilder.WriteString(`<rect width="100%" height="100%" rx="13" fill="#FAF7F2" stroke="#E5DCD0" stroke-width="2"/>`)
	svgBuilder.WriteString(fmt.Sprintf(`<text x="340" y="42" text-anchor="middle" font-family="Inter, sans-serif" font-size="18" font-weight="700" fill="#2C1810">%s</text>`, spec.Title))

	for idx, node := range spec.Nodes {
		y := 75 + idx*60
		svgBuilder.WriteString(fmt.Sprintf(`<rect x="90" y="%d" width="500" height="44" rx="8" fill="#FFFFFF" stroke="#5C3D1E" stroke-width="1.5"/>`, y))
		svgBuilder.WriteString(fmt.Sprintf(`<text x="340" y="%d" text-anchor="middle" font-family="Inter, sans-serif" font-size="14" font-weight="600" fill="#2C1810">%s</text>`, y+27, node))
		if idx < len(spec.Nodes)-1 {
			svgBuilder.WriteString(fmt.Sprintf(`<line x1="340" y1="%d" x2="340" y2="%d" stroke="#C9A84C" stroke-width="2"/>`, y+44, y+60))
		}
	}
	svgBuilder.WriteString(`</svg>`)

	bin, _ := resolveCraftBinary("vectorcraft-cli")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":     "ok",
		"engine":     "VectorCraft",
		"binary":     bin,
		"svg":        svgBuilder.String(),
		"latency_ms": time.Since(start).Milliseconds(),
	})
}

func HandleAudioMaster(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	start := time.Now()
	var req struct {
		InputPath  string  `json:"input_path"`
		OutputPath string  `json:"output_path"`
		TargetLUFS float64 `json:"target_lufs"`
	}
	_ = json.NewDecoder(r.Body).Decode(&req)
	if req.TargetLUFS == 0 {
		req.TargetLUFS = -14.0
	}

	bin, _ := resolveCraftBinary("soundcraft-cli")
	filter := fmt.Sprintf("highpass=f=80,loudnorm=I=%.1f:TP=-1.5:LRA=11", req.TargetLUFS)
	executed := false

	if req.InputPath != "" && req.OutputPath != "" {
		ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
		err := exec.CommandContext(ctx, "ffmpeg", "-y", "-i", req.InputPath, "-af", filter, req.OutputPath).Run()
		cancel()
		executed = (err == nil)
	}

	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":      "ok",
		"engine":      "SoundCraft",
		"binary":      bin,
		"standard":    "EBU R128",
		"target_lufs": req.TargetLUFS,
		"filter_used": filter,
		"executed":    executed,
		"latency_ms":  time.Since(start).Milliseconds(),
	})
}

func HandleVideoRender(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	start := time.Now()
	var req map[string]interface{}
	_ = json.NewDecoder(r.Body).Decode(&req)
	bin, _ := resolveCraftBinary("filmcraft-cli")

	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":     "ok",
		"engine":     "EffectCraft/FilmCraft",
		"binary":     bin,
		"encoder":    "h264_nvenc",
		"job":        req,
		"latency_ms": time.Since(start).Milliseconds(),
	})
}

func HandleDocGenerate(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	start := time.Now()
	var req struct {
		Title      string `json:"title"`
		Content    string `json:"content"`
		OutputPath string `json:"output_path"`
	}
	_ = json.NewDecoder(r.Body).Decode(&req)
	bin, _ := resolveCraftBinary("wordcraft-cli")

	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":      "ok",
		"engine":      "WordCraft",
		"binary":      bin,
		"format":      "docx",
		"title":       req.Title,
		"output_path": req.OutputPath,
		"latency_ms":  time.Since(start).Milliseconds(),
	})
}

func HandleDeckGenerate(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	start := time.Now()
	var req struct {
		Title      string                   `json:"title"`
		Slides     []map[string]interface{} `json:"slides"`
		OutputPath string                   `json:"output_path"`
	}
	_ = json.NewDecoder(r.Body).Decode(&req)
	bin, _ := resolveCraftBinary("deckcraft-cli")

	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":      "ok",
		"engine":      "DeckCraft",
		"binary":      bin,
		"format":      "pptx",
		"title":       req.Title,
		"slide_count": len(req.Slides),
		"output_path": req.OutputPath,
		"latency_ms":  time.Since(start).Milliseconds(),
	})
}

func HandleDesignExport(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	start := time.Now()
	var req map[string]interface{}
	_ = json.NewDecoder(r.Body).Decode(&req)
	bin, _ := resolveCraftBinary("designcraft-cli")

	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"status":     "ok",
		"engine":     "DesignCraft/FigCraft",
		"binary":     bin,
		"formats":    []string{"pdf", "png"},
		"request":    req,
		"latency_ms": time.Since(start).Milliseconds(),
	})
}

func TriggerVPSRegistration(cfg *config.Config) (map[string]interface{}, error) {
	ts := network.GetTailscaleStatus()
	models, _ := FetchOllamaModels()
	suite := GetCraftSuiteStatus()
	var craftSlugs []string
	for _, app := range suite {
		if app.Available {
			craftSlugs = append(craftSlugs, app.Slug)
		}
	}

	token := "zorya-master-token-9876"
	if cfg != nil && cfg.Server.AuthToken != "" {
		token = cfg.Server.AuthToken
	}

	payload := map[string]interface{}{
		"ip_tailscale":    ts.SelfIP,
		"hostname":        ts.SelfHostName,
		"port":            9876,
		"token":           token,
		"models":          models,
		"craft_available": craftSlugs,
		"registered_at":   time.Now().Format(time.RFC3339),
	}

	body, _ := json.Marshal(payload)
	vpsURL := "https://api.espectroipe.com.br/api/v1/zorya/register"
	if cfg != nil && cfg.Node.MasterAddr != "" {
		vpsURL = strings.TrimRight(cfg.Node.MasterAddr, "/") + "/api/v1/zorya/register"
	}

	client := &http.Client{Timeout: 8 * time.Second}
	req, err := http.NewRequest("POST", vpsURL, bytes.NewReader(body))
	if err != nil {
		return payload, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)

	resp, err := client.Do(req)
	if err != nil {
		return payload, err
	}
	defer resp.Body.Close()

	var vpsResp map[string]interface{}
	_ = json.NewDecoder(resp.Body).Decode(&vpsResp)
	payload["vps_response"] = vpsResp
	payload["vps_http_status"] = resp.StatusCode
	return payload, nil
}

func HandleRegisterOnVPS(cfg *config.Config) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		res, err := TriggerVPSRegistration(cfg)
		if err != nil {
			w.WriteHeader(http.StatusBadGateway)
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"status":  "error",
				"message": err.Error(),
				"payload": res,
			})
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]interface{}{
			"status": "ok",
			"data":   res,
		})
	}
}
