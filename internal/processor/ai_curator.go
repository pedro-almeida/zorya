// PT: Curadoria Fotográfica com IA, Análise Estética e Sliders LightCraft
// EN: AI Photographic Curation, Aesthetic Scoring and LightCraft Sliders
// AI: @llm-context Módulo Zorya v1.0.0 / Curadoria Inteligente e Integração Multimodal

package processor

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	_ "image/jpeg"
	_ "image/png"
	"math"
	"net/http"
	"path/filepath"
	"strings"
	"time"
)

// LightCraftSliders defines precision RAW post-processing parameters for the desktop engine.
type LightCraftSliders struct {
	Exposure          float64 `json:"exposure"`           // -2.0 to +2.0 eV
	Contrast          int     `json:"contrast"`           // -50 to +50
	Highlights        int     `json:"highlights"`         // -100 to +100
	Shadows           int     `json:"shadows"`            // -100 to +100
	TemperatureKelvin int     `json:"temperature_kelvin"` // 2000 to 10000 K
	Tint              int     `json:"tint"`               // -50 to +50
	Clarity           int     `json:"clarity"`            // -100 to +100
	Vibrance          int     `json:"vibrance"`           // -100 to +100
}

// AICurationResult aggregates neural aesthetic evaluation and development suggestions.
type AICurationResult struct {
	ImagePath         string            `json:"image_path"`
	ThumbnailFilename string            `json:"thumbnail_filename"`
	ThumbnailURL      string            `json:"thumbnail_url"`
	AestheticScore    float64           `json:"aesthetic_score"`
	CompositionRating string            `json:"composition_rating"`
	ColorPalette      []string          `json:"color_palette"`
	AestheticFeedback string            `json:"aesthetic_feedback"`
	RecommendedCrop   string            `json:"recommended_crop"`
	Sliders           LightCraftSliders `json:"sliders"`
	ProviderUsed      string            `json:"provider_used"`
	BokehProtected    bool              `json:"bokeh_protected"`
}

// AICurationRequest represents input for the AI curation endpoint.
type AICurationRequest struct {
	ImagePath         string  `json:"image_path"`
	ThumbnailFilename string  `json:"thumbnail_filename,omitempty"`
	GeminiKey         string  `json:"gemini_key,omitempty"`
	GroqKey           string  `json:"groq_key,omitempty"`
	ServerPort        int     `json:"server_port,omitempty"`
}

// CurateImage runs hybrid curation: fast local optical analysis combined with Gemini/Groq vision evaluation.
func CurateImage(imgData []byte, filename string, culling CullingAnalysis, geminiKey, groqKey string, serverPort int) (*AICurationResult, error) {
	if serverPort <= 0 {
		serverPort = 9876
	}

	baseName := strings.TrimSuffix(filepath.Base(filename), filepath.Ext(filename))
	thumbName := fmt.Sprintf("%s_proof_2048.webp", baseName)
	thumbURL := fmt.Sprintf("http://127.0.0.1:%d/thumbnails/%s", serverPort, thumbName)

	// 1. Calculate local optical baseline sliders
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

	if culling.BokehDetected {
		result.AestheticFeedback = "Foco artístico no sujeito com desfoque suave de fundo (Bokeh protegido)."
		result.CompositionRating = "EXCELLENT_BOKEH"
	}

	// 1. Canal Local Prioritário: Inferência de Visão Multimodal com Moondream no Ollama (Acelerado por GPU CUDA GTX 1650)
	if len(imgData) > 0 {
		aiRes, err := queryOllamaMoondream(imgData, culling)
		if err == nil && aiRes != nil {
			aiRes.ImagePath = filename
			aiRes.ThumbnailFilename = thumbName
			aiRes.ThumbnailURL = thumbURL
			aiRes.BokehProtected = culling.BokehDetected
			return aiRes, nil
		}
	}

	// 2. Canal Nuvem Híbrido: Gemini API se configurada
	if geminiKey != "" && len(imgData) > 0 {
		aiRes, err := queryGeminiVision(imgData, geminiKey, culling)
		if err == nil && aiRes != nil {
			aiRes.ImagePath = filename
			aiRes.ThumbnailFilename = thumbName
			aiRes.ThumbnailURL = thumbURL
			aiRes.BokehProtected = culling.BokehDetected
			return aiRes, nil
		}
	}

	// 3. Fallback to Groq API if configured
	if groqKey != "" && len(imgData) > 0 {
		aiRes, err := queryGroqVision(imgData, groqKey, culling)
		if err == nil && aiRes != nil {
			aiRes.ImagePath = filename
			aiRes.ThumbnailFilename = thumbName
			aiRes.ThumbnailURL = thumbURL
			aiRes.BokehProtected = culling.BokehDetected
			return aiRes, nil
		}
	}

	return result, nil
}

// computeBaselineSliders derives optical LightCraft adjustments from histogram and sharpness telemetry.
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

	// Compensate underexposure
	if c.MeanLuminance < 110.0 {
		diff := 128.0 - c.MeanLuminance
		sliders.Exposure = math.Round((diff/128.0)*0.75*100) / 100
		sliders.Shadows += int(diff * 0.4)
		sliders.Highlights -= 8
	} else if c.MeanLuminance > 155.0 {
		// Recover highlights from overexposure
		diff := c.MeanLuminance - 128.0
		sliders.Exposure = -math.Round((diff/128.0)*0.65*100) / 100
		sliders.Highlights -= int(diff * 0.6)
		sliders.Contrast += 4
	}

	// Highlight clipping protection
	if c.HighlightClipPct > 4.0 {
		sliders.Highlights = -25 - int(c.HighlightClipPct*1.5)
		if sliders.Highlights < -100 {
			sliders.Highlights = -100
		}
	}

	// Shadow recovery
	if c.ShadowClippingPct > 8.0 {
		sliders.Shadows = 20 + int(c.ShadowClippingPct*1.2)
		if sliders.Shadows > 100 {
			sliders.Shadows = 100
		}
	}

	// Bokeh pop adjustments: boost clarity on focal plane while keeping shadows warm
	if c.BokehDetected {
		sliders.Clarity = 14
		sliders.Contrast += 4
		sliders.Vibrance += 6
	}

	return sliders
}

// queryGeminiVision queries Gemini 2.5 Flash / 1.5 Flash for multimodal aesthetic feedback.
func queryGeminiVision(imgBytes []byte, apiKey string, culling CullingAnalysis) (*AICurationResult, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 7*time.Second)
	defer cancel()

	// Subsample or limit image slice for rapid upload
	previewBytes := imgBytes
	if len(previewBytes) > 512*1024 {
		previewBytes = previewBytes[:512*1024]
	}

	b64Image := base64.StdEncoding.EncodeToString(previewBytes)
	url := fmt.Sprintf("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=%s", apiKey)

	prompt := fmt.Sprintf(`Você é o curador de fotografia do Zorya LightCraft. Analise esta foto (Nitidez=%0.1f, Luma=%0.1f, Bokeh=%t).
Responda EXCLUSIVAMENTE em formato JSON com o seguinte schema:
{
  "aesthetic_score": 85.0,
  "composition_rating": "EXCELLENT",
  "color_palette": ["#Hex1", "#Hex2", "#Hex3", "#Hex4"],
  "aesthetic_feedback": "string concisa em português",
  "recommended_crop": "ORIGINAL",
  "sliders": {
    "exposure": 0.15,
    "contrast": 8,
    "highlights": -18,
    "shadows": 14,
    "temperature_kelvin": 5500,
    "tint": 2,
    "clarity": 12,
    "vibrance": 10
  }
}`, culling.SharpnessScore, culling.MeanLuminance, culling.BokehDetected)

	payload := map[string]interface{}{
		"contents": []map[string]interface{}{
			{
				"parts": []map[string]interface{}{
					{"text": prompt},
					{
						"inline_data": map[string]string{
							"mime_type": "image/jpeg",
							"data":      b64Image,
						},
					},
				},
			},
		},
		"generationConfig": map[string]interface{}{
			"temperature":       0.2,
			"response_mime_type": "application/json",
		},
	}

	bodyJSON, _ := json.Marshal(payload)
	req, err := http.NewRequestWithContext(ctx, "POST", url, bytes.NewReader(bodyJSON))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 8 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("gemini api returned status %d", resp.StatusCode)
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
		return nil, err
	}

	if len(geminiResp.Candidates) == 0 || len(geminiResp.Candidates[0].Content.Parts) == 0 {
		return nil, fmt.Errorf("resposta vazia do Gemini")
	}

	rawJSON := geminiResp.Candidates[0].Content.Parts[0].Text
	var aiRes AICurationResult
	if err := json.Unmarshal([]byte(rawJSON), &aiRes); err != nil {
		return nil, err
	}

	aiRes.ProviderUsed = "GEMINI_2.5_FLASH"
	return &aiRes, nil
}

// queryGroqVision queries Groq Vision for high-throughput image classification.
func queryGroqVision(imgBytes []byte, apiKey string, culling CullingAnalysis) (*AICurationResult, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	previewBytes := imgBytes
	if len(previewBytes) > 512*1024 {
		previewBytes = previewBytes[:512*1024]
	}

	b64Image := base64.StdEncoding.EncodeToString(previewBytes)
	url := "https://api.groq.com/openai/v1/chat/completions"

	prompt := fmt.Sprintf(`Curadoria fotográfica LightCraft (Nitidez=%0.1f, Luma=%0.1f, Bokeh=%t).
Responda APENAS em JSON:
{
  "aesthetic_score": 88.0,
  "composition_rating": "EXCELLENT",
  "color_palette": ["#1A1A1A", "#E0A96D", "#778899", "#F5F5F5"],
  "aesthetic_feedback": "Excelente harmonia cromática e nitidez.",
  "recommended_crop": "ORIGINAL",
  "sliders": {
    "exposure": 0.10,
    "contrast": 6,
    "highlights": -15,
    "shadows": 12,
    "temperature_kelvin": 5600,
    "tint": 1,
    "clarity": 10,
    "vibrance": 8
  }
}`, culling.SharpnessScore, culling.MeanLuminance, culling.BokehDetected)

	payload := map[string]interface{}{
		"model": "llama-3.2-11b-vision-preview",
		"messages": []map[string]interface{}{
			{
				"role": "user",
				"content": []map[string]interface{}{
					{"type": "text", "text": prompt},
					{
						"type": "image_url",
						"image_url": map[string]string{
							"url": fmt.Sprintf("data:image/jpeg;base64,%s", b64Image),
						},
					},
				},
			},
		},
		"response_format": map[string]string{"type": "json_object"},
		"temperature":     0.2,
	}

	bodyJSON, _ := json.Marshal(payload)
	req, err := http.NewRequestWithContext(ctx, "POST", url, bytes.NewReader(bodyJSON))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+apiKey)
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 6 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("groq vision api returned status %d", resp.StatusCode)
	}

	var groqResp struct {
		Choices []struct {
			Message struct {
				Content string `json:"content"`
			} `json:"message"`
		} `json:"choices"`
	}

	if err := json.NewDecoder(resp.Body).Decode(&groqResp); err != nil || len(groqResp.Choices) == 0 {
		return nil, fmt.Errorf("groq decode error: %v", err)
	}

	var aiRes AICurationResult
	if err := json.Unmarshal([]byte(groqResp.Choices[0].Message.Content), &aiRes); err != nil {
		return nil, err
	}

	aiRes.ProviderUsed = "GROQ_LLAMA_VISION"
	return &aiRes, nil
}

// PT: Consulta o modelo Moondream no Ollama local via GPU NVIDIA CUDA
// EN: Queries local Moondream model in Ollama accelerated by NVIDIA CUDA GPU
// AI: @llm-context Módulo Zorya v1.0.0 / Visão Local na GPU Ollama Moondream
//     @llm-invariant Conexão local em http://localhost:11434/api/generate com stream: false
//     @llm-rule Retorna AICurationResult com ProviderUsed="Ollama Moondream (Local GPU CUDA)"
func queryOllamaMoondream(imgBytes []byte, culling CullingAnalysis) (*AICurationResult, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 12*time.Second)
	defer cancel()

	previewBytes := imgBytes
	if len(previewBytes) > 1024*1024 {
		previewBytes = previewBytes[:1024*1024]
	}

	b64Image := base64.StdEncoding.EncodeToString(previewBytes)
	url := "http://localhost:11434/api/generate"

	payload := map[string]interface{}{
		"model":  "moondream",
		"prompt": "Evaluate this portrait: is the face sharp and eyes open? Answer in one word: APPROVED, REVIEW or REJECTED.",
		"images": []string{b64Image},
		"stream": false,
	}

	bodyJSON, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequestWithContext(ctx, "POST", url, bytes.NewReader(bodyJSON))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 14 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("ollama api returned status %d", resp.StatusCode)
	}

	var ollamaResp struct {
		Model    string `json:"model"`
		Response string `json:"response"`
		Done     bool   `json:"done"`
	}

	if err := json.NewDecoder(resp.Body).Decode(&ollamaResp); err != nil {
		return nil, err
	}

	raw := strings.ToUpper(strings.TrimSpace(ollamaResp.Response))
	decision := "REVIEW"
	score := math.Round(culling.OverallScore*10) / 10
	feedback := "Moondream GPU: Retrato sob análise técnica."

	if strings.Contains(raw, "APPROVED") {
		decision = "APPROVED"
		score = math.Max(85.0, score)
		feedback = "Moondream GPU: Rosto nítido e olhos abertos. Foto aprovada com sucesso."
	} else if strings.Contains(raw, "REJECT") {
		decision = "REJECTED"
		score = math.Min(45.0, score)
		feedback = "Moondream GPU: Foco facial insuficiente ou olhos fechados detectados."
	} else if strings.Contains(raw, "REVIEW") {
		decision = "REVIEW"
		feedback = "Moondream GPU: Recomendada revisão estética manual do enquadramento."
	}

	if culling.BokehDetected {
		feedback += " Bokeh artístico suave identificado e protegido."
		if decision == "REVIEW" {
			decision = "REVIEW_BOKEH"
		}
	}

	sliders := computeBaselineSliders(culling)
	result := &AICurationResult{
		AestheticScore:    score,
		CompositionRating: decision,
		ColorPalette:      []string{"#1A1A1A", "#E0A96D", "#778899", "#F5F5F5"},
		AestheticFeedback: feedback,
		RecommendedCrop:   "ORIGINAL",
		Sliders:           sliders,
		ProviderUsed:      "Ollama Moondream (Local GPU CUDA)",
		BokehProtected:    culling.BokehDetected,
	}

	return result, nil
}

// PT: Avalia o retrato com IA em cascata: Ollama Moondream GPU -> Nuvem Gemini/Groq -> Fallback Laplaciano
// EN: Evaluates portrait via tiered AI pipeline: Ollama Moondream GPU -> Gemini/Groq Cloud -> Laplacian Math Fallback
// AI: @llm-context Módulo Zorya v1.0.0 / Classificação Inteligente de Retratos
//     @llm-invariant Tenta Moondream local na GPU primeiro; se indisponível, recorre à nuvem ou cálculo Laplaciano
//     @llm-rule Retorna (recomendação, provedor_utilizado)
func EvaluatePortraitAI(imgBytes []byte, culling CullingAnalysis, geminiKey, groqKey string) (string, string) {
	// 1. Canal Local Prioritário (Ollama na GPU NVIDIA CUDA)
	if len(imgBytes) > 0 {
		aiRes, err := queryOllamaMoondream(imgBytes, culling)
		if err == nil && aiRes != nil && aiRes.CompositionRating != "" {
			return aiRes.CompositionRating, "Ollama Moondream (Local GPU CUDA)"
		}
	}

	// 2. Canal Nuvem Híbrido: Gemini API
	if geminiKey != "" && len(imgBytes) > 0 {
		aiRes, err := queryGeminiVision(imgBytes, geminiKey, culling)
		if err == nil && aiRes != nil {
			rec := "APPROVED"
			if aiRes.AestheticScore < 50.0 {
				rec = "REJECTED"
			} else if aiRes.AestheticScore < 75.0 {
				rec = "REVIEW"
			}
			return rec, "Gemini Cloud"
		}
	}

	// 2b. Canal Nuvem Híbrido: Groq API
	if groqKey != "" && len(imgBytes) > 0 {
		aiRes, err := queryGroqVision(imgBytes, groqKey, culling)
		if err == nil && aiRes != nil {
			rec := "APPROVED"
			if aiRes.AestheticScore < 50.0 {
				rec = "REJECTED"
			} else if aiRes.AestheticScore < 75.0 {
				rec = "REVIEW"
			}
			return rec, "Groq Cloud"
		}
	}

	// 3. Fallback Matemático: Variância Laplaciana do culling.go
	return culling.Recommendation, "LOCAL_LAPLACIAN_ENGINE"
}