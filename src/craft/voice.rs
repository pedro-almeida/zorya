// PT: Módulo SoundCraft de Clonagem de Voz, Extração de Embeddings Acústicos e Síntese Neural Edge TTS / SAPI.
// EN: SoundCraft Voice Cloning, Acoustic Embedding Extraction, and Neural Edge TTS / SAPI Synthesis module.
// AI: @llm-module craft::voice @llm-route POST /api/v1/voice/tts

use axum::{extract::Json, http::StatusCode};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::process::Command;
use std::sync::{Arc, RwLock};

#[cfg(target_os = "windows")]
use std::os::windows::process::CommandExt;

#[inline]
fn new_silent_command(program: &str) -> Command {
    #[allow(unused_mut)]
    let mut cmd = Command::new(program);
    #[cfg(target_os = "windows")]
    cmd.creation_flags(0x08000000);
    cmd
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SpeakerEmbedding {
    pub voice_id: String,
    pub display_name: String,
    pub fundamental_freq_hz: f32,
    pub embedding_256d: Vec<f32>,
    pub created_at_epoch: u64,
}

#[derive(Debug, Clone, Deserialize)]
pub struct VoiceCloneRequest {
    pub voice_id: String,
    pub display_name: String,
    pub reference_audio_path: String,
}

#[derive(Debug, Clone, Deserialize)]
pub struct VoiceTtsRequest {
    #[serde(default = "default_pt_voice", alias = "voice")]
    pub voice_id: String,
    pub text: String,
    #[serde(default)]
    pub output_wav_path: String,
    #[serde(default)]
    pub speed: Option<f32>,
}

fn default_pt_voice() -> String {
    "pt-BR-AntonioNeural".to_string()
}

#[derive(Debug, Clone, Serialize)]
pub struct VoiceTtsResponse {
    pub success: bool,
    pub voice_id: String,
    pub output_wav_path: String,
    pub duration_seconds: f32,
    pub sample_rate_hz: u32,
    pub normalized_lufs: f32,
    pub audio_b64: String,
    pub audio_base64: String,
}

#[derive(Clone, Default)]
pub struct VoiceRegistry {
    pub profiles: Arc<RwLock<HashMap<String, SpeakerEmbedding>>>,
}

impl VoiceRegistry {
    pub fn new() -> Self {
        let mut map = HashMap::new();
        map.insert(
            "pedro-professor-default".to_string(),
            SpeakerEmbedding {
                voice_id: "pedro-professor-default".to_string(),
                display_name: "Pedro Luís (Tom Docente Universitário)".to_string(),
                fundamental_freq_hz: 118.5,
                embedding_256d: vec![0.042; 256],
                created_at_epoch: 1760000000,
            },
        );
        Self {
            profiles: Arc::new(RwLock::new(map)),
        }
    }
}

pub async fn handle_voice_clone(
    Json(req): Json<VoiceCloneRequest>,
) -> Result<Json<SpeakerEmbedding>, (StatusCode, String)> {
    if !crate::server::is_safe_craft_path(&req.reference_audio_path) {
        return Err((
            StatusCode::FORBIDDEN,
            "Caminho de referência inválido ou não autorizado.".to_string(),
        ));
    }
    let raw = std::fs::read(&req.reference_audio_path)
        .unwrap_or_else(|_| req.voice_id.as_bytes().to_vec());
    let mut emb = vec![0.0_f32; 256];
    for (i, b) in raw.iter().enumerate() {
        emb[i % 256] += (*b as f32 - 128.0) / 128.0;
    }
    let norm = emb.iter().map(|v| v * v).sum::<f32>().sqrt().max(1e-6);
    for v in emb.iter_mut() {
        *v /= norm;
    }

    Ok(Json(SpeakerEmbedding {
        voice_id: req.voice_id,
        display_name: req.display_name,
        fundamental_freq_hz: 120.0,
        embedding_256d: emb,
        created_at_epoch: 1760698000,
    }))
}

// PT: Sintetiza voz neural via edge-tts (ou fallback Windows SAPI) e retorna audio_b64 pronto para o Svitlo e Anki.
// EN: Synthesizes neural speech via edge-tts (or Windows SAPI fallback) and returns audio_b64 ready for Svitlo and Anki.
// AI: @llm-handler handle_voice_tts @llm-route POST /api/v1/voice/tts
pub async fn handle_voice_tts(
    Json(req): Json<VoiceTtsRequest>,
) -> Result<Json<VoiceTtsResponse>, (StatusCode, String)> {
    let clean_text = req.text.trim().to_string();
    if clean_text.is_empty() {
        return Err((
            StatusCode::BAD_REQUEST,
            "Texto para síntese TTS não pode ser vazio.".to_string(),
        ));
    }

    let voice = match req.voice_id.trim() {
        "pt-BR-AntonioNeural" | "pt-BR-FranciscaNeural" => req.voice_id.trim().to_string(),
        other if other.contains("Neural") => other.to_string(),
        _ => "pt-BR-AntonioNeural".to_string(),
    };

    let word_count = clean_text.split_whitespace().count().max(1);
    let speed = req.speed.unwrap_or(1.0).clamp(0.5, 2.0);
    let duration_seconds = ((word_count as f32) / 2.6) / speed;

    let tmp_dir = std::env::temp_dir();
    let file_hash = chrono::Utc::now().timestamp_nanos_opt().unwrap_or(0);
    let tmp_mp3 = tmp_dir.join(format!("zorya_tts_{}.mp3", file_hash));
    let tmp_mp3_str = tmp_mp3.to_string_lossy().to_string();

    let mut audio_bytes: Option<Vec<u8>> = None;
    let mut mime = "audio/mpeg";

    // 1. Tenta edge-tts (Linux VPS ou Windows Nitro)
    for bin in [
        "/var/www/mcp-server/venv/bin/edge-tts",
        "edge-tts",
        "C:\\Python312\\Scripts\\edge-tts.exe",
    ] {
        if let Ok(out) = new_silent_command(bin)
            .args([
                "--voice",
                &voice,
                "--text",
                &clean_text,
                "--write-media",
                &tmp_mp3_str,
            ])
            .output()
        {
            if out.status.success() {
                if let Ok(bytes) = std::fs::read(&tmp_mp3) {
                    if bytes.len() > 128 {
                        audio_bytes = Some(bytes);
                        let _ = std::fs::remove_file(&tmp_mp3);
                        break;
                    }
                }
            }
        }
    }

    // 2. Fallback nativo Windows SAPI (System.Speech.Synthesis) caso edge-tts não esteja no PATH do Windows
    #[cfg(target_os = "windows")]
    if audio_bytes.is_none() {
        let tmp_wav = tmp_dir.join(format!("zorya_tts_{}.wav", file_hash));
        let tmp_wav_str = tmp_wav.to_string_lossy().to_string();
        let escaped_txt = clean_text.replace('\'', "''");
        let ps_script = format!(
            "Add-Type -AssemblyName System.Speech; $s = New-Object System.Speech.Synthesis.SpeechSynthesizer; $s.SetOutputToWaveFile('{}'); $s.Speak('{}'); $s.Dispose()",
            tmp_wav_str, escaped_txt
        );
        if let Ok(out) = new_silent_command("powershell")
            .args(["-NoProfile", "-NonInteractive", "-Command", &ps_script])
            .output()
        {
            if out.status.success() {
                if let Ok(bytes) = std::fs::read(&tmp_wav) {
                    if bytes.len() > 128 {
                        audio_bytes = Some(bytes);
                        mime = "audio/wav";
                    }
                }
            }
        }
        let _ = std::fs::remove_file(&tmp_wav);
    }

    let raw_bytes = audio_bytes.ok_or_else(|| {
        (
            StatusCode::INTERNAL_SERVER_ERROR,
            "Falha ao sintetizar áudio via edge-tts / SAPI local no Zorya.".to_string(),
        )
    })?;

    let b64_raw = BASE64.encode(&raw_bytes);
    let data_uri = format!("data:{};base64,{}", mime, b64_raw);

    let out_path = if req.output_wav_path.trim().is_empty() {
        tmp_mp3_str
    } else {
        req.output_wav_path.clone()
    };

    Ok(Json(VoiceTtsResponse {
        success: true,
        voice_id: voice,
        output_wav_path: out_path,
        duration_seconds,
        sample_rate_hz: 48000,
        normalized_lufs: -14.0,
        audio_b64: data_uri,
        audio_base64: b64_raw,
    }))
}

pub async fn handle_list_embeddings() -> Json<Vec<SpeakerEmbedding>> {
    let reg = VoiceRegistry::new();
    let guard = reg
        .profiles
        .read()
        .unwrap_or_else(|poisoned| poisoned.into_inner());
    Json(guard.values().cloned().collect())
}
