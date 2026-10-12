// PT: Renderizador de Marca d'Água para Galerias de Seleção
// EN: Watermark Rendering Engine for Proofing and Client Gallery
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Aplicação de Marca d'Água

package processor

import (
	"image"
	"image/color"
	"math"
	"strings"
)

// WatermarkOptions defines formatting for the gallery watermark.
type WatermarkOptions struct {
	Text      string  `json:"text"`     // default: "PEDRO ALMEIDA | PROVA"
	Opacity   float64 `json:"opacity"`  // 0.0 - 1.0 (default 0.35)
	Position  string  `json:"position"` // "center", "diagonal", "bottom-right"
	DrawLines bool    `json:"draw_lines"`
}

// DefaultWatermarkOptions returns photographic proof default settings.
func DefaultWatermarkOptions() WatermarkOptions {
	return WatermarkOptions{
		Text:      "PEDRO ALMEIDA | PROVA",
		Opacity:   0.35,
		Position:  "center",
		DrawLines: true,
	}
}

// ApplyWatermark renders a semi-transparent watermark onto an image.
func ApplyWatermark(src image.Image, opts WatermarkOptions) *image.RGBA {
	bounds := src.Bounds()
	dst := image.NewRGBA(bounds)

	// Copy base image pixels
	for y := bounds.Min.Y; y < bounds.Max.Y; y++ {
		for x := bounds.Min.X; x < bounds.Max.X; x++ {
			dst.Set(x, y, src.At(x, y))
		}
	}

	if opts.Opacity <= 0 {
		return dst
	}
	if opts.Opacity > 1.0 {
		opts.Opacity = 1.0
	}
	if opts.Text == "" {
		opts.Text = "PEDRO ALMEIDA | PROVA"
	}
	if opts.Position == "" {
		opts.Position = "center"
	}

	w := bounds.Dx()
	h := bounds.Dy()

	// 1. Optional subtle diagonal security line across frame
	if opts.DrawLines {
		lineAlpha := opts.Opacity * 0.45
		for y := bounds.Min.Y; y < bounds.Max.Y; y++ {
			x1 := int(float64(y-bounds.Min.Y) * float64(w) / float64(h))
			drawSoftLine(dst, x1, y, 2, lineAlpha)
			x2 := w - x1
			drawSoftLine(dst, x2, y, 2, lineAlpha)
		}
	}

	// 2. Render text with shadow/glow for contrast against dark or bright backgrounds
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

	case "center":
		fallthrough
	default:
		textW := len(text) * 6 * scale
		startX := (w - textW) / 2
		startY := (h - (8 * scale)) / 2
		renderText(dst, text, startX, startY, scale, opts.Opacity)
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
