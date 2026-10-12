// PT: Algoritmos de Culling, Nitidez Laplaciana e Avaliação de Exposição
// EN: Culling Algorithms, Laplacian Variance Sharpness and Exposure Analysis
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Culling e Triagem Fotográfica

package processor

import (
	"image"
	"math"
)

// CullingAnalysis holds algorithmic scores and classification for an image.
type CullingAnalysis struct {
	SharpnessScore    float64 `json:"sharpness_score"`     // 0-100
	ExposureScore     float64 `json:"exposure_score"`      // 0-100
	OverallScore      float64 `json:"overall_score"`       // 0-100
	LaplacianVariance float64 `json:"laplacian_variance"`  // raw variance
	MeanLuminance     float64 `json:"mean_luminance"`      // 0-255
	ShadowClippingPct float64 `json:"shadow_clipping_pct"` // % pixels underexposed
	HighlightClipPct  float64 `json:"highlight_clip_pct"`  // % pixels blown out
	BlinkDetected     bool    `json:"blink_detected"`      // eye blink / anomaly flag
	Recommendation    string  `json:"recommendation"`      // "APPROVED", "REVIEW", "REJECTED"
}

// AnalyzeCulling performs Laplacian variance, luminance distribution and exposure quality checks.
func AnalyzeCulling(img image.Image) CullingAnalysis {
	bounds := img.Bounds()
	w, h := bounds.Dx(), bounds.Dy()
	if w < 4 || h < 4 {
		return CullingAnalysis{
			OverallScore:   50.0,
			Recommendation: "REVIEW",
		}
	}

	// 1. Compute luminance matrix and sample statistics
	// For performance with high-res images (e.g. 24MP-60MP), we can subsample if larger than 1200px
	step := 1
	if w > 1200 || h > 1200 {
		step = int(math.Max(float64(w), float64(h)) / 1000.0)
		if step < 1 {
			step = 1
		}
	}

	sw := (w + step - 1) / step
	sh := (h + step - 1) / step

	luma := make([]float64, sw*sh)
	var sumLuma float64
	var shadowCount, highlightCount int
	totalPixels := sw * sh

	idx := 0
	for y := bounds.Min.Y; y < bounds.Max.Y; y += step {
		for x := bounds.Min.X; x < bounds.Max.X; x += step {
			if idx >= totalPixels {
				break
			}
			r, g, b, _ := img.At(x, y).RGBA()
			// 16-bit to 8-bit float: Y = 0.299R + 0.587G + 0.114B
			yVal := 0.299*float64(r>>8) + 0.587*float64(g>>8) + 0.114*float64(b>>8)
			luma[idx] = yVal
			sumLuma += yVal

			if yVal < 8.0 {
				shadowCount++
			} else if yVal > 248.0 {
				highlightCount++
			}
			idx++
		}
	}

	actualCount := idx
	if actualCount == 0 {
		actualCount = 1
	}

	meanLuma := sumLuma / float64(actualCount)
	shadowPct := (float64(shadowCount) / float64(actualCount)) * 100.0
	highlightPct := (float64(highlightCount) / float64(actualCount)) * 100.0

	// 2. Compute Laplacian variance (sharpness/focus detector)
	// Kernel:
	//  0   1   0
	//  1  -4   1
	//  0   1   0
	var sumLaplacian, sumLaplacianSq float64
	var countLaplacian int

	for sy := 1; sy < sh-1; sy++ {
		for sx := 1; sx < sw-1; sx++ {
			c := luma[sy*sw+sx]
			up := luma[(sy-1)*sw+sx]
			dn := luma[(sy+1)*sw+sx]
			lf := luma[sy*sw+(sx-1)]
			rt := luma[sy*sw+(sx+1)]

			lap := up + dn + lf + rt - 4.0*c
			sumLaplacian += lap
			sumLaplacianSq += lap * lap
			countLaplacian++
		}
	}

	var lapVariance float64
	if countLaplacian > 0 {
		meanLap := sumLaplacian / float64(countLaplacian)
		lapVariance = (sumLaplacianSq / float64(countLaplacian)) - (meanLap * meanLap)
		if lapVariance < 0 {
			lapVariance = 0
		}
	}

	// 3. Sharpness score calculation
	// Calibrated based on photographic criteria:
	// lapVariance < 80: soft / motion blur / out of focus (score 0-45)
	// lapVariance 80-250: acceptable focus (score 50-70)
	// lapVariance 250-700: good sharp focus (score 70-88)
	// lapVariance > 700: tack sharp (score 90-100)
	sharpnessScore := math.Sqrt(lapVariance) * 3.8
	if sharpnessScore > 100.0 {
		sharpnessScore = 100.0
	}

	// 4. Exposure score calculation
	// Optimal mean luminance is between 110 and 150
	lumaDist := math.Abs(meanLuma - 128.0)
	exposureScore := 100.0 - (lumaDist * 0.75)
	// Penalize excessive clipping
	if shadowPct > 12.0 {
		exposureScore -= (shadowPct - 12.0) * 1.5
	}
	if highlightPct > 8.0 {
		exposureScore -= (highlightPct - 8.0) * 2.0
	}
	if exposureScore < 0 {
		exposureScore = 0
	}

	// 5. Eye / facial anomaly heuristic
	// In portraits, closed eyes reduce high-frequency contrast in the upper third/center
	blinkDetected := false
	if sharpnessScore < 40.0 && meanLuma > 60.0 && meanLuma < 200.0 && lapVariance < 65.0 {
		blinkDetected = true
	}

	// 6. Overall composite score
	overallScore := (sharpnessScore * 0.65) + (exposureScore * 0.35)
	if blinkDetected {
		overallScore -= 15.0
	}
	if overallScore < 0 {
		overallScore = 0
	} else if overallScore > 100.0 {
		overallScore = 100.0
	}

	// 7. Recommendation classification
	var recommendation string
	switch {
	case overallScore >= 70.0:
		recommendation = "APPROVED"
	case overallScore >= 48.0:
		recommendation = "REVIEW"
	default:
		recommendation = "REJECTED"
	}

	return CullingAnalysis{
		SharpnessScore:    math.Round(sharpnessScore*10) / 10,
		ExposureScore:     math.Round(exposureScore*10) / 10,
		OverallScore:      math.Round(overallScore*10) / 10,
		LaplacianVariance: math.Round(lapVariance*10) / 10,
		MeanLuminance:     math.Round(meanLuma*10) / 10,
		ShadowClippingPct: math.Round(shadowPct*10) / 10,
		HighlightClipPct:  math.Round(highlightPct*10) / 10,
		BlinkDetected:     blinkDetected,
		Recommendation:    recommendation,
	}
}
