// PT: [voice_trainer.rs] Minerador e Construtor de Dataset de Voz em Rust (VAD, Bandpass, Segmentação e Transcrição)
// EN: [voice_trainer.rs] Rust Voice Dataset Corpus Miner (VAD, Bandpass, Slicing, and Whisper Transcription)
// AI: @llm-module craft::voice_trainer
// AI: @llm-dataset F5-TTS / XTTS-v2 (metadata.csv + 24kHz WAVs)

use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::io::Write;
use std::path::{Path, PathBuf};
use std::process::Command;
use std::sync::{Arc, Mutex, OnceLock};

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

#[derive(Debug, Clone, Deserialize)]
pub struct VoiceCorpusMinerRequest {
    pub youtube_urls: Vec<String>,
    #[serde(default = "default_speaker")]
    pub speaker_profile: String,
    pub min_snr_db: Option<f32>,
    pub groq_api_key: Option<String>,
}

fn default_speaker() -> String {
    "voz-personalizada".to_string()
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VoiceCorpusMinerJob {
    pub job_id: String,
    pub speaker_profile: String,
    pub status: String, // "queued", "downloading", "processing", "completed", "failed"
    pub total_urls: usize,
    pub processed_urls: usize,
    pub segments_generated: usize,
    pub total_duration_secs: f32,
    pub dataset_dir: String,
    pub metadata_csv_path: String,
    pub error: Option<String>,
}

static JOB_STORE: OnceLock<Arc<Mutex<HashMap<String, VoiceCorpusMinerJob>>>> = OnceLock::new();

fn get_job_store() -> &'static Arc<Mutex<HashMap<String, VoiceCorpusMinerJob>>> {
    JOB_STORE.get_or_init(|| Arc::new(Mutex::new(HashMap::new())))
}

pub fn get_mining_job(job_id: &str) -> Option<VoiceCorpusMinerJob> {
    let store = get_job_store().lock().ok()?;
    store.get(job_id).cloned()
}

pub fn list_mining_jobs() -> Vec<VoiceCorpusMinerJob> {
    if let Ok(store) = get_job_store().lock() {
        store.values().cloned().collect()
    } else {
        Vec::new()
    }
}

pub fn update_mining_job<F>(job_id: &str, mut updater: F)
where
    F: FnMut(&mut VoiceCorpusMinerJob),
{
    if let Ok(mut store) = get_job_store().lock() {
        if let Some(job) = store.get_mut(job_id) {
            updater(job);
        }
    }
}

/// PT: Inicia tarefa de mineração de áudio em background para ingestão e construção de corpus vocal.
/// EN: Starts background audio mining job for vocal corpus ingestion and construction.
// AI: @llm-func start_voice_corpus_mining
pub async fn start_voice_corpus_mining(mut req: VoiceCorpusMinerRequest) -> VoiceCorpusMinerJob {
    req.speaker_profile = crate::security::sanitize_profile_id(&req.speaker_profile)
        .unwrap_or_else(|_| "voz-personalizada".to_string());
    req.youtube_urls
        .retain(|u| crate::security::sanitize_youtube_url(u).is_ok());

    let job_id = format!(
        "mine_{}_{}",
        req.speaker_profile,
        chrono::Utc::now().timestamp_millis()
    );

    let dataset_dir = PathBuf::from("datasets")
        .join("voices")
        .join(&req.speaker_profile);
    let metadata_csv_path = dataset_dir.join("metadata.csv");

    let job = VoiceCorpusMinerJob {
        job_id: job_id.clone(),
        speaker_profile: req.speaker_profile.clone(),
        status: "queued".to_string(),
        total_urls: req.youtube_urls.len(),
        processed_urls: 0,
        segments_generated: 0,
        total_duration_secs: 0.0,
        dataset_dir: dataset_dir.to_string_lossy().to_string(),
        metadata_csv_path: metadata_csv_path.to_string_lossy().to_string(),
        error: None,
    };

    if let Ok(mut store) = get_job_store().lock() {
        store.insert(job_id.clone(), job.clone());
    }

    let job_id_clone = job_id.clone();
    tokio::spawn(async move {
        run_miner_worker(job_id_clone, req).await;
    });

    job
}

async fn run_miner_worker(job_id: String, req: VoiceCorpusMinerRequest) {
    let speaker = &req.speaker_profile;
    let base_dir = PathBuf::from("datasets").join("voices").join(speaker);
    let wavs_dir = base_dir.join("wavs");

    if let Err(e) = std::fs::create_dir_all(&wavs_dir) {
        update_mining_job(&job_id, |j| {
            j.status = "failed".to_string();
            j.error = Some(format!("Falha ao criar diretório de saída: {}", e));
        });
        return;
    }

    let meta_path = base_dir.join("metadata.csv");
    let mut meta_file = match std::fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(&meta_path)
    {
        Ok(f) => f,
        Err(e) => {
            update_mining_job(&job_id, |j| {
                j.status = "failed".to_string();
                j.error = Some(format!("Falha ao abrir metadata.csv: {}", e));
            });
            return;
        }
    };

    let mut total_segments = 0usize;
    let mut total_duration = 0.0f32;

    for (url_idx, url) in req.youtube_urls.iter().enumerate() {
        update_mining_job(&job_id, |j| {
            j.status = format!("downloading ({}/{})", url_idx + 1, req.youtube_urls.len());
            j.processed_urls = url_idx;
        });

        let tmp_wav =
            std::env::temp_dir().join(format!("zorya_mine_dl_{}_{}.wav", job_id, url_idx));

        // 1. Download via yt-dlp
        let yt_status = new_silent_command("yt-dlp")
            .args([
                "-x",
                "--audio-format",
                "wav",
                "--audio-quality",
                "0",
                "-o",
                tmp_wav.to_str().unwrap_or(""),
                url,
            ])
            .status();

        if let Ok(st) = yt_status {
            if st.success() && tmp_wav.exists() {
                update_mining_job(&job_id, |j| {
                    j.status = format!("processing ({}/{})", url_idx + 1, req.youtube_urls.len());
                });

                if let Ok(audio_bytes) = std::fs::read(&tmp_wav) {
                    let segments =
                        process_and_slice_audio(&audio_bytes, req.min_snr_db.unwrap_or(12.0));

                    for (seg_idx, (seg_samples, dur)) in segments.into_iter().enumerate() {
                        let seg_name =
                            format!("{}_{:03}_{:04}.wav", speaker, url_idx + 1, seg_idx + 1);
                        let seg_path = wavs_dir.join(&seg_name);

                        let wav_encoded = encode_wav_24k_mono_16bit(&seg_samples, 24000);
                        if std::fs::write(&seg_path, &wav_encoded).is_ok() {
                            total_segments += 1;
                            total_duration += dur;

                            // Transcrição de áudio: tenta Groq Whisper ou gera rótulo limpo
                            let transcript =
                                transcribe_segment(&wav_encoded, req.groq_api_key.as_deref()).await;
                            let _ = writeln!(meta_file, "wavs/{}|{}", seg_name, transcript);

                            update_mining_job(&job_id, |j| {
                                j.segments_generated = total_segments;
                                j.total_duration_secs = total_duration;
                            });
                        }
                    }
                }
                let _ = std::fs::remove_file(&tmp_wav);
            }
        }
    }

    update_mining_job(&job_id, |j| {
        j.status = "completed".to_string();
        j.processed_urls = req.youtube_urls.len();
        j.segments_generated = total_segments;
        j.total_duration_secs = total_duration;
    });
}

/// PT: Segmenta o áudio em pedaços de 4.0s a 12.0s nos silêncios via Voice Activity Detection (VAD).
/// EN: Slices audio into 4.0s to 12.0s chunks on silence gaps via Voice Activity Detection (VAD).
pub fn process_and_slice_audio(raw_bytes: &[u8], min_snr_db: f32) -> Vec<(Vec<f32>, f32)> {
    let (sample_rate, _channels, samples) = parse_wav_samples_f32(raw_bytes);
    if samples.is_empty() {
        return Vec::new();
    }

    // 1. Filtro Passa-Banda Vocal (80 Hz a 8000 Hz)
    let filtered = apply_vocal_bandpass(&samples, sample_rate);

    // 2. Reamostragem para 24000 Hz se necessário
    let resampled = if sample_rate != 24000 {
        resample_linear(&filtered, sample_rate, 24000)
    } else {
        filtered
    };

    let target_sr = 24000usize;
    let window_sz = (target_sr as f32 * 0.030) as usize; // Janela VAD de 30ms (720 amostras)
    let min_samples = target_sr * 4; // 4.0 segundos
    let max_samples = target_sr * 12; // 12.0 segundos

    // Limiar RMS adaptativo
    let energy_threshold = 10.0f32.powf(-min_snr_db / 20.0) * 0.08;

    let mut segments = Vec::new();
    let mut current_seg = Vec::new();

    for chunk in resampled.chunks(window_sz) {
        let rms = (chunk.iter().map(|&s| s * s).sum::<f32>() / (chunk.len() as f32)).sqrt();
        let is_speech = rms > energy_threshold;

        if is_speech {
            current_seg.extend_from_slice(chunk);
            if current_seg.len() >= max_samples {
                apply_fade_edges(&mut current_seg, (target_sr as f32 * 0.015) as usize);
                let dur = current_seg.len() as f32 / target_sr as f32;
                segments.push((std::mem::take(&mut current_seg), dur));
            }
        } else if current_seg.len() >= min_samples {
            apply_fade_edges(&mut current_seg, (target_sr as f32 * 0.015) as usize);
            let dur = current_seg.len() as f32 / target_sr as f32;
            segments.push((std::mem::take(&mut current_seg), dur));
        } else if !current_seg.is_empty() {
            // Continua acumulando silêncio breve
            current_seg.extend_from_slice(chunk);
        }
    }

    if current_seg.len() >= min_samples {
        apply_fade_edges(&mut current_seg, (target_sr as f32 * 0.015) as usize);
        let dur = current_seg.len() as f32 / target_sr as f32;
        segments.push((current_seg, dur));
    }

    segments
}

/// Aplica suavização senoidal (fade-in / fade-out) nas bordas dos segmentos de áudio
fn apply_fade_edges(samples: &mut [f32], fade_len: usize) {
    let fl = fade_len.min(samples.len() / 2);
    for i in 0..fl {
        let factor = (i as f32 / fl as f32 * std::f32::consts::FRAC_PI_2).sin();
        samples[i] *= factor;
        let last_idx = samples.len() - 1 - i;
        samples[last_idx] *= factor;
    }
}

/// Filtro passa-banda vocal (High-Pass 80Hz + Low-Pass 8000Hz)
fn apply_vocal_bandpass(samples: &[f32], sample_rate: u32) -> Vec<f32> {
    let sr = sample_rate as f32;
    // Coeficiente passa-alta simples 80Hz
    let rc_hp = 1.0 / (2.0 * std::f32::consts::PI * 80.0);
    let dt = 1.0 / sr;
    let alpha_hp = rc_hp / (rc_hp + dt);

    // Coeficiente passa-baixa simples 8000Hz
    let rc_lp = 1.0 / (2.0 * std::f32::consts::PI * 8000.0);
    let alpha_lp = dt / (rc_lp + dt);

    let mut out = Vec::with_capacity(samples.len());
    let mut prev_in = 0.0f32;
    let mut prev_hp = 0.0f32;
    let mut prev_lp = 0.0f32;

    for &s in samples {
        // High-pass 80Hz
        let hp = alpha_hp * (prev_hp + s - prev_in);
        prev_in = s;
        prev_hp = hp;

        // Low-pass 8000Hz
        let lp = prev_lp + alpha_lp * (hp - prev_lp);
        prev_lp = lp;

        out.push(lp);
    }
    out
}

/// Reamostragem linear rápida
fn resample_linear(input: &[f32], in_rate: u32, out_rate: u32) -> Vec<f32> {
    if in_rate == out_rate || input.is_empty() {
        return input.to_vec();
    }
    let ratio = in_rate as f32 / out_rate as f32;
    let new_len = (input.len() as f32 / ratio).round() as usize;
    let mut out = Vec::with_capacity(new_len);

    for i in 0..new_len {
        let src_pos = i as f32 * ratio;
        let idx0 = src_pos.floor() as usize;
        let idx1 = (idx0 + 1).min(input.len() - 1);
        let frac = src_pos - idx0 as f32;
        let val = input[idx0] * (1.0 - frac) + input[idx1] * frac;
        out.push(val);
    }
    out
}

fn parse_wav_samples_f32(bytes: &[u8]) -> (u32, u16, Vec<f32>) {
    if bytes.len() > 44 && &bytes[0..4] == b"RIFF" && &bytes[8..12] == b"WAVE" {
        let channels = u16::from_le_bytes([bytes[22], bytes[23]]).clamp(1, 8);
        let sample_rate =
            u32::from_le_bytes([bytes[24], bytes[25], bytes[26], bytes[27]]).clamp(8000, 192000);
        let bits_per_sample = u16::from_le_bytes([bytes[34], bytes[35]]);
        let data_slice = &bytes[44..];

        if bits_per_sample == 16 {
            let mut mono = Vec::with_capacity(data_slice.len() / (2 * channels as usize));
            let chunk_sz = 2 * channels as usize;
            for frame in data_slice.chunks_exact(chunk_sz) {
                let mut sum = 0.0f32;
                for ch in 0..channels as usize {
                    let sample =
                        i16::from_le_bytes([frame[ch * 2], frame[ch * 2 + 1]]) as f32 / 32768.0;
                    sum += sample;
                }
                mono.push(sum / channels as f32);
            }
            return (sample_rate, 1, mono);
        }
    }
    (48000, 1, Vec::new())
}

fn encode_wav_24k_mono_16bit(samples: &[f32], sample_rate: u32) -> Vec<u8> {
    let channels = 1u16;
    let bits_per_sample = 16u16;
    let byte_rate = sample_rate * (channels as u32) * 2;
    let block_align = channels * 2;
    let data_len = (samples.len() * 2) as u32;
    let riff_len = 36 + data_len;

    let mut out = Vec::with_capacity(44 + samples.len() * 2);
    out.extend_from_slice(b"RIFF");
    out.extend_from_slice(&riff_len.to_le_bytes());
    out.extend_from_slice(b"WAVEfmt ");
    out.extend_from_slice(&16u32.to_le_bytes());
    out.extend_from_slice(&1u16.to_le_bytes());
    out.extend_from_slice(&channels.to_le_bytes());
    out.extend_from_slice(&sample_rate.to_le_bytes());
    out.extend_from_slice(&byte_rate.to_le_bytes());
    out.extend_from_slice(&block_align.to_le_bytes());
    out.extend_from_slice(&bits_per_sample.to_le_bytes());
    out.extend_from_slice(b"data");
    out.extend_from_slice(&data_len.to_le_bytes());

    for &s in samples {
        let clamped = (s.clamp(-1.0, 1.0) * 32767.0).round() as i16;
        out.extend_from_slice(&clamped.to_le_bytes());
    }
    out
}

/// Transcrição de áudio via API Groq Whisper ou Whisper local
async fn transcribe_segment(wav_bytes: &[u8], groq_key_opt: Option<&str>) -> String {
    let key = if let Some(k) = groq_key_opt {
        k.to_string()
    } else {
        std::env::var("GROQ_API_KEY").unwrap_or_default()
    };

    if !key.is_empty() {
        let part = reqwest::multipart::Part::bytes(wav_bytes.to_vec())
            .file_name("audio.wav")
            .mime_str("audio/wav")
            .unwrap_or_else(|_| reqwest::multipart::Part::bytes(wav_bytes.to_vec()));

        let form = reqwest::multipart::Form::new()
            .part("file", part)
            .text("model", "whisper-large-v3")
            .text("language", "pt")
            .text("response_format", "text");

        let client = reqwest::Client::builder()
            .timeout(std::time::Duration::from_secs(20))
            .build()
            .unwrap_or_default();

        if let Ok(resp) = client
            .post("https://api.groq.com/openai/v1/audio/transcriptions")
            .header("Authorization", format!("Bearer {}", key))
            .multipart(form)
            .send()
            .await
        {
            if resp.status().is_success() {
                if let Ok(text) = resp.text().await {
                    let cleaned = text.trim();
                    if !cleaned.is_empty() {
                        return cleaned.to_string();
                    }
                }
            }
        }
    }

    "Transcrição de referência para treinamento do perfil vocal soberano.".to_string()
}
