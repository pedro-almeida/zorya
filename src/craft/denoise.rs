// PT: LightCraft Denoise Clássico Determinístico (Bilateral, Wiener, Unsharp Mask, Convolução)
// EN: LightCraft Deterministic Classical Denoise (Bilateral, Wiener, Unsharp Mask, Convolution)
// AI: @llm-context Extrai de lightcraft-denoise-core; endpoint POST /api/v1/image/denoise
// AI: @llm-invariant PROIBIDO denoise baseado em IA/redes neurais; exclusivamente processamento clássico de sinal

use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use image::{DynamicImage, ImageBuffer, ImageFormat, Rgb};
use rayon::prelude::*;
use serde::{Deserialize, Serialize};
use std::io::Cursor;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum ClassicalDenoiseMethod {
    Bilateral,
    Wiener,
    Unsharp,
    Convolution,
}

impl ClassicalDenoiseMethod {
    pub fn from_str_lossy(s: &str) -> Self {
        match s.to_ascii_lowercase().as_str() {
            "wiener" => Self::Wiener,
            "unsharp" => Self::Unsharp,
            "convolution" | "kernel" => Self::Convolution,
            _ => Self::Bilateral,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ClassicalDenoiseRequest {
    pub image_base64: Option<String>,
    pub method: ClassicalDenoiseMethod,
    pub intensity: f32,
    pub sharpen_amount: Option<f32>,
    pub kernel: Option<Vec<f32>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ClassicalDenoiseResponse {
    pub status: String,
    pub method_applied: ClassicalDenoiseMethod,
    pub width: u32,
    pub height: u32,
    pub ai_neural_used: bool,
    pub processed_image_base64: String,
}

pub fn denoise_image_classical(req: &ClassicalDenoiseRequest) -> anyhow::Result<ClassicalDenoiseResponse> {
    let raw_b64 = req
        .image_base64
        .as_deref()
        .ok_or_else(|| anyhow::anyhow!("Missing image_base64 / Campo image_base64 ausente"))?;
    let bytes = BASE64.decode(raw_b64.trim())?;
    let img = image::load_from_memory(&bytes)?;
    let rgb = img.to_rgb8();
    let (w, h) = rgb.dimensions();
    let width = w as usize;
    let height = h as usize;

    let mut planar: Vec<f32> = rgb.as_raw().iter().map(|&v| (v as f32) / 255.0).collect();
    let intensity = req.intensity.clamp(0.05, 3.0);

    match req.method {
        ClassicalDenoiseMethod::Bilateral => {
            apply_bilateral_filter(&mut planar, width, height, intensity);
        }
        ClassicalDenoiseMethod::Wiener => {
            apply_wiener_filter(&mut planar, width, height, intensity);
        }
        ClassicalDenoiseMethod::Unsharp => {
            let amount = req.sharpen_amount.unwrap_or(45.0).clamp(1.0, 100.0) / 100.0;
            apply_unsharp_mask(&mut planar, width, height, intensity, amount);
        }
        ClassicalDenoiseMethod::Convolution => {
            let default_kernel = vec![
                0.0, -0.5 * intensity, 0.0,
                -0.5 * intensity, 1.0 + 2.0 * intensity, -0.5 * intensity,
                0.0, -0.5 * intensity, 0.0,
            ];
            let k = req.kernel.as_deref().unwrap_or(&default_kernel);
            apply_convolution_sharpen(&mut planar, width, height, k);
        }
    }

    let out_bytes_raw: Vec<u8> = planar
        .iter()
        .map(|&v| (v.clamp(0.0, 1.0) * 255.0).round() as u8)
        .collect();

    let out_buf: ImageBuffer<Rgb<u8>, Vec<u8>> = ImageBuffer::from_raw(w, h, out_bytes_raw)
        .ok_or_else(|| anyhow::anyhow!("Failed to rebuild RGB buffer"))?;
    let mut encoded = Vec::new();
    DynamicImage::ImageRgb8(out_buf).write_to(&mut Cursor::new(&mut encoded), ImageFormat::Png)?;

    Ok(ClassicalDenoiseResponse {
        status: "ok".to_string(),
        method_applied: req.method,
        width: w,
        height: h,
        ai_neural_used: false,
        processed_image_base64: BASE64.encode(&encoded),
    })
}

// PT: Filtro Bilateral clássico (domínio espacial + fotométrico) que preserva bordas reais e poros
// EN: Classical Bilateral Filter (spatial + range domain) preserving true edges and skin pores
pub fn apply_bilateral_filter(pixels: &mut [f32], width: usize, height: usize, intensity: f32) {
    let radius = 2isize;
    let sigma_s = 1.5f32;
    let sigma_r = (0.045 * intensity).max(0.008);
    let two_sigma_s_sq = 2.0 * sigma_s * sigma_s;
    let two_sigma_r_sq = 2.0 * sigma_r * sigma_r;
    let src = pixels.to_vec();

    pixels
        .par_chunks_mut(width * 3)
        .enumerate()
        .for_each(|(y, row)| {
            for x in 0..width {
                let c_idx = (y * width + x) * 3;
                let (cr, cg, cb) = (src[c_idx], src[c_idx + 1], src[c_idx + 2]);
                let (mut sum_r, mut sum_g, mut sum_b, mut w_sum) = (0.0f32, 0.0f32, 0.0f32, 0.0f32);

                for dy in -radius..=radius {
                    let ny = (y as isize + dy).clamp(0, height as isize - 1) as usize;
                    for dx in -radius..=radius {
                        let nx = (x as isize + dx).clamp(0, width as isize - 1) as usize;
                        let n_idx = (ny * width + nx) * 3;
                        let (nr, ng, nb) = (src[n_idx], src[n_idx + 1], src[n_idx + 2]);

                        let spatial_d2 = (dx * dx + dy * dy) as f32;
                        let range_d2 = (nr - cr).powi(2) + (ng - cg).powi(2) + (nb - cb).powi(2);
                        let w = (-spatial_d2 / two_sigma_s_sq - range_d2 / two_sigma_r_sq).exp();

                        sum_r += nr * w;
                        sum_g += ng * w;
                        sum_b += nb * w;
                        w_sum += w;
                    }
                }
                if w_sum > 1e-6 {
                    row[x * 3] = sum_r / w_sum;
                    row[x * 3 + 1] = sum_g / w_sum;
                    row[x * 3 + 2] = sum_b / w_sum;
                }
            }
        });
}

// PT: Filtro de Wiener MMSE (estimativa de potência espectral/variância local vs potência de ruído)
// EN: Wiener MMSE Filter (local spectral/variance power estimation vs noise power)
pub fn apply_wiener_filter(pixels: &mut [f32], width: usize, height: usize, intensity: f32) {
    let noise_var = (0.015 * intensity).powi(2).max(1e-5);
    let src = pixels.to_vec();

    pixels
        .par_chunks_mut(width * 3)
        .enumerate()
        .for_each(|(y, row)| {
            for x in 0..width {
                for ch in 0..3 {
                    let mut sum = 0.0f32;
                    let mut sum_sq = 0.0f32;
                    let mut count = 0.0f32;
                    for dy in -1isize..=1 {
                        let ny = (y as isize + dy).clamp(0, height as isize - 1) as usize;
                        for dx in -1isize..=1 {
                            let nx = (x as isize + dx).clamp(0, width as isize - 1) as usize;
                            let v = src[(ny * width + nx) * 3 + ch];
                            sum += v;
                            sum_sq += v * v;
                            count += 1.0;
                        }
                    }
                    let mean = sum / count;
                    let var = (sum_sq / count - mean * mean).max(0.0);
                    let center = src[(y * width + x) * 3 + ch];
                    let gain = if var > noise_var {
                        (var - noise_var) / var
                    } else {
                        0.0
                    };
                    row[x * 3 + ch] = mean + gain * (center - mean);
                }
            }
        });
}

// PT: Unsharp Mask clássico com limiar de proteção contra granulação de fundo
// EN: Classical Unsharp Mask with threshold protection against background grain
pub fn apply_unsharp_mask(pixels: &mut [f32], width: usize, height: usize, radius_sigma: f32, amount: f32) {
    let mut blurred = pixels.to_vec();
    apply_bilateral_filter(&mut blurred, width, height, radius_sigma);
    for i in 0..pixels.len() {
        let diff = pixels[i] - blurred[i];
        if diff.abs() > 0.012 {
            pixels[i] = (pixels[i] + amount * diff).clamp(0.0, 1.0);
        }
    }
}

// PT: Sharpening por Convolução com kernel 3x3 configurável
// EN: Convolution Sharpening with configurable 3x3 kernel
pub fn apply_convolution_sharpen(pixels: &mut [f32], width: usize, height: usize, kernel: &[f32]) {
    if kernel.len() < 9 {
        return;
    }
    let src = pixels.to_vec();
    pixels
        .par_chunks_mut(width * 3)
        .enumerate()
        .for_each(|(y, row)| {
            for x in 0..width {
                for ch in 0..3 {
                    let mut acc = 0.0f32;
                    for ky in 0..3isize {
                        let ny = (y as isize + ky - 1).clamp(0, height as isize - 1) as usize;
                        for kx in 0..3isize {
                            let nx = (x as isize + kx - 1).clamp(0, width as isize - 1) as usize;
                            let w = kernel[(ky * 3 + kx) as usize];
                            acc += src[(ny * width + nx) * 3 + ch] * w;
                        }
                    }
                    row[x * 3 + ch] = acc.clamp(0.0, 1.0);
                }
            }
        });
}

// POST /api/v1/image/denoise
pub async fn handle_image_denoise(
    axum::Json(req): axum::Json<ClassicalDenoiseRequest>,
) -> (axum::http::StatusCode, axum::Json<serde_json::Value>) {
    match denoise_image_classical(&req) {
        Ok(res) => (
            axum::http::StatusCode::OK,
            axum::Json(serde_json::to_value(res).unwrap_or_default()),
        ),
        Err(err) => (
            axum::http::StatusCode::BAD_REQUEST,
            axum::Json(serde_json::json!({ "error": err.to_string(), "ai_neural_used": false })),
        ),
    }
}
