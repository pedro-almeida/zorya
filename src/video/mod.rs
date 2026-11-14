// PT: Pipeline completo Svitlo -> YouTube (Roteiro, Narração Clonada, Composição FFmpeg e Upload YouTube).
// EN: Complete Svitlo -> YouTube pipeline (Script, Cloned Narration, FFmpeg Composition, and YouTube Upload).
// AI: @llm-module video @llm-endpoint POST /api/v1/video/youtube-pipeline

pub mod composer;
pub mod narration;
pub mod script;
pub mod transcode;
pub mod youtube;

use axum::{extract::Json, http::StatusCode};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VideoScene {
    pub scene_index: usize,
    pub heading: String,
    pub narration_text: String,
    pub key_concepts: Vec<String>,
    pub duration_secs: f32,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct VideoLessonScript {
    pub title: String,
    pub description: String,
    pub tags: Vec<String>,
    pub scenes: Vec<VideoScene>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct YouTubeUploadConfig {
    pub client_id: String,
    pub client_secret: String,
    pub refresh_token: String,
    pub privacy_status: String, // "unlisted" | "private" | "public"
}

#[derive(Debug, Clone, Serialize)]
pub struct YouTubePublishResult {
    pub video_id: String,
    pub watch_url: String,
    pub privacy_status: String,
    pub total_duration_secs: f32,
}

#[derive(Debug, Clone, Deserialize)]
pub struct YouTubePipelineRequest {
    pub title: String,
    pub raw_markdown: String,
    pub upload_to_youtube: Option<bool>,
    pub youtube_config: Option<YouTubeUploadConfig>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct SvitloVideoV2Request {
    pub title: String,
    pub raw_markdown: String,
    pub voice_name: Option<String>,
    pub gemini_api_key: Option<String>,
    pub background_music: Option<bool>,
}

// Re-exports
pub use composer::{
    compose_lesson_video, compose_lesson_video_with_options, get_wav_bytes_duration,
    get_wav_duration_seconds, SvitloVideoOptions,
};
pub use narration::{
    build_srt_subtitles, format_energetic_rhetoric, synthesize_energetic_narration,
    synthesize_gemini_tts,
};
pub use script::generate_lesson_script;
pub use youtube::build_youtube_metadata_payload;

// POST /api/v1/video/youtube-pipeline
pub async fn handle_youtube_pipeline(
    Json(req): Json<YouTubePipelineRequest>,
) -> (StatusCode, Json<serde_json::Value>) {
    let script = generate_lesson_script(&req.title, &req.raw_markdown);
    let srt = build_srt_subtitles(&script);
    let total_duration: f32 = script.scenes.iter().map(|s| s.duration_secs).sum();

    let yt_metadata = if let Some(ref cfg) = req.youtube_config {
        Some(build_youtube_metadata_payload(&script, cfg))
    } else {
        None
    };

    (
        StatusCode::OK,
        Json(serde_json::json!({
            "status": "pipeline_generated",
            "title": script.title,
            "scene_count": script.scenes.len(),
            "total_duration_secs": total_duration,
            "subtitles_srt": srt,
            "youtube_metadata": yt_metadata,
            "script": script
        })),
    )
}

// POST /api/v1/video/svitlo-v2
pub async fn handle_svitlo_video_v2(
    Json(req): Json<SvitloVideoV2Request>,
) -> (StatusCode, Json<serde_json::Value>) {
    let script = generate_lesson_script(&req.title, &req.raw_markdown);
    let srt = build_srt_subtitles(&script);
    let full_text: String = script
        .scenes
        .iter()
        .map(|s| s.narration_text.as_str())
        .collect::<Vec<_>>()
        .join(" ");

    let voice = req.voice_name.as_deref().unwrap_or("Charon");
    let key = req.gemini_api_key.as_deref();

    let audio_bytes = match synthesize_energetic_narration(&full_text, Some(voice), key).await {
        Ok(b) => b,
        Err(e) => {
            return (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({
                    "status": "error",
                    "error": format!("Falha na síntese vocal Modo Clóvis: {}", e)
                })),
            );
        }
    };

    let dur = get_wav_bytes_duration(&audio_bytes).unwrap_or(script.scenes.len() as f32 * 4.0);

    let ts = chrono::Utc::now().timestamp_millis();
    let tmp_dir = std::env::temp_dir();
    let audio_file = tmp_dir.join(format!("svitlo_v2_{ts}.wav"));
    let srt_file = tmp_dir.join(format!("svitlo_v2_{ts}.srt"));
    let out_video = tmp_dir.join(format!("svitlo_v2_{ts}.mp4"));

    let _ = std::fs::write(&audio_file, &audio_bytes);
    let _ = std::fs::write(&srt_file, &srt);

    let opts = SvitloVideoOptions::default();
    let comp_res =
        compose_lesson_video_with_options(&script, &audio_file, &srt_file, &out_video, &opts);

    let _ = std::fs::remove_file(&audio_file);
    let _ = std::fs::remove_file(&srt_file);

    match comp_res {
        Ok(()) => (
            StatusCode::OK,
            Json(serde_json::json!({
                "status": "ok",
                "title": script.title,
                "engine": "Svitlo Video Engine v2 (3D Avatar + Modo Clóvis + PiP NVENC)",
                "duration_seconds": dur,
                "output_video_path": out_video.to_string_lossy(),
                "subtitles_srt": srt,
                "voice_profile": voice,
            })),
        ),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({
                "status": "error",
                "error": format!("Falha na composição FFmpeg: {}", e)
            })),
        ),
    }
}
