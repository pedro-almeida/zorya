// PT: [narration.rs] Geração de legendas SRT sincronizadas e síntese vocal Modo Clóvis via Gemini TTS Oficial
// EN: [narration.rs] Synchronized SRT subtitle generation and Clovis-Mode vocal synthesis via Official Gemini TTS
// AI: @llm-module video::narration
// AI: @llm-model gemini-2.5-flash-preview-tts

use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use serde_json::json;

use crate::video::VideoLessonScript;

pub fn build_srt_subtitles(script: &VideoLessonScript) -> String {
    let mut srt = String::new();
    let mut current_sec = 0.0_f32;
    for scene in &script.scenes {
        let start = current_sec;
        let end = current_sec + scene.duration_secs;
        srt.push_str(&format!(
            "{}\n{} --> {}\n{}\n\n",
            scene.scene_index,
            format_srt_time(start),
            format_srt_time(end),
            scene.narration_text
        ));
        current_sec = end;
    }
    srt
}

fn format_srt_time(total_secs: f32) -> String {
    let secs = total_secs as u32;
    let millis = ((total_secs - (secs as f32)) * 1000.0) as u32;
    let h = secs / 3600;
    let m = (secs % 3600) / 60;
    let s = secs % 60;
    format!("{:02}:{:02}:{:02},{:03}", h, m, s, millis)
}

/// PT: Formatador retórico 'Modo Clóvis' que insere pausas dramáticas (...), provocações diretas e ênfases viscerais.
/// EN: 'Clovis Mode' rhetorical formatter inserting dramatic pauses (...), direct provocations, and visceral emphasis.
// AI: @llm-func format_clovis_rhetoric
pub fn format_clovis_rhetoric(text: &str) -> String {
    let clean = text.trim();
    if clean.is_empty() {
        return String::new();
    }

    let sentences: Vec<&str> = clean
        .split(&['.', '!', '?'][..])
        .map(|s| s.trim())
        .filter(|s| !s.is_empty())
        .collect();

    if sentences.is_empty() {
        return clean.to_string();
    }

    let provocations = [
        "Preste muita atenção!",
        "Você entende a gravidade do que eu estou dizendo?",
        "Olhe bem para isso...",
        "Onde está o seu brio diante deste conceito?",
        "Compreende o que está em jogo aqui?",
        "É a vida em sua potência máxima!",
    ];

    let mut out = String::with_capacity(clean.len() * 2);

    for (idx, sentence) in sentences.iter().enumerate() {
        if idx > 0 {
            out.push(' ');
        }

        // Realça termos-chave clássicos com ênfase
        let mut highlighted = sentence.to_string();
        for keyword in &[
            "brio",
            "potência",
            "liberdade",
            "consciência",
            "agir",
            "vida",
        ] {
            let re_upper = keyword.to_uppercase();
            if highlighted.to_lowercase().contains(keyword) {
                let lower = highlighted.to_lowercase();
                if let Some(pos) = lower.find(keyword) {
                    highlighted.replace_range(pos..pos + keyword.len(), &re_upper);
                }
            }
        }

        out.push_str(&highlighted);
        out.push_str("... ");

        // A cada 2 sentenças, insere provocação retórica reflexiva
        if idx % 2 == 1 {
            let prov = provocations[(idx / 2) % provocations.len()];
            out.push_str(prov);
            out.push(' ');
        }
    }

    out.trim().to_string()
}

/// PT: Converte bytes de áudio PCM raw 24kHz 16-bit mono em RIFF WAVE canônico.
/// EN: Converts raw 24kHz 16-bit mono PCM bytes into canonical RIFF WAVE.
fn wrap_pcm24k_to_wav(pcm_bytes: &[u8]) -> Vec<u8> {
    let sample_rate = 24000u32;
    let channels = 1u16;
    let bits_per_sample = 16u16;
    let byte_rate = sample_rate * (channels as u32) * (bits_per_sample as u32 / 8);
    let block_align = channels * (bits_per_sample / 8);
    let data_len = pcm_bytes.len() as u32;
    let riff_len = 36 + data_len;

    let mut wav = Vec::with_capacity(44 + pcm_bytes.len());
    wav.extend_from_slice(b"RIFF");
    wav.extend_from_slice(&riff_len.to_le_bytes());
    wav.extend_from_slice(b"WAVE");
    wav.extend_from_slice(b"fmt ");
    wav.extend_from_slice(&16u32.to_le_bytes()); // subchunk1 size
    wav.extend_from_slice(&1u16.to_le_bytes()); // PCM format
    wav.extend_from_slice(&channels.to_le_bytes());
    wav.extend_from_slice(&sample_rate.to_le_bytes());
    wav.extend_from_slice(&byte_rate.to_le_bytes());
    wav.extend_from_slice(&block_align.to_le_bytes());
    wav.extend_from_slice(&bits_per_sample.to_le_bytes());
    wav.extend_from_slice(b"data");
    wav.extend_from_slice(&data_len.to_le_bytes());
    wav.extend_from_slice(pcm_bytes);
    wav
}

/// PT: Síntese vocal de alta fidelidade usando a API oficial gemini-2.5-flash-preview-tts e normalização EBU R128 (-14 LUFS).
/// EN: High-fidelity vocal synthesis using official gemini-2.5-flash-preview-tts API and EBU R128 (-14 LUFS) normalization.
// AI: @llm-func synthesize_gemini_tts
pub async fn synthesize_gemini_tts(
    text: &str,
    voice_name: Option<&str>,
    api_key: Option<&str>,
) -> anyhow::Result<Vec<u8>> {
    let key = if let Some(k) = api_key.filter(|k| !k.trim().is_empty()) {
        k.trim().to_string()
    } else if let Ok(k) = std::env::var("GEMINI_API_KEY").map(|k| k.trim().to_string()) {
        k
    } else {
        // Tenta ler zorya.toml para recuperar a primeira chave configurada
        std::fs::read_to_string("zorya.toml")
            .ok()
            .and_then(|c| {
                c.lines()
                    .find(|l| l.contains("AIzaSy"))
                    .map(|l| l.replace(['"', '\'', ',', '[', ']'], "").trim().to_string())
            })
            .unwrap_or_default()
    };

    let voice = voice_name.unwrap_or("Charon"); // "Charon" ou "Fenrir" para timbre grave/incisivo

    if !key.is_empty() {
        let endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent";
        let client = reqwest::Client::builder()
            .timeout(std::time::Duration::from_secs(35))
            .build()
            .unwrap_or_default();

        let payload = json!({
            "contents": [{
                "parts": [{ "text": text }]
            }],
            "generationConfig": {
                "responseModalities": ["AUDIO"],
                "speechConfig": {
                    "voiceConfig": {
                        "prebuiltVoiceConfig": {
                            "voiceName": voice
                        }
                    }
                }
            }
        });

        let resp_res = client
            .post(endpoint)
            .header("x-goog-api-key", &key)
            .header("content-type", "application/json")
            .json(&payload)
            .send()
            .await;

        if let Ok(resp) = resp_res {
            if resp.status().is_success() {
                if let Ok(val) = resp.json::<serde_json::Value>().await {
                    if let Some(b64_audio) = val
                        .pointer("/candidates/0/content/parts/0/inlineData/data")
                        .and_then(|v| v.as_str())
                    {
                        if let Ok(raw_audio) = BASE64.decode(b64_audio.trim()) {
                            let wav_bytes = if raw_audio.starts_with(b"RIFF") {
                                raw_audio
                            } else {
                                wrap_pcm24k_to_wav(&raw_audio)
                            };

                            let mastered = crate::craft::master_audio_ebu_r128(
                                &wav_bytes,
                                Some("wav"),
                                -14.0,
                            )?;
                            return Ok(mastered.mastered_wav_bytes);
                        }
                    }
                }
            }
        }
    }

    // Fallback determinístico offline caso Gemini TTS não esteja acessível
    let fallback_req = crate::craft::VoiceSynthesizeRequest {
        text: text.to_string(),
        profile_id: Some("clovis-barros-filho".to_string()),
        language: Some("pt-BR".to_string()),
    };
    let synthesized = crate::craft::clone_and_synthesize_voice(&fallback_req)?;
    let decoded = BASE64.decode(&synthesized.audio_base64)?;
    Ok(decoded)
}

/// PT: Pipeline completo de narração 'Modo Clóvis' com retórica personalizada e Gemini TTS.
/// EN: Complete 'Clovis Mode' narration pipeline with custom rhetoric and Gemini TTS.
// AI: @llm-func synthesize_clovis_narration
pub async fn synthesize_clovis_narration(
    raw_text: &str,
    voice_name: Option<&str>,
    api_key: Option<&str>,
) -> anyhow::Result<Vec<u8>> {
    let clovis_text = format_clovis_rhetoric(raw_text);
    synthesize_gemini_tts(&clovis_text, voice_name, api_key).await
}
