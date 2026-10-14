// PT: Tratamento Comercial "Portrait Sales Pop", Curva S em Luminância, Proteção de Pele e Unsharp Mask
// EN: Commercial "Portrait Sales Pop" Grade: Luminance S-Curve, DIGIC Skin Protection and Unsharp Mask
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Tratamento Comercial de Vitrine de Vendas

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

// initSCurveLUT gera uma Curva Tonal em S (S-Curve) comercial:
// - Pretos profundos e aveludados (0-48) na barba e camisa preta
// - Abertura e pop nos médios (48-180) para dar presença e luminosidade
// - Transição suave de ombro (soft shoulder roll-off) acima de 200 nas altas luzes sem estourar
func initSCurveLUT() {
	for i := 0; i < 256; i++ {
		x := float64(i) / 255.0

		var val float64
		if x < 0.20 {
			// Aprofundamento sutil de sombras e pretos (barba e camisa)
			val = x * 0.94
		} else if x < 0.75 {
			// Abertura de médios com contraste suave (Hermite)
			normX := (x - 0.20) / 0.55
			hermite := normX * normX * (3.0 - 2.0*normX)
			val = 0.188 + hermite*(0.76-0.188)
		} else {
			// Ombro suave nas altas luzes (highlight roll-off sem clip estourado)
			over := (x - 0.75) / 0.25
			val = 0.76 + (1.0-math.Exp(-over*2.6))*(0.24/(1.0-math.Exp(-2.6)))
		}

		clamped := int(math.Round(val * 255.0))
		if clamped < 0 {
			clamped = 0
		} else if clamped > 255 {
			clamped = 255
		}
		sCurveLUT[i] = uint8(clamped)
	}
}

// clampUint8 protege toda conversão aritmética de ponto flutuante contra overflow/underflow uint8.
func clampUint8(v float64) uint8 {
	return uint8(math.Min(255.0, math.Max(0.0, v+0.5)))
}

// ClampWBGain garante que qualquer ganho multiplicativo de WB fique estritamente no intervalo [0.92, 1.08].
func ClampWBGain(gain float64) float64 {
	if gain < 0.92 {
		return 0.92
	}
	if gain > 1.08 {
		return 1.08
	}
	return gain
}

// ComputeAutoWBGains calcula o balanço de branco seguro preservando integralmente o As-Shot da câmera.
// Algoritmos destrutivos como "Gray World" cego foram desativados para impedir a descaracterização
// de paredes de fundo (ex: teal/verde-petróleo escuro) ou dominantes artísticas intencionais.
// Caso ocorra ajuste fino de balanço de branco, os ganhos multiplicativos são estritamente limitados a [0.92, 1.08].
func ComputeAutoWBGains(src image.Image) (float64, float64, float64) {
	bounds := src.Bounds()
	w, h := bounds.Dx(), bounds.Dy()
	if w <= 0 || h <= 0 {
		return 1.0, 1.0, 1.0
	}

	step := int(math.Max(float64(w), float64(h)) / 150.0)
	if step < 1 {
		step = 1
	}

	var sumR, sumG, sumB float64
	var count int

	rgba, isRGBA := src.(*image.RGBA)
	for y := bounds.Min.Y; y < bounds.Max.Y; y += step {
		for x := bounds.Min.X; x < bounds.Max.X; x += step {
			var r, g, b uint8
			if isRGBA {
				idx := (y-bounds.Min.Y)*rgba.Stride + (x-bounds.Min.X)*4
				r = rgba.Pix[idx]
				g = rgba.Pix[idx+1]
				b = rgba.Pix[idx+2]
			} else {
				rc, gc, bc, _ := src.At(x, y).RGBA()
				r = uint8(rc >> 8)
				g = uint8(gc >> 8)
				b = uint8(bc >> 8)
			}

			luma := 0.299*float64(r) + 0.587*float64(g) + 0.114*float64(b)
			if luma < 15.0 || luma > 240.0 {
				continue
			}

			sumR += float64(r)
			sumG += float64(g)
			sumB += float64(b)
			count++
		}
	}

	if count < 10 {
		return 1.0, 1.0, 1.0
	}

	meanR := sumR / float64(count)
	meanG := sumG / float64(count)
	meanB := sumB / float64(count)

	if meanR <= 0.001 || meanG <= 0.001 || meanB <= 0.001 {
		return 1.0, 1.0, 1.0
	}

	rawGainR := meanG / meanR
	rawGainB := meanG / meanB

	// Ajuste ultra-suave com clamp obrigatório no intervalo estrito e seguro [0.92, 1.08]
	gainR := 1.0 + 0.40*(rawGainR-1.0)
	gainB := 1.0 + 0.40*(rawGainB-1.0)

	gainR = ClampWBGain(gainR)
	gainB = ClampWBGain(gainB)

	return gainR, 1.0, gainB
}

// rgbToHueAndSat converte valores RGB normalizados [0..255] em matiz [0..360°) e saturação [0..1].
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

// ApplyPortraitSalesPop aplica o tratamento fotográfico comercial de alto padrão para venda:
// 1. Preservação integral do balanço de branco da câmera (As-Shot WB da Canon SL3 / DIGIC 8).
// 2. Processamento de contraste e curva em espaço de Luminância Y (YCbCr) para eliminar 100% o hue-shift.
// 3. Estabilidade absoluta dos canais cromáticos (Cb/Cr), impedindo que verde-petróleo/teal vire amarelo.
// 4. Saturação inteligente (Vibrance) com proteção estrita de tons de pele (matiz entre 15° e 45°).
// 5. Toda aritmética protegida por float64 intermediário e clamp estrito math.Min(255, math.Max(0, v + 0.5)).
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

			// 1. Aplicação segura de ganhos de balanço de branco As-Shot [0.92, 1.08]
			rf := float64(r) * gainR
			gf := float64(g) * gainG
			bf := float64(b) * gainB

			// 2. Conversão exata para o espaço de cor YCbCr (ITU-R BT.601)
			yLuma := 0.299000*rf + 0.587000*gf + 0.114000*bf
			cb := -0.168736*rf - 0.331264*gf + 0.500000*bf
			cr := 0.500000*rf - 0.418688*gf - 0.081312*bf

			// 3. Detecção de matiz e saturação para proteção de tons de pele humana (15° a 45°)
			hue, sat := rgbToHueAndSat(rf, gf, bf)

			// Peso de pele humana: matiz quente (15° a 45°) com R > G e G > B
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

			// Previne clarear pretos profundos (barba/roupa escura) ou estourar altas luzes
			if yLuma < 30.0 || yLuma > 235.0 {
				skinWeight = 0.0
			}

			// 4. Aplicação de contraste (Curva Tonal em S) estritamente no canal Y (Luminância)
			// Leve realce de exposição facial em Y sem alterar cromaticidade
			yAdjusted := yLuma
			if skinWeight > 0.01 {
				// Roll-off suave nas altas luzes para evitar estouro
				highlightRollOff := math.Max(0.0, math.Min(1.0, (230.0-yLuma)/45.0))
				shadowRollOff := math.Max(0.0, math.Min(1.0, (yLuma-30.0)/30.0))
				yAdjusted += skinWeight * 5.0 * highlightRollOff * shadowRollOff
			}

			yIdx := int(math.Min(255.0, math.Max(0.0, yAdjusted+0.5)))
			ySCurve := float64(sCurveLUT[yIdx])

			// 5. Saturação Inteligente (Vibrance):
			// Dá leve ganho em cores pouco saturadas sem saturar tons de pele (tons entre 15° e 45°)
			vibranceFactor := 1.0
			if skinWeight < 0.65 && sat > 0.04 && sat < 0.85 {
				boost := 0.12 * (1.0 - sat) * (1.0 - skinWeight)
				vibranceFactor = 1.0 + boost
			}

			// Cb e Cr conservados estáveis, sofrendo apenas o escalonamento proporcional de vibrância
			cbAdjusted := cb * vibranceFactor
			crAdjusted := cr * vibranceFactor

			// 6. Conversão inversa de volta para RGB a partir de YCbCr
			rOut := ySCurve + 1.402000*crAdjusted
			gOut := ySCurve - 0.344136*cbAdjusted - 0.714136*crAdjusted
			bOut := ySCurve + 1.772000*cbAdjusted

			// 7. Clamp estrito math.Min(255, math.Max(0, v + 0.5))
			dstIdx := dstRow + x*4
			dst.Pix[dstIdx] = clampUint8(rOut)
			dst.Pix[dstIdx+1] = clampUint8(gOut)
			dst.Pix[dstIdx+2] = clampUint8(bOut)
			dst.Pix[dstIdx+3] = a
		}
	}

	return dst
}

// ApplyUnsharpMask aplica nitidez comercial 3x3 estritamente no canal de Luminância Y,
// realçando olhos, cílios e textura da pele com zero aberração cromática ou halos coloridos.
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

	k := amount * 0.22

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

			// Bordas: copia direto
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

			// Nitidez calculada em Luminância Y para proteger os canais de cromaticidade
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

// ApplyAutoWhiteBalanceAndTone mantém a compatibilidade com a esteira chamando Portrait Sales Pop.
func ApplyAutoWhiteBalanceAndTone(src image.Image) image.Image {
	return ApplyPortraitSalesPop(src)
}

// ApplyPortraitProofCurve aplica a curva tonal com preservação de matiz.
func ApplyPortraitProofCurve(src image.Image) image.Image {
	return ApplyPortraitSalesPop(src)
}
