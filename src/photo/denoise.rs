//! Núcleo de Denoise Matemático Clássico e Smart Sharpening (PhotoCraft + LightCraft)
//!
//! DIRETRIZ ESTRITA:
//! - 100% Determinístico e Matemático: Sem redes neurais, sem difusão, sem IA generativa.
//! - Preservação de textura natural da pele, poros e fios de cabelo (evita efeito boneca de porcelana).
//! - Separação Luma/Chroma com filtragem isolada de Cb/Cr.
//! - Filtro bilateral adaptativo com limiar de microdetalhe e Smart Sharpening com compensação de difração.

use rayon::prelude::*;

/// Parâmetros de calibração para o denoise clássico de estúdio
#[derive(Debug, Clone)]
pub struct DenoiseParams {
    /// Intensidade do filtro de luminância (0.0 = desligado, 1.0 = padrão suave, 2.5 = forte)
    pub luma_strength: f32,
    /// Raio espacial da filtragem bilateral (1 a 5 pixels)
    pub luma_spatial_radius: i32,
    /// Limiar estrito de detalhe (detalhes com gradiente acima deste limiar são protegidos para reter poros e cabelo)
    pub detail_threshold: f32,
    /// Intensidade do filtro de crominância (redução de manchas verde/magenta em ISO alto)
    pub chroma_strength: f32,
    /// Raio do filtro de crominância (2 a 6 pixels)
    pub chroma_radius: i32,
    /// Quantidade de Smart Sharpening para compensar difração da lente (0.0 a 100.0)
    pub sharpen_amount: f32,
    /// Raio do Smart Sharpening (0.5 a 3.0 pixels)
    pub sharpen_radius: f32,
    /// Limiar de difração do Smart Sharpening (impede amplificação de granulação fina de fundo)
    pub sharpen_threshold: f32,
}

impl Default for DenoiseParams {
    fn default() -> Self {
        Self {
            luma_strength: 0.85,
            luma_spatial_radius: 2,
            detail_threshold: 0.035, // Preserva microtextura natural de poros e mechas finas
            chroma_strength: 1.2,
            chroma_radius: 3,
            sharpen_amount: 35.0,
            sharpen_radius: 1.1,
            sharpen_threshold: 0.02,
        }
    }
}

/// Converte RGB [0.0, 1.0] para YCbCr
#[inline]
pub fn rgb_to_ycbcr(r: f32, g: f32, b: f32) -> (f32, f32, f32) {
    let y = 0.299 * r + 0.587 * g + 0.114 * b;
    let cb = -0.168736 * r - 0.331264 * g + 0.5 * b;
    let cr = 0.5 * r - 0.418688 * g - 0.081312 * b;
    (y, cb, cr)
}

/// Converte YCbCr de volta para RGB [0.0, 1.0] com clamp
#[inline]
pub fn ycbcr_to_rgb(y: f32, cb: f32, cr: f32) -> (f32, f32, f32) {
    let r = y + 1.402 * cr;
    let g = y - 0.344136 * cb - 0.714136 * cr;
    let b = y + 1.772 * cb;
    (r.clamp(0.0, 1.0), g.clamp(0.0, 1.0), b.clamp(0.0, 1.0))
}

/// Executa a esteira completa de Denoise Clássico + Smart Sharpening em uma imagem RGB 32-bit float linear.
/// `pixels` contém valores RGB intercalados (r, g, b, r, g, b...) de tamanho `width * height * 3`.
pub fn process_classical_denoise(
    pixels: &mut [f32],
    width: usize,
    height: usize,
    params: &DenoiseParams,
) {
    if width == 0 || height == 0 || pixels.len() < width * height * 3 {
        return;
    }

    let len = width * height;
    let mut y_plane = vec![0.0f32; len];
    let mut cb_plane = vec![0.0f32; len];
    let mut cr_plane = vec![0.0f32; len];

    // 1. Decomposição para canais Y, Cb, Cr
    for i in 0..len {
        let r = pixels[i * 3];
        let g = pixels[i * 3 + 1];
        let b = pixels[i * 3 + 2];
        let (y, cb, cr) = rgb_to_ycbcr(r, g, b);
        y_plane[i] = y;
        cb_plane[i] = cb;
        cr_plane[i] = cr;
    }

    // 2. Ruído Cromático (Chroma Denoise):
    if params.chroma_strength > 0.0 && params.chroma_radius > 0 {
        cb_plane = filter_chroma_channel(&cb_plane, width, height, params.chroma_radius, params.chroma_strength);
        cr_plane = filter_chroma_channel(&cr_plane, width, height, params.chroma_radius, params.chroma_strength);
    }

    // 3. Ruído de Luminância (Luma Denoise com preservação estrita de microtextura):
    let mut luma_filtered = if params.luma_strength > 0.0 && params.luma_spatial_radius > 0 {
        filter_luma_bilateral(
            &y_plane,
            width,
            height,
            params.luma_spatial_radius,
            params.luma_strength,
            params.detail_threshold,
        )
    } else {
        y_plane
    };

    // 4. Smart Sharpening (inspirado no PhotoCraft crates/algo/src/sharpen.rs):
    if params.sharpen_amount > 0.0 && params.sharpen_radius > 0.1 {
        luma_filtered = apply_smart_sharpen(
            &luma_filtered,
            width,
            height,
            params.sharpen_amount,
            params.sharpen_radius,
            params.sharpen_threshold,
        );
    }

    // 5. Recomposição YCbCr -> RGB
    pixels
        .par_chunks_exact_mut(3)
        .enumerate()
        .for_each(|(i, rgb)| {
            let y = luma_filtered[i];
            let cb = cb_plane[i];
            let cr = cr_plane[i];
            let (r, g, b) = ycbcr_to_rgb(y, cb, cr);
            rgb[0] = r;
            rgb[1] = g;
            rgb[2] = b;
        });
}

/// Filtro bilateral ponderado por variância e máscara de preservação de microtextura
fn filter_luma_bilateral(
    src: &[f32],
    width: usize,
    height: usize,
    radius: i32,
    strength: f32,
    detail_threshold: f32,
) -> Vec<f32> {
    let mut dst = vec![0.0f32; width * height];
    let sigma_space = radius as f32;
    let two_sigma_space_sq = 2.0 * sigma_space * sigma_space;
    let sigma_range = (strength * 0.08).max(0.01);
    let two_sigma_range_sq = 2.0 * sigma_range * sigma_range;

    dst.par_chunks_exact_mut(width)
        .enumerate()
        .for_each(|(y_idx, row)| {
            let y = y_idx as i32;
            for x in 0..width as i32 {
                let center_idx = (y as usize) * width + (x as usize);
                let center_val = src[center_idx];

                let mut sum_weights = 0.0f32;
                let mut sum_values = 0.0f32;

                for dy in -radius..=radius {
                    let ny = (y + dy).clamp(0, height as i32 - 1) as usize;
                    for dx in -radius..=radius {
                        let nx = (x + dx).clamp(0, width as i32 - 1) as usize;
                        let neighbor_val = src[ny * width + nx];

                        let spatial_dist_sq = (dx * dx + dy * dy) as f32;
                        let space_weight = (-spatial_dist_sq / two_sigma_space_sq).exp();

                        let diff = (neighbor_val - center_val).abs();

                        // Detail preservation: se a variação for microtextura relevante (poros, cabelo),
                        // atenua a suavização preservando a nitidez natural da derme humana.
                        let range_weight = if diff > detail_threshold {
                            (-diff * diff / (two_sigma_range_sq * 0.3)).exp()
                        } else {
                            (-diff * diff / two_sigma_range_sq).exp()
                        };

                        let weight = space_weight * range_weight;
                        sum_weights += weight;
                        sum_values += neighbor_val * weight;
                    }
                }

                let filtered_val = if sum_weights > 0.0 {
                    sum_values / sum_weights
                } else {
                    center_val
                };

                let blend_factor = (strength * 0.7).clamp(0.0, 1.0);
                row[x as usize] = center_val * (1.0 - blend_factor) + filtered_val * blend_factor;
            }
        });

    dst
}

/// Filtro gaussiano rápido para canais de crominância (Cb/Cr)
fn filter_chroma_channel(
    src: &[f32],
    width: usize,
    height: usize,
    radius: i32,
    strength: f32,
) -> Vec<f32> {
    let mut dst = vec![0.0f32; width * height];
    let sigma = (radius as f32 * 0.6).max(0.5);
    let two_sigma_sq = 2.0 * sigma * sigma;

    dst.par_chunks_exact_mut(width)
        .enumerate()
        .for_each(|(y_idx, row)| {
            let y = y_idx as i32;
            for x in 0..width as i32 {
                let center_idx = (y as usize) * width + (x as usize);
                let center_val = src[center_idx];

                let mut sum_weights = 0.0f32;
                let mut sum_values = 0.0f32;

                for dy in -radius..=radius {
                    let ny = (y + dy).clamp(0, height as i32 - 1) as usize;
                    for dx in -radius..=radius {
                        let nx = (x + dx).clamp(0, width as i32 - 1) as usize;
                        let neighbor_val = src[ny * width + nx];

                        let dist_sq = (dx * dx + dy * dy) as f32;
                        let weight = (-dist_sq / two_sigma_sq).exp();

                        sum_weights += weight;
                        sum_values += neighbor_val * weight;
                    }
                }

                let filtered = if sum_weights > 0.0 {
                    sum_values / sum_weights
                } else {
                    center_val
                };

                let blend = (strength * 0.8).clamp(0.0, 1.0);
                row[x as usize] = center_val * (1.0 - blend) + filtered * blend;
            }
        });

    dst
}

/// Smart Sharpening com supressão de ruído residual
fn apply_smart_sharpen(
    src: &[f32],
    width: usize,
    height: usize,
    amount: f32,
    radius: f32,
    threshold: f32,
) -> Vec<f32> {
    let blurred = box_blur_approx(src, width, height, radius.round() as i32);
    let mut dst = vec![0.0f32; width * height];
    let k = amount / 100.0;

    dst.par_chunks_exact_mut(width)
        .enumerate()
        .for_each(|(y_idx, row)| {
            let offset = y_idx * width;
            for x in 0..width {
                let orig = src[offset + x];
                let blur = blurred[offset + x];
                let diff = orig - blur;
                let abs_diff = diff.abs();

                if abs_diff >= threshold {
                    let keep = 1.0 - (-abs_diff * 40.0).exp();
                    let sharpened = orig + k * diff * keep;
                    row[x] = sharpened.clamp(0.0, 1.0);
                } else {
                    row[x] = orig;
                }
            }
        });

    dst
}

fn box_blur_approx(src: &[f32], width: usize, height: usize, radius: i32) -> Vec<f32> {
    let r = radius.clamp(1, 5);
    let mut temp = vec![0.0f32; width * height];
    let mut dst = vec![0.0f32; width * height];

    temp.par_chunks_exact_mut(width)
        .enumerate()
        .for_each(|(y, row)| {
            let y_offset = y * width;
            for x in 0..width as i32 {
                let mut sum = 0.0f32;
                let mut count = 0;
                for dx in -r..=r {
                    let nx = (x + dx).clamp(0, width as i32 - 1) as usize;
                    sum += src[y_offset + nx];
                    count += 1;
                }
                row[x as usize] = sum / count as f32;
            }
        });

    dst.par_chunks_exact_mut(width)
        .enumerate()
        .for_each(|(y_idx, row)| {
            let y = y_idx as i32;
            for x in 0..width {
                let mut sum = 0.0f32;
                let mut count = 0;
                for dy in -r..=r {
                    let ny = (y + dy).clamp(0, height as i32 - 1) as usize;
                    sum += temp[ny * width + x];
                    count += 1;
                }
                row[x] = sum / count as f32;
            }
        });

    dst
}
