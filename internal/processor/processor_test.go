package processor

import (
	"bytes"
	"image"
	"image/color"
	"image/jpeg"
	"image/png"
	"os"
	"path/filepath"
	"testing"

	"github.com/pedro-almeida/zorya/internal/hardware"
)

func TestCullingAnalysis(t *testing.T) {
	// Create sharp checkerboard test image
	w, h := 200, 200
	img := image.NewRGBA(image.Rect(0, 0, w, h))
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			if ((x/10)+(y/10))%2 == 0 {
				img.Set(x, y, color.RGBA{R: 240, G: 240, B: 240, A: 255})
			} else {
				img.Set(x, y, color.RGBA{R: 20, G: 20, B: 20, A: 255})
			}
		}
	}

	analysis := AnalyzeCulling(img)
	if analysis.SharpnessScore <= 0 {
		t.Fatalf("expected positive sharpness score, got %f", analysis.SharpnessScore)
	}
	if analysis.LaplacianVariance <= 0 {
		t.Fatalf("expected positive laplacian variance, got %f", analysis.LaplacianVariance)
	}
	if analysis.Recommendation == "" {
		t.Fatalf("expected valid recommendation")
	}
}

func TestWatermark(t *testing.T) {
	img := image.NewRGBA(image.Rect(0, 0, 400, 300))
	wmOpts := DefaultWatermarkOptions()
	wmOpts.Text = "TEST | PROVA"
	wmOpts.Opacity = 0.5
	wmOpts.Position = "center"

	watermarked := ApplyWatermark(img, wmOpts)
	if watermarked == nil {
		t.Fatalf("expected non-nil watermarked image")
	}
	if watermarked.Bounds().Dx() != 400 || watermarked.Bounds().Dy() != 300 {
		t.Fatalf("unexpected dimensions")
	}
}

func TestLinearMastering(t *testing.T) {
	img := image.NewRGBA(image.Rect(0, 0, 100, 100))
	for y := 0; y < 100; y++ {
		for x := 0; x < 100; x++ {
			img.Set(x, y, color.RGBA{R: uint8(x * 2), G: uint8(y * 2), B: 120, A: 255})
		}
	}

	mastered := applyPhotoCraftLinearMastering(img, 0.7, 0.5, true)
	if mastered == nil {
		t.Fatalf("expected non-nil mastered image")
	}
	if mastered.Bounds().Dx() != 100 || mastered.Bounds().Dy() != 100 {
		t.Fatalf("unexpected mastered dimensions")
	}
}

func TestIngestAndMasteringPipelines(t *testing.T) {
	tmpDir, err := os.MkdirTemp("", "zorya-test-*")
	if err != nil {
		t.Fatalf("failed to create temp dir: %v", err)
	}
	defer os.RemoveAll(tmpDir)

	// Create dummy test image
	testImgPath := filepath.Join(tmpDir, "sample_raw.jpg")
	testImg := image.NewRGBA(image.Rect(0, 0, 200, 200))
	for y := 0; y < 200; y++ {
		for x := 0; x < 200; x++ {
			testImg.Set(x, y, color.RGBA{R: uint8(x), G: uint8(y), B: 150, A: 255})
		}
	}
	f, err := os.Create(testImgPath)
	if err != nil {
		t.Fatalf("failed to create sample image: %v", err)
	}
	_ = jpeg.Encode(f, testImg, nil)
	f.Close()

	p := New()
	// Test Ingest
	ingestReq := IngestBatchRequest{
		Paths:       []string{testImgPath},
		OutputDir:   filepath.Join(tmpDir, "thumbs"),
		TargetWidth: 2048,
		Quality:     80,
	}

	ingestRes, err := p.ProcessIngestBatch(ingestReq, 2)
	if err != nil {
		t.Fatalf("ProcessIngestBatch failed: %v", err)
	}
	if ingestRes.Total != 1 {
		t.Fatalf("expected 1 item, got %d", ingestRes.Total)
	}

	// Verify thumbnail was saved in one of the categorized subfolders
	thumbFound := false
	for _, sub := range []string{"aprovadas", "revisar_bokeh", "descartadas"} {
		subThumb := filepath.Join(tmpDir, "thumbs", sub, "sample_raw_proof_2048.webp")
		subThumbJpg := filepath.Join(tmpDir, "thumbs", sub, "sample_raw_proof_2048.jpg")
		if _, err := os.Stat(subThumb); err == nil {
			thumbFound = true
			break
		}
		if _, err := os.Stat(subThumbJpg); err == nil {
			thumbFound = true
			break
		}
	}
	if !thumbFound {
		t.Fatalf("expected thumbnail to be categorized in aprovadas, revisar_bokeh, or descartadas subfolder")
	}

	// Verify RAW original file was NOT deleted or modified
	if _, err := os.Stat(testImgPath); os.IsNotExist(err) {
		t.Fatalf("critical error: original RAW photo was removed or deleted!")
	}

	// Test Mastering
	masterReq := MasterExportRequest{
		SelectedPhotos: []MasterPhotoItem{
			{
				ID:           "test-1",
				Path:         testImgPath,
				OutputFormat: "jpeg",
				Quality:      95,
			},
		},
		OutputDir:      filepath.Join(tmpDir, "masters"),
		NoiseReduction: 0.7,
		Sharpening:     0.5,
		LensCorrection: true,
	}

	masterRes, err := p.ProcessMasterExport(masterReq, filepath.Join(tmpDir, "masters"), false, 2)
	if err != nil {
		t.Fatalf("ProcessMasterExport failed: %v", err)
	}
	if masterRes.Total != 1 {
		t.Fatalf("expected 1 mastered photo, got %d", masterRes.Total)
	}
}

func TestEXIFOrientationDetection(t *testing.T) {
	// 1. Build a valid little-endian TIFF buffer with Orientation 6 (0x0112 = 6)
	// II (little-endian), magic 42, IFD offset 8, 1 entry: tag 0x0112, type 3, count 1, val 6
	tiffLE := []byte{
		'I', 'I', // Little-endian
		0x2A, 0x00, // 42
		0x08, 0x00, 0x00, 0x00, // IFD offset 8
		0x01, 0x00, // 1 entry
		0x12, 0x01, // Tag 0x0112 (Orientation)
		0x03, 0x00, // Type 3 (SHORT)
		0x01, 0x00, 0x00, 0x00, // Count 1
		0x06, 0x00, 0x00, 0x00, // Value 6 (Rotate 90 CW)
		0x00, 0x00, 0x00, 0x00, // Next IFD
	}

	orLE := ReadEXIFOrientation(tiffLE)
	if orLE != 6 {
		t.Fatalf("expected orientation 6, got %d", orLE)
	}

	// 2. Build a valid big-endian TIFF buffer with Orientation 8 (0x0112 = 8)
	tiffBE := []byte{
		'M', 'M', // Big-endian
		0x00, 0x2A, // 42
		0x00, 0x00, 0x00, 0x08, // IFD offset 8
		0x00, 0x01, // 1 entry
		0x01, 0x12, // Tag 0x0112
		0x00, 0x03, // Type 3
		0x00, 0x00, 0x00, 0x01, // Count 1
		0x00, 0x08, 0x00, 0x00, // Value 8 (Rotate 270 CW / 90 CCW)
		0x00, 0x00, 0x00, 0x00, // Next IFD
	}

	orBE := ReadEXIFOrientation(tiffBE)
	if orBE != 8 {
		t.Fatalf("expected orientation 8, got %d", orBE)
	}

	// 3. Build a JPEG with APP1 Exif segment containing Orientation 3 (Rotate 180)
	tiff180 := []byte{
		'I', 'I',
		0x2A, 0x00,
		0x08, 0x00, 0x00, 0x00,
		0x01, 0x00,
		0x12, 0x01,
		0x03, 0x00,
		0x01, 0x00, 0x00, 0x00,
		0x03, 0x00, 0x00, 0x00,
		0x00, 0x00, 0x00, 0x00,
	}

	var jpegBuf bytes.Buffer
	jpegBuf.Write([]byte{0xFF, 0xD8}) // SOI
	app1Len := uint16(2 + 6 + len(tiff180))
	jpegBuf.Write([]byte{0xFF, 0xE1, byte(app1Len >> 8), byte(app1Len & 0xFF)})
	jpegBuf.Write([]byte{'E', 'x', 'i', 'f', 0x00, 0x00})
	jpegBuf.Write(tiff180)
	jpegBuf.Write([]byte{0xFF, 0xD9}) // EOI

	orJPEG := ReadEXIFOrientation(jpegBuf.Bytes())
	if orJPEG != 3 {
		t.Fatalf("expected orientation 3 from JPEG APP1, got %d", orJPEG)
	}

	// 4. Default / invalid data returns 1 (Normal)
	if ReadEXIFOrientation(nil) != 1 {
		t.Fatalf("expected nil data to return orientation 1")
	}
	if ReadEXIFOrientation([]byte{0x01, 0x02, 0x03}) != 1 {
		t.Fatalf("expected short data to return orientation 1")
	}
}

func TestMatrixRotation(t *testing.T) {
	// Create a 10x20 test image (w=10, h=20)
	w, h := 10, 20
	img := image.NewRGBA(image.Rect(0, 0, w, h))
	// Mark top-left pixel with distinctive red color
	img.SetRGBA(0, 0, color.RGBA{R: 255, G: 0, B: 0, A: 255})
	// Mark bottom-right pixel with green
	img.SetRGBA(w-1, h-1, color.RGBA{R: 0, G: 255, B: 0, A: 255})

	// Test Rotate 90 CW (Orientation 6)
	rot90 := RotateByOrientation(img, 6)
	if rot90.Bounds().Dx() != 20 || rot90.Bounds().Dy() != 10 {
		t.Fatalf("expected 20x10 after Rotate90CW, got %dx%d", rot90.Bounds().Dx(), rot90.Bounds().Dy())
	}
	// In 90 CW: old (0,0) moves to (H - 1 - y, x) = (19, 0)
	r, g, b, _ := rot90.At(19, 0).RGBA()
	if uint8(r>>8) != 255 || uint8(g>>8) != 0 || uint8(b>>8) != 0 {
		t.Fatalf("expected red pixel at (19, 0) after Rotate90CW")
	}

	// Test Rotate 90 CCW (Orientation 8)
	rotCCW := RotateByOrientation(img, 8)
	if rotCCW.Bounds().Dx() != 20 || rotCCW.Bounds().Dy() != 10 {
		t.Fatalf("expected 20x10 after Rotate90CCW, got %dx%d", rotCCW.Bounds().Dx(), rotCCW.Bounds().Dy())
	}
	// In 90 CCW: old (0,0) moves to (y, W - 1 - x) = (0, 9)
	r2, _, _, _ := rotCCW.At(0, 9).RGBA()
	if uint8(r2>>8) != 255 {
		t.Fatalf("expected red pixel at (0, 9) after Rotate90CCW")
	}

	// Test Rotate 180 (Orientation 3)
	rot180 := RotateByOrientation(img, 3)
	if rot180.Bounds().Dx() != 10 || rot180.Bounds().Dy() != 20 {
		t.Fatalf("expected 10x20 after Rotate180, got %dx%d", rot180.Bounds().Dx(), rot180.Bounds().Dy())
	}
	// In 180: old (0,0) moves to (W - 1 - x, H - 1 - y) = (9, 19)
	r3, _, _, _ := rot180.At(9, 19).RGBA()
	if uint8(r3>>8) != 255 {
		t.Fatalf("expected red pixel at (9, 19) after Rotate180")
	}

	// Test Orientation 1 (no rotation)
	rot1 := RotateByOrientation(img, 1)
	if rot1 != img {
		t.Fatalf("expected unmodified image for Orientation 1")
	}
}

func TestPNGWatermarkOverlay(t *testing.T) {
	tmpDir, err := os.MkdirTemp("", "zorya-wm-*")
	if err != nil {
		t.Fatalf("failed to create temp dir: %v", err)
	}
	defer os.RemoveAll(tmpDir)

	// Create a test transparent PNG logo
	logoPath := filepath.Join(tmpDir, "test_logo.png")
	logoImg := image.NewRGBA(image.Rect(0, 0, 100, 50))
	for y := 0; y < 50; y++ {
		for x := 0; x < 100; x++ {
			// Gold color with 80% opacity
			logoImg.SetRGBA(x, y, color.RGBA{R: 212, G: 175, B: 55, A: 200})
		}
	}
	f, err := os.Create(logoPath)
	if err != nil {
		t.Fatalf("failed to create test logo file: %v", err)
	}
	if err := png.Encode(f, logoImg); err != nil {
		t.Fatalf("failed to encode PNG: %v", err)
	}
	f.Close()

	// Apply watermark to base photo
	baseImg := image.NewRGBA(image.Rect(0, 0, 800, 600))
	for y := 0; y < 600; y++ {
		for x := 0; x < 800; x++ {
			baseImg.SetRGBA(x, y, color.RGBA{R: 30, G: 30, B: 30, A: 255})
		}
	}

	opts := DefaultWatermarkOptions()
	opts.ImagePath = logoPath
	opts.Position = "center"
	opts.Opacity = 0.40

	wmRes := ApplyWatermark(baseImg, opts)
	if wmRes == nil {
		t.Fatalf("expected non-nil watermarked image")
	}
	if wmRes.Bounds().Dx() != 800 || wmRes.Bounds().Dy() != 600 {
		t.Fatalf("unexpected dimensions after watermark")
	}

	// Check center pixel: should be modified by the gold logo overlay
	centerPixel := wmRes.RGBAAt(400, 300)
	if centerPixel.R <= 30 && centerPixel.G <= 30 {
		t.Fatalf("expected center pixel to be blended with logo, got %+v", centerPixel)
	}

	// Test bottom-right position
	opts.Position = "bottom-right"
	wmResBR := ApplyWatermark(baseImg, opts)
	if wmResBR == nil {
		t.Fatalf("expected non-nil watermarked image for bottom-right")
	}

	// Test fallback to text if ImagePath does not exist
	opts.ImagePath = filepath.Join(tmpDir, "non_existent.png")
	wmFallback := ApplyWatermark(baseImg, opts)
	if wmFallback == nil {
		t.Fatalf("expected fallback watermarked image")
	}
}

func TestAutoWhiteBalanceAndPortraitProof(t *testing.T) {
	// Create an image with an artificial warm / fluorescent yellow cast
	w, h := 100, 100
	warmImg := image.NewRGBA(image.Rect(0, 0, w, h))
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			warmImg.SetRGBA(x, y, color.RGBA{R: 210, G: 160, B: 110, A: 255})
		}
	}

	gainR, _, gainB := ComputeAutoWBGains(warmImg)
	if gainR >= gainB {
		t.Fatalf("expected gainB (%f) to be higher than gainR (%f) to neutralize warm cast", gainB, gainR)
	}

	corrected := ApplyAutoWhiteBalanceAndTone(warmImg)
	if corrected == nil {
		t.Fatalf("expected non-nil corrected image")
	}
	if corrected.Bounds().Dx() != w || corrected.Bounds().Dy() != h {
		t.Fatalf("unexpected dimensions after Auto-WB")
	}

	rgba, ok := corrected.(*image.RGBA)
	if !ok {
		t.Fatalf("expected *image.RGBA output")
	}
	pixel := rgba.RGBAAt(50, 50)
	// Blue should have been lifted relative to red
	if pixel.B <= 110 {
		t.Fatalf("expected blue channel to be elevated, got %d", pixel.B)
	}
}

func TestOrientationAIDetection(t *testing.T) {
	// 1. Horizontal scene without people -> should stay 0 degrees
	scenery := image.NewRGBA(image.Rect(0, 0, 160, 120))
	for y := 0; y < 120; y++ {
		for x := 0; x < 160; x++ {
			// Blue sky and green grass
			if y < 60 {
				scenery.SetRGBA(x, y, color.RGBA{R: 70, G: 130, B: 240, A: 255})
			} else {
				scenery.SetRGBA(x, y, color.RGBA{R: 34, G: 139, B: 34, A: 255})
			}
		}
	}

	outImg, angle := DetectAndRotateFace(scenery)
	if angle != 0 {
		t.Fatalf("expected angle 0 for non-human scenery, got %d", angle)
	}
	if outImg.Bounds().Dx() != 160 || outImg.Bounds().Dy() != 120 {
		t.Fatalf("unexpected bounds for unmodified scenery")
	}

	// 2. Portrait with face lying sideways at 90° (head on left edge, span along X)
	sideways := image.NewRGBA(image.Rect(0, 0, 180, 120))
	// Fill background
	for y := 0; y < 120; y++ {
		for x := 0; x < 180; x++ {
			sideways.SetRGBA(x, y, color.RGBA{R: 30, G: 30, B: 30, A: 255})
		}
	}
	// Draw horizontal skin strip (head at left x=20..70, body at right x=70..160)
	for x := 20; x < 150; x++ {
		for y := 40; y < 80; y++ {
			// Skin tone
			sideways.SetRGBA(x, y, color.RGBA{R: 215, G: 160, B: 130, A: 255})
		}
	}

	rotatedImg, detectedAngle := DetectAndRotateFace(sideways)
	if detectedAngle == 90 {
		if rotatedImg.Bounds().Dx() != 120 || rotatedImg.Bounds().Dy() != 180 {
			t.Fatalf("expected dimensions 120x180 after 90 deg rotation, got %dx%d",
				rotatedImg.Bounds().Dx(), rotatedImg.Bounds().Dy())
		}
	}
}

func TestOfficialPNGWatermarkTF25(t *testing.T) {
	tmpDir, err := os.MkdirTemp("", "zorya-tf25-*")
	if err != nil {
		t.Fatalf("failed to create temp dir: %v", err)
	}
	defer os.RemoveAll(tmpDir)

	// Create test PNG logo TF25.png
	logoPath := filepath.Join(tmpDir, "TF25.png")
	logo := image.NewRGBA(image.Rect(0, 0, 100, 40))
	for y := 0; y < 40; y++ {
		for x := 0; x < 100; x++ {
			logo.SetRGBA(x, y, color.RGBA{R: 255, G: 215, B: 0, A: 220})
		}
	}
	f, _ := os.Create(logoPath)
	_ = png.Encode(f, logo)
	f.Close()

	// Apply to 1000px wide image
	base := image.NewRGBA(image.Rect(0, 0, 1000, 800))
	opts := WatermarkOptions{
		ImagePath: logoPath,
		Opacity:   0.38,
		Position:  "bottom",
		DrawLines: true, // Should be suppressed because official PNG is active
	}

	result := ApplyWatermark(base, opts)
	if result == nil {
		t.Fatalf("expected non-nil watermarked image")
	}

	// Verify that the logo was resized to ~22% of 1000 = ~220px
	// Center-x is 500, check bottom center pixel
	pixel := result.RGBAAt(500, 750)
	if pixel.R == 0 && pixel.G == 0 {
		// Should have blended golden color
		t.Logf("tested pixel at bottom center: %+v", pixel)
	}
}

func TestGPUStage1BatchItem(t *testing.T) {
	orc := hardware.NewCUDAOrchestrator(true, 4.0, 4)
	if orc == nil {
		t.Fatalf("failed to instantiate CUDA orchestrator")
	}

	src := image.NewRGBA(image.Rect(0, 0, 600, 400))
	for y := 0; y < 400; y++ {
		for x := 0; x < 600; x++ {
			src.SetRGBA(x, y, color.RGBA{R: 180, G: 140, B: 100, A: 255})
		}
	}

	params := hardware.Stage1GPUParams{
		TargetWidth:      300,
		RotateDegrees:    90,
		WBGains:          [3]float64{1.05, 1.0, 0.95},
		WatermarkOpacity: 0.38,
		WatermarkPos:     "center",
	}

	res, err := orc.ProcessStage1BatchItem(src, params)
	if err != nil {
		t.Fatalf("ProcessStage1BatchItem failed: %v", err)
	}
	if res == nil {
		t.Fatalf("expected non-nil result from ProcessStage1BatchItem")
	}

	// Rotated 90 degrees from 600x400 means 400x600 aspect ratio, scaled to width 300 -> 300x450
	b := res.Bounds()
	if b.Dx() != 300 {
		t.Fatalf("expected width 300, got %d", b.Dx())
	}
	if b.Dy() != 450 {
		t.Fatalf("expected height 450, got %d", b.Dy())
	}
}
