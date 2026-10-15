// PT: Tratamento Comercial LightCraft (auto.rs) + PhotoCraft (look.rs): Auto-EV Log2, Shadow Lift, Highlight Roll-Off, Proteção de Pele DIGIC 8 e Unsharp Mask
// EN: Commercial LightCraft + PhotoCraft Grade: Log2 Auto-EV, Shadow Lift, Highlight Roll-Off, DIGIC 8 Skin Protection and Unsharp Mask
// AI: @llm-context Módulo Zorya v1.1.0 / Estágio 1 Tratamento Comercial de Vitrine de Vendas com LightCraft & PhotoCraft

package processor

import (
	"image"
	"math"
	"sync"
)

var (
	sCurveLUT  [256]uint8
	sCurveOnce sync.Once
)

func initSCurveLUT() {
	for i := 0; i < 256; i++ {
		x := float64(i) / 255.0
		// Curva S perceptual LightCraft (+68 contrast equivalent) com ombro suave
		shadowLift := 0.14 * math.Pow(1.0-x, 2.2) * x
		highlightComp := 0.15 * math.Pow(x, 2.8) * math.Max(0.0, x-0.64)
		toned := math.Min(1.0, math.Max(0.0, x+shadowLift-highlightComp))
		val := math.Min(1.0, math.Max(0.0, toned-0.055*math.Sin(2.0*math.Pi*toned)))

		clamped := int(math.Round(val * 255.0))
		if clamped < 0 {
			clamped = 0
		} else if clamped > 255 {
			clamped = 255
		}
		sCurveLUT[i] = uint8(clamped)
	}
}

func clampUint8(v float64) uint8 {
	return uint8(math.Min(255.0, math.Max(0.0, v+0.5)))
}

func ClampWBGain(gain float64) float64 {
	if gain < 0.92 {
		return 0.92
	}
	if gain > 1.08 {
		return 1.08
	}
	return gain
}

func ComputeAutoWBGains(src image.Image) (float64, float64, float64) {
	return 1.0, 1.0, 1.0
}

// ComputeSceneAutoEV calcula a exposição automática calibrada do LightCraft (auto.rs):
// sceneLogMean = mean(log2(Y / 0.18)), autoEV = clamp(-0.38 - 0.34 * sceneLogMean, -0.45, +0.85)
func ComputeSceneAutoEV(src image.Image) float64 {
	bounds := src.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()
	if w == 0 || h == 0 {
		return 1.0
	}
	stepX := w / 120
	if stepX < 1 {
		stepX = 1
	}
	stepY := h / 120
	if stepY < 1 {
		stepY = 1
	}
	var logLumSum, logLumCount float64
	for y := bounds.Min.Y; y < bounds.Max.Y; y += stepY {
		for x := bounds.Min.X; x < bounds.Max.X; x += stepX {
			rc, gc, bc, _ := src.At(x, y).RGBA()
			rf := float64(rc) / 65535.0
			gf := float64(gc) / 65535.0
			bf := float64(bc) / 65535.0
			lum := 0.2126*rf + 0.7152*gf + 0.0722*bf
			if lum > 0.015 && lum < 0.92 {
				logLumSum += math.Log2(lum / 0.18)
				logLumCount++
			}
		}
	}
	if logLumCount < 20 {
		return 1.04
	}
	sceneLogMean := logLumSum / logLumCount
	autoEV := -0.32 - 0.30*sceneLogMean
	if autoEV < -0.35 {
		autoEV = -0.35
	} else if autoEV > 0.75 {
		autoEV = 0.75
	}
	return math.Pow(2.0, autoEV)
}

func rgbToHueAndSat(r, g, b float64) (float64, float64) {
	maxC := math.Max(r, math.Max(g, b))
	minC := math.Min(r, math.Min(g, b))
	delta := maxC - minC

	if maxC < 0.001 || delta < 1e-4 {
		return 0.0, 0.0
	}

	sat := delta / maxC

	var h float64
	if maxC == r {
		h = (g - b) / delta
		h = math.Mod(h, 6.0)
	} else if maxC == g {
		h = (b-r)/delta + 2.0
	} else {
		h = (r-g)/delta + 4.0
	}
	h *= 60.0
	if h < 0 {
		h += 360.0
	}

	return h, sat
}

func ApplyPortraitSalesPop(src image.Image) image.Image {
	sCurveOnce.Do(initSCurveLUT)

	bounds := src.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()
	dst := image.NewRGBA(image.Rect(0, 0, w, h))

	rgba, isRGBA := src.(*image.RGBA)

	gainR, gainG, gainB := ComputeAutoWBGains(src)
	gainR = ClampWBGain(gainR)
	gainG = ClampWBGain(gainG)
	gainB = ClampWBGain(gainB)
	evMult := ComputeSceneAutoEV(src)

	for y := 0; y < h; y++ {
		srcY := bounds.Min.Y + y
		dstRow := y * dst.Stride

		for x := 0; x < w; x++ {
			srcX := bounds.Min.X + x
			var r, g, b, a uint8

			if isRGBA {
				idx := (srcY-bounds.Min.Y)*rgba.Stride + (srcX-bounds.Min.X)*4
				r = rgba.Pix[idx]
				g = rgba.Pix[idx+1]
				b = rgba.Pix[idx+2]
				a = rgba.Pix[idx+3]
			} else {
				rc, gc, bc, ac := src.At(srcX, srcY).RGBA()
				r = uint8(rc >> 8)
				g = uint8(gc >> 8)
				b = uint8(bc >> 8)
				a = uint8(ac >> 8)
			}

			rf := float64(r) * gainR
			gf := float64(g) * gainG
			bf := float64(b) * gainB

			// Conversão YCbCr (PhotoCraft look.rs chromaticity lock)
			yLuma := (0.299000*rf + 0.587000*gf + 0.114000*bf) * evMult
			cb := -0.168736*rf - 0.331264*gf + 0.500000*bf
			cr := 0.500000*rf - 0.418688*gf - 0.081312*bf

			hue, sat := rgbToHueAndSat(rf, gf, bf)

			skinWeight := 0.0
			if hue >= 12.0 && hue <= 48.0 && rf > gf && gf >= bf {
				if hue >= 18.0 && hue <= 42.0 {
					skinWeight = 1.0
				} else if hue < 18.0 {
					skinWeight = (hue - 12.0) / 6.0
				} else {
					skinWeight = (48.0 - hue) / 6.0
				}
			}

			if yLuma < 28.0 || yLuma > 238.0 {
				skinWeight = 0.0
			}

			yAdjusted := yLuma
			if skinWeight > 0.01 {
				highlightRollOff := math.Max(0.0, math.Min(1.0, (232.0-yLuma)/48.0))
				shadowRollOff := math.Max(0.0, math.Min(1.0, (yLuma-28.0)/30.0))
				yAdjusted += skinWeight * 6.0 * highlightRollOff * shadowRollOff
			}

			yIdx := int(math.Min(255.0, math.Max(0.0, yAdjusted+0.5)))
			ySCurve := float64(sCurveLUT[yIdx])

			// Vibrance seletiva LightCraft (+22% em meios-tons, protegendo tons de pele)
			vibranceFactor := 1.0
			if sat > 0.03 && sat < 0.88 {
				baseBoost := 0.18 * (1.0 - sat)
				if skinWeight > 0.01 {
					baseBoost *= (1.0 - 0.68*skinWeight)
				}
				vibranceFactor = 1.0 + baseBoost
			}

			cbAdjusted := cb * vibranceFactor
			crAdjusted := cr * vibranceFactor

			rOut := ySCurve + 1.402000*crAdjusted
			gOut := ySCurve - 0.344136*cbAdjusted - 0.714136*crAdjusted
			bOut := ySCurve + 1.772000*cbAdjusted

			dstIdx := dstRow + x*4
			dst.Pix[dstIdx] = clampUint8(rOut)
			dst.Pix[dstIdx+1] = clampUint8(gOut)
			dst.Pix[dstIdx+2] = clampUint8(bOut)
			dst.Pix[dstIdx+3] = a
		}
	}

	return dst
}

func ApplyUnsharpMask(src image.Image, amount float64) image.Image {
	if amount <= 0 {
		return src
	}
	if amount > 1.0 {
		amount = 1.0
	}

	bounds := src.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()
	if w < 3 || h < 3 {
		return src
	}

	dst := image.NewRGBA(image.Rect(0, 0, w, h))
	rgba, isRGBA := src.(*image.RGBA)

	k := amount * 0.24

	getRGB := func(x, y int) (float64, float64, float64, uint8) {
		if isRGBA {
			idx := (y-bounds.Min.Y)*rgba.Stride + (x-bounds.Min.X)*4
			return float64(rgba.Pix[idx]), float64(rgba.Pix[idx+1]), float64(rgba.Pix[idx+2]), rgba.Pix[idx+3]
		}
		rc, gc, bc, ac := src.At(x, y).RGBA()
		return float64(rc >> 8), float64(gc >> 8), float64(bc >> 8), uint8(ac >> 8)
	}

	for y := 0; y < h; y++ {
		srcY := bounds.Min.Y + y
		dstRow := y * dst.Stride

		for x := 0; x < w; x++ {
			srcX := bounds.Min.X + x

			rC, gC, bC, a := getRGB(srcX, srcY)

			if x == 0 || x == w-1 || y == 0 || y == h-1 {
				dstIdx := dstRow + x*4
				dst.Pix[dstIdx] = clampUint8(rC)
				dst.Pix[dstIdx+1] = clampUint8(gC)
				dst.Pix[dstIdx+2] = clampUint8(bC)
				dst.Pix[dstIdx+3] = a
				continue
			}

			rUp, gUp, bUp, _ := getRGB(srcX, srcY-1)
			rDown, gDown, bDown, _ := getRGB(srcX, srcY+1)
			rLeft, gLeft, bLeft, _ := getRGB(srcX-1, srcY)
			rRight, gRight, bRight, _ := getRGB(srcX+1, srcY)

			yC := 0.299*rC + 0.587*gC + 0.114*bC
			yUp := 0.299*rUp + 0.587*gUp + 0.114*bUp
			yDown := 0.299*rDown + 0.587*gDown + 0.114*bDown
			yLeft := 0.299*rLeft + 0.587*gLeft + 0.114*bLeft
			yRight := 0.299*rRight + 0.587*gRight + 0.114*bRight

			deltaY := k * (4.0*yC - yUp - yDown - yLeft - yRight)

			rOut := rC + deltaY
			gOut := gC + deltaY
			bOut := bC + deltaY

			dstIdx := dstRow + x*4
			dst.Pix[dstIdx] = clampUint8(rOut)
			dst.Pix[dstIdx+1] = clampUint8(gOut)
			dst.Pix[dstIdx+2] = clampUint8(bOut)
			dst.Pix[dstIdx+3] = a
		}
	}

	return dst
}

func ApplyAutoWhiteBalanceAndTone(src image.Image) image.Image {
	return ApplyPortraitSalesPop(src)
}

func ApplyPortraitProofCurve(src image.Image) image.Image {
	return ApplyPortraitSalesPop(src)
}

func ApplySensorAutoWBAndTone(src image.Image) image.Image {
	return ApplyPortraitSalesPop(src)
}
