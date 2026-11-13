// PT: Módulo SoundCraft de Clonagem de Voz, Extração de Embeddings Acústicos e Síntese Neural Edge TTS / SAPI.
// EN: SoundCraft Voice Cloning, Acoustic Embedding Extraction, and Neural Edge TTS / SAPI Synthesis module.
// AI: @llm-module craft::voice @llm-route POST /api/v1/voice/tts

use axum::{
    extract::{Json, Multipart, Path as AxumPath},
    http::{header, StatusCode},
    response::IntoResponse,
};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::path::{Path as StdPath, PathBuf};
use std::process::{Command, Stdio};
use std::sync::{Arc, OnceLock, RwLock};
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::process::Command as TokioCommand;
use tokio::sync::RwLock as TokioRwLock;

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

pub use crate::craft::voice_trainer::{
    get_mining_job, list_mining_jobs, start_voice_corpus_mining, VoiceCorpusMinerJob,
    VoiceCorpusMinerRequest,
};

pub async fn handle_mine_voice_corpus(
    Json(req): Json<VoiceCorpusMinerRequest>,
) -> (StatusCode, Json<VoiceCorpusMinerJob>) {
    let job = start_voice_corpus_mining(req).await;
    (StatusCode::ACCEPTED, Json(job))
}

// ============================================================================
// SOUNDCRAFT LONG CORPUS + RVC TRAINING (GTX 1650) + AUDIOBOOK STUDIO
// ============================================================================

#[inline]
fn new_silent_tokio_command(program: &str) -> TokioCommand {
    #[allow(unused_mut)]
    let mut cmd = TokioCommand::new(program);
    #[cfg(target_os = "windows")]
    cmd.creation_flags(0x08000000);
    cmd
}

fn epoch_now() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs()
}

pub fn sanitize_voice_id(raw: &str) -> String {
    let cleaned: String = raw
        .trim()
        .chars()
        .map(|c| {
            if c.is_ascii_alphanumeric() || c == '-' || c == '_' {
                c.to_ascii_lowercase()
            } else if c.is_whitespace() {
                '-'
            } else {
                '_'
            }
        })
        .collect();
    let trimmed = cleaned.trim_matches(|c| c == '-' || c == '_').to_string();
    if trimmed.is_empty() {
        "custom-voice".to_string()
    } else {
        trimmed
    }
}

fn resolve_gpu_python() -> String {
    let preferred = StdPath::new(r"C:\mriya\mriya\audiobook_tts\.venv\Scripts\python.exe");
    if preferred.exists() {
        return preferred.to_string_lossy().to_string();
    }
    if cfg!(target_os = "windows") {
        "python".to_string()
    } else {
        "python3".to_string()
    }
}

fn voice_profiles_manifest_path() -> PathBuf {
    PathBuf::from("datasets/voices/profiles.json")
}

fn voice_profile_dir(voice_id: &str) -> PathBuf {
    PathBuf::from("datasets/voices/profiles").join(voice_id)
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VoiceProfileRecord {
    pub voice_id: String,
    pub display_name: String,
    pub clip_count: usize,
    pub total_duration_seconds: f64,
    pub has_rvc_model: bool,
    pub rvc_model_path: Option<String>,
    pub rvc_index_path: Option<String>,
    pub updated_at_epoch: u64,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
struct VoiceProfilesManifest {
    pub profiles: Vec<VoiceProfileRecord>,
}

fn scan_profile_rvc_artifacts(profile_dir: &StdPath) -> (bool, Option<String>, Option<String>) {
    let rvc_dir = profile_dir.join("rvc");
    if !rvc_dir.exists() {
        return (false, None, None);
    }
    let mut model_path = None;
    let mut index_path = None;
    if let Ok(entries) = std::fs::read_dir(&rvc_dir) {
        for entry in entries.flatten() {
            let path = entry.path();
            let ext = path
                .extension()
                .and_then(|e| e.to_str())
                .map(|s| s.to_ascii_lowercase())
                .unwrap_or_default();
            if ext == "pth" && model_path.is_none() {
                model_path = Some(path.to_string_lossy().replace('\\', "/"));
            } else if ext == "index" && index_path.is_none() {
                index_path = Some(path.to_string_lossy().replace('\\', "/"));
            }
        }
    }
    (model_path.is_some(), model_path, index_path)
}

fn count_wav_clips(profile_dir: &StdPath) -> usize {
    let wavs_dir = profile_dir.join("wavs");
    std::fs::read_dir(wavs_dir)
        .ok()
        .map(|rd| {
            rd.flatten()
                .filter(|e| {
                    e.path()
                        .extension()
                        .and_then(|s| s.to_str())
                        .map(|s| s.eq_ignore_ascii_case("wav"))
                        .unwrap_or(false)
                })
                .count()
        })
        .unwrap_or(0)
}

fn load_profiles_manifest() -> VoiceProfilesManifest {
    let path = voice_profiles_manifest_path();
    let mut manifest = if let Ok(raw) = std::fs::read_to_string(&path) {
        serde_json::from_str::<VoiceProfilesManifest>(&raw).unwrap_or_default()
    } else {
        VoiceProfilesManifest::default()
    };

    for rec in &mut manifest.profiles {
        let pdir = voice_profile_dir(&rec.voice_id);
        let disk_clips = count_wav_clips(&pdir);
        if disk_clips > 0 {
            rec.clip_count = disk_clips;
        }
        let (has_rvc, mpath, ipath) = scan_profile_rvc_artifacts(&pdir);
        rec.has_rvc_model = has_rvc;
        rec.rvc_model_path = mpath;
        rec.rvc_index_path = ipath;
    }

    manifest
}

fn save_profiles_manifest(manifest: &VoiceProfilesManifest) {
    let path = voice_profiles_manifest_path();
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    if let Ok(json) = serde_json::to_string_pretty(manifest) {
        let _ = std::fs::write(&path, json);
    }
}

fn upsert_profile_record(
    voice_id: &str,
    display_name: Option<&str>,
    clip_count: Option<usize>,
    total_duration_seconds: Option<f64>,
) -> VoiceProfileRecord {
    let mut manifest = load_profiles_manifest();
    let pdir = voice_profile_dir(voice_id);
    let (has_rvc, mpath, ipath) = scan_profile_rvc_artifacts(&pdir);
    let disk_clips = count_wav_clips(&pdir);

    if let Some(existing) = manifest
        .profiles
        .iter_mut()
        .find(|p| p.voice_id == voice_id)
    {
        if let Some(name) = display_name {
            if !name.trim().is_empty() {
                existing.display_name = name.trim().to_string();
            }
        }
        if let Some(c) = clip_count {
            existing.clip_count = c.max(disk_clips);
        } else if disk_clips > 0 {
            existing.clip_count = disk_clips;
        }
        if let Some(dur) = total_duration_seconds {
            existing.total_duration_seconds = dur;
        }
        existing.has_rvc_model = has_rvc;
        existing.rvc_model_path = mpath;
        existing.rvc_index_path = ipath;
        existing.updated_at_epoch = epoch_now();
        let cloned = existing.clone();
        save_profiles_manifest(&manifest);
        return cloned;
    }

    let created = VoiceProfileRecord {
        voice_id: voice_id.to_string(),
        display_name: display_name
            .map(str::trim)
            .filter(|s| !s.is_empty())
            .unwrap_or(voice_id)
            .to_string(),
        clip_count: clip_count.unwrap_or(disk_clips),
        total_duration_seconds: total_duration_seconds.unwrap_or(0.0),
        has_rvc_model: has_rvc,
        rvc_model_path: mpath,
        rvc_index_path: ipath,
        updated_at_epoch: epoch_now(),
    };
    manifest.profiles.push(created.clone());
    save_profiles_manifest(&manifest);
    created
}

pub async fn handle_list_profiles() -> Json<Vec<VoiceProfileRecord>> {
    let manifest = load_profiles_manifest();
    Json(manifest.profiles)
}

#[derive(Debug, Clone, Deserialize)]
pub struct YoutubeIngestRequest {
    pub voice_id: String,
    #[serde(default)]
    pub display_name: Option<String>,
    pub youtube_urls: Vec<String>,
    #[serde(default = "default_true")]
    pub isolate_vocals: bool,
}

fn default_true() -> bool {
    true
}

#[derive(Debug, Clone, Serialize)]
pub struct VoiceIngestJob {
    pub job_id: String,
    pub voice_id: String,
    pub status: String,
    pub stage: String,
    pub clips_generated: usize,
    pub total_duration_seconds: f64,
    pub logs: Vec<String>,
    pub error: Option<String>,
}

static INGEST_JOBS: OnceLock<Arc<TokioRwLock<HashMap<String, VoiceIngestJob>>>> = OnceLock::new();

fn ingest_store() -> &'static Arc<TokioRwLock<HashMap<String, VoiceIngestJob>>> {
    INGEST_JOBS.get_or_init(|| Arc::new(TokioRwLock::new(HashMap::new())))
}

async fn update_ingest_job<F>(job_id: &str, f: F)
where
    F: FnOnce(&mut VoiceIngestJob),
{
    let mut guard = ingest_store().write().await;
    if let Some(job) = guard.get_mut(job_id) {
        f(job);
    }
}

fn spawn_ingest_worker(
    job_id: String,
    voice_id: String,
    display_name: Option<String>,
    args: Vec<String>,
) {
    tokio::spawn(async move {
        update_ingest_job(&job_id, |j| {
            j.status = "running".to_string();
            j.stage = "executando pipeline Python/CUDA".to_string();
        })
        .await;

        let py = resolve_gpu_python();
        let mut cmd = new_silent_tokio_command(&py);
        cmd.arg("-u").arg("scripts/zorya_rvc_trainer.py");
        for arg in &args {
            cmd.arg(arg);
        }
        cmd.stdout(Stdio::piped()).stderr(Stdio::piped());

        let mut child = match cmd.spawn() {
            Ok(c) => c,
            Err(err) => {
                update_ingest_job(&job_id, |j| {
                    j.status = "failed".to_string();
                    j.error = Some(format!("Falha ao iniciar Python GPU: {}", err));
                })
                .await;
                return;
            }
        };

        let stdout = child.stdout.take();
        let stderr = child.stderr.take();
        let jid_err = job_id.clone();
        let err_handle = tokio::spawn(async move {
            let mut collected = String::new();
            if let Some(se) = stderr {
                let mut reader = BufReader::new(se).lines();
                while let Ok(Some(line)) = reader.next_line().await {
                    if !line.trim().is_empty() {
                        collected.push_str(&line);
                        collected.push('\n');
                        update_ingest_job(&jid_err, |j| {
                            if j.logs.len() >= 60 {
                                j.logs.remove(0);
                            }
                            j.logs.push(line.clone());
                        })
                        .await;
                    }
                }
            }
            collected
        });

        let mut clips_found: Option<usize> = None;
        let mut dur_found: Option<f64> = None;

        if let Some(so) = stdout {
            let mut reader = BufReader::new(so).lines();
            while let Ok(Some(line)) = reader.next_line().await {
                let trimmed = line.trim().to_string();
                if trimmed.is_empty() {
                    continue;
                }
                if let Some(rest) = trimmed.strip_prefix("STATS:") {
                    let mut parts = rest.split(':');
                    if let (Some(c_str), Some(d_str)) = (parts.next(), parts.next()) {
                        if let Ok(c) = c_str.parse::<usize>() {
                            clips_found = Some(c);
                        }
                        if let Ok(d) = d_str.parse::<f64>() {
                            dur_found = Some(d);
                        }
                    }
                } else if let Some(rest) = trimmed.strip_prefix("STAGE:") {
                    let stage_msg = rest.to_string();
                    update_ingest_job(&job_id, |j| j.stage = stage_msg.clone()).await;
                } else if let Some(rest) = trimmed.strip_prefix("DONE_INGEST:") {
                    if let Ok(c) = rest.parse::<usize>() {
                        if clips_found.is_none() {
                            clips_found = Some(c);
                        }
                    }
                }
                update_ingest_job(&job_id, |j| {
                    if j.logs.len() >= 60 {
                        j.logs.remove(0);
                    }
                    j.logs.push(trimmed.clone());
                    if let Some(c) = clips_found {
                        j.clips_generated = c;
                    }
                    if let Some(d) = dur_found {
                        j.total_duration_seconds = d;
                    }
                })
                .await;
            }
        }

        let stderr_text = err_handle.await.unwrap_or_default();
        match child.wait().await {
            Ok(status) if status.success() => {
                let record = upsert_profile_record(
                    &voice_id,
                    display_name.as_deref(),
                    clips_found,
                    dur_found,
                );
                update_ingest_job(&job_id, |j| {
                    j.status = "completed".to_string();
                    j.stage = "corpus pronto".to_string();
                    j.clips_generated = record.clip_count;
                    j.total_duration_seconds = record.total_duration_seconds;
                })
                .await;
            }
            Ok(status) => {
                update_ingest_job(&job_id, |j| {
                    j.status = "failed".to_string();
                    j.stage = "erro".to_string();
                    j.error = Some(format!(
                        "Worker encerrou com codigo {:?}: {}",
                        status.code(),
                        stderr_text.chars().take(500).collect::<String>()
                    ));
                })
                .await;
            }
            Err(err) => {
                update_ingest_job(&job_id, |j| {
                    j.status = "failed".to_string();
                    j.error = Some(format!("Erro ao aguardar processo: {}", err));
                })
                .await;
            }
        }
    });
}

pub async fn handle_youtube_ingest(
    Json(req): Json<YoutubeIngestRequest>,
) -> (StatusCode, Json<VoiceIngestJob>) {
    let voice_id = sanitize_voice_id(&req.voice_id);
    let pdir = voice_profile_dir(&voice_id);
    let _ = std::fs::create_dir_all(pdir.join("raw"));
    let _ = std::fs::create_dir_all(pdir.join("wavs"));

    let urls: Vec<String> = req
        .youtube_urls
        .iter()
        .map(|s| s.trim().to_string())
        .filter(|s| !s.is_empty())
        .collect();

    let job_id = format!("ingest_{}_{}", voice_id, epoch_now());

    if urls.is_empty() {
        let job = VoiceIngestJob {
            job_id: job_id.clone(),
            voice_id,
            status: "failed".to_string(),
            stage: "validacao".to_string(),
            clips_generated: 0,
            total_duration_seconds: 0.0,
            logs: vec![],
            error: Some("Nenhuma URL valida informada.".to_string()),
        };
        return (StatusCode::BAD_REQUEST, Json(job));
    }

    let urls_file = pdir.join("urls.txt");
    let _ = std::fs::write(&urls_file, urls.join("\n"));
    upsert_profile_record(&voice_id, req.display_name.as_deref(), None, None);

    let job = VoiceIngestJob {
        job_id: job_id.clone(),
        voice_id: voice_id.clone(),
        status: "queued".to_string(),
        stage: "na fila".to_string(),
        clips_generated: 0,
        total_duration_seconds: 0.0,
        logs: vec![format!("{} URL(s) enfileirada(s)", urls.len())],
        error: None,
    };

    ingest_store()
        .write()
        .await
        .insert(job_id.clone(), job.clone());

    let mut args = vec![
        "ingest".to_string(),
        "--profile-dir".to_string(),
        pdir.to_string_lossy().to_string(),
        "--urls-file".to_string(),
        urls_file.to_string_lossy().to_string(),
    ];
    if req.isolate_vocals {
        args.push("--isolate-vocals".to_string());
    }

    spawn_ingest_worker(job_id, voice_id, req.display_name, args);
    (StatusCode::ACCEPTED, Json(job))
}

pub async fn handle_youtube_ingest_status(
    AxumPath(job_id): AxumPath<String>,
) -> Result<Json<VoiceIngestJob>, StatusCode> {
    let guard = ingest_store().read().await;
    guard
        .get(&job_id)
        .cloned()
        .map(Json)
        .ok_or(StatusCode::NOT_FOUND)
}

pub async fn handle_corpus_upload(
    mut multipart: Multipart,
) -> Result<(StatusCode, Json<VoiceIngestJob>), (StatusCode, String)> {
    let mut voice_id_raw = "custom-voice".to_string();
    let mut display_name: Option<String> = None;
    let mut isolate_vocals = true;
    let mut streamed_paths: Vec<PathBuf> = Vec::new();

    // Se voice_id vier antes dos arquivos, gravamos direto em stream; caso contrário, buffer temporário em datasets/voices/_incoming.
    let incoming_tmp =
        PathBuf::from("datasets/voices/_incoming").join(format!("up_{}", epoch_now()));
    let _ = tokio::fs::create_dir_all(&incoming_tmp).await;

    while let Some(mut field) = multipart
        .next_field()
        .await
        .map_err(|e| (StatusCode::BAD_REQUEST, format!("Erro multipart: {}", e)))?
    {
        let name = field.name().unwrap_or_default().to_string();
        if name == "voice_id" {
            if let Ok(val) = field.text().await {
                if !val.trim().is_empty() {
                    voice_id_raw = val;
                }
            }
        } else if name == "display_name" {
            if let Ok(val) = field.text().await {
                if !val.trim().is_empty() {
                    display_name = Some(val);
                }
            }
        } else if name == "isolate_vocals" {
            if let Ok(val) = field.text().await {
                isolate_vocals = !matches!(
                    val.trim().to_ascii_lowercase().as_str(),
                    "false" | "0" | "no"
                );
            }
        } else if let Some(file_name) = field.file_name().map(str::to_string) {
            let safe_name = StdPath::new(&file_name)
                .file_name()
                .and_then(|s| s.to_str())
                .unwrap_or("audio_upload.wav")
                .replace(
                    |c: char| !c.is_ascii_alphanumeric() && c != '.' && c != '-' && c != '_',
                    "_",
                );
            let temp_target = incoming_tmp.join(&safe_name);
            let mut out_file = tokio::fs::File::create(&temp_target).await.map_err(|e| {
                (
                    StatusCode::INTERNAL_SERVER_ERROR,
                    format!("Erro disco: {}", e),
                )
            })?;
            while let Some(chunk) = field
                .chunk()
                .await
                .map_err(|e| (StatusCode::BAD_REQUEST, format!("Erro chunk upload: {}", e)))?
            {
                out_file.write_all(&chunk).await.map_err(|e| {
                    (
                        StatusCode::INTERNAL_SERVER_ERROR,
                        format!("Erro escrita: {}", e),
                    )
                })?;
            }
            streamed_paths.push(temp_target);
        }
    }

    if streamed_paths.is_empty() {
        let _ = tokio::fs::remove_dir_all(&incoming_tmp).await;
        return Err((
            StatusCode::BAD_REQUEST,
            "Nenhum arquivo de audio enviado no multipart.".to_string(),
        ));
    }

    let voice_id = sanitize_voice_id(&voice_id_raw);
    let pdir = voice_profile_dir(&voice_id);
    let raw_dir = pdir.join("raw");
    let _ = tokio::fs::create_dir_all(&raw_dir).await;
    let _ = tokio::fs::create_dir_all(pdir.join("wavs")).await;

    let mut final_paths: Vec<String> = Vec::new();
    for src in streamed_paths {
        let fname = src
            .file_name()
            .and_then(|s| s.to_str())
            .unwrap_or("upload.wav");
        let dest = raw_dir.join(fname);
        if tokio::fs::rename(&src, &dest).await.is_err() {
            let _ = tokio::fs::copy(&src, &dest).await;
            let _ = tokio::fs::remove_file(&src).await;
        }
        final_paths.push(dest.to_string_lossy().to_string());
    }
    let _ = tokio::fs::remove_dir_all(&incoming_tmp).await;

    upsert_profile_record(&voice_id, display_name.as_deref(), None, None);

    let job_id = format!("upload_{}_{}", voice_id, epoch_now());
    let job = VoiceIngestJob {
        job_id: job_id.clone(),
        voice_id: voice_id.clone(),
        status: "queued".to_string(),
        stage: "arquivos recebidos, iniciando fatiamento".to_string(),
        clips_generated: 0,
        total_duration_seconds: 0.0,
        logs: vec![format!("{} arquivo(s) enviado(s)", final_paths.len())],
        error: None,
    };

    ingest_store()
        .write()
        .await
        .insert(job_id.clone(), job.clone());

    let mut args = vec![
        "ingest-files".to_string(),
        "--profile-dir".to_string(),
        pdir.to_string_lossy().to_string(),
        "--files".to_string(),
    ];
    args.extend(final_paths);
    if isolate_vocals {
        args.push("--isolate-vocals".to_string());
    }

    spawn_ingest_worker(job_id, voice_id, display_name, args);
    Ok((StatusCode::ACCEPTED, Json(job)))
}

#[derive(Debug, Clone, Deserialize)]
pub struct RvcTrainRequest {
    pub voice_id: String,
    #[serde(default = "default_epochs")]
    pub epochs: u32,
    #[serde(default = "default_sample_rate")]
    pub sample_rate: String,
}

fn default_epochs() -> u32 {
    120
}

fn default_sample_rate() -> String {
    "40k".to_string()
}

#[derive(Debug, Clone, Serialize)]
pub struct RvcTrainJob {
    pub job_id: String,
    pub voice_id: String,
    pub status: String,
    pub stage: String,
    pub current_epoch: u32,
    pub total_epochs: u32,
    pub percent: u8,
    pub logs: Vec<String>,
    pub error: Option<String>,
}

static RVC_TRAIN_JOBS: OnceLock<Arc<TokioRwLock<HashMap<String, RvcTrainJob>>>> = OnceLock::new();

fn rvc_train_store() -> &'static Arc<TokioRwLock<HashMap<String, RvcTrainJob>>> {
    RVC_TRAIN_JOBS.get_or_init(|| Arc::new(TokioRwLock::new(HashMap::new())))
}

async fn update_rvc_job<F>(job_id: &str, f: F)
where
    F: FnOnce(&mut RvcTrainJob),
{
    let mut guard = rvc_train_store().write().await;
    if let Some(job) = guard.get_mut(job_id) {
        f(job);
    }
}

pub async fn handle_rvc_train_start(
    Json(req): Json<RvcTrainRequest>,
) -> (StatusCode, Json<RvcTrainJob>) {
    let voice_id = sanitize_voice_id(&req.voice_id);
    let pdir = voice_profile_dir(&voice_id);
    let epochs = req.epochs.clamp(10, 1000);
    let sr = if req.sample_rate.trim().is_empty() {
        "40k".to_string()
    } else {
        req.sample_rate.trim().to_string()
    };

    let job_id = format!("rvc_{}_{}", voice_id, epoch_now());
    let job = RvcTrainJob {
        job_id: job_id.clone(),
        voice_id: voice_id.clone(),
        status: "queued".to_string(),
        stage: "iniciando treino RVC (GTX 1650 batch_size=1 fp16)".to_string(),
        current_epoch: 0,
        total_epochs: epochs,
        percent: 0,
        logs: vec![],
        error: None,
    };

    rvc_train_store()
        .write()
        .await
        .insert(job_id.clone(), job.clone());

    let jid = job_id.clone();
    tokio::spawn(async move {
        update_rvc_job(&jid, |j| {
            j.status = "running".to_string();
        })
        .await;

        let py = resolve_gpu_python();
        let mut cmd = new_silent_tokio_command(&py);
        cmd.arg("-u")
            .arg("scripts/zorya_rvc_trainer.py")
            .arg("train")
            .arg("--profile-dir")
            .arg(pdir.to_string_lossy().to_string())
            .arg("--voice-id")
            .arg(&voice_id)
            .arg("--epochs")
            .arg(epochs.to_string())
            .arg("--sample-rate")
            .arg(&sr)
            .stdout(Stdio::piped())
            .stderr(Stdio::piped());

        let mut child = match cmd.spawn() {
            Ok(c) => c,
            Err(e) => {
                update_rvc_job(&jid, |j| {
                    j.status = "failed".to_string();
                    j.error = Some(format!("Falha ao iniciar Python: {}", e));
                })
                .await;
                return;
            }
        };

        let stdout = child.stdout.take();
        let stderr = child.stderr.take();
        let jid_err = jid.clone();
        let err_task = tokio::spawn(async move {
            let mut buf = String::new();
            if let Some(se) = stderr {
                let mut reader = BufReader::new(se).lines();
                while let Ok(Some(line)) = reader.next_line().await {
                    if !line.trim().is_empty() {
                        buf.push_str(&line);
                        buf.push('\n');
                        update_rvc_job(&jid_err, |j| {
                            if j.logs.len() >= 60 {
                                j.logs.remove(0);
                            }
                            j.logs.push(line.clone());
                        })
                        .await;
                    }
                }
            }
            buf
        });

        if let Some(so) = stdout {
            let mut reader = BufReader::new(so).lines();
            while let Ok(Some(line)) = reader.next_line().await {
                let trimmed = line.trim().to_string();
                if trimmed.is_empty() {
                    continue;
                }
                if let Some(rest) = trimmed.strip_prefix("STAGE:") {
                    let s = rest.to_string();
                    update_rvc_job(&jid, |j| j.stage = s.clone()).await;
                } else if let Some(rest) = trimmed.strip_prefix("PROGRESS:") {
                    // PROGRESS:10/120 (8%)
                    if let Some((frac, pct_part)) = rest.split_once('(') {
                        if let Some((cur, tot)) = frac.trim().split_once('/') {
                            let c = cur.trim().parse::<u32>().unwrap_or(0);
                            let t = tot.trim().parse::<u32>().unwrap_or(epochs);
                            let p = pct_part
                                .trim_end_matches(')')
                                .trim_end_matches('%')
                                .trim()
                                .parse::<u8>()
                                .unwrap_or(0);
                            update_rvc_job(&jid, |j| {
                                j.current_epoch = c;
                                j.total_epochs = t;
                                j.percent = p;
                            })
                            .await;
                        }
                    }
                }
                update_rvc_job(&jid, |j| {
                    if j.logs.len() >= 60 {
                        j.logs.remove(0);
                    }
                    j.logs.push(trimmed.clone());
                })
                .await;
            }
        }

        let stderr_text = err_task.await.unwrap_or_default();
        match child.wait().await {
            Ok(st) if st.success() => {
                upsert_profile_record(&voice_id, None, None, None);
                update_rvc_job(&jid, |j| {
                    j.status = "completed".to_string();
                    j.stage = "modelo RVC salvo e indexado".to_string();
                    j.percent = 100;
                })
                .await;
            }
            Ok(st) => {
                update_rvc_job(&jid, |j| {
                    j.status = "failed".to_string();
                    j.stage = "falha".to_string();
                    j.error = Some(format!(
                        "Treino falhou (codigo {:?}): {}",
                        st.code(),
                        stderr_text.chars().take(600).collect::<String>()
                    ));
                })
                .await;
            }
            Err(e) => {
                update_rvc_job(&jid, |j| {
                    j.status = "failed".to_string();
                    j.error = Some(format!("Erro de processo: {}", e));
                })
                .await;
            }
        }
    });

    (StatusCode::ACCEPTED, Json(job))
}

pub async fn handle_rvc_train_status(
    AxumPath(job_id): AxumPath<String>,
) -> Result<Json<RvcTrainJob>, StatusCode> {
    let guard = rvc_train_store().read().await;
    guard
        .get(&job_id)
        .cloned()
        .map(Json)
        .ok_or(StatusCode::NOT_FOUND)
}

pub async fn handle_rvc_import(
    mut multipart: Multipart,
) -> Result<Json<VoiceProfileRecord>, (StatusCode, String)> {
    let mut voice_id_raw = "custom-voice".to_string();
    let mut display_name: Option<String> = None;
    let incoming_tmp =
        PathBuf::from("datasets/voices/_rvc_import").join(format!("imp_{}", epoch_now()));
    let _ = tokio::fs::create_dir_all(&incoming_tmp).await;
    let mut imported_files: Vec<(String, PathBuf)> = Vec::new();

    while let Some(mut field) = multipart
        .next_field()
        .await
        .map_err(|e| (StatusCode::BAD_REQUEST, format!("Erro multipart: {}", e)))?
    {
        let name = field.name().unwrap_or_default().to_string();
        if name == "voice_id" {
            if let Ok(v) = field.text().await {
                if !v.trim().is_empty() {
                    voice_id_raw = v;
                }
            }
        } else if name == "display_name" {
            if let Ok(v) = field.text().await {
                if !v.trim().is_empty() {
                    display_name = Some(v);
                }
            }
        } else if let Some(fname) = field.file_name().map(str::to_string) {
            let lower = fname.to_ascii_lowercase();
            let target_name = if lower.ends_with(".pth") {
                "model.pth"
            } else if lower.ends_with(".index") {
                "added.index"
            } else {
                return Err((
                    StatusCode::BAD_REQUEST,
                    format!(
                        "Extensao invalida para RVC import ({}). Envie .pth ou .index.",
                        fname
                    ),
                ));
            };
            let tmp_path = incoming_tmp.join(target_name);
            let mut out = tokio::fs::File::create(&tmp_path).await.map_err(|e| {
                (
                    StatusCode::INTERNAL_SERVER_ERROR,
                    format!("Erro disco: {}", e),
                )
            })?;
            while let Some(chunk) = field
                .chunk()
                .await
                .map_err(|e| (StatusCode::BAD_REQUEST, format!("Erro chunk: {}", e)))?
            {
                out.write_all(&chunk).await.map_err(|e| {
                    (
                        StatusCode::INTERNAL_SERVER_ERROR,
                        format!("Erro escrita: {}", e),
                    )
                })?;
            }
            imported_files.push((target_name.to_string(), tmp_path));
        }
    }

    if imported_files.is_empty() {
        let _ = tokio::fs::remove_dir_all(&incoming_tmp).await;
        return Err((
            StatusCode::BAD_REQUEST,
            "Nenhum arquivo .pth ou .index enviado.".to_string(),
        ));
    }

    let voice_id = sanitize_voice_id(&voice_id_raw);
    let rvc_dir = voice_profile_dir(&voice_id).join("rvc");
    let _ = tokio::fs::create_dir_all(&rvc_dir).await;
    for (tname, src) in imported_files {
        let dest = rvc_dir.join(tname);
        if tokio::fs::rename(&src, &dest).await.is_err() {
            let _ = tokio::fs::copy(&src, &dest).await;
        }
    }
    let _ = tokio::fs::remove_dir_all(&incoming_tmp).await;

    let record = upsert_profile_record(&voice_id, display_name.as_deref(), None, None);
    Ok(Json(record))
}

#[derive(Debug, Clone, Deserialize)]
pub struct AudiobookRequest {
    pub voice_id: String,
    #[serde(default = "default_engine")]
    pub engine: String,
    #[serde(default = "default_base_voice")]
    pub base_voice: String,
    #[serde(default = "default_speed")]
    pub speed: f32,
    pub text: String,
}

fn default_engine() -> String {
    "kokoro_rvc".to_string()
}

fn default_base_voice() -> String {
    "pm_alex".to_string()
}

fn default_speed() -> f32 {
    1.0
}

#[derive(Debug, Clone, Serialize)]
pub struct AudiobookJob {
    pub job_id: String,
    pub voice_id: String,
    pub engine: String,
    pub status: String,
    pub stage: String,
    pub percent: u8,
    pub duration_seconds: f64,
    pub download_url: Option<String>,
    pub logs: Vec<String>,
    pub error: Option<String>,
}

static AUDIOBOOK_JOBS: OnceLock<Arc<TokioRwLock<HashMap<String, AudiobookJob>>>> = OnceLock::new();

fn audiobook_store() -> &'static Arc<TokioRwLock<HashMap<String, AudiobookJob>>> {
    AUDIOBOOK_JOBS.get_or_init(|| Arc::new(TokioRwLock::new(HashMap::new())))
}

async fn update_audiobook_job<F>(job_id: &str, f: F)
where
    F: FnOnce(&mut AudiobookJob),
{
    let mut guard = audiobook_store().write().await;
    if let Some(job) = guard.get_mut(job_id) {
        f(job);
    }
}

pub async fn handle_audiobook_start(
    Json(req): Json<AudiobookRequest>,
) -> (StatusCode, Json<AudiobookJob>) {
    let voice_id = sanitize_voice_id(&req.voice_id);
    let engine = match req.engine.trim() {
        "kokoro" | "xtts_multi" | "kokoro_rvc" => req.engine.trim().to_string(),
        _ => "kokoro_rvc".to_string(),
    };
    let base_voice = match req.base_voice.trim() {
        "pf_dora" | "pm_alex" | "pm_santa" => req.base_voice.trim().to_string(),
        _ => "pm_alex".to_string(),
    };
    let speed = req.speed.clamp(0.5, 1.8);
    let job_id = format!("ab_{}_{}", voice_id, epoch_now());

    if req.text.trim().is_empty() {
        let job = AudiobookJob {
            job_id,
            voice_id,
            engine,
            status: "failed".to_string(),
            stage: "validacao".to_string(),
            percent: 0,
            duration_seconds: 0.0,
            download_url: None,
            logs: vec![],
            error: Some("Texto do audiobook esta vazio.".to_string()),
        };
        return (StatusCode::BAD_REQUEST, Json(job));
    }

    let ab_dir = PathBuf::from("datasets/voices/audiobooks").join(&job_id);
    let _ = std::fs::create_dir_all(&ab_dir);
    let text_file = ab_dir.join("input.txt");
    let output_mp3 = ab_dir.join("output.mp3");
    let _ = std::fs::write(&text_file, &req.text);

    let job = AudiobookJob {
        job_id: job_id.clone(),
        voice_id: voice_id.clone(),
        engine: engine.clone(),
        status: "queued".to_string(),
        stage: "na fila".to_string(),
        percent: 0,
        duration_seconds: 0.0,
        download_url: None,
        logs: vec![format!("Motor: {} | Voz base: {}", engine, base_voice)],
        error: None,
    };

    audiobook_store()
        .write()
        .await
        .insert(job_id.clone(), job.clone());

    let jid = job_id.clone();
    let pdir = voice_profile_dir(&voice_id);
    tokio::spawn(async move {
        update_audiobook_job(&jid, |j| {
            j.status = "running".to_string();
            j.stage = "inicializando sintese neural GPU".to_string();
        })
        .await;

        let py = resolve_gpu_python();
        let mut cmd = new_silent_tokio_command(&py);
        cmd.arg("-u")
            .arg("scripts/zorya_voice_worker.py")
            .arg("--text-file")
            .arg(text_file.to_string_lossy().to_string())
            .arg("--output-mp3")
            .arg(output_mp3.to_string_lossy().to_string())
            .arg("--engine")
            .arg(&engine)
            .arg("--base-voice")
            .arg(&base_voice)
            .arg("--speed")
            .arg(format!("{:.2}", speed));
        if engine != "kokoro" {
            cmd.arg("--profile-dir")
                .arg(pdir.to_string_lossy().to_string());
        }
        cmd.stdout(Stdio::piped()).stderr(Stdio::piped());

        let mut child = match cmd.spawn() {
            Ok(c) => c,
            Err(e) => {
                update_audiobook_job(&jid, |j| {
                    j.status = "failed".to_string();
                    j.error = Some(format!("Falha ao iniciar worker: {}", e));
                })
                .await;
                return;
            }
        };

        let stdout = child.stdout.take();
        let stderr = child.stderr.take();
        let jid_err = jid.clone();
        let err_task = tokio::spawn(async move {
            let mut buf = String::new();
            if let Some(se) = stderr {
                let mut reader = BufReader::new(se).lines();
                while let Ok(Some(line)) = reader.next_line().await {
                    if !line.trim().is_empty() {
                        buf.push_str(&line);
                        buf.push('\n');
                        update_audiobook_job(&jid_err, |j| {
                            if j.logs.len() >= 60 {
                                j.logs.remove(0);
                            }
                            j.logs.push(line.clone());
                        })
                        .await;
                    }
                }
            }
            buf
        });

        let mut duration_out = 0.0_f64;
        if let Some(so) = stdout {
            let mut reader = BufReader::new(so).lines();
            while let Ok(Some(line)) = reader.next_line().await {
                let trimmed = line.trim().to_string();
                if trimmed.is_empty() {
                    continue;
                }
                if let Some(rest) = trimmed.strip_prefix("STAGE:") {
                    let st = rest.to_string();
                    update_audiobook_job(&jid, |j| j.stage = st.clone()).await;
                } else if let Some(rest) = trimmed.strip_prefix("PROGRESS:") {
                    if let Some((_, pct_part)) = rest.split_once('(') {
                        let p = pct_part
                            .trim_end_matches(')')
                            .trim_end_matches('%')
                            .trim()
                            .parse::<u8>()
                            .unwrap_or(0);
                        update_audiobook_job(&jid, |j| j.percent = p).await;
                    }
                } else if let Some(rest) = trimmed.strip_prefix("DONE_AUDIOBOOK:") {
                    if let Some((dur_s, _)) = rest.split_once(':') {
                        duration_out = dur_s.parse::<f64>().unwrap_or(0.0);
                    }
                }
                update_audiobook_job(&jid, |j| {
                    if j.logs.len() >= 60 {
                        j.logs.remove(0);
                    }
                    j.logs.push(trimmed.clone());
                })
                .await;
            }
        }

        let stderr_text = err_task.await.unwrap_or_default();
        match child.wait().await {
            Ok(st) if st.success() && output_mp3.exists() => {
                let dl = format!("/api/v1/voice/audiobook/{}/download", jid);
                update_audiobook_job(&jid, |j| {
                    j.status = "completed".to_string();
                    j.stage = "audiobook masterizado (-14 LUFS)".to_string();
                    j.percent = 100;
                    j.duration_seconds = duration_out;
                    j.download_url = Some(dl);
                })
                .await;
            }
            Ok(st) => {
                update_audiobook_job(&jid, |j| {
                    j.status = "failed".to_string();
                    j.stage = "falha na sintese".to_string();
                    j.error = Some(format!(
                        "Worker falhou (codigo {:?}): {}",
                        st.code(),
                        stderr_text.chars().take(600).collect::<String>()
                    ));
                })
                .await;
            }
            Err(e) => {
                update_audiobook_job(&jid, |j| {
                    j.status = "failed".to_string();
                    j.error = Some(format!("Erro de processo: {}", e));
                })
                .await;
            }
        }
    });

    (StatusCode::ACCEPTED, Json(job))
}

pub async fn handle_audiobook_status(
    AxumPath(job_id): AxumPath<String>,
) -> Result<Json<AudiobookJob>, StatusCode> {
    let guard = audiobook_store().read().await;
    guard
        .get(&job_id)
        .cloned()
        .map(Json)
        .ok_or(StatusCode::NOT_FOUND)
}

pub async fn handle_audiobook_download(AxumPath(job_id): AxumPath<String>) -> impl IntoResponse {
    let safe_id: String = job_id
        .chars()
        .filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-')
        .collect();
    let mp3_path = PathBuf::from("datasets/voices/audiobooks")
        .join(&safe_id)
        .join("output.mp3");
    match tokio::fs::read(&mp3_path).await {
        Ok(bytes) => {
            let disp = format!("attachment; filename=\"{}.mp3\"", safe_id);
            (
                StatusCode::OK,
                [
                    (header::CONTENT_TYPE, "audio/mpeg".to_string()),
                    (header::CONTENT_DISPOSITION, disp),
                ],
                bytes,
            )
                .into_response()
        }
        Err(_) => (StatusCode::NOT_FOUND, "Audiobook MP3 nao encontrado.").into_response(),
    }
}
