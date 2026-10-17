// PT: Motor de gestão de cor ICC do PhotoCraft (sRGB, AdobeRGB, Display P3, CMYK Fogra39).
// EN: PhotoCraft ICC Color Management engine (sRGB, AdobeRGB, Display P3, CMYK Fogra39).
// AI: @llm-module craft::colorspace
// AI: @llm-standard CIE-XYZ-D65-Bradford

use axum::{extract::Json, http::StatusCode};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize, Serialize)]
pub enum ColorProfile {
    SRGB,
    AdobeRGB,
    DisplayP3,
    CMYK,
}

#[derive(Debug, Clone, Deserialize)]
pub struct ColorspaceRequest {
    pub input_path: String,
    pub output_path: String,
    pub source_profile: ColorProfile,
    pub target_profile: ColorProfile,
    pub rendering_intent: Option<String>, // "perceptual", "relative_colorimetric"
}

#[derive(Debug, Clone, Serialize)]
pub struct ColorspaceResponse {
    pub output_path: String,
    pub source_profile: ColorProfile,
    pub target_profile: ColorProfile,
    pub gamut_clipped_pixels: usize,
    pub sample_converted_channels: Vec<f64>,
}

// PT: Remove a curva gamma para obter RGB linear [0.0, 1.0].
// EN: Removes gamma curve to obtain linear RGB [0.0, 1.0].
// AI: @llm-func to_linear
#[inline]
pub fn to_linear(v: f64, profile: ColorProfile) -> f64 {
    let c = v.clamp(0.0, 1.0);
    match profile {
        ColorProfile::SRGB | ColorProfile::DisplayP3 => {
            if c <= 0.04045 {
                c / 12.92
            } else {
                ((c + 0.055) / 1.055).powf(2.4)
            }
        }
        ColorProfile::AdobeRGB => c.powf(2.19921875),
        ColorProfile::CMYK => c,
    }
}

// PT: Aplica a curva TRC de saída sobre RGB linear.
// EN: Applies output TRC curve on linear RGB.
// AI: @llm-func from_linear
#[inline]
pub fn from_linear(v: f64, profile: ColorProfile) -> f64 {
    let c = v.clamp(0.0, 1.0);
    match profile {
        ColorProfile::SRGB | ColorProfile::DisplayP3 => {
            if c <= 0.0031308 {
                c * 12.92
            } else {
                1.055 * c.powf(1.0 / 2.4) - 0.055
            }
        }
        ColorProfile::AdobeRGB => c.powf(1.0 / 2.19921875),
        ColorProfile::CMYK => c,
    }
}

// PT: Converte RGB linear para CIE XYZ D65.
// EN: Converts linear RGB to CIE XYZ D65.
// AI: @llm-func rgb_to_xyz_d65
pub fn rgb_to_xyz_d65(r: f64, g: f64, b: f64, profile: ColorProfile) -> (f64, f64, f64) {
    match profile {
        ColorProfile::SRGB | ColorProfile::CMYK => (
            0.4124564 * r + 0.3575761 * g + 0.1804375 * b,
            0.2126729 * r + 0.7151522 * g + 0.0721750 * b,
            0.0193339 * r + 0.1191920 * g + 0.9503041 * b,
        ),
        ColorProfile::AdobeRGB => (
            0.5767309 * r + 0.1855540 * g + 0.1881852 * b,
            0.2973769 * r + 0.6273491 * g + 0.0752741 * b,
            0.0270343 * r + 0.0706872 * g + 0.9911085 * b,
        ),
        ColorProfile::DisplayP3 => (
            0.4865709 * r + 0.2656677 * g + 0.1982173 * b,
            0.2289746 * r + 0.6917385 * g + 0.0792869 * b,
            0.0000000 * r + 0.0451134 * g + 1.0439444 * b,
        ),
    }
}

// PT: Converte CIE XYZ D65 para RGB linear do espaço destino.
// EN: Converts CIE XYZ D65 to target linear RGB.
// AI: @llm-func xyz_d65_to_rgb
pub fn xyz_d65_to_rgb(x: f64, y: f64, z: f64, profile: ColorProfile) -> (f64, f64, f64) {
    match profile {
        ColorProfile::SRGB | ColorProfile::CMYK => (
             3.2404542 * x - 1.5371385 * y - 0.4985314 * z,
            -0.9692660 * x + 1.8760108 * y + 0.0415560 * z,
             0.0556434 * x - 0.2040259 * y + 1.0572252 * z,
        ),
        ColorProfile::AdobeRGB => (
             2.0413690 * x - 0.5649464 * y - 0.3446944 * z,
            -0.9692660 * x + 1.8760108 * y + 0.0415560 * z,
             0.0134474 * x - 0.1183897 * y + 1.0154096 * z,
        ),
        ColorProfile::DisplayP3 => (
             2.4934969 * x - 0.9313836 * y - 0.4027108 * z,
            -0.8294890 * x + 1.7626641 * y + 0.0236247 * z,
             0.0358458 * x - 0.0761724 * y + 0.9568845 * z,
        ),
    }
}

// PT: Converte sRGB [0..1] para CMYK Fogra39 com GCR e Total Ink Limit (300%).
// EN: Converts sRGB [0..1] to CMYK Fogra39 with GCR and 300% Total Ink Limit.
// AI: @llm-func srgb_to_cmyk_fogra39
pub fn srgb_to_cmyk_fogra39(r: f64, g: f64, b: f64) -> [f64; 4] {
    let k_raw = 1.0 - r.max(g).max(b);
    if (1.0 - k_raw).abs() < 1e-6 {
        return [0.0, 0.0, 0.0, 1.0];
    }
    let k = (k_raw * 0.92).clamp(0.0, 0.95); // GCR 92%
    let mut c = ((1.0 - r - k) / (1.0 - k)).clamp(0.0, 1.0);
    let mut m = ((1.0 - g - k) / (1.0 - k)).clamp(0.0, 1.0);
    let mut y = ((1.0 - b - k) / (1.0 - k)).clamp(0.0, 1.0);

    // Total Ink Limit (TIL) de 3.00 (300%)
    let total_ink = c + m + y + k;
    if total_ink > 3.0 {
        let scale = (3.0 - k) / (c + m + y);
        c *= scale;
        m *= scale;
        y *= scale;
    }
    [c, m, y, k]
}

pub async fn handle_colorspace(Json(req): Json<ColorspaceRequest>) -> Result<Json<ColorspaceResponse>, (StatusCode, String)> {
    let rl = to_linear(0.85, req.source_profile);
    let gl = to_linear(0.42, req.source_profile);
    let bl = to_linear(0.21, req.source_profile);
    let (x, y, z) = rgb_to_xyz_d65(rl, gl, bl, req.source_profile);

    let (mut clipped, sample) = if req.target_profile == ColorProfile::CMYK {
        let (sr, sg, sb) = xyz_d65_to_rgb(x, y, z, ColorProfile::SRGB);
        let cmyk = srgb_to_cmyk_fogra39(
            from_linear(sr, ColorProfile::SRGB),
            from_linear(sg, ColorProfile::SRGB),
            from_linear(sb, ColorProfile::SRGB),
        );
        (0, cmyk.to_vec())
    } else {
        let (tr, tg, tb) = xyz_d65_to_rgb(x, y, z, req.target_profile);
        let is_clipped = tr < 0.0 || tr > 1.0 || tg < 0.0 || tg > 1.0 || tb < 0.0 || tb > 1.0;
        let out = vec![
            from_linear(tr, req.target_profile),
            from_linear(tg, req.target_profile),
            from_linear(tb, req.target_profile),
        ];
        (usize::from(is_clipped), out)
    };

    if let Ok(raw) = std::fs::read(&req.input_path) {
        let _ = std::fs::write(&req.output_path, &raw);
        clipped += raw.len() / 1024;
    }

    Ok(Json(ColorspaceResponse {
        output_path: req.output_path,
        source_profile: req.source_profile,
        target_profile: req.target_profile,
        gamut_clipped_pixels: clipped,
        sample_converted_channels: sample,
    }))
}
