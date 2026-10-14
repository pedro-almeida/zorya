package api

import (
	"strings"
	"testing"
)

func TestFormatZoryaRAWProgressLog(t *testing.T) {
	logLine := FormatZoryaRAWProgressLog(1, 10, "IMG_7050.CR3", 6, "Camera As-Shot", "GPU CUDA Ativa", "APPROVED")

	expected := "[ZORYA-RAW] [1/10] Foto: IMG_7050.CR3 | EXIF: 6 (Rot 90° CW) | WB: Camera As-Shot | GPU CUDA Ativa | Status: Aprovada"
	if logLine != expected {
		t.Fatalf("expected:\n%s\ngot:\n%s", expected, logLine)
	}

	logLine2 := FormatZoryaRAWProgressLog(2, 10, "IMG_6894.CR3", 1, "", "", "REJECTED")
	if !strings.Contains(logLine2, "Status: Descartada") {
		t.Fatalf("expected status Descartada, got: %s", logLine2)
	}
	if !strings.Contains(logLine2, "WB: Camera As-Shot") {
		t.Fatalf("expected default Camera As-Shot WB, got: %s", logLine2)
	}
	if !strings.Contains(logLine2, "EXIF: 1 (Normal 0°)") {
		t.Fatalf("expected EXIF 1 (Normal 0°), got: %s", logLine2)
	}
}

func TestTranslateStatus(t *testing.T) {
	if TranslateStatus("APPROVED") != "Aprovada" {
		t.Fatalf("expected Aprovada, got %s", TranslateStatus("APPROVED"))
	}
	if TranslateStatus("REVIEW_BOKEH") != "Revisar Bokeh" {
		t.Fatalf("expected Revisar Bokeh, got %s", TranslateStatus("REVIEW_BOKEH"))
	}
	if TranslateStatus("REVIEW") != "Revisar" {
		t.Fatalf("expected Revisar, got %s", TranslateStatus("REVIEW"))
	}
	if TranslateStatus("REJECTED") != "Descartada" {
		t.Fatalf("expected Descartada, got %s", TranslateStatus("REJECTED"))
	}
}
