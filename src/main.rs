//! Zorya 2.0 - Unified Audio-Visual Studio Daemon & MCP Server
//! Motores Integrados: LightCraft + PhotoCraft + FilmCraft + EffectCraft + SoundCraft

#[cfg(feature = "photo")]
pub mod photo;

#[cfg(feature = "video")]
pub mod video;

#[cfg(feature = "audio")]
pub mod audio;

pub mod server;

use std::net::SocketAddr;
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

use crate::server::AppState;

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    tracing_subscriber::registry()
        .with(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| "zorya=info,tower_http=info".into()),
        )
        .with(tracing_subscriber::fmt::layer())
        .init();

    tracing::info!("============================================================");
    tracing::info!("  ZORYA 2.0 - DAEMON MULTIMIDIA & SERVIDOR MCP (ESTUDIO)    ");
    tracing::info!("  Foto:  LightCraft (RAW 32-bit float) + PhotoCraft (Sharp)  ");
    tracing::info!("  Video: FilmCraft (Color Grade LUTs) + EffectCraft (Motion) ");
    tracing::info!("  Audio: SoundCraft DAW (Spectral Denoise + EBU R128)        ");
    tracing::info!("  Denoise matematico estrito: Zero IA generativa / difusao   ");
    tracing::info!("============================================================");

    // Inicialização do estado compartilhado para os 3 motores
    let app_state = AppState {
        photo: photo::create_shared_photo_state(),
        video: video::create_shared_video_state(),
        audio: audio::create_shared_audio_state(),
    };

    let app = server::create_app(app_state);

    let port = 9876;
    let addr = SocketAddr::from(([0, 0, 0, 0], port));
    tracing::info!("Servidor REST e MCP escutando em http://{}", addr);

    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(listener, app).await?;

    Ok(())
}
