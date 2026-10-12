//! Servidor HTTP Unificado e Ponte MCP (Model Context Protocol) do Zorya
//! Escuta na porta 9876 com suporte a CORS para o Svitanok (https://tenorio.fot.br).

use std::path::{Path, PathBuf};
use axum::{
    extract::{Path as AxumPath, State},
    http::{header, HeaderValue, Method, StatusCode},
    response::{IntoResponse, Json},
    routing::{get, post},
    Router,
};
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use tower_http::cors::{Any, CorsLayer};

use crate::audio::{AudioCleanRequest, SharedAudioState, trigger_audio_clean};
use crate::photo::{
    PhotoCullStatus, PhotoMasterConfig, PhotoMasterStatus, SharedPhotoState,
    trigger_photo_cull, trigger_photo_master,
};
use crate::video::{
    SharedVideoState, VideoProxyRequest, VideoRenderRequest,
    trigger_video_proxy, trigger_video_render,
};

/// Estado global compartilhado por todos os módulos
#[derive(Clone)]
pub struct AppState {
    pub photo: SharedPhotoState,
    pub video: SharedVideoState,
    pub audio: SharedAudioState,
}

#[derive(Debug, Deserialize)]
pub struct PhotoIngestRequest {
    pub folder: String,
    pub preview_output_dir: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct PhotoMasterProcessRequest {
    pub selected_ids: Vec<String>,
    pub output_dir: Option<String>,
    pub denoise_luma: Option<f32>,
    pub denoise_chroma: Option<f32>,
    pub detail_threshold: Option<f32>,
    pub sharpen_amount: Option<f32>,
    pub jpeg_quality: Option<u8>,
}

#[derive(Debug, Serialize)]
pub struct HealthResponse {
    pub status: &'static str,
    pub service: &'static str,
    pub version: &'static str,
    pub port: u16,
    pub capabilities: Vec<&'static str>,
    pub cpu_cores: usize,
    pub engine: &'static str,
    pub denoise_mode: &'static str,
}

/// Mensagem de requisição MCP (JSON-RPC 2.0)
#[derive(Debug, Deserialize)]
pub struct McpRequest {
    pub jsonrpc: Option<String>,
    pub id: Option<Value>,
    pub method: String,
    pub params: Option<Value>,
}

/// Constrói o aplicativo Axum com rotas REST e MCP
pub fn create_app(state: AppState) -> Router {
    let cors = CorsLayer::new()
        .allow_origin(Any)
        .allow_methods([Method::GET, Method::POST, Method::OPTIONS])
        .allow_headers(Any);

    Router::new()
        // Endpoints de Saúde e Telemetria
        .route("/api/health", get(health_handler))
        // Endpoints Fotográficos (Svitanok + Zorya v2)
        .route("/api/photo/ingest", post(photo_ingest_handler))
        .route("/api/photo/culling/status", get(photo_culling_status_handler))
        .route("/api/photo/master/process", post(photo_master_process_handler))
        .route("/api/photo/master/status", get(photo_master_status_handler))
        // Aliases para retrocompatibilidade com o frontend atual do Svitanok
        .route("/api/ingest", post(photo_ingest_handler))
        .route("/api/culling/status", get(photo_culling_status_handler))
        .route("/api/master/process", post(photo_master_process_handler))
        .route("/api/master/status", get(photo_master_status_handler))
        // Endpoints de Vídeo (FilmCraft + EffectCraft)
        .route("/api/video/proxy", post(video_proxy_handler))
        .route("/api/video/render", post(video_render_handler))
        .route("/api/video/status", get(video_status_handler))
        // Endpoints de Áudio (SoundCraft DAW)
        .route("/api/audio/clean", post(audio_clean_handler))
        .route("/api/audio/status", get(audio_status_handler))
        // Servidor MCP para Agentes de IA
        .route("/mcp", post(mcp_handler))
        .route("/api/mcp", post(mcp_handler))
        // Servidor nativo de miniaturas WebP
        .route("/cache/previews/:filename", get(serve_preview_handler))
        .layer(cors)
        .with_state(state)
}

/// GET /api/health
async fn health_handler() -> Json<HealthResponse> {
    Json(HealthResponse {
        status: "ok",
        service: "zorya",
        version: "2.0.0",
        port: 9876,
        capabilities: vec!["photo", "video", "audio", "mcp"],
        cpu_cores: std::thread::available_parallelism().map(|n| n.get()).unwrap_or(4),
        engine: "Zorya Studio Daemon (LightCraft + PhotoCraft + FilmCraft + EffectCraft + SoundCraft)",
        denoise_mode: "Strict Classical Mathematical (Zero AI/Diffusion)",
    })
}

/// GET /cache/previews/:filename
async fn serve_preview_handler(
    AxumPath(filename): AxumPath<String>,
) -> Result<impl IntoResponse, StatusCode> {
    let clean_name = Path::new(&filename)
        .file_name()
        .and_then(|s| s.to_str())
        .ok_or(StatusCode::BAD_REQUEST)?;

    let path = PathBuf::from("cache/previews").join(clean_name);
    match tokio::fs::read(&path).await {
        Ok(bytes) => {
            let mut headers = axum::http::HeaderMap::new();
            headers.insert(header::CONTENT_TYPE, HeaderValue::from_static("image/webp"));
            headers.insert(header::CACHE_CONTROL, HeaderValue::from_static("public, max-age=86400"));
            Ok((headers, bytes))
        }
        Err(_) => Err(StatusCode::NOT_FOUND),
    }
}

// ----------------- FOTOGRAFIA -----------------

async fn photo_ingest_handler(
    State(state): State<AppState>,
    Json(req): Json<PhotoIngestRequest>,
) -> impl IntoResponse {
    let folder_path = PathBuf::from(&req.folder);
    if !folder_path.exists() {
        return (
            StatusCode::BAD_REQUEST,
            Json(json!({ "error": format!("Pasta inexistente: {}", req.folder) })),
        );
    }

    let preview_dir = req
        .preview_output_dir
        .map(PathBuf::from)
        .unwrap_or_else(|| PathBuf::from("cache/previews"));

    trigger_photo_cull(state.photo, folder_path, preview_dir);

    (
        StatusCode::ACCEPTED,
        Json(json!({
            "message": "Ingestao e culling disparados em background",
            "status": "processing"
        })),
    )
}

async fn photo_culling_status_handler(
    State(state): State<AppState>,
) -> Json<PhotoCullStatus> {
    let s = state.photo.read().await;
    Json(s.cull.clone())
}

async fn photo_master_process_handler(
    State(state): State<AppState>,
    Json(req): Json<PhotoMasterProcessRequest>,
) -> impl IntoResponse {
    if req.selected_ids.is_empty() {
        return (
            StatusCode::BAD_REQUEST,
            Json(json!({ "error": "Nenhum arquivo selecionado" })),
        );
    }

    let output_dir = req
        .output_dir
        .map(PathBuf::from)
        .unwrap_or_else(|| PathBuf::from("export/master"));

    let selected_paths: Vec<PathBuf> = req.selected_ids.iter().map(PathBuf::from).collect();

    let mut config = PhotoMasterConfig::default();
    if let Some(v) = req.denoise_luma { config.denoise_luma = v; }
    if let Some(v) = req.denoise_chroma { config.denoise_chroma = v; }
    if let Some(v) = req.detail_threshold { config.detail_threshold = v; }
    if let Some(v) = req.sharpen_amount { config.sharpen_amount = v; }
    if let Some(v) = req.jpeg_quality { config.jpeg_quality = v; }

    trigger_photo_master(state.photo, selected_paths, output_dir, config);

    (
        StatusCode::ACCEPTED,
        Json(json!({
            "message": "Processamento Master disparado",
            "total_selected": req.selected_ids.len(),
            "status": "processing"
        })),
    )
}

async fn photo_master_status_handler(
    State(state): State<AppState>,
) -> Json<PhotoMasterStatus> {
    let s = state.photo.read().await;
    Json(s.master.clone())
}

// ----------------- VÍDEO -----------------

async fn video_proxy_handler(
    State(state): State<AppState>,
    Json(req): Json<VideoProxyRequest>,
) -> impl IntoResponse {
    trigger_video_proxy(state.video, req);
    (StatusCode::ACCEPTED, Json(json!({ "status": "video proxy initiated" })))
}

async fn video_render_handler(
    State(state): State<AppState>,
    Json(req): Json<VideoRenderRequest>,
) -> impl IntoResponse {
    trigger_video_render(state.video, req);
    (StatusCode::ACCEPTED, Json(json!({ "status": "video render initiated" })))
}

async fn video_status_handler(
    State(state): State<AppState>,
) -> impl IntoResponse {
    let s = state.video.read().await;
    Json(json!({
        "active_jobs": s.active_jobs,
        "completed_jobs": s.completed_jobs
    }))
}

// ----------------- ÁUDIO -----------------

async fn audio_clean_handler(
    State(state): State<AppState>,
    Json(req): Json<AudioCleanRequest>,
) -> impl IntoResponse {
    trigger_audio_clean(state.audio, req);
    (StatusCode::ACCEPTED, Json(json!({ "status": "audio cleanup initiated" })))
}

async fn audio_status_handler(
    State(state): State<AppState>,
) -> impl IntoResponse {
    let s = state.audio.read().await;
    Json(json!({ "completed_jobs": s.completed_jobs }))
}

// ----------------- SERVIDOR MCP (JSON-RPC 2.0) -----------------

async fn mcp_handler(
    State(state): State<AppState>,
    Json(req): Json<McpRequest>,
) -> Json<Value> {
    let req_id = req.id.unwrap_or(json!(1));

    match req.method.as_str() {
        "initialize" => Json(json!({
            "jsonrpc": "2.0",
            "id": req_id,
            "result": {
                "serverInfo": { "name": "zorya-studio-mcp", "version": "2.0.0" },
                "capabilities": { "tools": {} }
            }
        })),
        "tools/list" => Json(json!({
            "jsonrpc": "2.0",
            "id": req_id,
            "result": {
                "tools": [
                    {
                        "name": "zorya_photo_cull",
                        "description": "Dispara ingestao e culling rapido de RAWs com pontuacao de foco e WebP",
                        "inputSchema": {
                            "type": "object",
                            "properties": { "folder": { "type": "string" } },
                            "required": ["folder"]
                        }
                    },
                    {
                        "name": "zorya_photo_master",
                        "description": "Dispara masterizacao final 32-bit com denoise matematico classico",
                        "inputSchema": {
                            "type": "object",
                            "properties": { "selected_ids": { "type": "array", "items": { "type": "string" } } },
                            "required": ["selected_ids"]
                        }
                    },
                    {
                        "name": "zorya_video_proxy",
                        "description": "Gera proxy leve de video para visualizacao web no Svitanok",
                        "inputSchema": {
                            "type": "object",
                            "properties": { "input_path": { "type": "string" } },
                            "required": ["input_path"]
                        }
                    },
                    {
                        "name": "zorya_audio_clean",
                        "description": "Executa reducao de ruido e normalizacao vocal EBU R128 (-14 LUFS)",
                        "inputSchema": {
                            "type": "object",
                            "properties": { "input_path": { "type": "string" } },
                            "required": ["input_path"]
                        }
                    },
                    {
                        "name": "zorya_system_status",
                        "description": "Consulta saude do daemon e jobs ativos",
                        "inputSchema": { "type": "object" }
                    }
                ]
            }
        })),
        "tools/call" => {
            let tool_name = req.params.as_ref()
                .and_then(|p| p.get("name"))
                .and_then(|n| n.as_str())
                .unwrap_or_default();

            let args = req.params.as_ref()
                .and_then(|p| p.get("arguments"))
                .cloned()
                .unwrap_or(json!({}));

            let result_text = match tool_name {
                "zorya_photo_cull" => {
                    let folder = args.get("folder").and_then(|v| v.as_str()).unwrap_or("");
                    if !folder.is_empty() {
                        trigger_photo_cull(state.photo, PathBuf::from(folder), PathBuf::from("cache/previews"));
                        format!("Culling disparado para {}", folder)
                    } else {
                        "Erro: parametro 'folder' ausente".to_string()
                    }
                }
                "zorya_photo_master" => {
                    let ids = args.get("selected_ids")
                        .and_then(|v| v.as_array())
                        .map(|arr| arr.iter().filter_map(|s| s.as_str().map(PathBuf::from)).collect())
                        .unwrap_or_default();
                    trigger_photo_master(state.photo, ids, PathBuf::from("export/master"), PhotoMasterConfig::default());
                    "Masterizacao fotográfica disparada com sucesso".to_string()
                }
                "zorya_video_proxy" => {
                    let in_path = args.get("input_path").and_then(|v| v.as_str()).unwrap_or("");
                    trigger_video_proxy(state.video, VideoProxyRequest {
                        input_path: in_path.to_string(),
                        output_dir: None,
                        resolution: Some("720p".to_string()),
                        crf: Some(24),
                    });
                    format!("Proxy disparado para {}", in_path)
                }
                "zorya_audio_clean" => {
                    let in_path = args.get("input_path").and_then(|v| v.as_str()).unwrap_or("");
                    trigger_audio_clean(state.audio, AudioCleanRequest {
                        input_path: in_path.to_string(),
                        output_dir: None,
                        noise_reduction_amount_db: Some(12.0),
                        target_lufs: Some(-14.0),
                        remove_low_rumble_hz: Some(80.0),
                    });
                    format!("Limpeza de áudio iniciada para {}", in_path)
                }
                "zorya_system_status" => {
                    "Zorya Studio Daemon 2.0.0 operacional na porta 9876".to_string()
                }
                _ => format!("Ferramenta desconhecida: {}", tool_name),
            };

            Json(json!({
                "jsonrpc": "2.0",
                "id": req_id,
                "result": {
                    "content": [{ "type": "text", "text": result_text }]
                }
            }))
        }
        _ => Json(json!({
            "jsonrpc": "2.0",
            "id": req_id,
            "error": { "code": -32601, "message": "Method not found" }
        })),
    }
}
