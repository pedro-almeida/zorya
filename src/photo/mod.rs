//! Módulo Fotográfico Zorya: Pipeline RAW de 2 Estágios (LightCraft + PhotoCraft)

pub mod culling;
pub mod denoise;

use std::fs::File;
use std::io::BufWriter;
use std::path::{Path, PathBuf};
use std::sync::Arc;
use image::{GenericImageView, ImageBuffer, Rgb};
use image::codecs::jpeg::JpegEncoder;
use serde::{Deserialize, Serialize};
use tokio::sync::RwLock;
use walkdir::WalkDir;

use self::culling::{PhotoCullItem, extract_raw_embedded_preview, process_cull_photo};
use self::denoise::{DenoiseParams, process_classical_denoise};

const SUPPORTED_PHOTO_EXTENSIONS: &[&str] = &[
    "cr2", "cr3", "nef", "arw", "dng", "raf", "rw2", "orf", "pef",
    "jpg", "jpeg", "tif", "tiff", "png", "webp",
];

/// Configuração do Estágio 2 (Master pós-seleção)
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PhotoMasterConfig {
    pub denoise_luma: f32,
    pub denoise_chroma: f32,
    pub detail_threshold: f32,
    pub sharpen_amount: f32,
    pub lens_vignette_correction: f32,
    pub jpeg_quality: u8,
}

impl Default for PhotoMasterConfig {
    fn default() -> Self {
        Self {
            denoise_luma: 0.80,
            denoise_chroma: 1.15,
            detail_threshold: 0.032, // Preserva poros e fios sem aspecto plástico
            sharpen_amount: 38.0,
            lens_vignette_correction: 0.12,
            jpeg_quality: 98,
        }
    }
}

/// Item resultante da masterização (Estágio 2)
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PhotoMasterItem {
    pub file_name: String,
    pub original_path: String,
    pub master_path: String,
    pub width: u32,
    pub height: u32,
    pub duration_ms: u64,
}

/// Status do job de culling fotográfico (Estágio 1)
#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct PhotoCullStatus {
    pub is_running: bool,
    pub total_files: usize,
    pub processed_files: usize,
    pub current_file: Option<String>,
    pub items: Vec<PhotoCullItem>,
    pub error: Option<String>,
}

/// Status do job de masterização fotográfica (Estágio 2)
#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct PhotoMasterStatus {
    pub is_running: bool,
    pub total_selected: usize,
    pub processed: usize,
    pub current_file: Option<String>,
    pub results: Vec<PhotoMasterItem>,
    pub error: Option<String>,
}

#[derive(Debug, Default)]
pub struct PhotoEngineState {
    pub cull: PhotoCullStatus,
    pub master: PhotoMasterStatus,
}

pub type SharedPhotoState = Arc<RwLock<PhotoEngineState>>;

pub fn create_shared_photo_state() -> SharedPhotoState {
    Arc::new(RwLock::new(PhotoEngineState::default()))
}

/// Expansão gama sRGB 8-bit para Float Linear 32-bit [0.0, 1.0] (Rec.2020 linear representation)
#[inline]
fn srgb_to_linear(v: u8) -> f32 {
    let c = (v as f32) / 255.0;
    if c <= 0.04045 {
        c / 12.92
    } else {
        ((c + 0.055) / 1.055).powf(2.4)
    }
}

/// Compressão gama Float Linear 32-bit para sRGB 8-bit [0, 255]
#[inline]
fn linear_to_srgb(c: f32) -> u8 {
    let clamped = c.clamp(0.0, 1.0);
    let s = if clamped <= 0.0031308 {
        12.92 * clamped
    } else {
        1.055 * clamped.powf(1.0 / 2.4) - 0.055
    };
    (s * 255.0).round().clamp(0.0, 255.0) as u8
}

fn apply_lens_vignette_compensation(pixels: &mut [f32], width: usize, height: usize, strength: f32) {
    if strength <= 0.001 {
        return;
    }

    let cx = (width as f32) / 2.0;
    let cy = (height as f32) / 2.0;
    let max_dist = (cx * cx + cy * cy).sqrt();

    for y in 0..height {
        let dy = (y as f32) - cy;
        let row_offset = y * width * 3;
        for x in 0..width {
            let dx = (x as f32) - cx;
            let dist = (dx * dx + dy * dy).sqrt() / max_dist;
            let gain = 1.0 + strength * (dist * dist);
            let idx = row_offset + x * 3;
            pixels[idx] = (pixels[idx] * gain).clamp(0.0, 1.0);
            pixels[idx + 1] = (pixels[idx + 1] * gain).clamp(0.0, 1.0);
            pixels[idx + 2] = (pixels[idx + 2] * gain).clamp(0.0, 1.0);
        }
    }
}

/// Executa a masterização de uma foto individual (Estágio 2)
pub fn process_master_photo(
    raw_path: &Path,
    output_dir: &Path,
    config: &PhotoMasterConfig,
) -> anyhow::Result<PhotoMasterItem> {
    let start_time = std::time::Instant::now();
    let file_name = raw_path
        .file_name()
        .map(|s| s.to_string_lossy().to_string())
        .unwrap_or_else(|| "photo_master.raw".to_string());

    let raw_dyn = extract_raw_embedded_preview(raw_path)?;
    let (width, height) = raw_dyn.dimensions();
    let w_us = width as usize;
    let h_us = height as usize;
    let total_pixels = w_us * h_us;

    // 1. Conversão em Ponto Flutuante Linear de 32 bits
    let rgb_img = raw_dyn.to_rgb8();
    let mut linear_buffer = vec![0.0f32; total_pixels * 3];

    for (i, p) in rgb_img.pixels().enumerate() {
        linear_buffer[i * 3] = srgb_to_linear(p[0]);
        linear_buffer[i * 3 + 1] = srgb_to_linear(p[1]);
        linear_buffer[i * 3 + 2] = srgb_to_linear(p[2]);
    }

    // 2. Compensação de Vinhetagem
    apply_lens_vignette_compensation(&mut linear_buffer, w_us, h_us, config.lens_vignette_correction);

    // 3. Denoise Matemático Clássico + Smart Sharpening
    let denoise_params = DenoiseParams {
        luma_strength: config.denoise_luma,
        luma_spatial_radius: 2,
        detail_threshold: config.detail_threshold,
        chroma_strength: config.denoise_chroma,
        chroma_radius: 3,
        sharpen_amount: config.sharpen_amount,
        sharpen_radius: 1.1,
        sharpen_threshold: 0.02,
    };

    process_classical_denoise(&mut linear_buffer, w_us, h_us, &denoise_params);

    // 4. Conversão para sRGB Perceptivo de Entrega
    let mut master_buffer = ImageBuffer::<Rgb<u8>, Vec<u8>>::new(width, height);
    for (i, p) in master_buffer.pixels_mut().enumerate() {
        p[0] = linear_to_srgb(linear_buffer[i * 3]);
        p[1] = linear_to_srgb(linear_buffer[i * 3 + 1]);
        p[2] = linear_to_srgb(linear_buffer[i * 3 + 2]);
    }

    // 5. Exportação JPEG Master
    std::fs::create_dir_all(output_dir)?;
    let stem = raw_path.file_stem().unwrap_or_default().to_string_lossy();
    let master_file_name = format!("{}_MASTER.jpg", stem);
    let master_output_path = output_dir.join(&master_file_name);

    let file = File::create(&master_output_path)?;
    let mut writer = BufWriter::new(file);
    let mut encoder = JpegEncoder::new_with_quality(&mut writer, config.jpeg_quality);
    encoder.encode(
        master_buffer.as_raw(),
        width,
        height,
        image::ExtendedColorType::Rgb8,
    )?;

    let elapsed = start_time.elapsed().as_millis() as u64;

    Ok(PhotoMasterItem {
        file_name,
        original_path: raw_path.to_string_lossy().to_string(),
        master_path: master_output_path.to_string_lossy().to_string(),
        width,
        height,
        duration_ms: elapsed,
    })
}

/// Dispara a triagem (Estágio 1) em segundo plano
pub fn trigger_photo_cull(
    state: SharedPhotoState,
    folder_path: PathBuf,
    preview_output_dir: PathBuf,
) {
    tokio::spawn(async move {
        let mut raw_files = Vec::new();
        for entry in WalkDir::new(&folder_path).into_iter().filter_map(|e| e.ok()) {
            if entry.file_type().is_file() {
                if let Some(ext) = entry.path().extension().and_then(|s| s.to_str()) {
                    if SUPPORTED_PHOTO_EXTENSIONS.contains(&ext.to_ascii_lowercase().as_str()) {
                        raw_files.push(entry.into_path());
                    }
                }
            }
        }

        let total = raw_files.len();

        {
            let mut s = state.write().await;
            s.cull.is_running = true;
            s.cull.total_files = total;
            s.cull.processed_files = 0;
            s.cull.items.clear();
            s.cull.error = None;
        }

        if total == 0 {
            let mut s = state.write().await;
            s.cull.is_running = false;
            s.cull.error = Some("Nenhum arquivo fotografico suportado encontrado na pasta".to_string());
            return;
        }

        for file in raw_files {
            let name = file.file_name().unwrap_or_default().to_string_lossy().to_string();
            {
                let mut s = state.write().await;
                s.cull.current_file = Some(name.clone());
            }

            let preview_dir = preview_output_dir.clone();
            let res = tokio::task::spawn_blocking(move || {
                process_cull_photo(&file, &preview_dir)
            }).await;

            match res {
                Ok(Ok(item)) => {
                    let mut s = state.write().await;
                    s.cull.processed_files += 1;
                    s.cull.items.push(item);
                }
                Ok(Err(e)) => {
                    tracing::error!("Erro ao processar {}: {:?}", name, e);
                    let mut s = state.write().await;
                    s.cull.processed_files += 1;
                }
                Err(e) => {
                    tracing::error!("Falha na task de processamento: {:?}", e);
                    let mut s = state.write().await;
                    s.cull.processed_files += 1;
                }
            }
        }

        {
            let mut s = state.write().await;
            s.cull.is_running = false;
            s.cull.current_file = None;
        }
    });
}

/// Dispara a masterização (Estágio 2) para a lista de itens selecionados
pub fn trigger_photo_master(
    state: SharedPhotoState,
    selected_paths: Vec<PathBuf>,
    output_dir: PathBuf,
    config: PhotoMasterConfig,
) {
    tokio::spawn(async move {
        let total = selected_paths.len();

        {
            let mut s = state.write().await;
            s.master.is_running = true;
            s.master.total_selected = total;
            s.master.processed = 0;
            s.master.results.clear();
            s.master.error = None;
        }

        for path in selected_paths {
            let name = path.file_name().unwrap_or_default().to_string_lossy().to_string();
            {
                let mut s = state.write().await;
                s.master.current_file = Some(name.clone());
            }

            let out = output_dir.clone();
            let cfg = config.clone();
            let res = tokio::task::spawn_blocking(move || {
                process_master_photo(&path, &out, &cfg)
            }).await;

            match res {
                Ok(Ok(item)) => {
                    let mut s = state.write().await;
                    s.master.processed += 1;
                    s.master.results.push(item);
                }
                Ok(Err(e)) => {
                    tracing::error!("Erro no Master de {}: {:?}", name, e);
                    let mut s = state.write().await;
                    s.master.processed += 1;
                }
                Err(e) => {
                    tracing::error!("Falha na task Master: {:?}", e);
                    let mut s = state.write().await;
                    s.master.processed += 1;
                }
            }
        }

        {
            let mut s = state.write().await;
            s.master.is_running = false;
            s.master.current_file = None;
        }
    });
}
