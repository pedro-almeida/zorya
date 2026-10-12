package processor

import (
	"image"
	"image/color"
	"image/jpeg"
	"os"
	"path/filepath"
	"testing"
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
