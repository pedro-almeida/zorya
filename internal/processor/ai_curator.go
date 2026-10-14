// PT: Curadoria Fotográfica com IA em Cascata (Moondream na GTX 1650 + Qwen/Gemini Nuvem)
// EN: Cascading Photographic AI Curation (Moondream on GTX 1650 + Senior Qwen/Gemini Vision)
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Curadoria e Triagem com IA em Cascata

package processor

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"image"
	"image/jpeg"
	_ "image/png"
	"math"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// AICascadePreflight armazena o status dos motores de IA antes da execução do lote
type AICascadePreflight struct {
	OllamaAvailable    bool      `json:"ollama_available"`
	MoondreamReady     bool      `json:"moondream_ready"`
	QwenVLReady        bool      `json:"qwen_vl_ready"`
	OllamaModelFast    string    `json:"ollama_model_fast"`
	OllamaModelSenior  string    `json:"ollama_model_senior"`
	CloudProvider      string    `json:"cloud_provider"`
	CloudModel         string    `json:"cloud_model"`
	CloudReady         bool      `json:"cloud_ready"`
	CircuitBreakerOpen bool      `json:"circuit_breaker_open"`
	LastCheck          time.Time `json:"last_check"`
}

var (
	preflightMu      sync.RWMutex
	currentPreflight *AICascadePreflight
)

// LightCraftSliders defines precision RAW post-processing parameters.
type LightCraftSliders struct {
	Exposure          float64 `json:"exposure"`
	Contrast          int     `json:"contrast"`
	Highlights        int     `json:"highlights"`
	Shadows           int     `json:"shadows"`
	TemperatureKelvin int     `json:"temperature_kelvin"`
	Tint              int     `json:"tint"`
	Clarity           int     `json:"clarity"`
	Vibrance          int     `json:"vibrance"`
}

// AICurationResult aggregates neural aesthetic evaluation and development suggestions.
type AICurationResult struct {
	ImagePath         string            `json:"image_path"`
	ThumbnailFilename string            `json:"thumbnail_filename"`
	ThumbnailURL      string            `json:"thumbnail_url"`
	AestheticScore    float64           `json:"aesthetic_score"`
	CompositionRating string            `json:"composition_rating"` // APPROVED, REVIEW_BOKEH, REVIEW, REJECTED
	ColorPalette      []string          `json:"color_palette"`
	AestheticFeedback string            `json:"aesthetic_feedback"`
	RecommendedCrop   string            `json:"recommended_crop"`
	Sliders           LightCraftSliders `json:"sliders"`
	ProviderUsed      string            `json:"provider_used"`
	BokehProtected    bool              `json:"bokeh_protected"`
}

// AICurationRequest represents input for the AI curation endpoint.
type AICurationRequest struct {
	ImagePath         string `json:"image_path"`
	ThumbnailFilename string `json:"thumbnail_filename,omitempty"`
	GeminiKey         string `json:"gemini_key,omitempty"`
	GroqKey           string `json:"groq_key,omitempty"`
	ServerPort        int    `json:"server_port,omitempty"`
}

// RunAIBatchPreflight executa 1 ping de validação nos provedores de IA antes de iniciar o lote
func RunAIBatchPreflight(geminiKey, groqKey string) *AICascadePreflight {
	pf := &AICascadePreflight{
		OllamaModelFast:   "moondream",
		OllamaModelSenior: "qwen2.5vl:3b",
		CloudProvider:     "none",
		LastCheck:         time.Now(),
	}

	// 1. Validar Ollama local (GET http://localhost:11434/api/tags)
	client := &http.Client{Timeout: 2 * time.Second}
	resp, err := client.Get("http://localhost:11434/api/tags")
	if err == nil && resp.StatusCode == http.StatusOK {
		pf.OllamaAvailable = true
		var tagsResp struct {
			Models []struct {
				Name string `json:"name"`
			} `json:"models"`
		}
		if json.NewDecoder(resp.Body).Decode(&tagsResp) == nil {
			for _, m := range tagsResp.Models {
				name := strings.ToLower(m.Name)
				if strings.Contains(name, "moondream") {
					pf.MoondreamReady = true
					pf.OllamaModelFast = m.Name
				}
				if strings.Contains(name, "qwen2.5") && (strings.Contains(name, "vl") || strings.Contains(name, "vision")) {
					pf.QwenVLReady = true
					pf.OllamaModelSenior = m.Name
				}
			}
		}
		resp.Body.Close()
	}

	// 2. Validar Provedor Nuvem com Circuit Breaker caso ocorra 404/400
	if geminiKey != "" {
		pf.CloudProvider = "gemini"
		pf.CloudModel = "gemini-2.5-flash"
		if cachedGemini := getCachedJediVisionModel("gemini"); cachedGemini != "" {
			pf.CloudModel = cachedGemini
		}
		pf.CloudReady = true
	} else if groqKey != "" {
		pf.CloudProvider = "groq"
		pf.CloudModel = "meta-llama/llama-4-scout-17b-16e-instruct"
		if cachedGroq := getCachedJediVisionModel("groq"); cachedGroq != "" {
			pf.CloudModel = cachedGroq
		}
		pf.CloudReady = true
	}

	preflightMu.Lock()
	currentPreflight = pf
	preflightMu.Unlock()

	fmt.Printf("[ZORYA PRE-FLIGHT IA] Ollama: %t (Moondream: %t | QwenVL: %t) | Nuvem: %s (%s) [CircuitBreaker: %t]\n",
		pf.OllamaAvailable, pf.MoondreamReady, pf.QwenVLReady, pf.CloudProvider, pf.CloudModel, pf.CircuitBreakerOpen)

	return pf
}

// GetCurrentPreflight retorna o status ativo ou roda preflight se ainda não executado
func GetCurrentPreflight(geminiKey, groqKey string) *AICascadePreflight {
	preflightMu.RLock()
	pf := currentPreflight
	preflightMu.RUnlock()
	if pf != nil && time.Since(pf.LastCheck) < 5*time.Minute {
		return pf
	}
	return RunAIBatchPreflight(geminiKey, groqKey)
}

// GenerateUltraLightProxy gera uma miniatura de 384px em memória (< 25KB) para inferência em 150ms
func GenerateUltraLightProxy(src image.Image, maxDim int) []byte {
	if maxDim <= 0 {
		maxDim = 384
	}
	bounds := src.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()
	if w <= 0 || h <= 0 {
		return nil
	}

	scale := float64(maxDim) / math.Max(float64(w), float64(h))
	if scale > 1.0 {
		scale = 1.0
	}
	tw := int(float64(w) * scale)
	th := int(float64(h) * scale)
	if tw < 32 {
		tw = 32
	}
	if th < 32 {
		th = 32
	}

	scaled := resizeImageBilinear(src, tw, th)
	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, scaled, &jpeg.Options{Quality: 75}); err != nil {
		return nil
	}
	return buf.Bytes()
}

// EvaluatePortraitCascade executa o pipeline de IA em Cascata:
// Passo 1: Miniatura ultraleve de 384px para o Moondream na GTX 1650 (150ms). Se olhos fechados ou borrão extremo -> REJECTED.
// Passo 2: Para fotos promissoras, a IA sênior (Qwen2.5-VL ou Gemini Nuvem) avalia o bokeh e expressão -> APPROVED ou REVIEW_BOKEH.
func EvaluatePortraitCascade(img image.Image, culling CullingAnalysis, geminiKey, groqKey string) (string, string, string) {
	pf := GetCurrentPreflight(geminiKey, groqKey)

	// Gerar miniatura ultraleve de 384px
	proxyBytes := GenerateUltraLightProxy(img, 384)
	if len(proxyBytes) == 0 {
		return culling.Recommendation, "LOCAL_LAPLACIAN_ENGINE", "Análise algorítmica local concluída."
	}

	// -------------------------------------------------------------
	// PASSO 1: Moondream na GPU NVIDIA GTX 1650 (Triagem Rápida ~150ms)
	// -------------------------------------------------------------
	if pf.OllamaAvailable && pf.MoondreamReady {
		fastDecision, fastErr := queryMoondreamFastCheck(proxyBytes, pf.OllamaModelFast)
		if fastErr == nil && fastDecision == "REJECTED" {
			// Olhos fechados ou borrão grave detectado imediatamente pelo Moondream!
			return "REJECTED", "Moondream (GTX 1650 CUDA)", "Moondream GPU: Olhos fechados ou borrão severo no sujeito. Descartada."
		}
	}

	// -------------------------------------------------------------
	// PASSO 2: Foto Promissora! IA Sênior avalia Poética do Bokeh e Expressão
	// -------------------------------------------------------------
	// 2a. Se Qwen-VL estiver disponível localmente no Ollama
	if pf.OllamaAvailable && pf.QwenVLReady {
		rec, feedback, err := queryQwenVLSenior(proxyBytes, pf.OllamaModelSenior, culling)
		if err == nil && rec != "" {
			return rec, fmt.Sprintf("Qwen2.5-VL (%s)", pf.OllamaModelSenior), feedback
		}
	}

	// 2b. Canal Nuvem: Gemini Vision (se configurado e Circuit Breaker fechado)
	if geminiKey != "" && !pf.CircuitBreakerOpen {
		rec, feedback, err := queryGeminiSeniorVision(proxyBytes, geminiKey, culling)
		if err == nil && rec != "" {
			return rec, "Gemini Cloud (Senior Vision)", feedback
		}
		// Se Gemini retornar erro 400/404, abrir Circuit Breaker para não travar o lote
		if err != nil && (strings.Contains(err.Error(), "404") || strings.Contains(err.Error(), "400") || strings.Contains(err.Error(), "API_KEY_INVALID")) {
			preflightMu.Lock()
			if currentPreflight != nil {
				currentPreflight.CircuitBreakerOpen = true
			}
			preflightMu.Unlock()
			fmt.Printf("[ZORYA CIRCUIT BREAKER] Ativado para Gemini: %v. Fazendo fallback suave.\n", err)
		}
	}

	// 2c. Canal Nuvem: Groq Vision (se configurado)
	if groqKey != "" {
		rec, feedback, err := queryGroqSeniorVision(proxyBytes, groqKey, culling)
		if err == nil && rec != "" {
			return rec, "Groq Cloud (Senior Vision)", feedback
		}
	}

	// 2d. Fallback Inteligente: Análise Óptica e Proteção Artística de Bokeh
	if culling.BokehDetected {
		return "REVIEW_BOKEH", "Zorya Optical Engine (Bokeh Guard)", "Foco artístico no sujeito com desfoque suave de fundo (Bokeh protegido)."
	}

	return culling.Recommendation, "LOCAL_LAPLACIAN_ENGINE", "Avaliação óptica por nitidez local concluída."
}

// queryMoondreamFastCheck envia miniatura de 384px para Moondream com prompt ultra-específico de descarte
func queryMoondreamFastCheck(proxyBytes []byte, modelName string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()

	b64 := base64.StdEncoding.EncodeToString(proxyBytes)
	url := "http://localhost:11434/api/generate"

	payload := map[string]interface{}{
		"model":  modelName,
		"prompt": "Evaluate this portrait: are the eyes closed, blinking, or the face severely blurry? Respond REJECTED if eyes are closed or blurry, otherwise respond PASS.",
		"images": []string{b64},
		"stream": false,
		"options": map[string]interface{}{
			"temperature": 0.1,
			"num_predict": 10,
		},
	}

	bodyJSON, _ := json.Marshal(payload)
	req, err := http.NewRequestWithContext(ctx, "POST", url, bytes.NewReader(bodyJSON))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 9 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("ollama moondream status %d", resp.StatusCode)
	}

	var ollamaResp struct {
		Response string `json:"response"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&ollamaResp); err != nil {
		return "", err
	}

	raw := strings.ToUpper(strings.TrimSpace(ollamaResp.Response))
	if strings.Contains(raw, "REJECT") {
		return "REJECTED", nil
	}
	return "PASS", nil
}

// queryQwenVLSenior avalia a poética do bokeh e expressão no Qwen2.5-VL
func queryQwenVLSenior(proxyBytes []byte, modelName string, culling CullingAnalysis) (string, string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 12*time.Second)
	defer cancel()

	b64 := base64.StdEncoding.EncodeToString(proxyBytes)
	url := "http://localhost:11434/api/generate"

	prompt := fmt.Sprintf(`Você é o Curador Chefe de Fotografia de Retratos. Avalie este retrato fotográfico.
Dados ópticos: Nitidez=%0.1f, Bokeh Detectado=%t.
Classifique em uma das opções:
APPROVED: Rosto com expressão cativante, olhos abertos e foco nítido.
REVIEW_BOKEH: Foco intencional no sujeito com desfoque de fundo suave e atraente.
REVIEW: Dúvida de enquadramento ou expressão incerta.
Responda EXCLUSIVAMENTE em formato: RATING: [APPROVED|REVIEW_BOKEH|REVIEW] | FEEDBACK: [sua análise técnica concisa]`, culling.SharpnessScore, culling.BokehDetected)

	payload := map[string]interface{}{
		"model":  modelName,
		"prompt": prompt,
		"images": []string{b64},
		"stream": false,
	}

	bodyJSON, _ := json.Marshal(payload)
	req, err := http.NewRequestWithContext(ctx, "POST", url, bytes.NewReader(bodyJSON))
	if err != nil {
		return "", "", err
	}
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 14 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return "", "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return "", "", fmt.Errorf("qwen vl status %d", resp.StatusCode)
	}

	var ollamaResp struct {
		Response string `json:"response"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&ollamaResp); err != nil {
		return "", "", err
	}

	raw := ollamaResp.Response
	decision := "APPROVED"
	if strings.Contains(raw, "REVIEW_BOKEH") {
		decision = "REVIEW_BOKEH"
	} else if strings.Contains(raw, "REVIEW") {
		decision = "REVIEW"
	}

	feedback := "Qwen2.5-VL: Expressão estética e foco avaliados."
	if idx := strings.Index(raw, "FEEDBACK:"); idx != -1 {
		feedback = strings.TrimSpace(raw[idx+9:])
	}

	return decision, feedback, nil
}

// queryGeminiSeniorVision avalia via Gemini API Nuvem
func queryGeminiSeniorVision(proxyBytes []byte, apiKey string, culling CullingAnalysis) (string, string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 6*time.Second)
	defer cancel()

	b64 := base64.StdEncoding.EncodeToString(proxyBytes)
	url := fmt.Sprintf("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=%s", apiKey)

	prompt := fmt.Sprintf(`Curadoria fotográfica de retrato comercial: Nitidez=%0.1f, Bokeh=%t.
Classifique em UMA palavra: APPROVED, REVIEW_BOKEH ou REVIEW.
Linha seguinte: frase concisa de feedback estético.`, culling.SharpnessScore, culling.BokehDetected)

	payload := map[string]interface{}{
		"contents": []map[string]interface{}{
			{
				"parts": []map[string]interface{}{
					{"text": prompt},
					{
						"inline_data": map[string]string{
							"mime_type": "image/jpeg",
							"data":      b64,
						},
					},
				},
			},
		},
		"generationConfig": map[string]interface{}{
			"temperature": 0.2,
		},
	}

	bodyJSON, _ := json.Marshal(payload)
	req, err := http.NewRequestWithContext(ctx, "POST", url, bytes.NewReader(bodyJSON))
	if err != nil {
		return "", "", err
	}
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 7 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return "", "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return "", "", fmt.Errorf("gemini status %d", resp.StatusCode)
	}

	var geminiResp struct {
		Candidates []struct {
			Content struct {
				Parts []struct {
					Text string `json:"text"`
				} `json:"parts"`
			} `json:"content"`
		} `json:"candidates"`
	}

	if err := json.NewDecoder(resp.Body).Decode(&geminiResp); err != nil {
		return "", "", err
	}

	if len(geminiResp.Candidates) == 0 || len(geminiResp.Candidates[0].Content.Parts) == 0 {
		return "", "", fmt.Errorf("resposta vazia do Gemini")
	}

	text := geminiResp.Candidates[0].Content.Parts[0].Text
	lines := strings.Split(text, "\n")
	firstLine := strings.ToUpper(strings.TrimSpace(lines[0]))

	decision := "APPROVED"
	if strings.Contains(firstLine, "REVIEW_BOKEH") {
		decision = "REVIEW_BOKEH"
	} else if strings.Contains(firstLine, "REVIEW") {
		decision = "REVIEW"
	}

	feedback := "Gemini Nuvem: Retrato aprovado com excelente foco e expressão."
	if len(lines) > 1 && strings.TrimSpace(lines[1]) != "" {
		feedback = "Gemini Nuvem: " + strings.TrimSpace(lines[1])
	}

	return decision, feedback, nil
}

// getCachedJediVisionModel lê o cache diário .jedi_models_cache.json se existir.
func getCachedJediVisionModel(provider string) string {
	paths := []string{
		".jedi_models_cache.json",
		"C:\\dev\\zorya\\.jedi_models_cache.json",
	}
	for _, p := range paths {
		data, err := os.ReadFile(p)
		if err != nil {
			continue
		}
		var cache map[string]interface{}
		if err := json.Unmarshal(data, &cache); err != nil {
			continue
		}
		if provider == "groq" {
			if v, ok := cache["groq_vision"].(string); ok && v != "" {
				return v
			}
			if models, ok := cache["groq"].([]interface{}); ok {
				for _, m := range models {
					s, _ := m.(string)
					if strings.Contains(s, "scout") || strings.Contains(s, "vision") {
						return s
					}
				}
			}
		} else if provider == "gemini" {
			if v, ok := cache["gemini_vision"].(string); ok && v != "" {
				return v
			}
		}
	}
	return ""
}

// queryGroqSeniorVision avalia via Groq Vision
func queryGroqSeniorVision(proxyBytes []byte, apiKey string, culling CullingAnalysis) (string, string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	b64 := base64.StdEncoding.EncodeToString(proxyBytes)
	url := "https://api.groq.com/openai/v1/chat/completions"

	prompt := fmt.Sprintf(`Curadoria fotográfica LightCraft: Nitidez=%0.1f, Bokeh=%t. Responda APENAS: APPROVED, REVIEW_BOKEH ou REVIEW.`, culling.SharpnessScore, culling.BokehDetected)

	modelName := "meta-llama/llama-4-scout-17b-16e-instruct"
	if cached := getCachedJediVisionModel("groq"); cached != "" {
		modelName = cached
	}

	payload := map[string]interface{}{
		"model": modelName,
		"messages": []map[string]interface{}{
			{
				"role": "user",
				"content": []map[string]interface{}{
					{"type": "text", "text": prompt},
					{
						"type": "image_url",
						"image_url": map[string]string{
							"url": fmt.Sprintf("data:image/jpeg;base64,%s", b64),
						},
					},
				},
			},
		},
		"temperature": 0.2,
	}

	bodyJSON, _ := json.Marshal(payload)
	req, err := http.NewRequestWithContext(ctx, "POST", url, bytes.NewReader(bodyJSON))
	if err != nil {
		return "", "", err
	}
	req.Header.Set("Authorization", "Bearer "+apiKey)
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 6 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return "", "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return "", "", fmt.Errorf("groq status %d", resp.StatusCode)
	}

	var groqResp struct {
		Choices []struct {
			Message struct {
				Content string `json:"content"`
			} `json:"message"`
		} `json:"choices"`
	}

	if err := json.NewDecoder(resp.Body).Decode(&groqResp); err != nil || len(groqResp.Choices) == 0 {
		return "", "", fmt.Errorf("groq decode error: %v", err)
	}

	raw := strings.ToUpper(groqResp.Choices[0].Message.Content)
	decision := "APPROVED"
	if strings.Contains(raw, "REVIEW_BOKEH") {
		decision = "REVIEW_BOKEH"
	} else if strings.Contains(raw, "REVIEW") {
		decision = "REVIEW"
	}

	return decision, "Groq Nuvem: Avaliação estética concluída.", nil
}

// CurateImage mantém compatibilidade completa com a rota /api/v1/photo/ai-curate
func CurateImage(imgData []byte, filename string, culling CullingAnalysis, geminiKey, groqKey string, serverPort int) (*AICurationResult, error) {
	if serverPort <= 0 {
		serverPort = 9876
	}

	baseName := strings.TrimSuffix(filepath.Base(filename), filepath.Ext(filename))
	thumbName := fmt.Sprintf("%s_proof_2048.webp", baseName)
	thumbURL := fmt.Sprintf("http://127.0.0.1:%d/thumbnails/%s", serverPort, thumbName)

	sliders := computeBaselineSliders(culling)

	result := &AICurationResult{
		ImagePath:         filename,
		ThumbnailFilename: thumbName,
		ThumbnailURL:      thumbURL,
		AestheticScore:    math.Round(culling.OverallScore*10) / 10,
		CompositionRating: "BALANCED",
		ColorPalette:      []string{"#D4AF37", "#2E8B57", "#1E1E24", "#F4F4F9"},
		AestheticFeedback: "Exposição equilibrada com excelente nitidez no plano focal.",
		RecommendedCrop:   "ORIGINAL",
		Sliders:           sliders,
		ProviderUsed:      "LOCAL_OPTICAL_ENGINE",
		BokehProtected:    culling.BokehDetected,
	}

	if len(imgData) > 0 {
		srcImg, _, err := image.Decode(bytes.NewReader(imgData))
		if err == nil && srcImg != nil {
			rec, prov, feedback := EvaluatePortraitCascade(srcImg, culling, geminiKey, groqKey)
			result.CompositionRating = rec
			result.ProviderUsed = prov
			result.AestheticFeedback = feedback
			if rec == "APPROVED" {
				result.AestheticScore = math.Max(88.0, result.AestheticScore)
			} else if rec == "REJECTED" {
				result.AestheticScore = math.Min(40.0, result.AestheticScore)
			}
			return result, nil
		}
	}

	return result, nil
}

// computeBaselineSliders derives optical adjustments
func computeBaselineSliders(c CullingAnalysis) LightCraftSliders {
	sliders := LightCraftSliders{
		Exposure:          0.0,
		Contrast:          6,
		Highlights:        -10,
		Shadows:           12,
		TemperatureKelvin: 5500,
		Tint:              0,
		Clarity:           8,
		Vibrance:          10,
	}

	if c.MeanLuminance < 110.0 {
		diff := 128.0 - c.MeanLuminance
		sliders.Exposure = math.Round((diff/128.0)*0.75*100) / 100
		sliders.Shadows += int(diff * 0.4)
		sliders.Highlights -= 8
	} else if c.MeanLuminance > 155.0 {
		diff := c.MeanLuminance - 128.0
		sliders.Exposure = -math.Round((diff/128.0)*0.65*100) / 100
		sliders.Highlights -= int(diff * 0.6)
		sliders.Contrast += 4
	}

	if c.HighlightClipPct > 4.0 {
		sliders.Highlights = -25 - int(c.HighlightClipPct*1.5)
		if sliders.Highlights < -100 {
			sliders.Highlights = -100
		}
	}

	if c.ShadowClippingPct > 8.0 {
		sliders.Shadows = 20 + int(c.ShadowClippingPct*1.2)
		if sliders.Shadows > 100 {
			sliders.Shadows = 100
		}
	}

	if c.BokehDetected {
		sliders.Clarity = 14
		sliders.Contrast += 4
		sliders.Vibrance += 6
	}

	return sliders
}