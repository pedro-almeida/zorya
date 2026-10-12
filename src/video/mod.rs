//! Módulo de Vídeo Zorya (FilmCraft + EffectCraft)

pub mod transcode;

use std::sync::Arc;
use serde::{Deserialize, Serialize};
use tokio::sync::RwLock;

pub use self::transcode::{VideoJobResult, VideoProxyRequest, VideoRenderRequest, execute_video_proxy, execute_video_render};

#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct VideoEngineState {
    pub active_jobs: Vec<VideoJobResult>,
    pub completed_jobs: Vec<VideoJobResult>,
}

pub type SharedVideoState = Arc<RwLock<VideoEngineState>>;

pub fn create_shared_video_state() -> SharedVideoState {
    Arc::new(RwLock::new(VideoEngineState::default()))
}

/// Dispara o proxy de vídeo de forma assíncrona
pub fn trigger_video_proxy(state: SharedVideoState, req: VideoProxyRequest) {
    tokio::spawn(async move {
        let res = tokio::task::spawn_blocking(move || execute_video_proxy(&req)).await;
        if let Ok(Ok(result)) = res {
            let mut s = state.write().await;
            s.completed_jobs.push(result);
        }
    });
}

/// Dispara o render com LUT de vídeo de forma assíncrona
pub fn trigger_video_render(state: SharedVideoState, req: VideoRenderRequest) {
    tokio::spawn(async move {
        let res = tokio::task::spawn_blocking(move || execute_video_render(&req)).await;
        if let Ok(Ok(result)) = res {
            let mut s = state.write().await;
            s.completed_jobs.push(result);
        }
    });
}
