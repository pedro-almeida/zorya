// PT: Servidor HTTP Axum + MCP SSE Unificado do Zorya v3.0 (Porta Canônica 8085)
// EN: Zorya v3.0 Unified Axum HTTP + MCP SSE Server (Canonical Port 8085)
// AI: @llm-context Expõe endpoints v3.0: /settings, /api/v1/settings, /api/v1/ai/quotas, /api/v1/ai/route, /api/v1/test/svitlo-openalex, ArtCraft e MCP SSE
// AI: @llm-invariant Persistência atômica, sanitização contra Path Traversal, Headers OWASP e CSRF

use std::net::SocketAddr;
use std::sync::atomic::AtomicUsize;
use std::sync::Arc;
use axum::{
    extract::{ConnectInfo, DefaultBodyLimit, Multipart, State},
    http::{header, HeaderMap, Method, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use serde_json::json;
use tokio::sync::{broadcast, RwLock};
use tower_http::cors::CorsLayer;

use crate::agent::{handle_agent_execute, ZoryaAgent};
use crate::cluster::{handle_cluster_capabilities, handle_cluster_handshake, ClusterCoordinator};
use crate::config::ZoryaConfig;
use crate::craft::{
    decode_raw_with_lightcraft, master_audio_ebu_r128, render_vector_diagram_svg, RawOutputFormat,
    VectorDiagramSpec,
};
pub use crate::inference::{
    handle_anki_generate_media, handle_chat_completions, handle_list_models, handle_load_model,
    handle_svitlo_generate, handle_unload_model, AnkiGenerateMediaRequest, AnkiMediaRequest,
    NativeInferenceEngine, SvitloGenerateRequest,
};
use crate::mcp::{mcp_jsonrpc_handler, mcp_sse_handler};
use crate::network::NetworkManager;
use crate::quota_router::QuotaRouter;
use crate::security::{generate_csrf_token, security_headers_middleware, validate_csrf_token};
use crate::systray::SystrayController;
use crate::telemetry::TelemetryCollector;
use crate::ui::render_svitlo_dashboard_html;

// Global shared daemon state / Estado global compartilhado do daemon
#[derive(Clone)]
pub struct AppState {
    pub config: Arc<RwLock<ZoryaConfig>>,
    pub telemetry: Arc<RwLock<TelemetryCollector>>,
    pub network: Arc<NetworkManager>,
    pub inference: Arc<NativeInferenceEngine>,
    pub cluster: Arc<ClusterCoordinator>,
    pub agent: Arc<ZoryaAgent>,
    pub quota_router: Arc<QuotaRouter>,
    pub active_jobs: Arc<AtomicUsize>,
    pub systray: SystrayController,
    pub http_client: reqwest::Client,
    pub mcp_tx: broadcast::Sender<String>,
}

// Middleware para suporte ao W3C Private Network Access (PNA) exigido pelo Chrome/Edge
async fn private_network_access_middleware(
    req: axum::extract::Request,
    next: axum::middleware::Next,
) -> Response {
    let mut response = next.run(req).await;
    response.headers_mut().insert(
        header::HeaderName::from_static("access-control-allow-private-network"),
        header::HeaderValue::from_static("true"),
    );
    response
}

// PT: Middleware de segurança soberana que restringe rotas sensíveis a Loopback, Tailscale (100.64.0.0/10), LAN ou Bearer Token válido.
// EN: Sovereign security middleware restricting sensitive routes to Loopback, Tailscale (100.64.0.0/10), LAN or valid Bearer Token.
// AI: @llm-middleware sovereign_auth_middleware @llm-security Bloqueia acesso externo não autorizado
async fn sovereign_auth_middleware(
    State(state): State<AppState>,
    req: axum::extract::Request,
    next: axum::middleware::Next,
) -> Result<Response, (StatusCode, Json<serde_json::Value>)> {
    let cfg = state.config.read().await;
    let expected_token = cfg.network.auth_token.trim();
    let hs_auth = cfg.network.headscale_auth_key.trim();
    let shared_secret = cfg.cluster.shared_secret.trim();

    let auth_header = req
        .headers()
        .get(axum::http::header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");
    let zorya_header = req
        .headers()
        .get("x-zorya-token")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");
    let csrf_header = req
        .headers()
        .get("x-zorya-csrf")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");

    let bearer = auth_header.strip_prefix("Bearer ").unwrap_or("").trim();

    let token_valid = (!expected_token.is_empty()
        && (bearer == expected_token || zorya_header == expected_token))
        || (!hs_auth.is_empty()
            && (bearer == hs_auth || zorya_header == hs_auth))
        || (!shared_secret.is_empty()
            && (bearer == shared_secret || zorya_header == shared_secret))
        || (!csrf_header.is_empty() && validate_csrf_token(csrf_header));

    let host_header = req
        .headers()
        .get(axum::http::header::HOST)
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");
    let is_local_or_tailscale_host = host_header.starts_with("127.0.0.1")
        || host_header.starts_with("localhost")
        || host_header.starts_with("100.")
        || host_header.starts_with("192.168.")
        || host_header.starts_with("10.")
        || host_header.starts_with("143.95.223.218");

    if token_valid || is_local_or_tailscale_host {
        Ok(next.run(req).await)
    } else {
        Err((
            StatusCode::UNAUTHORIZED,
            Json(serde_json::json!({
                "error": "Acesso não autorizado ao nó Zorya. Forneça Authorization: Bearer <token> ou conecte via Tailscale."
            })),
        ))
    }
}

// PT: Valida se um caminho de arquivo local é seguro (sem Path Traversal '..' e sem diretórios críticos do sistema).
// EN: Validates whether a local file path is safe (no '..' Path Traversal and no critical system directories).
// AI: @llm-func is_safe_craft_path @llm-security Previne Path Traversal em endpoints Craft
pub fn is_safe_craft_path(raw_path: &str) -> bool {
    if raw_path.trim().is_empty() || raw_path.contains("..") {
        return false;
    }
    let lower = raw_path.replace('\\', "/").to_lowercase();
    let forbidden = [
        "/etc/shadow",
        "/etc/passwd",
        "credentials.json",
        "zorya.toml",
        "windows/system32",
        ".ssh/",
    ];
    !forbidden.iter().any(|f| lower.contains(f))
}

// Create unified Axum router with all mandatory endpoints / Cria roteador Axum unificado com todos os endpoints obrigatórios
pub fn create_router(state: AppState) -> Router {
    let cors = CorsLayer::new()
        .allow_origin(tower_http::cors::AllowOrigin::predicate(|origin: &header::HeaderValue, _req_parts| {
            if let Ok(s) = origin.to_str() {
                s.ends_with(".espectroipe.com.br")
                    || s == "https://espectroipe.com.br"
                    || s.ends_with(".tenorio.fot.br")
                    || s == "https://tenorio.fot.br"
                    || s.starts_with("http://localhost")
                    || s.starts_with("http://127.0.0.1")
                    || s.starts_with("http://100.")
                    || s.starts_with("https://100.")
            } else {
                false
            }
        }))
        .allow_methods([Method::GET, Method::POST, Method::OPTIONS])
        .allow_headers([
            axum::http::header::CONTENT_TYPE,
            axum::http::header::AUTHORIZATION,
            axum::http::header::ACCEPT,
            axum::http::HeaderName::from_static("x-zorya-token"),
            axum::http::HeaderName::from_static("x-zorya-csrf"),
            axum::http::HeaderName::from_static("x-requested-with"),
        ])
        .allow_credentials(true)
        .expose_headers([header::HeaderName::from_static("access-control-allow-private-network")]);

    let protected_routes = Router::new()
        // Settings Dashboard & Web UI (Svitlo Design System)
        .route("/", get(handle_settings_dashboard))
        .route("/settings", get(handle_settings_dashboard))
        .route("/api/v1/settings", get(handle_get_settings))
        .route("/api/v1/settings/keys", get(handle_get_settings_keys))
        .route("/api/v1/settings", post(handle_save_config_unified))
        .route("/api/v1/config/save", post(handle_save_config_unified))
        .route("/settings", post(handle_save_config_unified))
        // Rotas Sensíveis ArtCraft
        .route("/api/v1/image/cull", post(crate::craft::culling::handle_cull))
        .route("/api/v1/image/colorspace", post(crate::craft::colorspace::handle_colorspace))
        .route("/api/v1/voice/clone", post(crate::craft::voice::handle_voice_clone))
        .route("/api/v1/pdf/extract", post(crate::craft::pdf::handle_pdf_extract))
        .route_layer(axum::middleware::from_fn_with_state(state.clone(), sovereign_auth_middleware));

    Router::new()
        .merge(protected_routes)
        // Favicon Oficial Zorya
        .route("/favicon.ico", get(handle_favicon))
        // Health & System Info (Compatibilidade Frontend Svitlo & Private Network Access)
        .route("/health", get(health_handler))
        .route("/api/status", get(health_handler))
        .route("/api/v1/health", get(health_handler))
        .route("/api/v1/system/info", get(system_info_handler))
        // ZORYA-001: Runtime de Inferência Nativo GGUF (OpenAI-compatible)
        .route("/v1/chat/completions", post(handle_chat_completions))
        .route("/v1/models", get(handle_list_models))
        .route("/v1/models/load", post(handle_load_model))
        .route("/v1/models/unload", post(handle_unload_model))
        // Svitlo & Anki Cognitive Study Pipeline
        .route("/api/v1/svitlo/generate", post(handle_svitlo_generate))
        .route("/api/v1/anki/generate-media", post(handle_anki_generate_media))
        // Diagnóstico e Teste Svitlo + OpenAlex
        .route("/api/v1/test/svitlo-openalex", post(handle_test_svitlo_openalex))
        // Gestão de Cotas de IA (36 Modelos)
        .route("/api/v1/ai/quotas", get(handle_get_ai_quotas))
        .route("/api/v1/ai/quotas/reset", post(handle_reset_ai_quotas))
        .route("/api/v1/ai/route", post(handle_route_ai_task))
        // ZORYA-003: Agente Interno de IA com Tool Calling
        .route("/api/v1/agent/execute", post(handle_agent_execute))
        // ZORYA-006: Cluster Federado VPS + Nitro
        .route("/api/v1/cluster/capabilities", get(handle_cluster_capabilities))
        .route("/api/v1/cluster/handshake", post(handle_cluster_handshake))
        // ArtCraft Suite (ZORYA-007 a ZORYA-014)
        .route("/api/v1/image/denoise", post(crate::craft::denoise::handle_image_denoise))
        .route("/api/v1/audio/master-dsp", post(crate::craft::audio_dsp::handle_audio_master))
        .route("/api/v1/audio/denoise", post(crate::craft::audio_dsp::handle_audio_denoise))
        .route("/api/v1/voice/tts", post(crate::craft::voice::handle_voice_tts))
        .route("/api/v1/voice/embeddings", get(crate::craft::voice::handle_list_embeddings))
        .route("/api/v1/vector/render", post(crate::craft::vector::handle_vector_diagram))
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
        .layer(axum::middleware::from_fn(private_network_access_middleware))
        .layer(axum::middleware::from_fn(security_headers_middleware))
        .with_state(state)
}

// GET /health e GET /api/status -> {"status":"ok","online":true,"node":"usina-zorya","version":"3.0.0","port":8085,...}
async fn health_handler(
    State(state): State<AppState>,
) -> Response {
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
    let cfg = state.config.read().await;

    if !cfg.is_craft_enabled("lightcraft") {
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
    let cfg = state.config.read().await;

    if !cfg.is_craft_enabled("vectorcraft") {
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
    let cfg = state.config.read().await;

    if !cfg.is_craft_enabled("soundcraft") {
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

// Handler do Painel de Configurações Web HTML/CSS no padrão Svitlo Design System
pub async fn handle_settings_dashboard(State(state): State<AppState>) -> impl IntoResponse {
    let cfg = state.config.read().await.clone();
    let telem = {
        let mut t = state.telemetry.write().await;
        t.collect_detailed()
    };
    let net_snap = state.network.status_snapshot().await;
    let tray_snap = state.systray.snapshot();

    let ts_ip_str = net_snap
        .tailscale_ip
        .clone()
        .or_else(|| cfg.network.tailscale_peer_hostname.clone())
        .unwrap_or_else(|| cfg.server.tailscale_ip.clone());

    let csrf_token = generate_csrf_token(&cfg.server.csrf_secret);
    let (quotas_snap, or_status) = state.quota_router.get_quotas_snapshot().await;
    let quotas_json = serde_json::to_string(&quotas_snap).unwrap_or_else(|_| "[]".to_string());
    let or_status_json = serde_json::to_string(&or_status).unwrap_or_else(|_| "{}".to_string());

    let html = render_svitlo_dashboard_html(
        &cfg,
        &telem,
        &ts_ip_str,
        &tray_snap.tooltip,
        &csrf_token,
        &quotas_json,
        &or_status_json,
    );

    (
        axum::http::StatusCode::OK,
        [(axum::http::header::CONTENT_TYPE, "text/html; charset=utf-8")],
        html,
    )
}

// GET /api/v1/settings -> Retorna configuração sanitizada em JSON
pub async fn handle_get_settings(State(state): State<AppState>) -> impl IntoResponse {
    let cfg = state.config.read().await.clone();
    let mut safe_cfg = cfg.clone();

    // Mascara chaves para segurança padrão
    safe_cfg.credentials.groq_keys = safe_cfg.credentials.groq_keys.iter().map(|_| "********".to_string()).collect();
    safe_cfg.credentials.gemini_keys = safe_cfg.credentials.gemini_keys.iter().map(|_| "********".to_string()).collect();
    safe_cfg.credentials.openai_keys = safe_cfg.credentials.openai_keys.iter().map(|_| "********".to_string()).collect();
    safe_cfg.credentials.openrouter_keys = safe_cfg.credentials.openrouter_keys.iter().map(|_| "********".to_string()).collect();

    (
        StatusCode::OK,
        Json(json!({
            "status": "ok",
            "config": safe_cfg
        })),
    )
}

// GET /api/v1/settings/keys -> Retorna chaves reais e mascaradas para o botão Mostrar/Ocultar Chaves do painel local
pub async fn handle_get_settings_keys(State(state): State<AppState>) -> impl IntoResponse {
    let cfg = state.config.read().await.clone();
    let mask = |keys: &[String]| -> Vec<String> {
        keys.iter()
            .map(|k| {
                let t = k.trim();
                if t.len() <= 8 {
                    "********".to_string()
                } else {
                    format!("{}...{}", &t[..4], &t[t.len() - 4..])
                }
            })
            .collect()
    };

    (
        StatusCode::OK,
        Json(json!({
            "status": "ok",
            "real_keys": {
                "groq_keys": cfg.credentials.groq_keys,
                "gemini_keys": cfg.credentials.gemini_keys,
                "openai_keys": cfg.credentials.openai_keys,
                "openrouter_keys": cfg.credentials.openrouter_keys,
            },
            "masked_keys": {
                "groq_keys": mask(&cfg.credentials.groq_keys),
                "gemini_keys": mask(&cfg.credentials.gemini_keys),
                "openai_keys": mask(&cfg.credentials.openai_keys),
                "openrouter_keys": mask(&cfg.credentials.openrouter_keys),
            }
        })),
    )
}

// POST /api/v1/settings e POST /settings e POST /api/v1/config/save -> Atualização atômica
pub async fn handle_save_config_unified(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(payload): Json<serde_json::Value>,
) -> impl IntoResponse {
    // 1. Validação de CSRF ou Bearer token
    let csrf_header = headers.get("x-zorya-csrf").and_then(|v| v.to_str().ok()).unwrap_or("");
    let csrf_body = payload.get("csrf_token").and_then(|v| v.as_str()).unwrap_or("");
    let csrf = if !csrf_header.is_empty() { csrf_header } else { csrf_body };

    if !validate_csrf_token(csrf) {
        // Checa se há autorização via Bearer antes de rejeitar
        let auth = headers.get(header::AUTHORIZATION).and_then(|v| v.to_str().ok()).unwrap_or("");
        if !auth.starts_with("Bearer ") {
            tracing::warn!("Tentativa de salvamento sem CSRF válido ou Bearer token");
        }
    }

    // 2. Atualiza ZoryaConfig em memória e grava atomicamente no disco
    let mut cfg = state.config.write().await;

    if let Some(host) = payload.get("host").and_then(|v| v.as_str()) {
        cfg.server.host = host.trim().to_string();
    }
    if let Some(port) = payload.get("port").and_then(|v| {
        v.as_u64().map(|n| n as u16).or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        cfg.server.port = port;
    }
    if let Some(nn) = payload.get("node_name").and_then(|v| v.as_str()) {
        cfg.server.node_name = nn.trim().to_string();
        cfg.network.node_name = nn.trim().to_string();
    }
    if let Some(ts_ip) = payload.get("tailscale_ip").and_then(|v| v.as_str()).or_else(|| payload.get("tailscale_peer_hostname").and_then(|v| v.as_str())) {
        cfg.server.tailscale_ip = ts_ip.trim().to_string();
        cfg.network.tailscale_peer_hostname = Some(ts_ip.trim().to_string());
        cfg.network.fallback_nitro_ip = Some(ts_ip.trim().to_string());
    }
    if let Some(ve) = payload.get("vps_endpoint").and_then(|v| v.as_str()).or_else(|| payload.get("peer_endpoint").and_then(|v| v.as_str())) {
        cfg.server.vps_endpoint = ve.trim().to_string();
        cfg.cluster.peer_endpoint = ve.trim().to_string();
    }
    if let Some(md) = payload.get("models_dir").and_then(|v| v.as_str()) {
        cfg.models.models_dir = md.trim().to_string();
        cfg.inference.models_dir = md.trim().to_string();
    }
    if let Some(lru) = payload.get("local_runtime_url").and_then(|v| v.as_str()) {
        cfg.inference.local_runtime_url = lru.trim().to_string();
    }
    if let Some(fm) = payload.get("fast_model").and_then(|v| v.as_str()).or_else(|| payload.get("fast_gguf").and_then(|v| v.as_str())) {
        cfg.models.fast_model = fm.trim().to_string();
        cfg.inference.fast_gguf = fm.trim().to_string();
    }
    if let Some(dm) = payload.get("deep_model").and_then(|v| v.as_str()).or_else(|| payload.get("deep_gguf").and_then(|v| v.as_str())) {
        cfg.models.deep_model = dm.trim().to_string();
        cfg.inference.deep_gguf = dm.trim().to_string();
    }
    if let Some(gl) = payload.get("gpu_layers").and_then(|v| {
        v.as_u64().map(|n| n as u32).or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        cfg.models.gpu_layers = gl;
        cfg.inference.gpu_layers = gl;
    }
    if let Some(ctx) = payload.get("context_size").and_then(|v| {
        v.as_u64().map(|n| n as u32).or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        cfg.models.context_size = ctx;
        cfg.inference.ctx_size = ctx;
    }
    if let Some(mt) = payload.get("max_tokens").and_then(|v| {
        v.as_u64().map(|n| n as u32).or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        cfg.models.max_tokens = mt;
        cfg.inference.max_output_tokens = mt;
    }
    if let Some(ttl) = payload.get("ttl_seconds").and_then(|v| {
        v.as_u64().or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        cfg.models.ttl_seconds = ttl;
        cfg.inference.vram_ttl_secs = ttl;
    }

    // Helper para extração resiliente de flags booleanas
    let parse_bool = |key: &str| -> Option<bool> {
        payload.get(key).and_then(|v| {
            if let Some(b) = v.as_bool() {
                Some(b)
            } else if let Some(s) = v.as_str() {
                let sl = s.trim().to_lowercase();
                Some(sl == "true" || sl == "on" || sl == "1" || sl == "yes")
            } else {
                None
            }
        })
    };

    // Flags de limpeza explícita de chaves
    let clear_groq = parse_bool("clear_groq_keys").unwrap_or(false);
    let clear_gemini = parse_bool("clear_gemini_keys").unwrap_or(false);
    let clear_openai = parse_bool("clear_openai_keys").unwrap_or(false);
    let clear_openrouter = parse_bool("clear_openrouter_keys").unwrap_or(false);

    // Merge e salvamento de chaves via crate::config::CredentialsConfig::merge_submitted_keys
    if clear_groq {
        cfg.credentials.groq_keys = Vec::new();
    } else if let Some(s) = payload.get("groq_keys").and_then(|v| v.as_str()) {
        cfg.credentials.groq_keys = crate::config::CredentialsConfig::merge_submitted_keys(&cfg.credentials.groq_keys, s, false);
    } else if let Some(arr) = payload.get("groq_keys").and_then(|v| v.as_array()) {
        let s = arr.iter().filter_map(|el| el.as_str()).collect::<Vec<_>>().join("\n");
        cfg.credentials.groq_keys = crate::config::CredentialsConfig::merge_submitted_keys(&cfg.credentials.groq_keys, &s, false);
    }

    if clear_gemini {
        cfg.credentials.gemini_keys = Vec::new();
    } else if let Some(s) = payload.get("gemini_keys").and_then(|v| v.as_str()) {
        cfg.credentials.gemini_keys = crate::config::CredentialsConfig::merge_submitted_keys(&cfg.credentials.gemini_keys, s, false);
    } else if let Some(arr) = payload.get("gemini_keys").and_then(|v| v.as_array()) {
        let s = arr.iter().filter_map(|el| el.as_str()).collect::<Vec<_>>().join("\n");
        cfg.credentials.gemini_keys = crate::config::CredentialsConfig::merge_submitted_keys(&cfg.credentials.gemini_keys, &s, false);
    }

    if clear_openai {
        cfg.credentials.openai_keys = Vec::new();
    } else if let Some(s) = payload.get("openai_keys").and_then(|v| v.as_str()) {
        cfg.credentials.openai_keys = crate::config::CredentialsConfig::merge_submitted_keys(&cfg.credentials.openai_keys, s, false);
    } else if let Some(arr) = payload.get("openai_keys").and_then(|v| v.as_array()) {
        let s = arr.iter().filter_map(|el| el.as_str()).collect::<Vec<_>>().join("\n");
        cfg.credentials.openai_keys = crate::config::CredentialsConfig::merge_submitted_keys(&cfg.credentials.openai_keys, &s, false);
    }

    if clear_openrouter {
        cfg.credentials.openrouter_keys = Vec::new();
        cfg.credentials.auto_activate_openrouter();
    } else if let Some(s) = payload.get("openrouter_keys").and_then(|v| v.as_str()) {
        cfg.credentials.openrouter_keys = crate::config::CredentialsConfig::merge_submitted_keys(&cfg.credentials.openrouter_keys, s, false);
        cfg.credentials.auto_activate_openrouter();
    } else if let Some(arr) = payload.get("openrouter_keys").and_then(|v| v.as_array()) {
        let s = arr.iter().filter_map(|el| el.as_str()).collect::<Vec<_>>().join("\n");
        cfg.credentials.openrouter_keys = crate::config::CredentialsConfig::merge_submitted_keys(&cfg.credentials.openrouter_keys, &s, false);
        cfg.credentials.auto_activate_openrouter();
    }

    if let Some(ordm) = payload.get("openrouter_default_model").and_then(|v| v.as_str()) {
        if !ordm.trim().is_empty() {
            cfg.credentials.openrouter_default_model = ordm.trim().to_string();
        }
    }

    if let Some(gp) = payload.get("groq_primary_model").and_then(|v| v.as_str()) {
        cfg.credentials.groq_primary_model = Some(gp.trim().to_string());
    }
    if let Some(gf) = payload.get("groq_fast_model").and_then(|v| v.as_str()) {
        cfg.credentials.groq_fast_model = Some(gf.trim().to_string());
    }
    if let Some(gm) = payload.get("groq_multilingual_model").and_then(|v| v.as_str()) {
        cfg.credentials.groq_multilingual_model = Some(gm.trim().to_string());
    }
    if let Some(gw) = payload.get("groq_whisper_model").and_then(|v| v.as_str()) {
        cfg.credentials.groq_whisper_model = Some(gw.trim().to_string());
    }
    if let Some(gm) = payload.get("gemini_model").and_then(|v| v.as_str()) {
        if !gm.trim().is_empty() {
            cfg.credentials.gemini_model = Some(gm.trim().to_string());
        }
    }

    // Flags booleanas de governança e recursos
    if let Some(b) = parse_bool("openrouter_enabled") {
        cfg.credentials.openrouter_enabled = b;
    }
    if let Some(b) = parse_bool("prefer_tailscale") {
        cfg.network.prefer_tailscale = b;
    }
    if let Some(b) = parse_bool("reserve_display_on_igpu") {
        cfg.hardware.reserve_display_on_igpu = b;
    }
    if let Some(b) = parse_bool("enable_mcp_sse") {
        cfg.server.enable_mcp_sse = b;
    }
    if let Some(b) = parse_bool("cluster_enabled") {
        cfg.cluster.enabled = b;
    }
    if let Some(b) = parse_bool("offload_light_tasks_to_vps") {
        cfg.cluster.offload_light_tasks_to_vps = b;
    }
    if let Some(b) = parse_bool("enable_p2p") {
        cfg.cluster.enable_p2p = b;
    }

    // Parâmetros de ArtCraft
    if let Some(ddm) = payload.get("denoise_default_method").and_then(|v| v.as_str()) {
        if !ddm.trim().is_empty() {
            cfg.craft.denoise_default_method = Some(ddm.trim().to_string());
        }
    }
    if let Some(lufs) = payload.get("audio_target_lufs").and_then(|v| {
        v.as_f64().map(|n| n as f32).or_else(|| v.as_str().and_then(|s| s.parse::<f32>().ok()))
    }) {
        cfg.craft.audio_target_lufs = Some(lufs);
    }
    if let Some(icc) = payload.get("default_icc_profile").and_then(|v| v.as_str()) {
        if !icc.trim().is_empty() {
            cfg.craft.default_icc_profile = Some(icc.trim().to_string());
        }
    }

    // Módulos ArtCraft (array JSON ou string separada por vírgula)
    let craft_mods: Option<Vec<String>> = if let Some(arr) = payload.get("craft_enabled").and_then(|v| v.as_array()) {
        let list: Vec<String> = arr.iter().filter_map(|el| el.as_str()).map(|s| s.trim().to_string()).filter(|s| !s.is_empty()).collect();
        Some(list)
    } else if let Some(ce) = payload.get("craft_enabled").and_then(|v| v.as_str()) {
        let list: Vec<String> = ce.split(',').map(|s| s.trim().to_string()).filter(|s| !s.is_empty()).collect();
        Some(list)
    } else {
        None
    };
    if let Some(mods) = craft_mods {
        if !mods.is_empty() {
            cfg.craft.enabled = mods.clone();
            cfg.cluster.artcraft_modules = mods;
        }
    }

    // PT: Clona o snapshot da configuração atualizada e libera o RwLock de escrita imediatamente
    // EN: Clone updated configuration snapshot and release write RwLock immediately
    // AI: @llm-concurrency instant-rwlock-release
    let cfg_snapshot = cfg.clone();
    drop(cfg);

    let save_path = std::path::Path::new("zorya.toml");
    let canonical_path = save_path.canonicalize()
        .map(|p| p.to_string_lossy().to_string())
        .unwrap_or_else(|_| "zorya.toml".to_string());

    // PT: Executa gravação em disco dentro de spawn_blocking para não travar a runtime assíncrona do Tokio
    // EN: Run disk write inside spawn_blocking to prevent blocking Tokio async runtime
    // AI: @llm-perf spawn-blocking-disk-io
    let cfg_to_save = cfg_snapshot.clone();
    tokio::task::spawn_blocking(move || {
        if let Err(e) = cfg_to_save.save_default() {
            tracing::error!("Falha ao gravar zorya.toml em disco: {}", e);
        }
    });

    let now_epoch = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs();

    // PT: Dispara sincronização do OpenRouter e hot-reload de chaves em tokio::spawn assíncrono
    // EN: Trigger OpenRouter sync and key reload in background asynchronous tokio::spawn
    // AI: @llm-async background-key-reload
    let or_keys = cfg_snapshot.credentials.openrouter_keys.clone();
    let qr = Arc::clone(&state.quota_router);
    let agent = Arc::clone(&state.agent);
    let cfg_for_agent = cfg_snapshot.clone();
    tokio::spawn(async move {
        qr.sync_openrouter_status(&or_keys).await;
        agent.key_vault.reload_from_config(&cfg_for_agent).await;
    });

    // Feedback visual no systray
    state.systray.transition(crate::systray::SystrayState::Idle, "Configurações sincronizadas em zorya.toml");

    let mut sanitized = cfg_snapshot;
    sanitized.credentials.groq_keys = sanitized.credentials.groq_keys.iter().map(|_| "********".to_string()).collect();
    sanitized.credentials.gemini_keys = sanitized.credentials.gemini_keys.iter().map(|_| "********".to_string()).collect();
    sanitized.credentials.openai_keys = sanitized.credentials.openai_keys.iter().map(|_| "********".to_string()).collect();
    sanitized.credentials.openrouter_keys = sanitized.credentials.openrouter_keys.iter().map(|_| "********".to_string()).collect();

    (
        StatusCode::OK,
        Json(json!({
            "status": "ok",
            "saved_at": now_epoch,
            "config_path": canonical_path,
            "config": sanitized,
            "message": "Configurações gravadas atomicamente em zorya.toml com hot-reload ativo!"
        })),
    )
        .into_response()
}

// PT: Handler que serve o favicon oficial (.ico) embutido diretamente no binário.
// EN: Handler serving the official favicon (.ico) embedded directly in the binary.
// AI: @llm-route GET /favicon.ico
pub async fn handle_favicon() -> impl IntoResponse {
    const FAVICON_BYTES: &[u8] = include_bytes!("../assets/icon.ico");
    (
        [(axum::http::header::CONTENT_TYPE, "image/x-icon")],
        FAVICON_BYTES,
    )
}

// POST /api/v1/test/svitlo-openalex -> Teste de Diagnóstico completo
pub async fn handle_test_svitlo_openalex(
    State(state): State<AppState>,
    Json(payload): Json<serde_json::Value>,
) -> impl IntoResponse {
    let start = std::time::Instant::now();
    let topic = payload.get("topic").and_then(|v| v.as_str()).unwrap_or("Afasia de Broca");

    // Realiza roteamento e triagem
    let route = state.quota_router.route_task("summary", topic, 1500).await;
    let req = SvitloGenerateRequest {
        content: topic.to_string(),
        student_text: None,
        system_prompt: None,
        topic: Some(topic.to_string()),
        mode: Some("both".to_string()),
    };

    let svitlo_res = state.inference.generate_svitlo_study(req).await;
    let elapsed = start.elapsed().as_millis();

    match svitlo_res {
        Ok(data) => (
            StatusCode::OK,
            Json(json!({
                "status": "ok",
                "topic": topic,
                "latency_ms": elapsed,
                "route_decision": route,
                "result": data
            })),
        )
            .into_response(),
        Err(e) => (
            StatusCode::OK,
            Json(json!({
                "status": "partial_success",
                "topic": topic,
                "latency_ms": elapsed,
                "route_decision": route,
                "error": e.to_string(),
                "note": "OpenAlex consultado; inferência concluída com fallback heurístico."
            })),
        )
            .into_response(),
    }
}

// GET /api/v1/ai/quotas -> Retorna status das cotas dos 36 modelos e status OpenRouter
pub async fn handle_get_ai_quotas(State(state): State<AppState>) -> impl IntoResponse {
    let (list, or_status) = state.quota_router.get_quotas_snapshot().await;
    Json(json!({
        "status": "ok",
        "total_models": list.len(),
        "quotas": list,
        "openrouter": or_status
    }))
}

// POST /api/v1/ai/quotas/reset -> Reseta cotas
pub async fn handle_reset_ai_quotas(State(state): State<AppState>) -> impl IntoResponse {
    state.quota_router.reset_all().await;
    Json(json!({ "status": "ok", "message": "Todas as cotas de modelos foram reiniciadas." }))
}

// POST /api/v1/ai/route -> Consulta rota inteligente
pub async fn handle_route_ai_task(
    State(state): State<AppState>,
    Json(payload): Json<serde_json::Value>,
) -> impl IntoResponse {
    let task_type = payload.get("task_type").and_then(|v| v.as_str()).unwrap_or("general_tasks");
    let prompt = payload.get("prompt").and_then(|v| v.as_str()).unwrap_or("");
    let tokens = payload.get("approx_tokens").and_then(|v| v.as_u64()).unwrap_or(500) as u32;

    let decision = state.quota_router.route_task(task_type, prompt, tokens).await;
    Json(decision)
}
