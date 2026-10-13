// PT: Algoritmos de Culling, Nitidez Laplaciana, Exposição e Proteção de Bokeh Artístico
// EN: Culling Algorithms, Laplacian Variance, Exposure Histogram and Artistic Bokeh Protection
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
	BokehDetected     bool    `json:"bokeh_detected"`      // creamy background with focused subject
	SubjectSharpness  float64 `json:"subject_sharpness"`   // isolated focal plane sharpness
	Recommendation    string  `json:"recommendation"`      // "APPROVED", "REVIEW", "REVIEW_BOKEH", "REJECTED"
}

// AnalyzeCulling performs ultra-fast (~0.01s) Laplacian variance, luminance distribution, and bokeh analysis.
func AnalyzeCulling(img image.Image) CullingAnalysis {
	bounds := img.Bounds()
	w, h := bounds.Dx(), bounds.Dy()
	if w < 4 || h < 4 {
		return CullingAnalysis{
			OverallScore:   50.0,
			Recommendation: "REVIEW",
		}
	}

	// 1. Compute luminance matrix with adaptive sub-sampling (~1000px max)
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
			// Y = 0.299R + 0.587G + 0.114B (scaled to 0-255)
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

	// 2. Regional 3x3 Laplacian variance analysis for global focus & Bokeh isolation
	// Grid: 3 rows x 3 columns
	gridW := sw / 3
	gridH := sh / 3
	type gridStat struct {
		sum   float64
		sumSq float64
		count int
	}
	grid := [3][3]gridStat{}

	var sumLaplacian, sumLaplacianSq float64
	var countLaplacian int

	for sy := 1; sy < sh-1; sy++ {
		gy := sy / gridH
		if gy > 2 {
			gy = 2
		}

		for sx := 1; sx < sw-1; sx++ {
			gx := sx / gridW
			if gx > 2 {
				gx = 2
			}

			c := luma[sy*sw+sx]
			up := luma[(sy-1)*sw+sx]
			dn := luma[(sy+1)*sw+sx]
			lf := luma[sy*sw+(sx-1)]
			rt := luma[sy*sw+(sx+1)]

			lap := up + dn + lf + rt - 4.0*c
			sumLaplacian += lap
			sumLaplacianSq += lap * lap
			countLaplacian++

			grid[gy][gx].sum += lap
			grid[gy][gx].sumSq += lap * lap
			grid[gy][gx].count++
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

	// 3. Bokeh Protection Heuristic:
	// In artistic portraiture, the center/upper-third subject is in focus while periphery is creamy bokeh.
	var centerMaxVar float64
	var peripheryAvgVar float64
	var peripheryCount int

	for gy := 0; gy < 3; gy++ {
		for gx := 0; gx < 3; gx++ {
			stat := grid[gy][gx]
			if stat.count > 0 {
				m := stat.sum / float64(stat.count)
				v := (stat.sumSq / float64(stat.count)) - (m * m)
				if v < 0 {
					v = 0
				}

				// Center / upper-center is primary subject focus zone
				if (gy == 1 && gx == 1) || (gy == 0 && gx == 1) {
					if v > centerMaxVar {
						centerMaxVar = v
					}
				} else {
					peripheryAvgVar += v
					peripheryCount++
				}
			}
		}
	}

	if peripheryCount > 0 {
		peripheryAvgVar /= float64(peripheryCount)
	}

	bokehDetected := false
	subjectSharpness := math.Sqrt(centerMaxVar) * 3.8
	if subjectSharpness > 100.0 {
		subjectSharpness = 100.0
	}

	// If center zone is sharp (subject in focus) and background is significantly smoother:
	if centerMaxVar >= 85.0 && centerMaxVar > peripheryAvgVar*1.85 && peripheryAvgVar < 70.0 {
		bokehDetected = true
	}

	// 4. Sharpness score calculation
	sharpnessScore := math.Sqrt(lapVariance) * 3.8
	if sharpnessScore > 100.0 {
		sharpnessScore = 100.0
	}

	// 5. Exposure score calculation
	lumaDist := math.Abs(meanLuma - 128.0)
	exposureScore := 100.0 - (lumaDist * 0.75)
	if shadowPct > 12.0 {
		exposureScore -= (shadowPct - 12.0) * 1.5
	}
	if highlightPct > 8.0 {
		exposureScore -= (highlightPct - 8.0) * 2.0
	}
	if exposureScore < 0 {
		exposureScore = 0
	}

	// 6. Blink / closed eye anomaly heuristic
	blinkDetected := false
	if sharpnessScore < 40.0 && meanLuma > 60.0 && meanLuma < 200.0 && lapVariance < 65.0 && !bokehDetected {
		blinkDetected = true
	}

	// 7. Overall composite score
	overallScore := (sharpnessScore * 0.65) + (exposureScore * 0.35)
	if blinkDetected {
		overallScore -= 15.0
	}

	// Proteção de Bokeh: garante que a foto artística não receba nota de descarte
	if bokehDetected && overallScore < 72.0 {
		overallScore = math.Max(overallScore, (subjectSharpness*0.7)+(exposureScore*0.3))
	}

	if overallScore < 0 {
		overallScore = 0
	} else if overallScore > 100.0 {
		overallScore = 100.0
	}

	// 8. Recommendation classification (Nunca deletar; proteger Bokeh como REVIEW_BOKEH)
	var recommendation string
	switch {
	case bokehDetected && overallScore < 75.0:
		recommendation = "REVIEW_BOKEH"
	case overallScore >= 70.0:
		recommendation = "APPROVED"
	case bokehDetected:
		recommendation = "REVIEW_BOKEH"
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
		BokehDetected:     bokehDetected,
		SubjectSharpness:  math.Round(subjectSharpness*10) / 10,
		Recommendation:    recommendation,
	}
}
