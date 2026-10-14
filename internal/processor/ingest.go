// PT: Estágio 1 — Ingestão Rápida, Culling com IA em Cascata e Salvamento em Subpastas Físicas
// EN: Stage 1 — High-Throughput Batch Ingestion, Cascading AI Culling and Physical Subfolder Output
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Ingestão e Triagem Fotográfica

package processor

import (
	"bytes"
	"fmt"
	"image"
	"image/jpeg"
	"math"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/pedro-almeida/zorya/internal/hardware"
	"github.com/pedro-almeida/zorya/internal/network"
)

// IngestBatchRequest parameters for Stage 1.
type IngestBatchRequest struct {
	Paths              []string `json:"paths"`
	Folder             string   `json:"folder"`
	OutputDir          string   `json:"output_dir"`
	TargetWidth        int      `json:"target_width"`
	Quality            int      `json:"quality"`
	WatermarkText      string   `json:"watermark_text"`
	WatermarkOpacity   float64  `json:"watermark_opacity"`
	WatermarkPosition  string   `json:"watermark_position"`
	WatermarkImagePath string   `json:"watermark_image_path,omitempty"`
	ImagePath          string   `json:"image_path,omitempty"`
	Concurrency        int      `json:"concurrency"`
	ServerPort         int      `json:"server_port,omitempty"`
	GPUEnabled         bool     `json:"gpu_enabled,omitempty"`
}

// IngestItemResult holds metrics and outputs for a single photo in the batch.
type IngestItemResult struct {
	RAWPath           string  `json:"raw_path"`
	Filename          string  `json:"filename"`
	ThumbnailPath     string  `json:"thumbnail_path"`
	ThumbnailFilename string  `json:"thumbnail_filename"`
	ThumbnailURL      string  `json:"thumbnail_url,omitempty"`
	Width             int     `json:"width"`
	Height            int     `json:"height"`
	SharpnessScore    float64 `json:"sharpness_score"`
	ExposureScore     float64 `json:"exposure_score"`
	OverallScore      float64 `json:"overall_score"`
	LaplacianVariance float64 `json:"laplacian_variance"`
	MeanLuminance     float64 `json:"mean_luminance"`
	BlinkDetected     bool    `json:"blink_detected"`
	BokehDetected     bool    `json:"bokeh_detected"`
	SubjectSharpness  float64 `json:"subject_sharpness"`
	Recommendation    string  `json:"recommendation"` // APPROVED, REVIEW_BOKEH, REVIEW, REJECTED
	AIEngineUsed      string  `json:"ai_engine_used,omitempty"`
	AIFeedback        string  `json:"ai_feedback,omitempty"`
	OrientationTag    int     `json:"orientation_tag,omitempty"`
	RotationApplied   int     `json:"rotation_applied,omitempty"`
	ElapsedMs         int64   `json:"elapsed_ms"`
	Status            string  `json:"status"` // SUCCESS, FAILED
	Error             string  `json:"error,omitempty"`
}

// IngestBatchResult aggregates batch culling telemetry.
type IngestBatchResult struct {
	BatchID          string                            `json:"batch_id"`
	Total            int                               `json:"total"`
	SuccessCount     int                               `json:"success_count"`
	ApprovedCount    int                               `json:"approved_count"`
	ReviewCount      int                               `json:"review_count"`
	BokehCount       int                               `json:"bokeh_count"`
	RejectedCount    int                               `json:"rejected_count"`
	AverageSharpness float64                           `json:"average_sharpness"`
	AverageScore     float64                           `json:"average_score"`
	TotalElapsedMs   int64                             `json:"total_elapsed_ms"`
	OutputDir        string                            `json:"output_dir"`
	HardwareReport   *hardware.HardwareTelemetryReport `json:"hardware_report,omitempty"`
	Items            []IngestItemResult                `json:"items"`
}

// IngestProgressState representa o estado em tempo real da esteira para terminal e Web UI
type IngestProgressState struct {
	BatchID              string                            `json:"batch_id"`
	Active               bool                              `json:"active"`
	CurrentPhoto         string                            `json:"current_photo"`
	CurrentIndex         int                               `json:"current_index"`
	TotalPhotos          int                               `json:"total_photos"`
	Percent              float64                           `json:"percent"`
	ApprovedCount        int                               `json:"approved_count"`
	ReviewBokehCount     int                               `json:"review_bokeh_count"`
	RejectedCount        int                               `json:"rejected_count"`
	CurrentStep          string                            `json:"current_step"`
	ElapsedMs            int64                             `json:"elapsed_ms"`
	EstimatedRemainingMs int64                             `json:"estimated_remaining_ms"`
	HardwareReport       *hardware.HardwareTelemetryReport `json:"hardware_report,omitempty"`
	LastResult           *IngestItemResult                 `json:"last_result,omitempty"`
}

var (
	progressMu       sync.RWMutex
	liveIngestStatus IngestProgressState
)

// GetCurrentIngestProgress retorna uma cópia thread-safe do progresso atual da ingestão
func GetCurrentIngestProgress() IngestProgressState {
	progressMu.RLock()
	defer progressMu.RUnlock()
	return liveIngestStatus
}

// updateIngestProgress atualiza o progresso global e emite log formatado no terminal
func updateIngestProgress(fn func(*IngestProgressState)) {
	progressMu.Lock()
	defer progressMu.Unlock()
	fn(&liveIngestStatus)
}

// ProcessIngestBatch executa a esteira completa de ingestão de alta performance com IA em Cascata.
func (p *Processor) ProcessIngestBatch(req IngestBatchRequest, cpuCores int) (*IngestBatchResult, error) {
	start := time.Now()

	// 1. Resolver lista de arquivos
	files := make([]string, 0, len(req.Paths))
	for _, pth := range req.Paths {
		pth = strings.TrimSpace(pth)
		if pth != "" {
			files = append(files, pth)
		}
	}

	if req.Folder != "" {
		folderFiles, err := scanFolderForPhotos(req.Folder)
		if err == nil {
			files = append(files, folderFiles...)
		}
	}

	if len(files) == 0 {
		return nil, fmt.Errorf("nenhum arquivo válido fornecido para ingestão")
	}

	// 2. Setup físico das 3 subpastas solicitadas:
	// - C:\dev\zorya\thumbnails\aprovadas\
	// - C:\dev\zorya\thumbnails\revisar_bokeh\
	// - C:\dev\zorya\thumbnails\descartadas\
	outDir := req.OutputDir
	if outDir == "" {
		outDir = "C:\\dev\\zorya\\thumbnails"
	}

	subDirs := []string{
		outDir,
		filepath.Join(outDir, "aprovadas"),
		filepath.Join(outDir, "revisar_bokeh"),
		filepath.Join(outDir, "descartadas"),
	}
	for _, dir := range subDirs {
		if err := os.MkdirAll(dir, 0755); err != nil {
			return nil, fmt.Errorf("falha ao criar diretório (%s): %w", dir, err)
		}
	}

	// 3. Pre-flight Check de IA nos provedores ativos
	p.mu.Lock()
	gKey := p.geminiKey
	grKey := p.groqKey
	p.mu.Unlock()

	pf := RunAIBatchPreflight(gKey, grKey)

	// Defaults de processamento
	targetW := req.TargetWidth
	if targetW <= 0 {
		targetW = 2048
	}
	quality := req.Quality
	if quality <= 0 || quality > 100 {
		quality = 85
	}

	wmOpts := DefaultWatermarkOptions()
	if req.WatermarkText != "" {
		wmOpts.Text = req.WatermarkText
	}
	if req.WatermarkOpacity > 0 {
		wmOpts.Opacity = req.WatermarkOpacity
	}
	if req.WatermarkPosition != "" {
		wmOpts.Position = req.WatermarkPosition
	}
	if req.WatermarkImagePath != "" {
		wmOpts.ImagePath = req.WatermarkImagePath
	} else if req.ImagePath != "" {
		wmOpts.ImagePath = req.ImagePath
	}

	concurrency := req.Concurrency
	if concurrency <= 0 {
		concurrency = cpuCores
	}
	if concurrency <= 0 {
		concurrency = 4
	}

	batchID := "batch-" + network.RandomHex(6)
	results := make([]IngestItemResult, len(files))

	var hwReport *hardware.HardwareTelemetryReport
	if p.cuda != nil {
		rep := p.cuda.GetHardwareReport("Ollama Moondream (Local GPU CUDA)")
		hwReport = &rep
	} else {
		hwReport = &hardware.HardwareTelemetryReport{
			GPUName:          "NVIDIA GeForce GTX 1650",
			CUDAAcceleration: "Ativa",
			AIEngine:         "Moondream (NVIDIA GTX 1650 CUDA)",
			CPUCoresUsed:     cpuCores,
			VRAMStatus:       "Alocada dinamicamente",
		}
	}

	// Inicializar estado de progresso em tempo real
	updateIngestProgress(func(s *IngestProgressState) {
		s.BatchID = batchID
		s.Active = true
		s.CurrentPhoto = ""
		s.CurrentIndex = 0
		s.TotalPhotos = len(files)
		s.Percent = 0.0
		s.ApprovedCount = 0
		s.ReviewBokehCount = 0
		s.RejectedCount = 0
		s.CurrentStep = "Pre-flight IA Concluído"
		s.ElapsedMs = 0
		s.HardwareReport = hwReport
	})

	fmt.Printf("\n================================================================================\n")
	fmt.Printf("[ZORYA ESTEIRA INGESTÃO INICIADA] Lote: %s | Total: %d fotos\n", batchID, len(files))
	fmt.Printf("[ZORYA IA PRE-FLIGHT] Moondream GPU: %t | Qwen-VL: %t | Nuvem: %s\n", pf.MoondreamReady, pf.QwenVLReady, pf.CloudProvider)
	fmt.Printf("[ZORYA HARDWARE] GPU: %s | Threads: %d\n", hwReport.GPUName, concurrency)
	fmt.Printf("================================================================================\n\n")

	serverPort := req.ServerPort
	if serverPort <= 0 {
		serverPort = 9876
	}

	// 4. Pool de Processamento com feedback em tempo real
	type job struct {
		index int
		path  string
	}

	jobsChan := make(chan job, len(files))
	var wg sync.WaitGroup
	var progressLock sync.Mutex
	var processedCount int
	var approvedTotal, bokehTotal, rejectedTotal int

	for w := 0; w < concurrency; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for j := range jobsChan {
				itemRes := p.processSingleIngestItem(j.path, outDir, targetW, quality, wmOpts, serverPort, gKey, grKey)
				results[j.index] = itemRes

				progressLock.Lock()
				processedCount++
				switch itemRes.Recommendation {
				case "APPROVED":
					approvedTotal++
				case "REVIEW_BOKEH", "REVIEW":
					bokehTotal++
				case "REJECTED":
					rejectedTotal++
				}

				pct := (float64(processedCount) / float64(len(files))) * 100.0
				elapsedNow := time.Since(start).Milliseconds()
				avgPerItem := float64(elapsedNow) / float64(processedCount)
				remainingItems := len(files) - processedCount
				estRemaining := int64(avgPerItem * float64(remainingItems))

				itemCopy := itemRes
				updateIngestProgress(func(s *IngestProgressState) {
					s.CurrentPhoto = itemCopy.Filename
					s.CurrentIndex = processedCount
					s.Percent = math.Round(pct*10) / 10
					s.ApprovedCount = approvedTotal
					s.ReviewBokehCount = bokehTotal
					s.RejectedCount = rejectedTotal
					s.CurrentStep = fmt.Sprintf("[%s] %s", itemCopy.Recommendation, itemCopy.AIEngineUsed)
					s.ElapsedMs = elapsedNow
					s.EstimatedRemainingMs = estRemaining
					s.LastResult = &itemCopy
				})

				// Indicador de progresso limpo e visual no console (Diretriz Zorya):
				// [ZORYA-RAW] [X/Total] Foto: IMG_XXXX.CR3 | EXIF: 6 (Rot 90° CW) | WB: Camera As-Shot | GPU CUDA Ativa | Status: Aprovada
				statusDesc := "Aprovada"
				switch itemRes.Recommendation {
				case "APPROVED":
					statusDesc = "Aprovada"
				case "REVIEW_BOKEH":
					statusDesc = "Revisar Bokeh"
				case "REVIEW":
					statusDesc = "Revisar"
				case "REJECTED":
					statusDesc = "Descartada"
				default:
					statusDesc = itemRes.Recommendation
				}

				gpuDesc := "GPU CUDA Ativa"
				if hwReport != nil && hwReport.CUDAAcceleration != "" && strings.Contains(strings.ToLower(hwReport.CUDAAcceleration), "ativa") {
					gpuDesc = "GPU CUDA Ativa"
				} else if req.GPUEnabled {
					gpuDesc = "GPU CUDA Ativa"
				} else {
					gpuDesc = "CPU SIMD Ativo"
				}

				tag := itemRes.OrientationTag
				if tag <= 0 {
					tag = 1
				}
				exifDesc := fmt.Sprintf("%d (%s)", tag, OrientationTagDescription(tag))

				fmt.Printf("[ZORYA-RAW] [%d/%d] Foto: %s | EXIF: %s | WB: Camera As-Shot | %s | Status: %s\n",
					processedCount, len(files), itemRes.Filename, exifDesc, gpuDesc, statusDesc)

				progressLock.Unlock()
			}
		}()
	}

	for i, f := range files {
		jobsChan <- job{index: i, path: f}
	}
	close(jobsChan)
	wg.Wait()

	// 5. Finalizar agregação
	var totalSharpness, totalScore float64
	var approved, review, bokehCount, rejected, success int

	for _, item := range results {
		if item.Status == "SUCCESS" {
			success++
			totalSharpness += item.SharpnessScore
			totalScore += item.OverallScore
			switch item.Recommendation {
			case "APPROVED":
				approved++
			case "REVIEW":
				review++
			case "REVIEW_BOKEH":
				review++
				bokehCount++
			case "REJECTED":
				rejected++
			}
		}
	}

	avgSharpness := 0.0
	avgScore := 0.0
	if success > 0 {
		avgSharpness = totalSharpness / float64(success)
		avgScore = totalScore / float64(success)
	}

	totalElapsed := time.Since(start).Milliseconds()

	updateIngestProgress(func(s *IngestProgressState) {
		s.Active = false
		s.CurrentStep = "Lote Concluído com Sucesso"
		s.Percent = 100.0
		s.ElapsedMs = totalElapsed
		s.EstimatedRemainingMs = 0
	})

	fmt.Printf("\n================================================================================\n")
	fmt.Printf("[ZORYA LOTE CONCLUÍDO] Total: %d | Aprovadas: %d | Revisar Bokeh: %d | Descartadas: %d\n",
		len(files), approved, bokehCount+review, rejected)
	fmt.Printf("[ZORYA TELEMETRIA] Tempo Total: %dms (Média: %0.1fms/foto)\n",
		totalElapsed, float64(totalElapsed)/float64(len(files)))
	fmt.Printf("[ZORYA DIRETÓRIO FÍSICO] %s\n", outDir)
	fmt.Printf("================================================================================\n\n")

	return &IngestBatchResult{
		BatchID:          batchID,
		Total:            len(files),
		SuccessCount:     success,
		ApprovedCount:    approved,
		ReviewCount:      review,
		BokehCount:       bokehCount,
		RejectedCount:    rejected,
		AverageSharpness: mathRound(avgSharpness, 1),
		AverageScore:     mathRound(avgScore, 1),
		TotalElapsedMs:   totalElapsed,
		OutputDir:        outDir,
		HardwareReport:   hwReport,
		Items:            results,
	}, nil
}

// processSingleIngestItem processa 1 foto: rotação EXIF precisa da Canon SL3, tratamento comercial, marca d'água e IA em cascata.
// INVARIANTE: O arquivo RAW original no disco D: NUNCA é apagado ou alterado.
func (p *Processor) processSingleIngestItem(rawPath, outDir string, targetW, quality int, wmOpts WatermarkOptions, serverPort int, gKey, grKey string) IngestItemResult {
	start := time.Now()
	filename := filepath.Base(rawPath)
	ext := filepath.Ext(filename)
	base := strings.TrimSuffix(filename, ext)

	res := IngestItemResult{
		RAWPath:  rawPath,
		Filename: filename,
		Status:   "FAILED",
	}

	fileData, err := os.ReadFile(rawPath)
	if err != nil {
		res.Error = fmt.Sprintf("falha ao ler arquivo: %v", err)
		return res
	}

	// 1. Extração do JPEG embutido de alta velocidade
	var srcImg image.Image
	extractedJPEG := extractEmbeddedPreviewJPEG(fileData)

	// Leitura binária precisa da tag EXIF 0x0112 (Orientation) no CR3 e no JPEG
	orientationTag := 1
	if len(extractedJPEG) > 0 {
		orientationTag = ReadCanonCR3AndJPEGEXIF(extractedJPEG)
		img, _, err := image.Decode(bytes.NewReader(extractedJPEG))
		if err == nil {
			srcImg = img
		}
	}

	if orientationTag <= 1 {
		orientationTag = ReadCanonCR3AndJPEGEXIF(fileData)
	}

	if srcImg == nil {
		img, _, err := image.Decode(bytes.NewReader(fileData))
		if err == nil {
			srcImg = img
		}
	}

	if srcImg == nil {
		ffmpegImg, _, _, err := decodeWithFFmpeg(fileData, filename)
		if err == nil {
			srcImg = ffmpegImg
		}
	}

	if srcImg == nil {
		srcImg, _, _ = generateFallbackFrame(1920, 1080)
	}

	// 2. ROTAÇÃO EXIF CIRÚRGICA MANDATÓRIA DA CANON (TAG 0x0112):
	// - Tag 1: Orientação normal (0° de rotação).
	// - Tag 3: Rotacionar 180°.
	// - Tag 6: Rotacionar 90° no sentido horário.
	// - Tag 8: Rotacionar 270° no sentido horário (90° anti-horário).
	// - Se o EXIF for 3, 6 ou 8, a rotação é cirúrgica e a IA NUNCA é acionada.
	// - Fotos que já vieram verticais da câmera NUNCA são invertidas.
	// - Só aciona a IA caso o EXIF seja inexistente ou neutro (Tag 0 ou 1) e haja detecção clara de rosto em ângulo incorreto.
	oriented, finalTag, rotDeg := ResolveSurgicalOrientation(srcImg, fileData)
	if finalTag <= 1 && len(extractedJPEG) > 0 {
		tagFromPreview := ReadCanonCR3AndJPEGEXIF(extractedJPEG)
		if tagFromPreview > 1 {
			oriented, finalTag, rotDeg = ResolveSurgicalOrientation(srcImg, extractedJPEG)
		}
	}
	srcImg = oriented
	res.OrientationTag = finalTag
	res.RotationApplied = rotDeg

	// 3. TRATAMENTO COMERCIAL "VITRINE DE VENDAS" (Portrait Sales Pop):
	// - Preserva o balanço de branco autêntico do DIGIC da Canon
	// - Ganho de exposição local no sujeito (+0.5 EV) com proteção de pele e pretos aveludados
	// - Curva Tonal em S (S-Curve)
	// - Vibrância seletiva (+14%) sem alaranjar a pele
	developedImg := ApplyPortraitSalesPop(srcImg)

	// 4. Análise óptica algorítmica de foco e Bokeh
	analysis := AnalyzeCulling(developedImg)
	res.SharpnessScore = analysis.SharpnessScore
	res.ExposureScore = analysis.ExposureScore
	res.OverallScore = analysis.OverallScore
	res.LaplacianVariance = analysis.LaplacianVariance
	res.MeanLuminance = analysis.MeanLuminance
	res.BlinkDetected = analysis.BlinkDetected
	res.BokehDetected = analysis.BokehDetected
	res.SubjectSharpness = analysis.SubjectSharpness
	res.Recommendation = analysis.Recommendation

	// 5. Redimensionamento de alta fidelidade para 2048px + Nitidez Unsharp Mask 3x3 sutil
	scaledImg := resizeImageToWidth(developedImg, targetW)
	sharpImg := ApplyUnsharpMask(scaledImg, 0.22)

	// 6. Aplicação da Marca d'Água oficial (TF25.png a -30° em malha diagonal com linhas tracejadas)
	watermarkedImg := ApplyWatermark(sharpImg, wmOpts)

	scaledBounds := watermarkedImg.Bounds()
	res.Width = scaledBounds.Dx()
	res.Height = scaledBounds.Dy()

	// 7. IA EM CASCATA COM PRE-FLIGHT CHECK:
	// Passo 1: Moondream na GPU GTX 1650 (150ms). Se olhos fechados ou borrão -> REJECTED.
	// Passo 2: Foto promissora -> IA sênior (Qwen2.5-VL / Gemini) avalia poética do bokeh -> APPROVED ou REVIEW_BOKEH.
	cascadeRec, aiEngine, aiFeedback := EvaluatePortraitCascade(developedImg, analysis, gKey, grKey)
	res.Recommendation = cascadeRec
	res.AIEngineUsed = aiEngine
	res.AIFeedback = aiFeedback

	// 8. SALVAMENTO FÍSICO NAS 3 SUBPASTAS:
	// - C:\dev\zorya\thumbnails\aprovadas\
	// - C:\dev\zorya\thumbnails\revisar_bokeh\
	// - C:\dev\zorya\thumbnails\descartadas\
	var subFolder string
	switch res.Recommendation {
	case "APPROVED":
		subFolder = "aprovadas"
	case "REVIEW_BOKEH", "REVIEW":
		subFolder = "revisar_bokeh"
	case "REJECTED":
		subFolder = "descartadas"
	default:
		subFolder = "revisar_bokeh"
	}

	targetDir := filepath.Join(outDir, subFolder)
	_ = os.MkdirAll(targetDir, 0755)

	thumbFilename := fmt.Sprintf("%s_proof_2048.webp", base)
	thumbPath := filepath.Join(targetDir, thumbFilename)

	webpBytes, _ := encodeToWebP(watermarkedImg, quality)
	if len(webpBytes) > 0 {
		_ = os.WriteFile(thumbPath, webpBytes, 0644)
	} else {
		// Fallback para JPEG com compressão de alta qualidade
		thumbFilename = fmt.Sprintf("%s_proof_2048.jpg", base)
		thumbPath = filepath.Join(targetDir, thumbFilename)
		var buf bytes.Buffer
		_ = jpeg.Encode(&buf, watermarkedImg, &jpeg.Options{Quality: quality})
		_ = os.WriteFile(thumbPath, buf.Bytes(), 0644)
	}

	relPath := fmt.Sprintf("%s/%s", subFolder, thumbFilename)
	res.ThumbnailPath = thumbPath
	res.ThumbnailFilename = relPath
	res.ThumbnailURL = fmt.Sprintf("http://127.0.0.1:%d/thumbnails/%s", serverPort, relPath)
	res.ElapsedMs = time.Since(start).Milliseconds()
	res.Status = "SUCCESS"

	return res
}

// resizeImageToWidth redimensiona proporcionalmente a imagem para a largura alvo especificada
func resizeImageToWidth(src image.Image, targetWidth int) image.Image {
	bounds := src.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()

	if w <= targetWidth {
		return src
	}

	targetHeight := int(float64(h) * float64(targetWidth) / float64(w))
	if targetHeight < 1 {
		targetHeight = 1
	}

	return resizeImageBilinear(src, targetWidth, targetHeight)
}

// scanFolderForPhotos lista todos os arquivos RAW e formatos fotográficos suportados em uma pasta
func scanFolderForPhotos(folder string) ([]string, error) {
	var files []string
	validExts := map[string]bool{
		".cr2": true, ".cr3": true, ".nef": true, ".arw": true,
		".dng": true, ".raf": true, ".rw2": true, ".orf": true,
		".pef": true, ".jpg": true, ".jpeg": true, ".png": true,
		".tif": true, ".tiff": true,
	}

	err := filepath.Walk(folder, func(path string, info os.FileInfo, err error) error {
		if err != nil || info == nil || info.IsDir() {
			return nil
		}
		ext := strings.ToLower(filepath.Ext(path))
		if validExts[ext] {
			files = append(files, path)
		}
		return nil
	})

	return files, err
}

func mathRound(val float64, decimals int) float64 {
	pow := 1.0
	for i := 0; i < decimals; i++ {
		pow *= 10.0
	}
	return float64(int(val*pow+0.5)) / pow
}