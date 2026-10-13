// PT: Marca d'Água Oficial Tenorio Foto: Malha Diagonal com TF25.png a -30° e Linhas Tracejadas
// EN: Tenorio Foto Official Watermark: Diagonal Grid with TF25.png at -30° and Dashed Lines
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Proteção de Galeria e Marca d'Água Diagonal

package processor

import (
	"image"
	"image/color"
	_ "image/png"
	"math"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

// WatermarkOptions defines formatting for the gallery watermark.
type WatermarkOptions struct {
	Text      string  `json:"text"`       // default: "PEDRO ALMEIDA | PROVA"
	Opacity   float64 `json:"opacity"`    // 0.28 - 0.35 (default 0.32)
	Position  string  `json:"position"`   // "diagonal_grid", "center", etc.
	DrawLines bool    `json:"draw_lines"` // default: true (linhas tracejadas a -30°)
	ImagePath string  `json:"image_path"` // Caminho do PNG oficial (ex: TF25.png)
}

// DefaultWatermarkOptions returns photographic proof default settings.
func DefaultWatermarkOptions() WatermarkOptions {
	return WatermarkOptions{
		Text:      "PEDRO ALMEIDA | PROVA",
		Opacity:   0.32,
		Position:  "diagonal_grid",
		DrawLines: true,
		ImagePath: "D:\\Tenorio.fot.br\\IDV TENORIO FOTO_RGB\\IDV TENORIO FOTO_RGB\\TF25.png",
	}
}

var (
	logoCacheMu sync.RWMutex
	rawLogoPNG  image.Image
	cachedScale map[int]*image.RGBA // chave: targetLogoW -> logo rotacionado a -30°
)

func init() {
	cachedScale = make(map[int]*image.RGBA)
}

// loadOfficialTF25Logo carrega o arquivo PNG oficial TF25.png do disco com fallback
func loadOfficialTF25Logo(customPath string) image.Image {
	logoCacheMu.RLock()
	if rawLogoPNG != nil {
		img := rawLogoPNG
		logoCacheMu.RUnlock()
		return img
	}
	logoCacheMu.RUnlock()

	candidates := []string{
		customPath,
		"D:\\Tenorio.fot.br\\IDV TENORIO FOTO_RGB\\IDV TENORIO FOTO_RGB\\TF25.png",
		"C:\\dev\\zorya\\assets\\TF25.png",
		filepath.Join("assets", "TF25.png"),
		"TF25.png",
	}

	for _, cand := range candidates {
		if cand == "" {
			continue
		}
		if fi, err := os.Stat(cand); err == nil && !fi.IsDir() {
			f, err := os.Open(cand)
			if err == nil {
				img, _, err := image.Decode(f)
				f.Close()
				if err == nil && img != nil {
					logoCacheMu.Lock()
					rawLogoPNG = img
					logoCacheMu.Unlock()
					return img
				}
			}
		}
	}

	return nil
}

// getRotatedLogo gera ou obtém do cache o logo TF25.png inclinado a -30 graus
func getRotatedLogo(logo image.Image, targetWidth int) *image.RGBA {
	logoCacheMu.RLock()
	cached, ok := cachedScale[targetWidth]
	logoCacheMu.RUnlock()
	if ok && cached != nil {
		return cached
	}

	// 1. Redimensionar logo original para largura proporcional
	origB := logo.Bounds()
	aspect := float64(origB.Dy()) / float64(origB.Dx())
	targetHeight := int(float64(targetWidth) * aspect)
	if targetHeight < 1 {
		targetHeight = 1
	}

	scaled := resizeImageBilinear(logo, targetWidth, targetHeight)

	// 2. Rotacionar a -30° (-pi/6 radianos - inclinação diagonal ascendente)
	angleRad := -30.0 * (math.Pi / 180.0)
	cosA := math.Cos(angleRad)
	sinA := math.Sin(angleRad)

	sw := scaled.Bounds().Dx()
	sh := scaled.Bounds().Dy()

	// Dimensões da bounding box rotacionada
	rotW := int(math.Ceil(math.Abs(float64(sw)*cosA) + math.Abs(float64(sh)*sinA)))
	rotH := int(math.Ceil(math.Abs(float64(sw)*sinA) + math.Abs(float64(sh)*cosA)))

	rotImg := image.NewRGBA(image.Rect(0, 0, rotW, rotH))

	cxSrc := float64(sw) / 2.0
	cySrc := float64(sh) / 2.0
	cxDst := float64(rotW) / 2.0
	cyDst := float64(rotH) / 2.0

	for ry := 0; ry < rotH; ry++ {
		for rx := 0; rx < rotW; rx++ {
			xDst := float64(rx) - cxDst
			yDst := float64(ry) - cyDst

			// Rotação inversa para amostragem
			xSrc := xDst*cosA + yDst*sinA + cxSrc
			ySrc := -xDst*sinA + yDst*cosA + cySrc

			if xSrc >= 0 && xSrc < float64(sw) && ySrc >= 0 && ySrc < float64(sh) {
				x0 := int(xSrc)
				y0 := int(ySrc)
				x1 := x0 + 1
				y1 := y0 + 1
				if x1 >= sw {
					x1 = sw - 1
				}
				if y1 >= sh {
					y1 = sh - 1
				}

				fx := xSrc - float64(x0)
				fy := ySrc - float64(y0)

				c00 := scaled.RGBAAt(x0, y0)
				c10 := scaled.RGBAAt(x1, y0)
				c01 := scaled.RGBAAt(x0, y1)
				c11 := scaled.RGBAAt(x1, y1)

				w00 := (1.0 - fx) * (1.0 - fy)
				w10 := fx * (1.0 - fy)
				w01 := (1.0 - fx) * fy
				w11 := fx * fy

				r := uint8(float64(c00.R)*w00 + float64(c10.R)*w10 + float64(c01.R)*w01 + float64(c11.R)*w11)
				g := uint8(float64(c00.G)*w00 + float64(c10.G)*w10 + float64(c01.G)*w01 + float64(c11.G)*w11)
				b := uint8(float64(c00.B)*w00 + float64(c10.B)*w10 + float64(c01.B)*w01 + float64(c11.B)*w11)
				a := uint8(float64(c00.A)*w00 + float64(c10.A)*w10 + float64(c01.A)*w01 + float64(c11.A)*w11)

				rotImg.SetRGBA(rx, ry, color.RGBA{R: r, G: g, B: b, A: a})
			}
		}
	}

	logoCacheMu.Lock()
	cachedScale[targetWidth] = rotImg
	logoCacheMu.Unlock()

	return rotImg
}

// ApplyWatermark renderiza a marca d'água oficial da Tenorio Foto:
// - TF25.png inclinado a -30° distribuído em malha diagonal (3x3 / 4x4) cobrindo toda a extensão.
// - Linhas tracejadas (- - - -) finas com ângulo de -30° interligando a malha e protegendo contra recortes.
// - Opacidade suave e comercial entre 0.28 e 0.35.
func ApplyWatermark(src image.Image, opts WatermarkOptions) *image.RGBA {
	bounds := src.Bounds()
	dst := image.NewRGBA(bounds)

	// Cópia dos pixels base
	if rgba, ok := src.(*image.RGBA); ok {
		copy(dst.Pix, rgba.Pix)
	} else {
		for y := bounds.Min.Y; y < bounds.Max.Y; y++ {
			for x := bounds.Min.X; x < bounds.Max.X; x++ {
				dst.Set(x, y, src.At(x, y))
			}
		}
	}

	w := bounds.Dx()
	h := bounds.Dy()
	if w <= 0 || h <= 0 {
		return dst
	}

	opacity := opts.Opacity
	if opacity < 0.20 {
		opacity = 0.32
	} else if opacity > 0.45 {
		opacity = 0.35
	}

	logoImg := loadOfficialTF25Logo(opts.ImagePath)

	// 1. Renderizar Linhas Diagonais Tracejadas a -30° (- - - -) se solicitado ou na malha diagonal
	isDiagonalGrid := opts.Position == "" || strings.Contains(strings.ToLower(opts.Position), "diag")
	if opts.DrawLines && isDiagonalGrid {
		renderDiagonalDashedGrid(dst, opacity*0.65)
	}

	// 2. Renderizar logos TF25.png
	if logoImg != nil {
		switch strings.ToLower(opts.Position) {
		case "center":
			renderSingleLogo(dst, logoImg, "center", opacity)
		case "bottom-right":
			renderSingleLogo(dst, logoImg, "bottom-right", opacity)
		case "bottom", "rodape", "footer":
			renderSingleLogo(dst, logoImg, "bottom", opacity)
		default:
			// Malha Diagonal com TF25.png rotacionado a -30° (Padrão Oficial)
			renderDiagonalLogoGrid(dst, logoImg, opacity)
		}
	} else {
		// Fallback textual em malha caso o PNG esteja inacessível
		renderFallbackTextGrid(dst, opts.Text, opacity)
	}

	return dst
}

// renderSingleLogo desenha o logo único quando requisitado em posição específica (center, bottom-right)
func renderSingleLogo(dst *image.RGBA, logo image.Image, pos string, opacity float64) {
	bounds := dst.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()

	targetLogoW := int(float64(w) * 0.25)
	if targetLogoW < 48 {
		targetLogoW = 48
	}
	origB := logo.Bounds()
	aspect := float64(origB.Dy()) / float64(origB.Dx())
	targetLogoH := int(float64(targetLogoW) * aspect)
	if targetLogoH < 1 {
		targetLogoH = 1
	}

	scaled := resizeImageBilinear(logo, targetLogoW, targetLogoH)

	var startX, startY int
	switch strings.ToLower(pos) {
	case "bottom-right":
		startX = w - targetLogoW - 20
		startY = h - targetLogoH - 20
	case "bottom", "rodape", "footer":
		startX = (w - targetLogoW) / 2
		startY = h - targetLogoH - 20
	case "center":
		fallthrough
	default:
		startX = (w - targetLogoW) / 2
		startY = (h - targetLogoH) / 2
	}

	blendSubImage(dst, scaled, startX, startY, opacity)
}

// renderDiagonalDashedGrid traça linhas finas tracejadas a -30 graus cobrindo toda a área da foto
func renderDiagonalDashedGrid(dst *image.RGBA, alpha float64) {
	bounds := dst.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()

	// Ângulo de -30°: vetor diretor unitário u = (cos(-30°), sin(-30°))
	const angleDeg = -30.0
	rad := angleDeg * (math.Pi / 180.0)
	cosA := math.Cos(rad) // ~0.866
	sinA := math.Sin(rad) // ~-0.500

	// Vetor normal n = (-sinA, cosA)
	nx := -sinA // ~0.500
	ny := cosA  // ~0.866

	// Espaçamento entre linhas diagonais: cerca de 18% da largura da imagem
	lineSpacing := float64(w) * 0.19
	if lineSpacing < 140 {
		lineSpacing = 140
	}

	// Range de distâncias normais cobrindo o bounding box
	corners := [][2]float64{
		{0, 0},
		{float64(w), 0},
		{0, float64(h)},
		{float64(w), float64(h)},
	}
	minD := 1e9
	maxD := -1e9
	for _, c := range corners {
		d := c[0]*nx + c[1]*ny
		if d < minD {
			minD = d
		}
		if d > maxD {
			maxD = d
		}
	}

	dashLen := 14.0
	gapLen := 12.0
	cycle := dashLen + gapLen

	// Para cada linha da família paralela a -30°
	for d := minD - lineSpacing; d <= maxD + lineSpacing; d += lineSpacing {
		// Ponto base na linha: P0 = d * n
		p0x := d * nx
		p0y := d * ny

		// Varrer ao longo da linha em passos de 1px
		diagSpan := math.Sqrt(float64(w*w + h*h))
		for s := -diagSpan; s <= diagSpan; s += 1.0 {
			// Testar padrão tracejado
			mod := math.Mod(math.Mod(s, cycle)+cycle, cycle)
			if mod > dashLen {
				continue // Intervalo de espaço
			}

			px := int(p0x + s*cosA)
			py := int(p0y + s*sinA)

			if px >= bounds.Min.X && px < bounds.Max.X && py >= bounds.Min.Y && py < bounds.Max.Y {
				orig := dst.RGBAAt(px, py)
				invA := 1.0 - alpha

				// Linha clara suave com contraste natural
				rOut := uint8(math.Min(255, float64(orig.R)*invA+255.0*alpha))
				gOut := uint8(math.Min(255, float64(orig.G)*invA+255.0*alpha))
				bOut := uint8(math.Min(255, float64(orig.B)*invA+255.0*alpha))

				dst.SetRGBA(px, py, color.RGBA{R: rOut, G: gOut, B: bOut, A: orig.A})
			}
		}
	}
}

// renderDiagonalLogoGrid distribui o logo TF25.png rotacionado a -30° em malha repetida
func renderDiagonalLogoGrid(dst *image.RGBA, logo image.Image, opacity float64) {
	bounds := dst.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()

	// Tamanho de cada logo na malha: cerca de 13% da largura da foto
	targetLogoW := int(float64(w) * 0.13)
	if targetLogoW < 48 {
		targetLogoW = 48
	}
	if targetLogoW > 320 {
		targetLogoW = 320
	}

	rotLogo := getRotatedLogo(logo, targetLogoW)
	lw := rotLogo.Bounds().Dx()
	lh := rotLogo.Bounds().Dy()

	// Número de divisões na malha (grade 3x3 ou 4x4 dependendo da proporção)
	cols := 4
	rows := 4
	if w < h { // Retrato vertical
		cols = 3
		rows = 5
	}

	stepX := float64(w) / float64(cols)
	stepY := float64(h) / float64(rows)

	for r := 0; r < rows; r++ {
		// Offset diagonal intercalado por linha para efeito losango comercial
		rowOffset := 0.0
		if r%2 == 1 {
			rowOffset = stepX * 0.50
		}

		for c := -1; c <= cols; c++ {
			centerX := float64(c)*stepX + rowOffset + (stepX * 0.50)
			centerY := float64(r)*stepY + (stepY * 0.50)

			startX := int(centerX - float64(lw)/2.0)
			startY := int(centerY - float64(lh)/2.0)

			blendSubImage(dst, rotLogo, startX, startY, opacity)
		}
	}
}

// blendSubImage realiza a fusão alfa rápida de um sub-bitmap rotacionado sobre a imagem de destino
func blendSubImage(dst *image.RGBA, src *image.RGBA, startX, startY int, opacity float64) {
	dstBounds := dst.Bounds()
	srcBounds := src.Bounds()
	sw := srcBounds.Dx()
	sh := srcBounds.Dy()

	for ly := 0; ly < sh; ly++ {
		py := startY + ly
		if py < dstBounds.Min.Y || py >= dstBounds.Max.Y {
			continue
		}

		for lx := 0; lx < sw; lx++ {
			px := startX + lx
			if px < dstBounds.Min.X || px >= dstBounds.Max.X {
				continue
			}

			srcCol := src.RGBAAt(lx, ly)
			if srcCol.A == 0 {
				continue
			}

			effectiveAlpha := (float64(srcCol.A) / 255.0) * opacity
			if effectiveAlpha < 0.005 {
				continue
			}

			orig := dst.RGBAAt(px, py)
			invA := 1.0 - effectiveAlpha

			outR := uint8(math.Min(255, float64(orig.R)*invA+float64(srcCol.R)*effectiveAlpha))
			outG := uint8(math.Min(255, float64(orig.G)*invA+float64(srcCol.G)*effectiveAlpha))
			outB := uint8(math.Min(255, float64(orig.B)*invA+float64(srcCol.B)*effectiveAlpha))

			dst.SetRGBA(px, py, color.RGBA{R: outR, G: outG, B: outB, A: orig.A})
		}
	}
}

// renderFallbackTextGrid gera a malha repetida caso o PNG oficial esteja ausente
func renderFallbackTextGrid(dst *image.RGBA, text string, opacity float64) {
	if text == "" {
		text = "TENORIO FOTO | PROVA"
	}
	bounds := dst.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()
	scale := int(math.Max(2.0, float64(w)/500.0))

	stepX := w / 3
	stepY := h / 4

	for r := 0; r < 4; r++ {
		offset := 0
		if r%2 == 1 {
			offset = stepX / 2
		}
		for c := 0; c < 3; c++ {
			x := c*stepX + offset + 20
			y := r*stepY + 30
			renderText(dst, text, x, y, scale, opacity)
		}
	}
}

// resizeImageBilinear redimensiona com interpolação bilinear preservando transparência alfa
func resizeImageBilinear(src image.Image, targetW, targetH int) *image.RGBA {
	bounds := src.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()
	if w == targetW && h == targetH {
		if rgba, ok := src.(*image.RGBA); ok {
			return rgba
		}
	}

	dst := image.NewRGBA(image.Rect(0, 0, targetW, targetH))
	scaleX := float64(w) / float64(targetW)
	scaleY := float64(h) / float64(targetH)

	for y := 0; y < targetH; y++ {
		srcY := float64(bounds.Min.Y) + float64(y)*scaleY
		y0 := int(srcY)
		y1 := y0 + 1
		if y1 >= bounds.Max.Y {
			y1 = bounds.Max.Y - 1
		}
		fy := srcY - float64(y0)

		for x := 0; x < targetW; x++ {
			srcX := float64(bounds.Min.X) + float64(x)*scaleX
			x0 := int(srcX)
			x1 := x0 + 1
			if x1 >= bounds.Max.X {
				x1 = bounds.Max.X - 1
			}
			fx := srcX - float64(x0)

			c00 := src.At(x0, y0)
			c10 := src.At(x1, y0)
			c01 := src.At(x0, y1)
			c11 := src.At(x1, y1)

			r00, g00, b00, a00 := c00.RGBA()
			r10, g10, b10, a10 := c10.RGBA()
			r01, g01, b01, a01 := c01.RGBA()
			r11, g11, b11, a11 := c11.RGBA()

			w00 := (1.0 - fx) * (1.0 - fy)
			w10 := fx * (1.0 - fy)
			w01 := (1.0 - fx) * fy
			w11 := fx * fy

			r := uint8((float64(r00)*w00 + float64(r10)*w10 + float64(r01)*w01 + float64(r11)*w11) / 257.0)
			g := uint8((float64(g00)*w00 + float64(g10)*w10 + float64(g01)*w01 + float64(g11)*w11) / 257.0)
			b := uint8((float64(b00)*w00 + float64(b10)*w10 + float64(b01)*w01 + float64(b11)*w11) / 257.0)
			a := uint8((float64(a00)*w00 + float64(a10)*w10 + float64(a01)*w01 + float64(a11)*w11) / 257.0)

			dst.SetRGBA(x, y, color.RGBA{R: r, G: g, B: b, A: a})
		}
	}
	return dst
}

// renderText desenha fonte matricial de alta legibilidade para fallback
func renderText(dst *image.RGBA, text string, startX, startY, scale int, alpha float64) {
	cursorX := startX
	for _, ch := range text {
		bitmap := getGlyph(ch)
		drawGlyph(dst, bitmap, cursorX, startY, scale, color.RGBA{R: 20, G: 20, B: 20, A: 255}, alpha*0.8, true)
		drawGlyph(dst, bitmap, cursorX, startY, scale, color.RGBA{R: 255, G: 255, B: 255, A: 255}, alpha, false)
		cursorX += 6 * scale
	}
}

func drawGlyph(dst *image.RGBA, bitmap [7]uint8, x0, y0, scale int, col color.RGBA, alpha float64, isOutline bool) {
	bounds := dst.Bounds()
	offsets := [][2]int{{0, 0}}
	if isOutline {
		offsets = [][2]int{{-1, 0}, {1, 0}, {0, -1}, {0, 1}, {-1, -1}, {1, 1}}
	}

	for _, off := range offsets {
		ox, oy := off[0], off[1]
		for r := 0; r < 7; r++ {
			row := bitmap[r]
			for c := 0; c < 5; c++ {
				if (row & (1 << (4 - c))) != 0 {
					for py := 0; py < scale; py++ {
						for px := 0; px < scale; px++ {
							x := x0 + ox + (c * scale) + px
							y := y0 + oy + (r * scale) + py
							if x >= bounds.Min.X && x < bounds.Max.X && y >= bounds.Min.Y && y < bounds.Max.Y {
								orig := dst.RGBAAt(x, y)
								invA := 1.0 - alpha
								newR := uint8(math.Min(255, float64(orig.R)*invA+float64(col.R)*alpha))
								newG := uint8(math.Min(255, float64(orig.G)*invA+float64(col.G)*alpha))
								newB := uint8(math.Min(255, float64(orig.B)*invA+float64(col.B)*alpha))
								dst.SetRGBA(x, y, color.RGBA{R: newR, G: newG, B: newB, A: 255})
							}
						}
					}
				}
			}
		}
	}
}

// 5x7 Standard Minimalist Font Matrix
func getGlyph(r rune) [7]uint8 {
	switch r {
	case 'A':
		return [7]uint8{0x0E, 0x11, 0x11, 0x1F, 0x11, 0x11, 0x11}
	case 'B':
		return [7]uint8{0x1E, 0x11, 0x11, 0x1E, 0x11, 0x11, 0x1E}
	case 'C':
		return [7]uint8{0x0E, 0x11, 0x10, 0x10, 0x10, 0x11, 0x0E}
	case 'D':
		return [7]uint8{0x1C, 0x12, 0x11, 0x11, 0x11, 0x12, 0x1C}
	case 'E':
		return [7]uint8{0x1F, 0x10, 0x10, 0x1E, 0x10, 0x10, 0x1F}
	case 'F':
		return [7]uint8{0x1F, 0x10, 0x10, 0x1E, 0x10, 0x10, 0x10}
	case 'G':
		return [7]uint8{0x0E, 0x11, 0x10, 0x17, 0x11, 0x11, 0x0F}
	case 'H':
		return [7]uint8{0x11, 0x11, 0x11, 0x1F, 0x11, 0x11, 0x11}
	case 'I':
		return [7]uint8{0x0E, 0x04, 0x04, 0x04, 0x04, 0x04, 0x0E}
	case 'J':
		return [7]uint8{0x07, 0x02, 0x02, 0x02, 0x02, 0x12, 0x0C}
	case 'K':
		return [7]uint8{0x11, 0x12, 0x14, 0x18, 0x14, 0x12, 0x11}
	case 'L':
		return [7]uint8{0x10, 0x10, 0x10, 0x10, 0x10, 0x10, 0x1F}
	case 'M':
		return [7]uint8{0x11, 0x1B, 0x15, 0x15, 0x11, 0x11, 0x11}
	case 'N':
		return [7]uint8{0x11, 0x19, 0x15, 0x13, 0x11, 0x11, 0x11}
	case 'O':
		return [7]uint8{0x0E, 0x11, 0x11, 0x11, 0x11, 0x11, 0x0E}
	case 'P':
		return [7]uint8{0x1E, 0x11, 0x11, 0x1E, 0x10, 0x10, 0x10}
	case 'Q':
		return [7]uint8{0x0E, 0x11, 0x11, 0x11, 0x15, 0x12, 0x0D}
	case 'R':
		return [7]uint8{0x1E, 0x11, 0x11, 0x1E, 0x14, 0x12, 0x11}
	case 'S':
		return [7]uint8{0x0F, 0x10, 0x10, 0x0E, 0x01, 0x01, 0x1E}
	case 'T':
		return [7]uint8{0x1F, 0x04, 0x04, 0x04, 0x04, 0x04, 0x04}
	case 'U':
		return [7]uint8{0x11, 0x11, 0x11, 0x11, 0x11, 0x11, 0x0E}
	case 'V':
		return [7]uint8{0x11, 0x11, 0x11, 0x11, 0x11, 0x0A, 0x04}
	case 'W':
		return [7]uint8{0x11, 0x11, 0x11, 0x15, 0x15, 0x15, 0x0A}
	case 'X':
		return [7]uint8{0x11, 0x11, 0x0A, 0x04, 0x0A, 0x11, 0x11}
	case 'Y':
		return [7]uint8{0x11, 0x11, 0x0A, 0x04, 0x04, 0x04, 0x04}
	case 'Z':
		return [7]uint8{0x1F, 0x01, 0x02, 0x04, 0x08, 0x10, 0x1F}
	case '|':
		return [7]uint8{0x04, 0x04, 0x04, 0x04, 0x04, 0x04, 0x04}
	case '-':
		return [7]uint8{0x00, 0x00, 0x00, 0x1F, 0x00, 0x00, 0x00}
	case '.':
		return [7]uint8{0x00, 0x00, 0x00, 0x00, 0x00, 0x04, 0x04}
	case '0':
		return [7]uint8{0x0E, 0x11, 0x13, 0x15, 0x19, 0x11, 0x0E}
	case '1':
		return [7]uint8{0x04, 0x0C, 0x04, 0x04, 0x04, 0x04, 0x0E}
	case '2':
		return [7]uint8{0x0E, 0x11, 0x01, 0x06, 0x08, 0x10, 0x1F}
	case '3':
		return [7]uint8{0x1F, 0x02, 0x04, 0x02, 0x01, 0x11, 0x0E}
	case '4':
		return [7]uint8{0x02, 0x06, 0x0A, 0x12, 0x1F, 0x02, 0x02}
	case '5':
		return [7]uint8{0x1F, 0x10, 0x1E, 0x01, 0x01, 0x11, 0x0E}
	case '6':
		return [7]uint8{0x06, 0x08, 0x10, 0x1E, 0x11, 0x11, 0x0E}
	case '7':
		return [7]uint8{0x1F, 0x01, 0x02, 0x04, 0x08, 0x08, 0x08}
	case '8':
		return [7]uint8{0x0E, 0x11, 0x11, 0x0E, 0x11, 0x11, 0x0E}
	case '9':
		return [7]uint8{0x0E, 0x11, 0x11, 0x0F, 0x01, 0x02, 0x0C}
	default:
		return [7]uint8{0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00}
	}
}
