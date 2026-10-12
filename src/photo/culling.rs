//! Estágio 1 (Triagem das 737 fotos): Ingestão, Culling e Previews WebP
//!
//! Requisitos:
//! - Leitura de preview em ~0.1s por arquivo RAW (DNG, ARW, CR2, CR3, NEF, RAF).
//! - Avaliação rápida de foco/nitidez via variância do operador Laplaciano.
//! - Balanço de branco e contraste automático leve.
//! - Exportação WebP (largura máx 2048px, q=82) com marca d'água "Pedro Luís Fotografia".

use std::fs::File;
use std::io::Read;
use std::path::Path;
use image::{DynamicImage, GenericImageView, Rgb, RgbImage};
use image::imageops::FilterType;
use serde::{Deserialize, Serialize};

/// Resultado da análise e culling de uma foto no Estágio 1
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PhotoCullItem {
    pub file_name: String,
    pub original_path: String,
    pub preview_path: String,
    pub width: u32,
    pub height: u32,
    pub sharpness_score: f32,      // 0.0 a 100.0 (Laplacian variance calibrada)
    pub exposure_score: f32,       // 0.0 a 100.0 (Equilíbrio de histograma)
    pub is_sharp: bool,            // Foco satisfatório
    pub is_well_exposed: bool,     // Exposição equilibrada
    pub duration_ms: u64,
}

/// Extrai o preview de câmera integrado (JPEG embedded) de arquivos RAW em ~0.1s
pub fn extract_raw_embedded_preview(path: &Path) -> anyhow::Result<DynamicImage> {
    let mut file = File::open(path)?;
    let mut bytes = Vec::new();
    file.read_to_end(&mut bytes)?;

    // Procura streams de JPEG embutido (SOI 0xFF 0xD8 .. EOI 0xFF 0xD9)
    if let Some(jpeg_bytes) = find_largest_embedded_jpeg(&bytes) {
        if let Ok(img) = image::load_from_memory_with_format(jpeg_bytes, image::ImageFormat::Jpeg) {
            return Ok(img);
        }
    }

    // Fallback: carregar diretamente com a crate image
    let img = image::load_from_memory(&bytes)?;
    Ok(img)
}

fn find_largest_embedded_jpeg(bytes: &[u8]) -> Option<&[u8]> {
    let mut largest: Option<&[u8]> = None;
    let mut max_len = 0;
    let len = bytes.len();

    if len < 4 {
        return None;
    }

    let mut i = 0;
    while i + 4 < len {
        if bytes[i] == 0xFF && bytes[i + 1] == 0xD8 {
            let start = i;
            let mut j = i + 2;
            let mut found_eoi = false;
            let mut end = start;

            while j + 1 < len {
                if bytes[j] == 0xFF && bytes[j + 1] == 0xD9 {
                    end = j + 2;
                    found_eoi = true;
                    break;
                }
                j += 1;
            }

            if found_eoi {
                let candidate_len = end - start;
                if candidate_len > 50_000 && candidate_len > max_len {
                    max_len = candidate_len;
                    largest = Some(&bytes[start..end]);
                }
                i = end;
                continue;
            }
        }
        i += 1;
    }

    largest
}

/// Avaliação rápida de foco/nitidez via variância do operador Laplaciano
pub fn compute_laplacian_sharpness(img: &DynamicImage) -> f32 {
    let gray = img.to_luma8();
    let (w, h) = gray.dimensions();
    if w < 3 || h < 3 {
        return 0.0;
    }

    let step = if w > 2000 { 2 } else { 1 };
    let mut sum = 0.0f64;
    let mut sum_sq = 0.0f64;
    let mut count = 0usize;

    for y in (1..h - 1).step_by(step) {
        for x in (1..w - 1).step_by(step) {
            let p_center = gray.get_pixel(x, y)[0] as f64;
            let p_up = gray.get_pixel(x, y - 1)[0] as f64;
            let p_down = gray.get_pixel(x, y + 1)[0] as f64;
            let p_left = gray.get_pixel(x - 1, y)[0] as f64;
            let p_right = gray.get_pixel(x + 1, y)[0] as f64;

            let lap = p_up + p_down + p_left + p_right - 4.0 * p_center;
            sum += lap;
            sum_sq += lap * lap;
            count += 1;
        }
    }

    if count == 0 {
        return 0.0;
    }

    let mean = sum / (count as f64);
    let variance = (sum_sq / (count as f64)) - (mean * mean);
    (variance.sqrt() * 4.5).clamp(0.0, 100.0) as f32
}

/// Análise de histograma para exposição e dynamic range
pub fn analyze_exposure_histogram(img: &DynamicImage) -> (f32, bool) {
    let gray = img.to_luma8();
    let (w, h) = gray.dimensions();
    let total_pixels = (w * h) as f32;
    if total_pixels == 0.0 {
        return (0.0, false);
    }

    let mut histogram = [0u32; 256];
    for p in gray.pixels() {
        histogram[p[0] as usize] += 1;
    }

    let deep_shadows: u32 = histogram[0..12].iter().sum();
    let blown_highlights: u32 = histogram[244..256].iter().sum();

    let shadow_ratio = (deep_shadows as f32) / total_pixels;
    let highlight_ratio = (blown_highlights as f32) / total_pixels;

    let penalty = (shadow_ratio * 40.0) + (highlight_ratio * 50.0);
    let exposure_score = (100.0 - penalty).clamp(0.0, 100.0);
    let is_well_exposed = exposure_score >= 65.0;

    (exposure_score, is_well_exposed)
}

/// Balanço de branco e contraste automático leve
pub fn apply_preview_color_and_tone(img: &DynamicImage) -> RgbImage {
    let mut rgb = img.to_rgb8();
    let (w, h) = rgb.dimensions();
    let total_pixels = (w * h) as f64;
    if total_pixels == 0.0 {
        return rgb;
    }

    let mut sum_r = 0.0f64;
    let mut sum_g = 0.0f64;
    let mut sum_b = 0.0f64;

    for p in rgb.pixels() {
        sum_r += p[0] as f64;
        sum_g += p[1] as f64;
        sum_b += p[2] as f64;
    }

    let avg_r = (sum_r / total_pixels).max(1.0);
    let avg_g = (sum_g / total_pixels).max(1.0);
    let avg_b = (sum_b / total_pixels).max(1.0);
    let avg_gray = (avg_r + avg_g + avg_b) / 3.0;

    let gain_r = ((avg_gray / avg_r) as f32).clamp(0.75, 1.35);
    let gain_g = ((avg_gray / avg_g) as f32).clamp(0.85, 1.20);
    let gain_b = ((avg_gray / avg_b) as f32).clamp(0.75, 1.35);

    for p in rgb.pixels_mut() {
        let r_lin = ((p[0] as f32) * gain_r / 255.0).clamp(0.0, 1.0);
        let g_lin = ((p[1] as f32) * gain_g / 255.0).clamp(0.0, 1.0);
        let b_lin = ((p[2] as f32) * gain_b / 255.0).clamp(0.0, 1.0);

        let tone = |x: f32| -> u8 {
            let s_curve = 3.0 * x * x - 2.0 * x * x * x;
            let blended = x * 0.35 + s_curve * 0.65;
            (blended * 255.0).clamp(0.0, 255.0) as u8
        };

        p[0] = tone(r_lin);
        p[1] = tone(g_lin);
        p[2] = tone(b_lin);
    }

    rgb
}

/// Aplicação da marca d'água oficial "Pedro Luís Fotografia"
pub fn apply_official_watermark(img: &mut RgbImage) {
    let (w, h) = img.dimensions();
    if w < 100 || h < 100 {
        return;
    }

    // Faixa escurecida no rodapé
    let footer_height = (h as f32 * 0.05).max(38.0).min(75.0) as u32;
    let footer_start_y = h.saturating_sub(footer_height);

    for y in footer_start_y..h {
        for x in 0..w {
            let pixel = img.get_pixel_mut(x, y);
            pixel[0] = ((pixel[0] as f32) * 0.35) as u8;
            pixel[1] = ((pixel[1] as f32) * 0.35) as u8;
            pixel[2] = ((pixel[2] as f32) * 0.35) as u8;
        }
    }

    let footer_text = "PEDRO LUIS FOTOGRAFIA  |  PROVA DIGITAL  |  SELECAO SVITANOK";
    let text_x = (w.saturating_sub(footer_text.len() as u32 * 9)) / 2;
    let text_y = footer_start_y + (footer_height.saturating_sub(14)) / 2;
    draw_simple_text(img, text_x, text_y, footer_text, Rgb([240, 240, 240]), 0.95);

    let center_text = "PEDRO LUIS FOTOGRAFIA";
    let scale = if w > 1200 { 3 } else { 2 };
    let center_x = w.saturating_sub(center_text.len() as u32 * 8 * scale) / 2;
    let center_y = h / 2 - (8 * scale);
    draw_large_text(img, center_x, center_y, center_text, Rgb([255, 255, 255]), 0.28, scale);
}

fn draw_simple_text(img: &mut RgbImage, start_x: u32, start_y: u32, text: &str, color: Rgb<u8>, alpha: f32) {
    let mut cur_x = start_x;
    for ch in text.chars() {
        let glyph = get_glyph_bitmap(ch);
        for row in 0..10 {
            for col in 0..7 {
                if (glyph[row] & (1 << (6 - col))) != 0 {
                    let px = cur_x + col;
                    let py = start_y + row as u32;
                    if px < img.width() && py < img.height() {
                        let p = img.get_pixel_mut(px, py);
                        p[0] = ((p[0] as f32) * (1.0 - alpha) + (color[0] as f32) * alpha) as u8;
                        p[1] = ((p[1] as f32) * (1.0 - alpha) + (color[1] as f32) * alpha) as u8;
                        p[2] = ((p[2] as f32) * (1.0 - alpha) + (color[2] as f32) * alpha) as u8;
                    }
                }
            }
        }
        cur_x += 8;
    }
}

fn draw_large_text(img: &mut RgbImage, start_x: u32, start_y: u32, text: &str, color: Rgb<u8>, alpha: f32, scale: u32) {
    let mut cur_x = start_x;
    for ch in text.chars() {
        let glyph = get_glyph_bitmap(ch);
        for row in 0..10 {
            for col in 0..7 {
                if (glyph[row] & (1 << (6 - col))) != 0 {
                    for sy in 0..scale {
                        for sx in 0..scale {
                            let px = cur_x + (col * scale) + sx;
                            let py = start_y + (row as u32 * scale) + sy;
                            if px < img.width() && py < img.height() {
                                let p = img.get_pixel_mut(px, py);
                                p[0] = ((p[0] as f32) * (1.0 - alpha) + (color[0] as f32) * alpha) as u8;
                                p[1] = ((p[1] as f32) * (1.0 - alpha) + (color[1] as f32) * alpha) as u8;
                                p[2] = ((p[2] as f32) * (1.0 - alpha) + (color[2] as f32) * alpha) as u8;
                            }
                        }
                    }
                }
            }
        }
        cur_x += 8 * scale;
    }
}

fn get_glyph_bitmap(ch: char) -> [u8; 10] {
    match ch.to_ascii_uppercase() {
        'A' => [0x1C, 0x36, 0x63, 0x63, 0x7F, 0x7F, 0x63, 0x63, 0x63, 0x00],
        'B' => [0x7E, 0x63, 0x63, 0x7E, 0x7E, 0x63, 0x63, 0x63, 0x7E, 0x00],
        'C' => [0x3E, 0x63, 0x60, 0x60, 0x60, 0x60, 0x60, 0x63, 0x3E, 0x00],
        'D' => [0x7C, 0x66, 0x63, 0x63, 0x63, 0x63, 0x63, 0x66, 0x7C, 0x00],
        'E' => [0x7F, 0x60, 0x60, 0x7E, 0x7E, 0x60, 0x60, 0x60, 0x7F, 0x00],
        'F' => [0x7F, 0x60, 0x60, 0x7E, 0x7E, 0x60, 0x60, 0x60, 0x60, 0x00],
        'G' => [0x3E, 0x63, 0x60, 0x60, 0x6F, 0x63, 0x63, 0x63, 0x3F, 0x00],
        'H' => [0x63, 0x63, 0x63, 0x7F, 0x7F, 0x63, 0x63, 0x63, 0x63, 0x00],
        'I' => [0x3E, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x3E, 0x00],
        'J' => [0x07, 0x03, 0x03, 0x03, 0x03, 0x03, 0x63, 0x63, 0x3E, 0x00],
        'K' => [0x63, 0x66, 0x6C, 0x78, 0x78, 0x6C, 0x66, 0x63, 0x63, 0x00],
        'L' => [0x60, 0x60, 0x60, 0x60, 0x60, 0x60, 0x60, 0x60, 0x7F, 0x00],
        'M' => [0x63, 0x77, 0x7F, 0x6B, 0x63, 0x63, 0x63, 0x63, 0x63, 0x00],
        'N' => [0x63, 0x73, 0x7B, 0x6F, 0x67, 0x63, 0x63, 0x63, 0x63, 0x00],
        'O' => [0x3E, 0x63, 0x63, 0x63, 0x63, 0x63, 0x63, 0x63, 0x3E, 0x00],
        'P' => [0x7E, 0x63, 0x63, 0x63, 0x7E, 0x60, 0x60, 0x60, 0x60, 0x00],
        'Q' => [0x3E, 0x63, 0x63, 0x63, 0x63, 0x63, 0x6B, 0x37, 0x1F, 0x00],
        'R' => [0x7E, 0x63, 0x63, 0x63, 0x7E, 0x6C, 0x66, 0x63, 0x63, 0x00],
        'S' => [0x3E, 0x63, 0x60, 0x3C, 0x0E, 0x03, 0x03, 0x63, 0x3E, 0x00],
        'T' => [0x7F, 0x5D, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x00],
        'U' => [0x63, 0x63, 0x63, 0x63, 0x63, 0x63, 0x63, 0x63, 0x3E, 0x00],
        'V' => [0x63, 0x63, 0x63, 0x63, 0x63, 0x36, 0x36, 0x1C, 0x08, 0x00],
        'W' => [0x63, 0x63, 0x63, 0x63, 0x6B, 0x6B, 0x7F, 0x36, 0x36, 0x00],
        'X' => [0x63, 0x63, 0x36, 0x1C, 0x1C, 0x36, 0x63, 0x63, 0x63, 0x00],
        'Y' => [0x63, 0x63, 0x36, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x00],
        'Z' => [0x7F, 0x07, 0x0E, 0x1C, 0x38, 0x70, 0x60, 0x60, 0x7F, 0x00],
        '|' => [0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x1C, 0x00],
        '-' => [0x00, 0x00, 0x00, 0x00, 0x7F, 0x7F, 0x00, 0x00, 0x00, 0x00],
        '.' => [0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x1C, 0x1C, 0x00],
        _ => [0x00; 10],
    }
}

/// Executa o pipeline de triagem (Estágio 1) em um arquivo individual
pub fn process_cull_photo(
    raw_path: &Path,
    preview_output_dir: &Path,
) -> anyhow::Result<PhotoCullItem> {
    let start_time = std::time::Instant::now();
    let file_name = raw_path
        .file_name()
        .map(|s| s.to_string_lossy().to_string())
        .unwrap_or_else(|| "photo.raw".to_string());

    let raw_preview = extract_raw_embedded_preview(raw_path)?;
    let (orig_w, orig_h) = raw_preview.dimensions();

    let sharpness = compute_laplacian_sharpness(&raw_preview);
    let (exposure_score, is_well_exposed) = analyze_exposure_histogram(&raw_preview);
    let is_sharp = sharpness >= 45.0;

    let max_dimension = 2048u32;
    let (target_w, target_h) = if orig_w > max_dimension || orig_h > max_dimension {
        if orig_w >= orig_h {
            let ratio = (max_dimension as f32) / (orig_w as f32);
            (max_dimension, ((orig_h as f32) * ratio).round() as u32)
        } else {
            let ratio = (max_dimension as f32) / (orig_h as f32);
            (((orig_w as f32) * ratio).round() as u32, max_dimension)
        }
    } else {
        (orig_w, orig_h)
    };

    let resized = raw_preview.resize(target_w, target_h, FilterType::Triangle);
    let mut balanced_rgb = apply_preview_color_and_tone(&resized);
    apply_official_watermark(&mut balanced_rgb);

    std::fs::create_dir_all(preview_output_dir)?;
    let stem = raw_path.file_stem().unwrap_or_default().to_string_lossy();
    let webp_file_name = format!("{}.webp", stem);
    let webp_path = preview_output_dir.join(&webp_file_name);

    let final_dyn = DynamicImage::ImageRgb8(balanced_rgb);
    final_dyn.save_with_format(&webp_path, image::ImageFormat::WebP)?;

    let elapsed = start_time.elapsed().as_millis() as u64;

    Ok(PhotoCullItem {
        file_name,
        original_path: raw_path.to_string_lossy().to_string(),
        preview_path: webp_path.to_string_lossy().to_string(),
        width: target_w,
        height: target_h,
        sharpness_score: sharpness,
        exposure_score,
        is_sharp,
        is_well_exposed,
        duration_ms: elapsed,
    })
}
