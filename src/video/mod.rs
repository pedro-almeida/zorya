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

// Re-exports
pub use composer::compose_lesson_video;
pub use narration::build_srt_subtitles;
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
