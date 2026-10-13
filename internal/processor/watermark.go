// PT: Renderizador de Marca d'Água para Galerias de Seleção
// EN: Watermark Rendering Engine for Proofing and Client Gallery
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Aplicação de Marca d'Água

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
	Opacity   float64 `json:"opacity"`    // 0.0 - 1.0 (default 0.35)
	Position  string  `json:"position"`   // "center", "diagonal", "bottom-right"
	DrawLines bool    `json:"draw_lines"`
	ImagePath string  `json:"image_path"` // Caminho do PNG oficial (ex: TF25.png)
}

// DefaultWatermarkOptions returns photographic proof default settings.
func DefaultWatermarkOptions() WatermarkOptions {
	return WatermarkOptions{
		Text:      "PEDRO ALMEIDA | PROVA",
		Opacity:   0.35,
		Position:  "center",
		DrawLines: true,
		ImagePath: "",
	}
}

// ApplyWatermark renders a semi-transparent watermark onto an image.
// If ImagePath is provided and the file exists on disk, decodes and overlays the PNG logo.
// If ImagePath is empty or invalid, falls back to the high-contrast text generator.
func ApplyWatermark(src image.Image, opts WatermarkOptions) *image.RGBA {
	bounds := src.Bounds()
	dst := image.NewRGBA(bounds)

	// Copy base image pixels
	for y := bounds.Min.Y; y < bounds.Max.Y; y++ {
		for x := bounds.Min.X; x < bounds.Max.X; x++ {
			dst.Set(x, y, src.At(x, y))
		}
	}

	w := bounds.Dx()
	h := bounds.Dy()
	if w == 0 || h == 0 {
		return dst
	}

	if opts.Opacity <= 0 {
		opts.Opacity = 0.38
	}
	if opts.Opacity > 1.0 {
		opts.Opacity = 1.0
	}
	if opts.Position == "" {
		opts.Position = "center"
	}

	// 1. Auto-discover official PNG watermark (TF25.png) if ImagePath not explicitly provided
	if opts.ImagePath == "" {
		candidates := []string{
			filepath.Join("assets", "TF25.png"),
			"C:\\dev\\zorya\\assets\\TF25.png",
			"TF25.png",
			"C:\\dev\\zorya\\TF25.png",
			"D:\\Tenorio.fot.br\\IDV TENORIO FOTO_RGB\\IDV TENORIO FOTO_RGB\\TF25.png",
		}
		for _, cand := range candidates {
			if fi, err := os.Stat(cand); err == nil && !fi.IsDir() {
				opts.ImagePath = cand
				break
			}
		}
	}

	// 2. Check if PNG image logo was supplied and exists on disk
	appliedPNG := false
	if opts.ImagePath != "" {
		pngImg := loadCachedPNG(opts.ImagePath)
		if pngImg != nil {
			applyPNGWatermark(dst, pngImg, opts)
			appliedPNG = true
			// When official PNG logo is used, do not draw diagonal lines or fallback text
			opts.DrawLines = false
		}
	}

	// 3. Optional subtle diagonal security line across frame (ONLY if PNG logo is NOT active)
	if opts.DrawLines && !appliedPNG {
		lineAlpha := opts.Opacity * 0.45
		for y := bounds.Min.Y; y < bounds.Max.Y; y++ {
			x1 := int(float64(y-bounds.Min.Y) * float64(w) / float64(h))
			drawSoftLine(dst, x1, y, 2, lineAlpha)
			x2 := w - x1
			drawSoftLine(dst, x2, y, 2, lineAlpha)
		}
	}

	// 4. Fallback: if ImagePath is empty or file not found, use existing text watermark generator
	if !appliedPNG {
		if opts.Text == "" {
			opts.Text = "PEDRO ALMEIDA | PROVA"
		}
		text := strings.ToUpper(opts.Text)
		scale := int(math.Max(2.0, float64(w)/380.0))

		switch opts.Position {
		case "bottom-right":
			textW := len(text) * 6 * scale
			textH := 8 * scale
			startX := w - textW - 40
			startY := h - textH - 40
			renderText(dst, text, startX, startY, scale, opts.Opacity)

		case "diagonal":
			// Repeat diagonal pattern across 3 zones
			renderText(dst, text, w/6, h/4, scale, opts.Opacity*0.8)
			renderText(dst, text, w/2-(len(text)*3*scale), h/2, scale, opts.Opacity)
			renderText(dst, text, w*2/5, h*3/4, scale, opts.Opacity*0.8)

		case "bottom", "rodape", "footer":
			textW := len(text) * 6 * scale
			startX := (w - textW) / 2
			startY := h - (8 * scale) - 40
			renderText(dst, text, startX, startY, scale, opts.Opacity)

		case "center":
			fallthrough
		default:
			textW := len(text) * 6 * scale
			startX := (w - textW) / 2
			startY := (h - (8 * scale)) / 2
			renderText(dst, text, startX, startY, scale, opts.Opacity)
		}
	}

	return dst
}

var (
	pngCacheMu   sync.RWMutex
	pngCacheMap  = make(map[string]image.Image)
)

func loadCachedPNG(path string) image.Image {
	if path == "" {
		return nil
	}

	pngCacheMu.RLock()
	img, ok := pngCacheMap[path]
	pngCacheMu.RUnlock()
	if ok && img != nil {
		return img
	}

	resolvedPath := path
	if fi, err := os.Stat(resolvedPath); err != nil || fi.IsDir() {
		candidates := []string{
			filepath.Join("assets", filepath.Base(path)),
			filepath.Join("C:\\dev\\zorya\\assets", filepath.Base(path)),
			filepath.Join("C:\\dev\\zorya", filepath.Base(path)),
			"D:\\Tenorio.fot.br\\IDV TENORIO FOTO_RGB\\IDV TENORIO FOTO_RGB\\TF25.png",
		}
		found := false
		for _, cand := range candidates {
			if fi, err := os.Stat(cand); err == nil && !fi.IsDir() {
				resolvedPath = cand
				found = true
				break
			}
		}
		if !found {
			return nil
		}
	}

	f, err := os.Open(resolvedPath)
	if err != nil {
		return nil
	}
	defer f.Close()

	decoded, _, err := image.Decode(f)
	if err != nil || decoded == nil {
		return nil
	}

	pngCacheMu.Lock()
	pngCacheMap[path] = decoded
	pngCacheMap[resolvedPath] = decoded
	pngCacheMu.Unlock()

	return decoded
}

// applyPNGWatermark overlays a transparent PNG logo onto dst.
// Resizes proportionally to ~22% of photo width and blends with soft alpha (opacity 0.38).
func applyPNGWatermark(dst *image.RGBA, logo image.Image, opts WatermarkOptions) {
	dstBounds := dst.Bounds()
	dstW := dstBounds.Dx()
	dstH := dstBounds.Dy()
	if dstW == 0 || dstH == 0 {
		return
	}

	logoBounds := logo.Bounds()
	origW := logoBounds.Dx()
	origH := logoBounds.Dy()
	if origW == 0 || origH == 0 {
		return
	}

	// Redimensionar proporcionalmente para ocupar cerca de ~22% da largura da imagem já rotacionada
	targetLogoW := int(float64(dstW) * 0.25)
	if targetLogoW < 24 {
		targetLogoW = 24
	}
	if targetLogoW > dstW {
		targetLogoW = dstW
	}

	targetLogoH := int(float64(origH) * float64(targetLogoW) / float64(origW))
	if targetLogoH < 1 {
		targetLogoH = 1
	}
	if targetLogoH > dstH {
		targetLogoH = dstH
	}

	scaledLogo := resizeImageBilinear(logo, targetLogoW, targetLogoH)

	// Determinar posição: centro, rodapé / bottom, ou canto inferior direito
	var startX, startY int
	switch strings.ToLower(opts.Position) {
	case "bottom-right":
		margin := int(float64(dstW) * 0.03)
		if margin < 15 {
			margin = 15
		}
		startX = dstW - targetLogoW - margin
		startY = dstH - targetLogoH - margin
	case "bottom", "rodape", "footer", "bottom-center":
		margin := int(float64(dstH) * 0.04)
		if margin < 15 {
			margin = 15
		}
		startX = (dstW - targetLogoW) / 2
		startY = dstH - targetLogoH - margin
	case "center":
		fallthrough
	default:
		startX = (dstW - targetLogoW) / 2
		startY = (dstH - targetLogoH) / 2
	}
	if startX < 0 {
		startX = 0
	}
	if startY < 0 {
		startY = 0
	}

	// Opacidade suave (padrão 0.38)
	opacity := opts.Opacity
	if opacity <= 0 {
		opacity = 0.38
	} else if opacity > 1.0 {
		opacity = 1.0
	}

	// Alpha blending suave sobre a imagem final
	for ly := 0; ly < targetLogoH; ly++ {
		py := startY + ly
		if py < dstBounds.Min.Y || py >= dstBounds.Max.Y {
			continue
		}
		for lx := 0; lx < targetLogoW; lx++ {
			px := startX + lx
			if px < dstBounds.Min.X || px >= dstBounds.Max.X {
				continue
			}

			logoColor := scaledLogo.RGBAAt(lx, ly)
			if logoColor.A == 0 {
				continue
			}

			effectiveAlpha := (float64(logoColor.A) / 255.0) * opacity
			if effectiveAlpha < 0.005 {
				continue
			}

			orig := dst.RGBAAt(px, py)
			invA := 1.0 - effectiveAlpha

			outR := uint8(math.Min(255, math.Max(0, float64(orig.R)*invA+float64(logoColor.R)*effectiveAlpha)))
			outG := uint8(math.Min(255, math.Max(0, float64(orig.G)*invA+float64(logoColor.G)*effectiveAlpha)))
			outB := uint8(math.Min(255, math.Max(0, float64(orig.B)*invA+float64(logoColor.B)*effectiveAlpha)))

			dst.SetRGBA(px, py, color.RGBA{R: outR, G: outG, B: outB, A: orig.A})
		}
	}
}

// resizeImageBilinear scales an image using bilinear interpolation preserving alpha transparency.
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

// drawSoftLine blends a faint security line across coordinates.
func drawSoftLine(dst *image.RGBA, cx, cy, thickness int, alpha float64) {
	bounds := dst.Bounds()
	rVal := 255.0
	gVal := 255.0
	bVal := 255.0

	for dx := -thickness; dx <= thickness; dx++ {
		x := cx + dx
		if x < bounds.Min.X || x >= bounds.Max.X || cy < bounds.Min.Y || cy >= bounds.Max.Y {
			continue
		}
		orig := dst.RGBAAt(x, cy)
		invA := 1.0 - alpha
		outR := uint8(math.Min(255, float64(orig.R)*invA+rVal*alpha))
		outG := uint8(math.Min(255, float64(orig.G)*invA+gVal*alpha))
		outB := uint8(math.Min(255, float64(orig.B)*invA+bVal*alpha))
		dst.SetRGBA(x, cy, color.RGBA{R: outR, G: outG, B: outB, A: orig.A})
	}
}

// renderText renders a pixel-perfect 5x7 matrix font with outline glow and semi-transparent fill.
func renderText(dst *image.RGBA, text string, startX, startY, scale int, alpha float64) {
	cursorX := startX
	for _, ch := range text {
		bitmap := getGlyph(ch)
		// Draw dark glow outline first
		drawGlyph(dst, bitmap, cursorX, startY, scale, color.RGBA{R: 20, G: 20, B: 20, A: 255}, alpha*0.8, true)
		// Draw bright text inside
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
					// Draw pixel scaled block
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
