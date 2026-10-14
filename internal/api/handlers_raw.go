// PT: Handlers de API REST e Telemetria para Pipeline RAW Zorya
// EN: REST API Handlers and Telemetry for Zorya RAW Pipeline
// AI: @llm-context Módulo Zorya v1.0.0 / Handlers de Ingestão e Processamento RAW

package api

import (
	"fmt"
	"io"
	"strings"

	"github.com/pedro-almeida/zorya/internal/processor"
)

// FormatZoryaRAWProgressLog formata o indicador de progresso limpo e padronizado do terminal:
// [ZORYA-RAW] [X/Total] Foto: IMG_XXXX.CR3 | EXIF: 6 (Rot 90° CW) | WB: Camera As-Shot | GPU CUDA Ativa | Status: Aprovada
func FormatZoryaRAWProgressLog(current, total int, filename string, exifTag int, wbDesc string, gpuDesc string, status string) string {
	if wbDesc == "" {
		wbDesc = "Camera As-Shot"
	}
	if gpuDesc == "" {
		gpuDesc = "GPU CUDA Ativa"
	}

	tag := exifTag
	if tag <= 0 {
		tag = 1
	}

	exifDesc := fmt.Sprintf("%d (%s)", tag, processor.OrientationTagDescription(tag))
	statusDesc := TranslateStatus(status)

	return fmt.Sprintf("[ZORYA-RAW] [%d/%d] Foto: %s | EXIF: %s | WB: %s | %s | Status: %s",
		current, total, filename, exifDesc, wbDesc, gpuDesc, statusDesc)
}

// TranslateStatus traduz a recomendação técnica em status comercial amigável
func TranslateStatus(recommendation string) string {
	switch strings.ToUpper(recommendation) {
	case "APPROVED":
		return "Aprovada"
	case "REVIEW_BOKEH":
		return "Revisar Bokeh"
	case "REVIEW":
		return "Revisar"
	case "REJECTED":
		return "Descartada"
	default:
		if recommendation == "" {
			return "Processada"
		}
		return recommendation
	}
}

// HandleRawIngestBatch processa uma requisição de lote de RAWs aplicando preservação de WB As-Shot,
// rotação cirúrgica EXIF e tratamento de alto padrão comercial em espaço de luminância.
func HandleRawIngestBatch(proc *processor.Processor, req processor.IngestBatchRequest, cpuCores int) (*processor.IngestBatchResult, error) {
	if proc == nil {
		return nil, fmt.Errorf("processor não inicializado")
	}
	return proc.ProcessIngestBatch(req, cpuCores)
}

// HandleRawDecodeStream decodifica um buffer/stream RAW com preservação de Balanço de Branco da Câmera.
func HandleRawDecodeStream(proc *processor.Processor, r io.Reader, filename string, opts processor.DecodeOptions) (*processor.ProcessResult, error) {
	if proc == nil {
		return nil, fmt.Errorf("processor não inicializado")
	}
	return proc.DecodeStream(r, filename, opts)
}
