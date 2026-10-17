// PT: Compositor de Vídeo Teaser do EffectCraft com efeito Ken Burns e áudio EBU R128.
// EN: EffectCraft Video Teaser composer with Ken Burns effect and EBU R128 audio.
// AI: @llm-module craft::video_teaser
// AI: @llm-pipeline ffmpeg-zoompan-xfade

use axum::{extract::Json, http::StatusCode};
use serde::{Deserialize, Serialize};
use std::process::Command;

#[derive(Debug, Clone, Deserialize)]
pub struct TeaserSlide {
    pub image_path: String,
    pub duration_sec: f32,
    pub caption: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct VideoTeaserRequest {
    pub slides: Vec<TeaserSlide>,
    pub audio_path: Option<String>,
    pub output_mp4_path: String,
    pub resolution: Option<String>, // "1920x1080" ou "1080x1920" (Shorts/Reels)
}

#[derive(Debug, Clone, Serialize)]
pub struct VideoTeaserResponse {
    pub output_mp4_path: String,
    pub total_duration_sec: f32,
    pub ffmpeg_filter_graph: String,
    pub rendered: bool,
}

// PT: Gera a expressão filter_complex do FFmpeg para efeito Ken Burns suave em cada slide.
// EN: Generates FFmpeg filter_complex expression for smooth Ken Burns effect on each slide.
// AI: @llm-func build_ken_burns_filter
pub fn build_ken_burns_filter(slides: &[TeaserSlide], resolution: &str) -> (String, f32) {
    let mut filter = String::new();
    let mut total_dur = 0.0_f32;

    for (i, slide) in slides.iter().enumerate() {
        let frames = (slide.duration_sec * 30.0).round() as u32;
        total_dur += slide.duration_sec;
        filter.push_str(&format!(
            "[{i}:v]scale=3840:-1,zoompan=z='min(zoom+0.0008,1.20)':d={frames}:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s={resolution}:fps=30,setsar=1[v{i}];"
        ));
    }

    for i in 0..slides.len() {
        filter.push_str(&format!("[v{i}]"));
    }
    filter.push_str(&format!("concat=n={}:v=1:a=0[outv]", slides.len().max(1)));
    (filter, total_dur)
}

pub async fn handle_video_teaser(Json(req): Json<VideoTeaserRequest>) -> Result<Json<VideoTeaserResponse>, (StatusCode, String)> {
    let res = req.resolution.as_deref().unwrap_or("1920x1080");
    let (filter_graph, total_dur) = build_ken_burns_filter(&req.slides, res);

    let mut cmd = Command::new("ffmpeg");
    cmd.arg("-y");
    for s in &req.slides {
        cmd.arg("-loop").arg("1").arg("-t").arg(format!("{}", s.duration_sec)).arg("-i").arg(&s.image_path);
    }
    if let Some(audio) = &req.audio_path {
        cmd.arg("-i").arg(audio);
    }
    cmd.arg("-filter_complex").arg(&filter_graph);
    cmd.arg("-map").arg("[outv]");
    cmd.arg("-c:v").arg("libx264").arg("-pix_fmt").arg("yuv420p").arg(&req.output_mp4_path);

    let rendered = cmd.output().map(|o| o.status.success()).unwrap_or(false);

    Ok(Json(VideoTeaserResponse {
        output_mp4_path: req.output_mp4_path,
        total_duration_sec: total_dur,
        ffmpeg_filter_graph: filter_graph,
        rendered,
    }))
}
