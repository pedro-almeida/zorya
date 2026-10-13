// PT: Detecção Inteligente de Orientação Facial para Câmeras sem Giroscópio (ex: Canon SL3)
// EN: Intelligent Face Orientation Detection and Auto-Rotation for Gyro-less DSLRs (e.g. Canon SL3)
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Detecção de Orientação por Análise Facial

package processor

import (
	"image"
	"math"
)

// DetectAndRotateFace analyzes geometric face gradients and skin orientation in low resolution.
// If facial features indicate that the subject is at 90° (sideways portrait), it rotates 90° clockwise.
// If the face is at 270°, it rotates 90° counter-clockwise (270° CW).
// If the photo is normally horizontal (or contains no people), it preserves native rotation (0°).
// Returns the oriented image and the applied rotation in degrees (0, 90, or 270).
func DetectAndRotateFace(img image.Image) (image.Image, int) {
	bounds := img.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()
	if w < 16 || h < 16 {
		return img, 0
	}

	// 1. Generate ultra-fast low-resolution proxy (~160px max dimension)
	targetProxy := 160
	scale := float64(targetProxy) / math.Max(float64(w), float64(h))
	if scale >= 1.0 {
		scale = 1.0
	}

	pw := int(float64(w) * scale)
	ph := int(float64(h) * scale)
	if pw < 10 {
		pw = 10
	}
	if ph < 10 {
		ph = 10
	}

	proxyLuma := make([]float64, pw*ph)
	proxySkin := make([]bool, pw*ph)

	stepX := float64(w) / float64(pw)
	stepY := float64(h) / float64(ph)

	rgba, isRGBA := img.(*image.RGBA)
	var skinPixelCount int

	for py := 0; py < ph; py++ {
		srcY := bounds.Min.Y + int(float64(py)*stepY)
		if srcY >= bounds.Max.Y {
			srcY = bounds.Max.Y - 1
		}

		for px := 0; px < pw; px++ {
			srcX := bounds.Min.X + int(float64(px)*stepX)
			if srcX >= bounds.Max.X {
				srcX = bounds.Max.X - 1
			}

			var r8, g8, b8 uint8
			if isRGBA {
				idx := (srcY-bounds.Min.Y)*rgba.Stride + (srcX-bounds.Min.X)*4
				r8 = rgba.Pix[idx]
				g8 = rgba.Pix[idx+1]
				b8 = rgba.Pix[idx+2]
			} else {
				r, g, b, _ := img.At(srcX, srcY).RGBA()
				r8 = uint8(r >> 8)
				g8 = uint8(g >> 8)
				b8 = uint8(b >> 8)
			}

			// Luminance
			luma := 0.299*float64(r8) + 0.587*float64(g8) + 0.114*float64(b8)
			pIdx := py*pw + px
			proxyLuma[pIdx] = luma

			// Skin-tone detection: R > G > B, (R-G) >= 12, R > 65
			isSkin := isSkinTone(r8, g8, b8)
			proxySkin[pIdx] = isSkin
			if isSkin {
				skinPixelCount++
			}
		}
	}

	totalPixels := pw * ph
	skinRatio := float64(skinPixelCount) / float64(totalPixels)

	// If there are practically no people/skin pixels (< 1.2% of frame),
	// maintain native camera orientation without unnecessary rotation.
	if skinRatio < 0.012 {
		return img, 0
	}

	// 2. Find primary skin cluster centroid and bounding box
	var minSkinX, minSkinY, maxSkinX, maxSkinY int
	minSkinX, minSkinY = pw, ph
	maxSkinX, maxSkinY = 0, 0

	var sumSkinX, sumSkinY int
	for py := 0; py < ph; py++ {
		for px := 0; px < pw; px++ {
			if proxySkin[py*pw+px] {
				sumSkinX += px
				sumSkinY += py
				if px < minSkinX {
					minSkinX = px
				}
				if px > maxSkinX {
					maxSkinX = px
				}
				if py < minSkinY {
					minSkinY = py
				}
				if py > maxSkinY {
					maxSkinY = py
				}
			}
		}
	}

	if skinPixelCount == 0 || minSkinX >= maxSkinX || minSkinY >= maxSkinY {
		return img, 0
	}

	// 3. Evaluate orientation hypotheses:
	// - H0:   0° (upright horizontal/native)
	// - H90:  90° CW rotation needed (subject lying on left side, head on left, grip on top)
	// - H270: 270° CW / 90° CCW rotation needed (subject lying on right side, head on right)
	score0 := evaluateOrientation(proxyLuma, proxySkin, pw, ph, minSkinX, minSkinY, maxSkinX, maxSkinY, 0)
	score90 := evaluateOrientation(proxyLuma, proxySkin, pw, ph, minSkinX, minSkinY, maxSkinX, maxSkinY, 90)
	score270 := evaluateOrientation(proxyLuma, proxySkin, pw, ph, minSkinX, minSkinY, maxSkinX, maxSkinY, 270)

	// Decision threshold with margin to avoid false positives on ambiguous scenes
	margin := 0.12

	if score90 > score0+margin && score90 > score270 {
		return Rotate90CW(img), 90
	}

	if score270 > score0+margin && score270 > score90 {
		return Rotate90CCW(img), 270
	}

	return img, 0
}

// evaluateOrientation calculates a normalized confidence score [0.0 - 1.0]
// indicating how well the skin and facial gradients match an upright human subject
// if rotated by rotDeg (0, 90, or 270).
func evaluateOrientation(luma []float64, skin []bool, w, h, minX, minY, maxX, maxY, rotDeg int) float64 {
	// Sample candidate coordinate mapping:
	// Given candidate rotation rotDeg, what coordinate in (w, h) maps to (u, v) in normalized head space?
	// In normalized head space:
	// u in [-1, +1] is horizontal (across left-right face), 0 is midline
	// v in [0, 1] is vertical (0 = top of head/forehead, 1 = chin/neck)

	var uDirX, uDirY float64
	var vDirX, vDirY float64
	var headTopX, headTopY float64
	var boxSpan float64

	switch rotDeg {
	case 90:
		// Needs 90° CW rotation to be upright.
		// That means in native image, the head top is towards the LEFT edge (minX),
		// and the torso/neck is towards the RIGHT edge (maxX).
		// Across face (left ear to right ear) is along Y axis:
		headTopX = float64(minX)
		headTopY = float64(minY+maxY) / 2.0
		vDirX = 1.0 // down the face is towards +X
		vDirY = 0.0
		uDirX = 0.0
		uDirY = 1.0 // across the face is towards +Y
		boxSpan = float64(maxX - minX)

	case 270:
		// Needs 270° CW / 90° CCW rotation to be upright.
		// That means in native image, the head top is towards the RIGHT edge (maxX),
		// and torso/neck is towards LEFT edge (minX).
		headTopX = float64(maxX)
		headTopY = float64(minY+maxY) / 2.0
		vDirX = -1.0 // down the face is towards -X
		vDirY = 0.0
		uDirX = 0.0
		uDirY = 1.0
		boxSpan = float64(maxX - minX)

	default: // 0°: native upright
		// Head top is towards the TOP edge (minY), torso towards BOTTOM (maxY)
		headTopX = float64(minX+maxX) / 2.0
		headTopY = float64(minY)
		vDirX = 0.0
		vDirY = 1.0 // down the face is towards +Y
		uDirX = 1.0 // across face is towards +X
		uDirY = 0.0
		boxSpan = float64(maxY - minY)
	}

	if boxSpan < 4 {
		return 0.0
	}

	faceHeight := boxSpan * 0.70
	faceWidth := faceHeight * 0.65
	if faceWidth < 4 {
		faceWidth = 4
	}

	// 1. Bilateral horizontal symmetry check around candidate head midline
	// In an upright face, L(u, v) is very symmetric with L(-u, v)
	var symmetryDiff, symmetryCount float64
	// 2. Eye/mouth gradient check:
	// In an upright face, eye and mouth features create high gradients across the vertical axis (vDir)
	var eyeBandGrad, sideGrad float64
	var gradCount float64

	// Sample grid of points on the candidate face
	for vFrac := 0.15; vFrac <= 0.85; vFrac += 0.10 {
		for uFrac := 0.10; uFrac <= 0.70; uFrac += 0.15 {
			// Center point
			cx := headTopX + (vFrac * faceHeight * vDirX)
			cy := headTopY + (vFrac * faceHeight * vDirY)

			// Point on +u side
			xPlus := int(cx + (uFrac * faceWidth * uDirX))
			yPlus := int(cy + (uFrac * faceWidth * uDirY))

			// Point on -u side
			xMinus := int(cx - (uFrac * faceWidth * uDirX))
			yMinus := int(cy - (uFrac * faceWidth * uDirY))

			if xPlus >= 0 && xPlus < w && yPlus >= 0 && yPlus < h &&
				xMinus >= 0 && xMinus < w && yMinus >= 0 && yMinus < h {

				lPlus := luma[yPlus*w+xPlus]
				lMinus := luma[yMinus*w+xMinus]

				diff := math.Abs(lPlus - lMinus)
				symmetryDiff += diff
				symmetryCount++

				// In eye band region (vFrac ~ 0.35 - 0.55), test directional gradients
				if vFrac >= 0.30 && vFrac <= 0.60 {
					// Step along v (down face)
					xDown := int(cx + 2.0*vDirX)
					yDown := int(cy + 2.0*vDirY)
					xUp := int(cx - 2.0*vDirX)
					yUp := int(cy - 2.0*vDirY)

					if xDown >= 0 && xDown < w && yDown >= 0 && yDown < h &&
						xUp >= 0 && xUp < w && yUp >= 0 && yUp < h {
						gV := math.Abs(luma[yDown*w+xDown] - luma[yUp*w+xUp])
						gU := math.Abs(lPlus - lMinus)
						eyeBandGrad += gV
						sideGrad += gU
						gradCount++
					}
				}
			}
		}
	}

	if symmetryCount == 0 {
		return 0.0
	}

	avgSymmetryDiff := symmetryDiff / symmetryCount
	// Higher symmetryDiff means lower symmetry score
	symmetryScore := 1.0 - (avgSymmetryDiff / 100.0)
	if symmetryScore < 0 {
		symmetryScore = 0
	}

	gradientScore := 0.5
	if gradCount > 0 && (eyeBandGrad+sideGrad) > 0 {
		// Eye features should produce dominant gradient along face height (perpendicular to eyes)
		gradientScore = eyeBandGrad / (eyeBandGrad + sideGrad)
	}

	// 3. Aspect ratio check:
	// A human torso and portrait frame is taller along the body axis.
	// In landscape 3:2 photos where W > H (e.g. 1920x1080):
	// A person shot vertically will span across the width (W).
	aspectBonus := 0.0
	if (rotDeg == 90 || rotDeg == 270) && w > h {
		aspectBonus = 0.15
	} else if rotDeg == 0 && h >= w {
		aspectBonus = 0.15
	}

	finalScore := (0.50 * symmetryScore) + (0.35 * gradientScore) + aspectBonus
	return finalScore
}

// isSkinTone checks whether an sRGB color triplet falls within human skin chrominance.
func isSkinTone(r, g, b uint8) bool {
	if r <= 60 || g <= 40 || b <= 20 {
		return false
	}
	if r <= g || r <= b {
		return false
	}
	if int(r)-int(g) < 12 {
		return false
	}
	if int(r)-int(b) < 15 {
		return false
	}
	return true
}
