// PT: Servidor HTTP Axum + MCP SSE Unificado do Zorya v3.0 (Porta 8085)
// EN: Zorya v3.0 Unified Axum HTTP + MCP SSE Server (Port 8085)
// AI: @llm-context Expõe endpoints v3.0: /v1/chat/completions, /v1/models, /v1/models/load, /v1/models/unload, /api/v1/cluster/*, /api/v1/agent/execute, MCP SSE/JSON-RPC e ArtCraft
// AI: @llm-invariant Roteamento soberano integrado com HardwareProfile, NativeInferenceEngine, NetworkManager e ClusterCoordinator

use std::net::SocketAddr;
use std::sync::atomic::AtomicUsize;
use std::sync::Arc;
use axum::{
    extract::{ConnectInfo, DefaultBodyLimit, Multipart, State},
    http::{header, Method, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use serde_json::json;
use tokio::sync::{broadcast, RwLock};
use tower_http::cors::{Any, CorsLayer};

use crate::agent::{handle_agent_execute, ZoryaAgent};
use crate::cluster::{handle_cluster_capabilities, handle_cluster_handshake, ClusterCoordinator};
use crate::config::ZoryaConfig;
use crate::craft::{
    decode_raw_with_lightcraft, master_audio_ebu_r128, render_vector_diagram_svg, RawOutputFormat,
    VectorDiagramSpec,
};
use crate::inference::{
    handle_chat_completions, handle_list_models, handle_load_model, handle_unload_model,
    NativeInferenceEngine,
};
use crate::mcp::{mcp_jsonrpc_handler, mcp_sse_handler};
use crate::network::NetworkManager;
use crate::systray::SystrayController;
use crate::telemetry::TelemetryCollector;

// Global shared daemon state / Estado global compartilhado do daemon
#[derive(Clone)]
pub struct AppState {
    pub config: Arc<ZoryaConfig>,
    pub telemetry: Arc<RwLock<TelemetryCollector>>,
    pub network: Arc<NetworkManager>,
    pub inference: Arc<NativeInferenceEngine>,
    pub cluster: Arc<ClusterCoordinator>,
    pub agent: Arc<ZoryaAgent>,
    pub active_jobs: Arc<AtomicUsize>,
    pub systray: SystrayController,
    pub http_client: reqwest::Client,
    pub mcp_tx: broadcast::Sender<String>,
}

// Create unified Axum router with all mandatory endpoints / Cria roteador Axum unificado com todos os endpoints obrigatórios
pub fn create_router(state: AppState) -> Router {
    let cors = CorsLayer::new()
        .allow_origin(Any)
        .allow_methods([Method::GET, Method::POST, Method::OPTIONS])
        .allow_headers(Any);

    Router::new()
        // Health & System Info
        .route("/health", get(health_handler))
        .route("/api/v1/health", get(health_handler))
        .route("/api/v1/system/info", get(system_info_handler))
        // ZORYA-001: Runtime de Inferência Nativo GGUF (OpenAI-compatible)
        .route("/v1/chat/completions", post(handle_chat_completions))
        .route("/v1/models", get(handle_list_models))
        .route("/v1/models/load", post(handle_load_model))
        .route("/v1/models/unload", post(handle_unload_model))
        // ZORYA-003: Agente Interno de IA com Tool Calling
        .route("/api/v1/agent/execute", post(handle_agent_execute))
        // ZORYA-006: Cluster Federado VPS + Nitro
        .route("/api/v1/cluster/capabilities", get(handle_cluster_capabilities))
        .route("/api/v1/cluster/handshake", post(handle_cluster_handshake))
        // ArtCraft Suite (ZORYA-007 a ZORYA-014)
        .route("/api/v1/image/denoise", post(crate::craft::denoise::handle_image_denoise))
        .route("/api/v1/image/cull", post(crate::craft::culling::handle_cull))
        .route("/api/v1/image/colorspace", post(crate::craft::colorspace::handle_colorspace))
        .route("/api/v1/audio/master-dsp", post(crate::craft::audio_dsp::handle_audio_master))
        .route("/api/v1/audio/denoise", post(crate::craft::audio_dsp::handle_audio_denoise))
        .route("/api/v1/voice/clone", post(crate::craft::voice::handle_voice_clone))
        .route("/api/v1/voice/tts", post(crate::craft::voice::handle_voice_tts))
        .route("/api/v1/voice/embeddings", get(crate::craft::voice::handle_list_embeddings))
        .route("/api/v1/vector/render", post(crate::craft::vector::handle_vector_diagram))
        .route("/api/v1/pdf/extract", post(crate::craft::pdf::handle_pdf_extract))
        .route("/api/v1/video/teaser", post(crate::craft::video_teaser::handle_video_teaser))
        // Svitlo Video Pipeline (ZORYA-016)
        .route("/api/v1/video/youtube-pipeline", post(crate::video::handle_youtube_pipeline))
        // Endpoints Legados ArtCraft
        .route("/api/v1/raw/decode", post(raw_decode_handler))
        .route("/api/v1/vector/diagram", post(vector_diagram_handler))
        .route("/api/v1/audio/master", post(audio_master_handler))
        // ZORYA-004: MCP Server sobre SSE + JSON-RPC 2.0
        .route("/mcp/sse", get(mcp_sse_handler))
        .route("/mcp/message", post(mcp_jsonrpc_handler))
        .route("/mcp", post(mcp_jsonrpc_handler))
        // Limite de até 128 MB para uploads multipart de mídia
        .layer(DefaultBodyLimit::max(128 * 1024 * 1024))
        .layer(cors)
        .with_state(state)
}

// GET /health -> {"status":"ok","node":"usina-zorya","version":"3.0.0"}
async fn health_handler(
    ConnectInfo(addr): ConnectInfo<SocketAddr>,
    State(state): State<AppState>,
) -> Response {
    if !state.network.is_ip_authorized(addr.ip()) {
        return (
            StatusCode::FORBIDDEN,
            Json(json!({ "error": "Unauthorized peer IP / IP de peer não autorizado" })),
        )
            .into_response();
    }

    let mut telemetry = state.telemetry.write().await;
    let payload = telemetry.collect_health();
    Json(payload).into_response()
}

// GET /api/v1/system/info -> Full telemetry via sysinfo
async fn system_info_handler(
    ConnectInfo(addr): ConnectInfo<SocketAddr>,
    State(state): State<AppState>,
) -> Response {
    if !state.network.is_ip_authorized(addr.ip()) {
        return (
            StatusCode::FORBIDDEN,
            Json(json!({ "error": "Unauthorized peer IP / IP de peer não autorizado" })),
        )
            .into_response();
    }

    let mut telemetry = state.telemetry.write().await;
    let info = telemetry.collect_detailed();
    Json(info).into_response()
}

// POST /api/v1/raw/decode -> Multipart RAW -> LightCraft -> JPEG/PNG
async fn raw_decode_handler(
    State(state): State<AppState>,
    mut multipart: Multipart,
) -> Response {
    let _guard = state.systray.begin_work();

    if !state.config.is_craft_enabled("lightcraft") {
        return (
            StatusCode::SERVICE_UNAVAILABLE,
            Json(json!({ "error": "LightCraft module disabled in zorya.toml / Módulo LightCraft desabilitado no zorya.toml" })),
        )
            .into_response();
    }

    let mut raw_bytes: Option<Vec<u8>> = None;
    let mut output_format = RawOutputFormat::Jpeg;
    let mut quality: u8 = 95;

    while let Ok(Some(field)) = multipart.next_field().await {
        let name = field.name().unwrap_or("").to_string();
        match name.as_str() {
            "file" | "raw" | "image" => {
                if let Ok(bytes) = field.bytes().await {
                    raw_bytes = Some(bytes.to_vec());
                }
            }
            "format" => {
                if let Ok(txt) = field.text().await {
                    output_format = RawOutputFormat::from_str_lossy(txt.trim());
                }
            }
            "quality" => {
                if let Ok(txt) = field.text().await {
                    quality = txt.trim().parse::<u8>().unwrap_or(95).clamp(1, 100);
                }
            }
            _ => {}
        }
    }

    let Some(bytes) = raw_bytes else {
        return (
            StatusCode::BAD_REQUEST,
            Json(json!({ "error": "Multipart field 'file' or 'raw' is required / Campo multipart 'file' ou 'raw' é obrigatório" })),
        )
            .into_response();
    };

    let join_res = tokio::task::spawn_blocking(move || {
        decode_raw_with_lightcraft(&bytes, output_format, quality)
    })
    .await;

    match join_res {
        Ok(Ok(encoded)) => (
            StatusCode::OK,
            [(header::CONTENT_TYPE, output_format.content_type())],
            encoded,
        )
            .into_response(),
        Ok(Err(e)) => (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({ "error": e.to_string() })),
        )
            .into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(json!({ "error": e.to_string() })),
        )
            .into_response(),
    }
}

// POST /api/v1/vector/diagram -> JSON spec -> VectorCraft -> SVG
async fn vector_diagram_handler(
    State(state): State<AppState>,
    Json(spec): Json<VectorDiagramSpec>,
) -> Response {
    let _guard = state.systray.begin_work();

    if !state.config.is_craft_enabled("vectorcraft") {
        return (
            StatusCode::SERVICE_UNAVAILABLE,
            Json(json!({ "error": "VectorCraft module disabled in zorya.toml / Módulo VectorCraft desabilitado no zorya.toml" })),
        )
            .into_response();
    }

    match render_vector_diagram_svg(&spec) {
        Ok(svg) => (
            StatusCode::OK,
            [(header::CONTENT_TYPE, "image/svg+xml; charset=utf-8")],
            svg,
        )
            .into_response(),
        Err(e) => (
            StatusCode::BAD_REQUEST,
            Json(json!({ "error": e.to_string() })),
        )
            .into_response(),
    }
}

// POST /api/v1/audio/master -> WAV/MP3 -> SoundCraft EBU R128 -> Mastered WAV file
async fn audio_master_handler(
    State(state): State<AppState>,
    mut multipart: Multipart,
) -> Response {
    let _guard = state.systray.begin_work();

    if !state.config.is_craft_enabled("soundcraft") {
        return (
            StatusCode::SERVICE_UNAVAILABLE,
            Json(json!({ "error": "SoundCraft module disabled in zorya.toml / Módulo SoundCraft desabilitado no zorya.toml" })),
        )
            .into_response();
    }

    let mut audio_bytes: Option<Vec<u8>> = None;
    let mut target_lufs: f32 = -14.0;
    let mut ext_hint = "wav".to_string();

    while let Ok(Some(field)) = multipart.next_field().await {
        let name = field.name().unwrap_or("").to_string();
        if let Some(fname) = field.file_name() {
            if fname.to_ascii_lowercase().ends_with(".mp3") {
                ext_hint = "mp3".to_string();
            }
        }
        match name.as_str() {
            "file" | "audio" => {
                if let Ok(bytes) = field.bytes().await {
                    audio_bytes = Some(bytes.to_vec());
                }
            }
            "target_lufs" => {
                if let Ok(txt) = field.text().await {
                    target_lufs = txt.trim().parse::<f32>().unwrap_or(-14.0);
                }
            }
            _ => {}
        }
    }

    let Some(bytes) = audio_bytes else {
        return (
            StatusCode::BAD_REQUEST,
            Json(json!({ "error": "Multipart field 'file' or 'audio' is required / Campo multipart 'file' ou 'audio' é obrigatório" })),
        )
            .into_response();
    };

    let join_res = tokio::task::spawn_blocking(move || {
        master_audio_ebu_r128(&bytes, Some(&ext_hint), target_lufs)
    })
    .await;

    match join_res {
        Ok(Ok(report)) => (
            StatusCode::OK,
            [
                (header::CONTENT_TYPE, "audio/wav".to_string()),
                (
                    header::CONTENT_DISPOSITION,
                    "attachment; filename=\"mastered_ebu_r128.wav\"".to_string(),
                ),
                (
                    header::HeaderName::from_static("x-zorya-input-lufs"),
                    format!("{:.2}", report.input_lufs),
                ),
                (
                    header::HeaderName::from_static("x-zorya-output-lufs"),
                    format!("{:.2}", report.output_lufs),
                ),
                (
                    header::HeaderName::from_static("x-zorya-true-peak-dbtp"),
                    format!("{:.2}", report.true_peak_dbtp),
                ),
            ],
            report.mastered_wav_bytes,
        )
            .into_response(),
        Ok(Err(e)) => (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({ "error": e.to_string() })),
        )
            .into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(json!({ "error": e.to_string() })),
        )
            .into_response(),
    }
}
