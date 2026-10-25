// PT: [youtube.rs] Preparação de payload e upload OAuth2 para YouTube Data API v3
// EN: [youtube.rs] Payload preparation and OAuth2 upload for YouTube Data API v3
// AI: @llm-module video::youtube

use crate::video::{VideoLessonScript, YouTubeUploadConfig};

pub fn build_youtube_metadata_payload(
    script: &VideoLessonScript,
    cfg: &YouTubeUploadConfig,
) -> serde_json::Value {
    serde_json::json!({
        "snippet": {
            "title": script.title,
            "description": script.description,
            "tags": script.tags,
            "categoryId": "27",
            "defaultLanguage": "pt-BR",
            "defaultAudioLanguage": "pt-BR"
        },
        "status": {
            "privacyStatus": cfg.privacy_status,
            "selfDeclaredMadeForKids": false
        }
    })
}
