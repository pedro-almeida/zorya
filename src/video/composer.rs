// PT: [composer.rs] Composição de vídeo educacional Svitlo v2 com Avatar 3D, PiP e aceleração GTX 1650 NVENC
// EN: [composer.rs] Svitlo v2 educational video composition with 3D Avatar, PiP, and GTX 1650 NVENC acceleration
// AI: @llm-module video::composer
// AI: @llm-hardware NVIDIA GTX 1650 (h264_nvenc) com fallback determinístico libx264

#[cfg(target_os = "windows")]
use std::os::windows::process::CommandExt;
use std::path::{Path, PathBuf};
use std::process::Command;

use crate::video::VideoLessonScript;

#[inline]
fn new_silent_command(program: &str) -> Command {
    #[allow(unused_mut)]
    let mut cmd = Command::new(program);
    #[cfg(target_os = "windows")]
    cmd.creation_flags(0x08000000);
    cmd
}

/// PT: Lê a duração real em segundos diretamente dos bytes do cabeçalho RIFF WAVE (data_len / byte_rate).
/// EN: Reads real duration in seconds directly from RIFF WAVE header bytes (data_len / byte_rate).
// AI: @llm-func get_wav_duration_seconds
pub fn get_wav_duration_seconds(audio_path: &Path) -> Option<f32> {
    let bytes = std::fs::read(audio_path).ok()?;
    get_wav_bytes_duration(&bytes)
}

/// PT: Calcula duração em segundos a partir de um buffer de bytes RIFF WAVE.
/// EN: Computes duration in seconds from a RIFF WAVE byte buffer.
pub fn get_wav_bytes_duration(bytes: &[u8]) -> Option<f32> {
    if bytes.len() >= 44 && &bytes[0..4] == b"RIFF" && &bytes[8..12] == b"WAVE" {
        let byte_rate = u32::from_le_bytes([bytes[28], bytes[29], bytes[30], bytes[31]]) as f32;
        if byte_rate > 0.0 {
            let mut offset = 12;
            while offset + 8 <= bytes.len() {
                let chunk_id = &bytes[offset..offset + 4];
                let chunk_sz = u32::from_le_bytes([
                    bytes[offset + 4],
                    bytes[offset + 5],
                    bytes[offset + 6],
                    bytes[offset + 7],
                ]) as usize;

                if chunk_id == b"data" {
                    let actual_data = chunk_sz.min(bytes.len().saturating_sub(offset + 8));
                    return Some((actual_data as f32) / byte_rate);
                }
                offset += 8 + chunk_sz;
            }
            let data_len = bytes.len().saturating_sub(44) as f32;
            return Some(data_len / byte_rate);
        }
    }
    None
}

/// Garante a existência do avatar em assets/avatar_pedro_3d.png
pub fn ensure_avatar_asset(avatar_path: &Path) -> anyhow::Result<()> {
    if !avatar_path.exists() {
        if let Some(parent) = avatar_path.parent() {
            let _ = std::fs::create_dir_all(parent);
        }
        // Gera um avatar vetorial estilizado Svitlo em SVG e salva como fallback PNG
        let svg = r##"<svg xmlns="http://www.w3.org/2000/svg" width="720" height="720" viewBox="0 0 720 720">
  <defs>
    <radialGradient id="grad" cx="50%" cy="40%" r="50%">
      <stop offset="0%" stop-color="#C4956A"/>
      <stop offset="60%" stop-color="#5C3D1E"/>
      <stop offset="100%" stop-color="#2C1F14"/>
    </radialGradient>
  </defs>
  <rect width="720" height="720" rx="360" fill="url(#grad)"/>
  <circle cx="360" cy="300" r="140" fill="#FDFBF7" stroke="#C9A84C" stroke-width="8"/>
  <path d="M 180 580 Q 360 440 540 580" fill="#2C1F14" stroke="#C9A84C" stroke-width="8"/>
  <text x="360" y="320" font-family="Inter, sans-serif" font-size="36" font-weight="700" fill="#2C1F14" text-anchor="middle">PEDRO 3D</text>
  <text x="360" y="360" font-family="Inter, sans-serif" font-size="20" fill="#5C3D1E" text-anchor="middle">Svitlo Avatar</text>
</svg>"##;
        let _ = std::fs::write(avatar_path.with_extension("svg"), svg);
        // Cria um PNG simples de 1x1 ou cópia de icon.png se disponível
        if let Ok(icon_bytes) = std::fs::read("assets/icon.png") {
            let _ = std::fs::write(avatar_path, icon_bytes);
        } else {
            let dummy_png: &[u8] = &[
                0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x00, 0x0d, 0x49, 0x48,
                0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01, 0x08, 0x06, 0x00, 0x00,
                0x00, 0x1f, 0x15, 0xc4, 0x89, 0x00, 0x00, 0x00, 0x0b, 0x49, 0x44, 0x41, 0x54, 0x78,
                0x9c, 0x63, 0x60, 0x00, 0x00, 0x00, 0x02, 0x00, 0x01, 0xe2, 0x21, 0xbc, 0x33, 0x00,
                0x00, 0x00, 0x00, 0x49, 0x45, 0x4e, 0x44, 0xae, 0x42, 0x60, 0x82,
            ];
            let _ = std::fs::write(avatar_path, dummy_png);
        }
    }
    Ok(())
}

#[derive(Debug, Clone)]
pub struct SvitloVideoOptions {
    pub slide_image_path: Option<PathBuf>,
    pub avatar_image_path: Option<PathBuf>,
    pub background_music_path: Option<PathBuf>,
    pub force_libx264: bool,
}

impl Default for SvitloVideoOptions {
    fn default() -> Self {
        Self {
            slide_image_path: None,
            avatar_image_path: Some(PathBuf::from("assets/avatar_pedro_3d.png")),
            background_music_path: None,
            force_libx264: false,
        }
    }
}

/// PT: Composição de vídeo educacional Svitlo v2 com duração exata do WAV (-t), Avatar 3D e aceleração NVENC.
/// EN: Svitlo v2 educational video composition with exact WAV duration (-t), 3D Avatar, and NVENC acceleration.
// AI: @llm-func compose_lesson_video
pub fn compose_lesson_video(
    script: &VideoLessonScript,
    audio_path: &Path,
    subtitles_path: &Path,
    output_path: &Path,
) -> anyhow::Result<()> {
    compose_lesson_video_with_options(
        script,
        audio_path,
        subtitles_path,
        output_path,
        &SvitloVideoOptions::default(),
    )
}

/// PT: Composição de vídeo educacional completa com opções customizáveis.
/// EN: Full educational video composition with customizable options.
pub fn compose_lesson_video_with_options(
    script: &VideoLessonScript,
    audio_path: &Path,
    subtitles_path: &Path,
    output_path: &Path,
    opts: &SvitloVideoOptions,
) -> anyhow::Result<()> {
    let script_dur: f32 = script.scenes.iter().map(|s| s.duration_secs).sum();
    let exact_duration = get_wav_duration_seconds(audio_path).unwrap_or(script_dur.max(5.0));

    let avatar_path = opts
        .avatar_image_path
        .clone()
        .unwrap_or_else(|| PathBuf::from("assets/avatar_pedro_3d.png"));
    let _ = ensure_avatar_asset(&avatar_path);

    let has_avatar = avatar_path.exists();
    let subs_clean = subtitles_path.to_str().unwrap_or("").replace('\\', "/");

    // Tenta primeiro com NVIDIA h264_nvenc, com fallback para libx264
    let encoders = if opts.force_libx264 {
        vec![("libx264", vec!["-preset", "fast", "-b:v", "4M"])]
    } else {
        vec![
            ("h264_nvenc", vec!["-preset", "p4", "-b:v", "5M"]),
            ("libx264", vec!["-preset", "fast", "-b:v", "4M"]),
        ]
    };

    let mut last_err = String::new();

    for (vcodec, vcodec_args) in encoders {
        let mut cmd = new_silent_command("ffmpeg");
        cmd.arg("-y");

        // 1. Fundo do canvas (1920x1080)
        cmd.args(["-f", "lavfi", "-i", "color=c=0x18120E:s=1920x1080:r=30"]);

        // 2. Avatar 3D (se existir)
        if has_avatar {
            cmd.args(["-loop", "1", "-i", avatar_path.to_str().unwrap_or("")]);
        }

        // 3. Áudio da Narração
        cmd.args(["-i", audio_path.to_str().unwrap_or("")]);

        // 4. Trilha sonora opcional
        let has_bgm = opts
            .background_music_path
            .as_ref()
            .map(|p| p.exists())
            .unwrap_or(false);
        if has_bgm {
            if let Some(ref bgm) = opts.background_music_path {
                cmd.args(["-stream_loop", "-1", "-i", bgm.to_str().unwrap_or("")]);
            }
        }

        // Montagem do filter_complex
        let mut filter_complex = String::new();

        if has_avatar {
            // [0:v] canvas fundo; [1:v] avatar
            // 0s a 5s: Hook de Abertura (Avatar centralizado 720x720)
            // 5s até o fim: PiP no canto inferior direito (360x360 em W-w-40:H-h-40)
            filter_complex.push_str("[1:v]scale=720:720,format=rgba[avatar_hook];");
            filter_complex.push_str("[1:v]scale=360:360,format=rgba[avatar_pip];");
            filter_complex.push_str(
                "[0:v][avatar_hook]overlay=(W-w)/2:(H-h)/2:enable='between(t,0,5)'[v_hook];",
            );
            filter_complex
                .push_str("[v_hook][avatar_pip]overlay=W-w-40:H-h-40:enable='gte(t,5)'[v_pip];");

            if subtitles_path.exists() {
                filter_complex.push_str(&format!(
                    "[v_pip]subtitles='{}':force_style='FontSize=22,PrimaryColour=&H00FDFBF7,OutlineColour=&H002C1F14,BackColour=&H80000000'[v_out];",
                    subs_clean
                ));
            } else {
                filter_complex.push_str("[v_pip]copy[v_out];");
            }
        } else {
            if subtitles_path.exists() {
                filter_complex.push_str(&format!(
                    "[0:v]subtitles='{}':force_style='FontSize=22,PrimaryColour=&H00FDFBF7,OutlineColour=&H002C1F14,BackColour=&H80000000'[v_out];",
                    subs_clean
                ));
            } else {
                filter_complex.push_str("[0:v]copy[v_out];");
            }
        }

        let audio_idx = if has_avatar { 2 } else { 1 };
        if has_bgm {
            let bgm_idx = audio_idx + 1;
            filter_complex.push_str(&format!(
                "[{}:a]volume=1.0[vocal];[{}:a]volume=0.08[bgm];[vocal][bgm]amix=inputs=2:duration=first[a_out]",
                audio_idx, bgm_idx
            ));
        }

        cmd.args(["-filter_complex", &filter_complex]);
        cmd.args(["-map", "[v_out]"]);

        if has_bgm {
            cmd.args(["-map", "[a_out]"]);
        } else {
            cmd.args(["-map", &format!("{}:a", audio_idx)]);
        }

        cmd.args(["-c:v", vcodec]);
        for arg in vcodec_args {
            cmd.arg(arg);
        }

        cmd.args([
            "-pix_fmt",
            "yuv420p",
            "-c:a",
            "aac",
            "-b:a",
            "192k",
            "-t",
            &format!("{:.2}", exact_duration),
            output_path.to_str().unwrap_or(""),
        ]);

        let status = cmd.status();
        match status {
            Ok(st) if st.success() => return Ok(()),
            Ok(st) => {
                last_err = format!("Encoder {} falhou com status {}", vcodec, st);
            }
            Err(e) => {
                last_err = format!("Falha ao invocar {}: {}", vcodec, e);
            }
        }
    }

    anyhow::bail!("Falha na composição de vídeo FFmpeg: {}", last_err)
}
