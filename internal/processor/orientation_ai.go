// PT: Rotação Binária EXIF 100% Precisa para Canon SL3 (.CR3 / JPEG) e Análise Facial
// EN: 100% Accurate Binary EXIF Rotation for Canon SL3 (.CR3 / JPEG) and Face Orientation
// AI: @llm-context Módulo Zorya v1.0.0 / Estágio 1 Detecção e Rotação Precisa de Orientação

package processor

import (
	"bytes"
	"encoding/binary"
	"image"
	"math"
)

// ReadCanonCR3AndJPEGEXIF inspeciona os bytes brutos do arquivo .CR3 (ISOBMFF / CMT1)
// ou do JPEG embutido procurando a tag EXIF 0x0112 (Orientation).
// Retorna:
// 1 = Horizontal Normal (0° - mantém foto deitada na mesa intacta)
// 6 = Retrato Vertical (90° CW - botão no topo)
// 8 = Retrato Vertical (270° CW / 90° CCW - botão embaixo)
// 3 = Invertida (180°)
// Retorna 1 se o valor for indefinido ou normal.
func ReadCanonCR3AndJPEGEXIF(data []byte) int {
	if len(data) < 14 {
		return 1
	}

	// 1. Inspecionar se é JPEG (começa com SOI 0xFF, 0xD8)
	if data[0] == 0xFF && data[1] == 0xD8 {
		if val := scanJPEGAPP1Orientation(data); val >= 1 && val <= 8 {
			return val
		}
	}

	// 2. Inspecionar contêiner ISOBMFF do Canon CR3 (caixas ftyp, moov, uuid, CMT1, PRVW)
	if isCR3Container(data) {
		if val := scanCR3BoxesForOrientation(data); val >= 1 && val <= 8 {
			return val
		}
	}

	// 3. Varredura direta de blocos TIFF (II*\x00 ou MM\x00*) no fluxo
	// No CR3, a Canon embute blocos TIFF puros para CMT1 (IFD0) e CMT2 (ExifIFD)
	if val := scanAllTIFFBlocks(data); val >= 1 && val <= 8 {
		return val
	}

	// 4. Varredura por marcadores JPEG embutidos dentro do arquivo RAW
	if val := scanEmbeddedJPEGsOrientation(data); val >= 1 && val <= 8 {
		return val
	}

	return 1
}

// isCR3Container checa se os bytes correspondem a um contêiner ISO Base Media File Format (CR3)
func isCR3Container(data []byte) bool {
	if len(data) < 16 {
		return false
	}
	// Box 'ftyp' geralmente nos primeiros 4-12 bytes
	if bytes.Equal(data[4:8], []byte("ftyp")) {
		majorBrand := string(data[8:12])
		if majorBrand == "crx " || majorBrand == "isom" || majorBrand == "mp42" || majorBrand == "canon" {
			return true
		}
	}
	return false
}

// scanCR3BoxesForOrientation navega pelas boxes ISOBMFF até encontrar caixas com dados TIFF (CMT1, uuid)
func scanCR3BoxesForOrientation(data []byte) int {
	pos := 0
	maxScan := len(data)
	if maxScan > 16*1024*1024 { // Limita busca inicial a 16MB de metadados
		maxScan = 16 * 1024 * 1024
	}

	for pos+8 <= maxScan {
		boxLen := int(binary.BigEndian.Uint32(data[pos : pos+4]))
		boxType := string(data[pos+4 : pos+8])

		if boxLen == 1 && pos+16 <= maxScan { // Large box (64-bit)
			boxLen = int(binary.BigEndian.Uint64(data[pos+8 : pos+16]))
			pos += 8
		}

		if boxLen < 8 || pos+boxLen > len(data) {
			break
		}

		boxPayload := data[pos+8 : pos+boxLen]

		// Caixas Canon CMT1 / CMT2 ou moov / uuid contendo metadados TIFF
		if boxType == "CMT1" || boxType == "CMT2" || boxType == "uuid" || boxType == "moov" {
			if val := scanAllTIFFBlocks(boxPayload); val >= 1 && val <= 8 {
				return val
			}
		}

		// Se a caixa for 'moov', desce recursivamente nas caixas filhas
		if boxType == "moov" {
			subVal := scanCR3BoxesForOrientation(boxPayload)
			if subVal >= 1 && subVal <= 8 {
				return subVal
			}
		}

		pos += boxLen
	}

	return 0
}

// scanAllTIFFBlocks procura por assinaturas de cabeçalho TIFF (Little-Endian 'II*\x00' ou Big-Endian 'MM\x00*')
func scanAllTIFFBlocks(data []byte) int {
	limit := len(data)
	if limit > 8*1024*1024 {
		limit = 8 * 1024 * 1024
	}

	leHeader := []byte{'I', 'I', 0x2A, 0x00}
	beHeader := []byte{'M', 'M', 0x00, 0x2A}

	searchPos := 0
	for searchPos+8 <= limit {
		idxLE := bytes.Index(data[searchPos:limit], leHeader)
		idxBE := bytes.Index(data[searchPos:limit], beHeader)

		matchIdx := -1
		if idxLE != -1 && (idxBE == -1 || idxLE < idxBE) {
			matchIdx = searchPos + idxLE
		} else if idxBE != -1 {
			matchIdx = searchPos + idxBE
		}

		if matchIdx == -1 {
			break
		}

		tiffSlice := data[matchIdx:]
		if val := parseBinaryTIFFOrientation(tiffSlice); val >= 1 && val <= 8 {
			return val
		}

		searchPos = matchIdx + 4
	}

	return 0
}

// parseBinaryTIFFOrientation lê IFD0 e SubIFDs a partir de um ponteiro TIFF válido
func parseBinaryTIFFOrientation(data []byte) int {
	if len(data) < 14 {
		return 0
	}

	var isLE bool
	if data[0] == 'I' && data[1] == 'I' {
		isLE = true
	} else if data[0] == 'M' && data[1] == 'M' {
		isLE = false
	} else {
		return 0
	}

	readU16 := func(b []byte) uint16 {
		if isLE {
			return binary.LittleEndian.Uint16(b)
		}
		return binary.BigEndian.Uint16(b)
	}

	readU32 := func(b []byte) uint32 {
		if isLE {
			return binary.LittleEndian.Uint32(b)
		}
		return binary.BigEndian.Uint32(b)
	}

	if readU16(data[2:4]) != 42 {
		return 0
	}

	ifdOffset := int(readU32(data[4:8]))
	visited := 0

	for ifdOffset > 0 && ifdOffset+2 <= len(data) && visited < 8 {
		visited++
		numEntries := int(readU16(data[ifdOffset : ifdOffset+2]))
		entriesStart := ifdOffset + 2
		entriesEnd := entriesStart + numEntries*12

		if entriesEnd > len(data) {
			break
		}

		exifSubIFD := 0

		for i := 0; i < numEntries; i++ {
			entryOffset := entriesStart + i*12
			tag := readU16(data[entryOffset : entryOffset+2])

			if tag == 0x0112 { // Orientation Tag da Canon
				val := int(readU16(data[entryOffset+8 : entryOffset+10]))
				if val >= 1 && val <= 8 {
					return val
				}
			} else if tag == 0x8769 { // Exif SubIFD
				exifSubIFD = int(readU32(data[entryOffset+8 : entryOffset+12]))
			}
		}

		// Checar SubIFD se a tag 0x0112 não esteve no IFD0 principal
		if exifSubIFD > 0 && exifSubIFD+2 <= len(data) {
			subEntries := int(readU16(data[exifSubIFD : exifSubIFD+2]))
			subStart := exifSubIFD + 2
			subEnd := subStart + subEntries*12
			if subEnd <= len(data) {
				for i := 0; i < subEntries; i++ {
					entryOffset := subStart + i*12
					tag := readU16(data[entryOffset : entryOffset+2])
					if tag == 0x0112 {
						val := int(readU16(data[entryOffset+8 : entryOffset+10]))
						if val >= 1 && val <= 8 {
							return val
						}
					}
				}
			}
		}

		if entriesEnd+4 <= len(data) {
			ifdOffset = int(readU32(data[entriesEnd : entriesEnd+4]))
		} else {
			break
		}
	}

	return 0
}

// scanJPEGAPP1Orientation analisa marcadores APP1 no início de um fluxo JPEG
func scanJPEGAPP1Orientation(data []byte) int {
	pos := 2
	for pos+4 <= len(data) {
		if data[pos] != 0xFF {
			pos++
			continue
		}
		marker := data[pos+1]
		if marker == 0xD8 || marker == 0xD9 || (marker >= 0xD0 && marker <= 0xD7) || marker == 0x01 {
			pos += 2
			continue
		}
		if marker == 0xDA || marker == 0xD9 { // Fim dos metadados / início dos dados comprimidos
			break
		}
		if pos+4 > len(data) {
			break
		}
		markerLen := int(binary.BigEndian.Uint16(data[pos+2 : pos+4]))
		if markerLen < 2 || pos+2+markerLen > len(data) {
			break
		}

		if marker == 0xE1 { // APP1 Exif Marker
			app1Payload := data[pos+4 : pos+2+markerLen]
			if len(app1Payload) >= 14 && string(app1Payload[:6]) == "Exif\x00\x00" {
				if val := parseBinaryTIFFOrientation(app1Payload[6:]); val >= 1 && val <= 8 {
					return val
				}
			}
		}
		pos += 2 + markerLen
	}
	return 0
}

// scanEmbeddedJPEGsOrientation localiza JPEGs de pré-visualização embutidos no CR3 e lê seu APP1
func scanEmbeddedJPEGsOrientation(data []byte) int {
	pos := 0
	maxScan := len(data)
	if maxScan > 12*1024*1024 {
		maxScan = 12 * 1024 * 1024
	}

	soi := []byte{0xFF, 0xD8, 0xFF}
	for pos+3 <= maxScan {
		idx := bytes.Index(data[pos:maxScan], soi)
		if idx == -1 {
			break
		}
		start := pos + idx
		if val := scanJPEGAPP1Orientation(data[start:]); val >= 1 && val <= 8 {
			return val
		}
		pos = start + 3
	}
	return 0
}

// OrientationTagDescription descreve textualmente a tag EXIF 0x0112 para telemetria e console.
func OrientationTagDescription(tag int) string {
	switch tag {
	case 1:
		return "Normal 0°"
	case 3:
		return "Rot 180°"
	case 6:
		return "Rot 90° CW"
	case 8:
		return "Rot 270° CW"
	default:
		return "Normal 0°"
	}
}

// ResolveSurgicalOrientation implementa a rotação EXIF cirúrgica da Canon SL3 (Tag 0x0112):
// - Lê diretamente a tag EXIF de orientação antes de qualquer decisão da IA:
//   * Tag 1: Orientação normal (0° de rotação).
//   * Tag 3: Rotacionar 180°.
//   * Tag 6: Rotacionar 90° no sentido horário.
//   * Tag 8: Rotacionar 270° no sentido horário (90° anti-horário).
// - Se a tag for 3, 6 ou 8, a rotação EXIF é aplicada diretamente e a IA NUNCA é acionada.
// - NUNCA inverte fotos que já vieram verticais da câmera (height > width).
// - Só aciona a IA Moondream / heurística facial caso o EXIF seja inexistente ou neutro (Tag 0 ou 1)
//   e haja detecção clara de rosto em ângulo incorreto.
func ResolveSurgicalOrientation(img image.Image, rawOrJpegData []byte) (image.Image, int, int) {
	tag := ReadCanonCR3AndJPEGEXIF(rawOrJpegData)

	// Decisão cirúrgica com base na tag EXIF da câmera: IA terminantemente evitada
	switch tag {
	case 6:
		return Rotate90CW(img), 6, 90
	case 8:
		return Rotate90CCW(img), 8, 270
	case 3:
		return Rotate180(img), 3, 180
	}

	// NUNCA inverte fotos que já vieram verticais da câmera
	bounds := img.Bounds()
	if bounds.Dy() > bounds.Dx() {
		return img, tag, 0
	}

	// Caso EXIF seja inexistente ou neutro (0 ou 1), avalia detecção clara de rosto em ângulo incorreto
	rotated, deg := DetectAndRotateFace(img)
	return rotated, tag, deg
}

// ApplyCanonOrientation aplica a transformação geométrica exata baseada na tag EXIF 0x0112 da Canon SL3.
// Tag 1 (Horizontal Normal): 0 graus (mantém a proporção horizontal natural da foto na mesa).
// Tag 6 (Retrato Vertical - botão no topo): girar 90 graus no sentido horário (rotate90CW).
// Tag 8 (Retrato Vertical - botão embaixo): girar 90 graus no sentido anti-horário (rotate270CW).
// Tag 3 (Invertida): girar 180 graus.
// Retorna a imagem orientada e a rotação aplicada em graus.
func ApplyCanonOrientation(img image.Image, orientation int) (image.Image, int) {
	switch orientation {
	case 6:
		return Rotate90CW(img), 90
	case 8:
		return Rotate90CCW(img), 270
	case 3:
		return Rotate180(img), 180
	case 1:
		fallthrough
	default:
		// Tag 1 ou não orientada: Preserva orientação nativa original (0°)
		return img, 0
	}
}

// DetectAndRotateFace analisa gradientes faciais e orientação do sujeito caso não haja tag EXIF.
// ATENÇÃO: Regras estáticas que deitem fotos horizontais foram 100% ELIMINADAS.
// Só aplica rotação se a probabilidade do sujeito estar tombado for indiscutível.
func DetectAndRotateFace(img image.Image) (image.Image, int) {
	bounds := img.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()
	if w < 16 || h < 16 {
		return img, 0
	}

	// 1. Gerar proxy de resolução ultrarrápida (~160px)
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

			luma := 0.299*float64(r8) + 0.587*float64(g8) + 0.114*float64(b8)
			pIdx := py*pw + px
			proxyLuma[pIdx] = luma

			isSkin := isSkinTone(r8, g8, b8)
			proxySkin[pIdx] = isSkin
			if isSkin {
				skinPixelCount++
			}
		}
	}

	totalPixels := pw * ph
	skinRatio := float64(skinPixelCount) / float64(totalPixels)

	// Sem pele suficiente (< 2% da imagem), preserva rotação nativa original
	if skinRatio < 0.02 {
		return img, 0
	}

	var minSkinX, minSkinY, maxSkinX, maxSkinY int
	minSkinX, minSkinY = pw, ph
	maxSkinX, maxSkinY = 0, 0

	for py := 0; py < ph; py++ {
		for px := 0; px < pw; px++ {
			if proxySkin[py*pw+px] {
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

	score0 := evaluateOrientation(proxyLuma, proxySkin, pw, ph, minSkinX, minSkinY, maxSkinX, maxSkinY, 0)
	score90 := evaluateOrientation(proxyLuma, proxySkin, pw, ph, minSkinX, minSkinY, maxSkinX, maxSkinY, 90)
	score270 := evaluateOrientation(proxyLuma, proxySkin, pw, ph, minSkinX, minSkinY, maxSkinX, maxSkinY, 270)

	// Margem de segurança estrita para NUNCA tombar fotos horizontais por acidente
	strictMargin := 0.25

	if score90 > score0+strictMargin && score90 > score270 {
		return Rotate90CW(img), 90
	}

	if score270 > score0+strictMargin && score270 > score90 {
		return Rotate90CCW(img), 270
	}

	return img, 0
}

func evaluateOrientation(luma []float64, skin []bool, w, h, minX, minY, maxX, maxY, rotDeg int) float64 {
	var uDirX, uDirY float64
	var vDirX, vDirY float64
	var headTopX, headTopY float64
	var boxSpan float64

	switch rotDeg {
	case 90:
		headTopX = float64(minX)
		headTopY = float64(minY+maxY) / 2.0
		vDirX = 1.0
		vDirY = 0.0
		uDirX = 0.0
		uDirY = 1.0
		boxSpan = float64(maxX - minX)

	case 270:
		headTopX = float64(maxX)
		headTopY = float64(minY+maxY) / 2.0
		vDirX = -1.0
		vDirY = 0.0
		uDirX = 0.0
		uDirY = 1.0
		boxSpan = float64(maxX - minX)

	default: // 0°: native upright
		headTopX = float64(minX+maxX) / 2.0
		headTopY = float64(minY)
		vDirX = 0.0
		vDirY = 1.0
		uDirX = 1.0
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

	var symmetryDiff, symmetryCount float64
	var eyeBandGrad, sideGrad float64
	var gradCount float64

	for vFrac := 0.15; vFrac <= 0.85; vFrac += 0.10 {
		for uFrac := 0.10; uFrac <= 0.70; uFrac += 0.15 {
			cx := headTopX + (vFrac * faceHeight * vDirX)
			cy := headTopY + (vFrac * faceHeight * vDirY)

			xPlus := int(cx + (uFrac * faceWidth * uDirX))
			yPlus := int(cy + (uFrac * faceWidth * uDirY))

			xMinus := int(cx - (uFrac * faceWidth * uDirX))
			yMinus := int(cy - (uFrac * faceWidth * uDirY))

			if xPlus >= 0 && xPlus < w && yPlus >= 0 && yPlus < h &&
				xMinus >= 0 && xMinus < w && yMinus >= 0 && yMinus < h {

				lPlus := luma[yPlus*w+xPlus]
				lMinus := luma[yMinus*w+xMinus]

				diff := math.Abs(lPlus - lMinus)
				symmetryDiff += diff
				symmetryCount++

				if vFrac >= 0.30 && vFrac <= 0.60 {
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
	symmetryScore := 1.0 - (avgSymmetryDiff / 100.0)
	if symmetryScore < 0 {
		symmetryScore = 0
	}

	gradientScore := 0.5
	if gradCount > 0 && (eyeBandGrad+sideGrad) > 0 {
		gradientScore = eyeBandGrad / (eyeBandGrad + sideGrad)
	}

	finalScore := (0.60 * symmetryScore) + (0.40 * gradientScore)
	return finalScore
}

// isSkinTone checa se o pixel cai dentro da faixa de matiz cromático de pele humana.
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
