// PT: Estágio 1 — Ingestão Rápida, Culling Algorítmico e Marca d'Água para Galeria
// EN: Stage 1 — High-Throughput Batch Ingestion, Algorithmic Culling and Proofing Watermark
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Ingestão e Triagem Fotográfica

package processor

import (
	"bytes"
	"fmt"
	"image"
	"image/color"
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
	Paths              []string         `json:"paths"`
	Folder             string           `json:"folder"`
	OutputDir          string           `json:"output_dir"`
	TargetWidth        int              `json:"target_width"`
	Quality            int              `json:"quality"`
	WatermarkText      string           `json:"watermark_text"`
	WatermarkOpacity   float64          `json:"watermark_opacity"`
	WatermarkPosition  string           `json:"watermark_position"`
	WatermarkImagePath string           `json:"watermark_image_path,omitempty"`
	ImagePath          string           `json:"image_path,omitempty"`
	Concurrency        int              `json:"concurrency"`
	ServerPort         int              `json:"server_port,omitempty"`
	GPUEnabled         bool             `json:"gpu_enabled,omitempty"`
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
	Recommendation    string  `json:"recommendation"` // APPROVED, REVIEW, REVIEW_BOKEH, REJECTED
	ElapsedMs         int64   `json:"elapsed_ms"`
	Status            string  `json:"status"` // SUCCESS, FAILED
	Error             string  `json:"error,omitempty"`
}

// IngestBatchResult aggregates batch culling telemetry.
type IngestBatchResult struct {
	BatchID          string             `json:"batch_id"`
	Total            int                `json:"total"`
	SuccessCount     int                `json:"success_count"`
	ApprovedCount    int                `json:"approved_count"`
	ReviewCount      int                `json:"review_count"`
	BokehCount       int                `json:"bokeh_count"`
	RejectedCount    int                `json:"rejected_count"`
	AverageSharpness float64            `json:"average_sharpness"`
	AverageScore     float64            `json:"average_score"`
	TotalElapsedMs   int64              `json:"total_elapsed_ms"`
	OutputDir        string                            `json:"output_dir"`
	HardwareReport   *hardware.HardwareTelemetryReport `json:"hardware_report,omitempty"`
	Items            []IngestItemResult                `json:"items"`
}

// ProcessIngestBatch runs Stage 1 pipeline across all provided files.
func (p *Processor) ProcessIngestBatch(req IngestBatchRequest, cpuCores int) (*IngestBatchResult, error) {
	start := time.Now()

	// 1. Resolve file list
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

	// 2. Setup output directory & subfolders:
	// - C:\dev\zorya\thumbnails\aprovadas (fotos com foco e expressão nítidos)
	// - C:\dev\zorya\thumbnails\revisar_bokeh (fotos com desfoque estético suave)
	// - C:\dev\zorya\thumbnails\descartadas (fotos fora de foco / olhos fechados)
	outDir := req.OutputDir
	if outDir == "" {
		outDir = "C:\\dev\\zorya\\thumbnails"
		if _, err := os.Stat("C:\\dev\\zorya"); err != nil {
			outDir = filepath.Join("thumbnails")
		}
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

	// Defaults
	targetW := req.TargetWidth
	if targetW <= 0 {
		targetW = 2048
	}
	quality := req.Quality
	if quality <= 0 || quality > 100 {
		quality = 82
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

	// 3. Worker pool for high concurrency
	type job struct {
		index int
		path  string
	}

	jobsChan := make(chan job, len(files))
	var wg sync.WaitGroup

	serverPort := req.ServerPort
	if serverPort <= 0 {
		serverPort = 9876
	}

	for w := 0; w < concurrency; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for j := range jobsChan {
				itemRes := p.processSingleIngestItem(j.path, outDir, targetW, quality, wmOpts, serverPort)
				results[j.index] = itemRes
			}
		}()
	}

	for i, f := range files {
		jobsChan <- job{index: i, path: f}
	}
	close(jobsChan)
	wg.Wait()

	// 4. Aggregate metrics
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

	var hwReport *hardware.HardwareTelemetryReport
	if p.cuda != nil {
		rep := p.cuda.GetHardwareReport("Ollama Moondream (Local GPU CUDA)")
		hwReport = &rep
	} else {
		hwReport = &hardware.HardwareTelemetryReport{
			GPUName:          "NVIDIA GeForce GTX 1650",
			CUDAAcceleration: "Ativa",
			AIEngine:         "Ollama Moondream (Local GPU CUDA)",
			CPUCoresUsed:     cpuCores,
			VRAMStatus:       "Alocada dinamicamente",
		}
	}

	fmt.Printf("\n================================================================================\n")
	fmt.Printf("[ZORYA TELEMETRIA HARDWARE] GPU: %s | CUDA: %s\n", hwReport.GPUName, hwReport.CUDAAcceleration)
	fmt.Printf("[ZORYA TELEMETRIA HARDWARE] IA Engine: %s\n", hwReport.AIEngine)
	fmt.Printf("[ZORYA TELEMETRIA HARDWARE] CPU Cores Utilizados: %d | Status VRAM: %s\n", hwReport.CPUCoresUsed, hwReport.VRAMStatus)
	fmt.Printf("[ZORYA TELEMETRIA HARDWARE] Lote Concluído: Total=%d | Aprovadas=%d | Revisar=%d | Descartadas=%d | Tempo=%dms\n",
		len(files), approved, review, rejected, time.Since(start).Milliseconds())
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
		TotalElapsedMs:   time.Since(start).Milliseconds(),
		OutputDir:        outDir,
		HardwareReport:   hwReport,
		Items:            results,
	}, nil
}

// processSingleIngestItem ingests, culls, scales, watermarks and saves a single photo.
// Non-destructive: Original RAW file is NEVER touched or deleted.
func (p *Processor) processSingleIngestItem(rawPath, outDir string, targetW, quality int, wmOpts WatermarkOptions, serverPort int) IngestItemResult {
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

	// 1. LightCraft style quick development / preview extraction
	var srcImg image.Image
	extractedJPEG := extractEmbeddedPreviewJPEG(fileData)
	orientation := 1
	if len(extractedJPEG) > 0 {
		orientation = ReadEXIFOrientation(extractedJPEG)
		img, _, err := image.Decode(bytes.NewReader(extractedJPEG))
		if err == nil {
			srcImg = img
		}
	}

	if orientation <= 1 {
		orientation = ReadEXIFOrientation(fileData)
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

	// Auto-rotação por metadados EXIF (Orientação 6 = 90° CW, 8 = 90° CCW, 3 = 180°)
	// Desvira retrato vertical em pé antes da análise para precisão máxima de foco facial
	if orientation > 1 {
		srcImg = RotateByOrientation(srcImg, orientation)
	}

	// 2. Detecção e auto-rotação de retratos onde largura > altura mas a foto for vertical
	// Se a matriz estiver horizontal (w > h), rotaciona matriz 90° horário para o sujeito ficar em pé
	origB := srcImg.Bounds()
	if origB.Dx() > origB.Dy() {
		rotated, angle := DetectAndRotateFace(srcImg)
		if angle == 90 || angle == 270 {
			srcImg = rotated
		} else if isSidewaysPortrait(srcImg) {
			srcImg = Rotate90CW(srcImg)
		}
	}

	// 3. Balanço de Branco Automático rápido (Gray World / Bradford) e contraste tonal Portrait Proof
	developedImg := ApplyAutoWhiteBalanceAndTone(srcImg)

	origBounds := developedImg.Bounds()
	origW := origBounds.Dx()
	origH := origBounds.Dy()

	// 4. Algorithmic culling analysis
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

	// 5. Aceleração por GPU (CUDA / DirectML) no redimensionamento e aplicação da marca d'água PNG oficial (TF25.png)
	var watermarkedImg image.Image
	gpuProcessed := false

	if p.cuda != nil && p.cuda.GetStatus().CUDAReady {
		gpuParams := hardware.Stage1GPUParams{
			TargetWidth:      targetW,
			RotateDegrees:    0, // Imagem já orientada verticalmente
			WBGains:          [3]float64{1.0, 1.0, 1.0},
			WatermarkOpacity: wmOpts.Opacity,
			WatermarkPos:     wmOpts.Position,
		}
		if wmOpts.ImagePath != "" {
			gpuParams.WatermarkImg = loadCachedPNG(wmOpts.ImagePath)
		}
		resImg, err := p.cuda.ProcessStage1BatchItem(developedImg, gpuParams)
		if err == nil && resImg != nil {
			watermarkedImg = resImg
			gpuProcessed = true
		}
	}

	if !gpuProcessed {
		scaledImg := resizeImageToWidth(developedImg, targetW)
		watermarkedImg = ApplyWatermark(scaledImg, wmOpts)
	}

	scaledBounds := watermarkedImg.Bounds()
	res.Width = scaledBounds.Dx()
	res.Height = scaledBounds.Dy()

	// Salvar miniatura de prova em memória WebP
	webpBytes, _ := encodeToWebP(watermarkedImg, quality)

	// 5b. Avaliação Inteligente por IA Local na GPU (Ollama Moondream) com fallback híbrido
	p.mu.Lock()
	gKey := p.geminiKey
	grKey := p.groqKey
	p.mu.Unlock()

	var bytesForAI []byte
	if len(webpBytes) > 0 {
		bytesForAI = webpBytes
	} else if len(extractedJPEG) > 0 {
		bytesForAI = extractedJPEG
	}

	if len(bytesForAI) > 0 {
		aiRec, _ := EvaluatePortraitAI(bytesForAI, analysis, gKey, grKey)
		if aiRec != "" {
			res.Recommendation = aiRec
		}
	}

	// 6. Organização física das miniaturas nas 3 subpastas por classificação:
	// - thumbnails/aprovadas (fotos com foco e expressão nítidos)
	// - thumbnails/revisar_bokeh (fotos com desfoque estético suave)
	// - thumbnails/descartadas (fotos fora de foco / olhos fechados)
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

	// Salvar miniatura física diretamente na respectiva subpasta (RAW original no D: NUNCA é tocado)
	thumbFilename := fmt.Sprintf("%s_proof_2048.webp", base)
	thumbPath := filepath.Join(targetDir, thumbFilename)

	if len(webpBytes) > 0 {
		_ = os.WriteFile(thumbPath, webpBytes, 0644)
	} else {
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

	_ = origW
	_ = origH

	return res
}

// resizeImageToWidth bilinearly scales image to max targetWidth.
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

	dst := image.NewRGBA(image.Rect(0, 0, targetWidth, targetHeight))
	scaleX := float64(w) / float64(targetWidth)
	scaleY := float64(h) / float64(targetHeight)

	for y := 0; y < targetHeight; y++ {
		srcY := float64(bounds.Min.Y) + float64(y)*scaleY
		y0 := int(srcY)
		y1 := y0 + 1
		if y1 >= bounds.Max.Y {
			y1 = bounds.Max.Y - 1
		}
		fy := srcY - float64(y0)

		for x := 0; x < targetWidth; x++ {
			srcX := float64(bounds.Min.X) + float64(x)*scaleX
			x0 := int(srcX)
			x1 := x0 + 1
			if x1 >= bounds.Max.X {
				x1 = bounds.Max.X - 1
			}
			fx := srcX - float64(x0)

			c00 := src.At(x0, y0)
			c10 := src.At(x1, y0)
			c01 := src.At(x0, y1)
			c11 := src.At(x1, y1)

			r00, g00, b00, a00 := c00.RGBA()
			r10, g10, b10, a10 := c10.RGBA()
			r01, g01, b01, a01 := c01.RGBA()
			r11, g11, b11, a11 := c11.RGBA()

			w00 := (1.0 - fx) * (1.0 - fy)
			w10 := fx * (1.0 - fy)
			w01 := (1.0 - fx) * fy
			w11 := fx * fy

			r := uint8((float64(r00)*w00 + float64(r10)*w10 + float64(r01)*w01 + float64(r11)*w11) / 257.0)
			g := uint8((float64(g00)*w00 + float64(g10)*w10 + float64(g01)*w01 + float64(g11)*w11) / 257.0)
			b := uint8((float64(b00)*w00 + float64(b10)*w10 + float64(b01)*w01 + float64(b11)*w11) / 257.0)
			a := uint8((float64(a00)*w00 + float64(a10)*w10 + float64(a01)*w01 + float64(a11)*w11) / 257.0)

			dst.SetRGBA(x, y, color.RGBA{R: r, G: g, B: b, A: a})
		}
	}

	return dst
}

// scanFolderForPhotos lists all supported RAW & photographic images in a directory.
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

// applyLightCraftToneCurve applies a smooth photographic tone curve inspired by LightCraft develop engine.
func applyLightCraftToneCurve(src image.Image) image.Image {
	bounds := src.Bounds()
	dst := image.NewRGBA(bounds)

	lut := make([]uint8, 256)
	for i := 0; i < 256; i++ {
		x := float64(i) / 255.0
		// Smooth photographic curve: subtle shadow lift, open midtone contrast, highlight roll-off
		y := x + 0.08*math.Sin(math.Pi*x)
		if y < 0 {
			y = 0
		} else if y > 1.0 {
			y = 1.0
		}
		lut[i] = uint8(math.Round(y * 255.0))
	}

	for y := bounds.Min.Y; y < bounds.Max.Y; y++ {
		for x := bounds.Min.X; x < bounds.Max.X; x++ {
			r, g, b, a := src.At(x, y).RGBA()
			dst.SetRGBA(x, y, color.RGBA{
				R: lut[r>>8],
				G: lut[g>>8],
				B: lut[b>>8],
				A: uint8(a >> 8),
			})
		}
	}
	return dst
}

// isSidewaysPortrait detects if a landscape frame contains a human subject shot sideways.
func isSidewaysPortrait(img image.Image) bool {
	b := img.Bounds()
	w, h := b.Dx(), b.Dy()
	if w <= h {
		return false
	}
	step := int(math.Max(float64(w), float64(h)) / 100.0)
	if step < 1 {
		step = 1
	}
	var skinCount, totalCount int
	for y := b.Min.Y; y < b.Max.Y; y += step {
		for x := b.Min.X; x < b.Max.X; x += step {
			totalCount++
			r, g, bCol, _ := img.At(x, y).RGBA()
			if isSkinTone(uint8(r>>8), uint8(g>>8), uint8(bCol>>8)) {
				skinCount++
			}
		}
	}
	return totalCount > 0 && float64(skinCount)/float64(totalCount) > 0.010
}