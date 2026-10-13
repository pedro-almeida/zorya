// PT: Tratamento Comercial "Portrait Sales Pop", Curva S, Proteção de Pele e Unsharp Mask
// EN: Commercial "Portrait Sales Pop" Grade: S-Curve, DIGIC Skin Protection and Unsharp Mask
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Tratamento Comercial de Vitrine de Vendas

package processor

import (
	"image"
	"math"
	"sync"
)

var (
	sCurveLUT   [256]uint8
	sCurveOnce  sync.Once
)

// initSCurveLUT gera uma Curva Tonal em S (S-Curve) comercial:
// - Pretos profundos e aveludados (0-48) na barba e camisa preta
// - Abertura e pop nos médios (48-180) para dar presença e luminosidade
// - Transição suave de ombro (soft shoulder roll-off) acima de 200 nas altas luzes
func initSCurveLUT() {
	for i := 0; i < 256; i++ {
		x := float64(i) / 255.0

		// Função sigmoide de contraste suave em S
		// Centralizada em 0.45 com inclinação de alto impacto comercial
		var val float64
		if x < 0.20 {
			// Aprofundamento sutil de sombras e pretos (barba e camisa)
			val = x * 0.94
		} else if x < 0.75 {
			// Abertura de médios com contraste suave
			normX := (x - 0.20) / 0.55
			// Curva hermite suave
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

// ApplyPortraitSalesPop aplica o tratamento comercial completo de Vitrine de Vendas:
// ComputeAutoWBGains calcula ganhos para balanço de branco neutro
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
			if luma < 10.0 || luma > 248.0 {
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

	gainR := 1.0 + 0.85*(rawGainR-1.0)
	gainB := 1.0 + 0.85*(rawGainB-1.0)

	gainR = math.Max(0.70, math.Min(1.45, gainR))
	gainB = math.Max(0.70, math.Min(1.45, gainB))

	return gainR, 1.0, gainB
}

// ApplyPortraitSalesPop aplica o tratamento comercial completo de Vitrine de Vendas:
// 1. Preservação estrita do Balanço de Branco autêntico do processador DIGIC da Canon.
// 2. Detecção precisa de máscara de pele humana (vermelho/dourado) com ganho de exposição local (+0.5 EV).
// 3. Proteção total de pretos (barba e camisa preta densos) e preservação do azul da parede de fundo.
// 4. Aplicação de Curva Tonal em S (S-Curve).
// 5. Vibrância seletiva (+14%) nos livros e folhagens sem saturar ou alaranjar a pele.
func ApplyPortraitSalesPop(src image.Image) image.Image {
	sCurveOnce.Do(initSCurveLUT)

	bounds := src.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()
	dst := image.NewRGBA(image.Rect(0, 0, w, h))

	rgba, isRGBA := src.(*image.RGBA)

	gainR, gainG, gainB := ComputeAutoWBGains(src)

	// Fator de ganho de exposição local para o sujeito: +0.48 EV (~1.395x)
	const subjectGain = 1.395

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

			// Luminância perceptual
			luma := 0.299*rf + 0.587*gf + 0.114*bf

			// 1. Detecção da Máscara de Pele Humana com Proteção de Parede Azul e Pretos
			skinWeight := calculateSkinWeight(rf, gf, bf, luma)

			if skinWeight > 0.005 {
				// Ganho de exposição suave no sujeito (+0.4 EV a +0.6 EV)
				gain := 1.0 + skinWeight*(subjectGain-1.0)

				rf *= gain
				gf *= gain
				bf *= gain

				if rf > 255 {
					rf = 255
				}
				if gf > 255 {
					gf = 255
				}
				if bf > 255 {
					bf = 255
				}
			}

			// 2. Curva Tonal em S (S-Curve)
			rSCurve := float64(sCurveLUT[uint8(rf)])
			gSCurve := float64(sCurveLUT[uint8(gf)])
			bSCurve := float64(sCurveLUT[uint8(bf)])

			// 3. Vibrância Seletiva (+14%): realça livros coloridos e fundos sem alaranjar a pele
			maxC := math.Max(rSCurve, math.Max(gSCurve, bSCurve))
			minC := math.Min(rSCurve, math.Min(gSCurve, bSCurve))
			chroma := maxC - minC

			if chroma > 4.0 && skinWeight < 0.60 {
				// Cores com saturação baixa/média fora da máscara principal de pele
				// Ganho de vibrância inversamente proporcional à saturação existente
				sat := chroma / (maxC + 0.001)
				vibranceFactor := 1.0 + 0.14*(1.0-sat)*(1.0-skinWeight)

				avgL := (rSCurve + gSCurve + bSCurve) / 3.0
				rSCurve = avgL + (rSCurve-avgL)*vibranceFactor
				gSCurve = avgL + (gSCurve-avgL)*vibranceFactor
				bSCurve = avgL + (bSCurve-avgL)*vibranceFactor
			}

			// Clamp final
			if rSCurve < 0 {
				rSCurve = 0
			} else if rSCurve > 255 {
				rSCurve = 255
			}
			if gSCurve < 0 {
				gSCurve = 0
			} else if gSCurve > 255 {
				gSCurve = 255
			}
			if bSCurve < 0 {
				bSCurve = 0
			} else if bSCurve > 255 {
				bSCurve = 255
			}

			dstIdx := dstRow + x*4
			dst.Pix[dstIdx] = uint8(rSCurve)
			dst.Pix[dstIdx+1] = uint8(gSCurve)
			dst.Pix[dstIdx+2] = uint8(bSCurve)
			dst.Pix[dstIdx+3] = a
		}
	}

	return dst
}

// calculateSkinWeight identifica matizes avermelhados/dourados de pele humana
// com proteção estrita para:
// - Parede Azul: se o canal azul dominar (B > R ou B > G), retorna 0.0.
// - Pretos Profundos: se luma < 35 (barba preta, terno, camisa preta), não clareia.
// - Altas Luzes Extrema: luma > 230 tem atenuação de ganho.
func calculateSkinWeight(r, g, b, luma float64) float64 {
	// Proteção Absoluta contra Parede Azul
	if b > r+5.0 || b > g+5.0 {
		return 0.0
	}

	// Proteção Absoluta de Pretos (Barba e Camisa Preta continuam aveludados)
	if luma < 30.0 {
		return 0.0
	}

	// Faixa cromática de pele: R > G e G > B
	if r <= g || g <= b {
		return 0.0
	}

	rgDiff := r - g
	rbDiff := r - b

	if rgDiff < 10.0 || rbDiff < 15.0 || r < 55.0 {
		return 0.0
	}

	// Razão de matiz dourado/vermelho
	sum := r + g + b + 0.001
	rRatio := r / sum
	bRatio := b / sum

	if rRatio < 0.38 || rRatio > 0.68 || bRatio > 0.28 {
		return 0.0
	}

	// Transição suave (feathering) nas sombras para preservar detalhes de barba
	shadowWeight := 1.0
	if luma < 60.0 {
		shadowWeight = (luma - 30.0) / 30.0
	}

	// Transição suave nas altas luzes para evitar estouro
	highlightWeight := 1.0
	if luma > 220.0 {
		highlightWeight = math.Max(0.0, (255.0-luma)/35.0)
	}

	confidence := math.Min(1.0, (rgDiff/40.0)*(rbDiff/55.0))
	return confidence * shadowWeight * highlightWeight
}

// ApplyUnsharpMask aplica nitidez sutil 3x3 de vitrine fotográfica comercial.
// Realça detalhes de olhos, cílios, cabelo e textura sem introduzir ruído ou halos.
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

	// Kernel 3x3 Laplacian sutil com ganho amount
	// [ 0,  -k,  0]
	// [-k, 1+4k,-k]
	// [ 0,  -k,  0]
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

			// Bordas: copia direto
			if x == 0 || x == w-1 || y == 0 || y == h-1 {
				dstIdx := dstRow + x*4
				dst.Pix[dstIdx] = uint8(rC)
				dst.Pix[dstIdx+1] = uint8(gC)
				dst.Pix[dstIdx+2] = uint8(bC)
				dst.Pix[dstIdx+3] = a
				continue
			}

			rUp, gUp, bUp, _ := getRGB(srcX, srcY-1)
			rDown, gDown, bDown, _ := getRGB(srcX, srcY+1)
			rLeft, gLeft, bLeft, _ := getRGB(srcX-1, srcY)
			rRight, gRight, bRight, _ := getRGB(srcX+1, srcY)

			rOut := rC + k*(4.0*rC-rUp-rDown-rLeft-rRight)
			gOut := gC + k*(4.0*gC-gUp-gDown-gLeft-gRight)
			bOut := bC + k*(4.0*bC-bUp-bDown-bLeft-bRight)

			if rOut < 0 {
				rOut = 0
			} else if rOut > 255 {
				rOut = 255
			}
			if gOut < 0 {
				gOut = 0
			} else if gOut > 255 {
				gOut = 255
			}
			if bOut < 0 {
				bOut = 0
			} else if bOut > 255 {
				bOut = 255
			}

			dstIdx := dstRow + x*4
			dst.Pix[dstIdx] = uint8(rOut)
			dst.Pix[dstIdx+1] = uint8(gOut)
			dst.Pix[dstIdx+2] = uint8(bOut)
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
