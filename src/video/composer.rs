// PT: [composer.rs] Composição de vídeo educacional com slides SVG e áudio via FFmpeg
// EN: [composer.rs] Educational video composition with SVG slides and audio via FFmpeg
// AI: @llm-module video::composer

use std::path::Path;
use std::process::Command;
#[cfg(target_os = "windows")]
use std::os::windows::process::CommandExt;

use crate::video::VideoLessonScript;

pub fn compose_lesson_video(
    script: &VideoLessonScript,
    audio_path: &Path,
    subtitles_path: &Path,
    output_path: &Path,
) -> anyhow::Result<()> {
    let mut cmd = Command::new("ffmpeg");
    #[cfg(target_os = "windows")]
    cmd.creation_flags(0x08000000);

    let status = cmd
        .args([
            "-y",
            "-f", "lavfi",
            "-i", &format!("color=c=0x111827:s=1920x1080:d={}", script.scenes.iter().map(|s| s.duration_secs).sum::<f32>()),
            "-i", audio_path.to_str().unwrap_or(""),
            "-vf", &format!("subtitles={}", subtitles_path.to_str().unwrap_or("").replace('\\', "/")),
            "-c:v", "libx264",
            "-pix_fmt", "yuv420p",
            "-c:a", "aac",
            "-b:a", "192k",
            "-shortest",
            output_path.to_str().unwrap_or(""),
        ])
        .status()?;

    if !status.success() {
        anyhow::bail!("FFmpeg composition failed with status: {}", status);
    }
    Ok(())
}
