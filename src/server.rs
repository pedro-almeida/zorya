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

// Middleware para suporte ao W3C Private Network Access (PNA) exigido pelo Chrome/Edge
// Injeta o cabeçalho Access-Control-Allow-Private-Network: true em preflights OPTIONS e respostas normais
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

// Create unified Axum router with all mandatory endpoints / Cria roteador Axum unificado com todos os endpoints obrigatórios
pub fn create_router(state: AppState) -> Router {
    let cors = CorsLayer::new()
        .allow_origin(tower_http::cors::AllowOrigin::predicate(|origin: &header::HeaderValue, _parts| {
            if let Ok(s) = origin.to_str() {
                s.ends_with("espectroipe.com.br")
                    || s.ends_with("tenorio.fot.br")
                    || s.contains("localhost")
                    || s.contains("127.0.0.1")
                    || s.starts_with("https://")
                    || s.starts_with("http://")
            } else {
                false
            }
        }))
        .allow_methods([
            Method::GET,
            Method::POST,
            Method::PUT,
            Method::DELETE,
            Method::OPTIONS,
            Method::PATCH,
            Method::HEAD,
        ])
        .allow_headers(tower_http::cors::AllowHeaders::mirror_request())
        .allow_credentials(true)
        .expose_headers([header::HeaderName::from_static("access-control-allow-private-network")]);

    Router::new()
        // Settings Dashboard & Web UI
        .route("/", get(handle_settings_dashboard))
        .route("/settings", get(handle_settings_dashboard))
        .route("/api/v1/config/save", post(handle_save_config))
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
        .layer(axum::middleware::from_fn(private_network_access_middleware))
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

// Handler do Painel de Configurações Web HTML/CSS embutido
pub async fn handle_settings_dashboard(State(state): State<AppState>) -> impl IntoResponse {
    let cfg = crate::resolve_config_path();
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
        .unwrap_or_else(|| "100.106.120.35".to_string());

    let groq_keys_str = cfg.credentials.groq_keys.join("\n");
    let gemini_keys_str = cfg.credentials.gemini_keys.join("\n");
    let openai_keys_str = cfg.credentials.openai_keys.join("\n");
    let craft_enabled_str = cfg.craft.enabled.join(", ");

    let html = format!(r#"<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><title>Usina Zorya v3.0 — Painel de Controle Completo</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root {{ --bg: #12100E; --surface: #1E1A16; --border: #3D3226; --gold: #C9A84C; --text: #FAF7F2; --muted: #A89887; --green: #10B981; }}
  body {{ font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 24px; }}
  .container {{ max-width: 980px; margin: 0 auto; }}
  .header {{ display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; border-bottom: 2px solid var(--gold); padding-bottom: 16px; margin-bottom: 24px; gap: 12px; }}
  .title {{ font-size: 22px; font-weight: bold; color: var(--gold); }}
  .card {{ background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 20px; }}
  .grid {{ display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }}
  label {{ display: block; font-size: 11px; color: var(--muted); margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }}
  input, select, textarea {{ width: 100%; box-sizing: border-box; background: #0E0C0A; border: 1px solid var(--border); color: #fff; padding: 10px; border-radius: 6px; font-size: 13.5px; font-family: inherit; }}
  textarea {{ font-family: monospace; min-height: 76px; resize: vertical; }}
  .btn {{ background: var(--gold); color: #12100E; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 14px; transition: 0.2s; }}
  .btn:hover {{ background: #E5C365; }}
  .btn-secondary {{ background: #2D261E; color: var(--gold); border: 1px solid var(--gold); padding: 10px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px; }}
  .status-badge {{ background: #1B382B; color: #4ADE80; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-block; }}
  .pill {{ background: #2A231C; border: 1px solid var(--border); padding: 8px 12px; border-radius: 8px; font-size: 13px; }}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div>
      <div class="title">⚡ USINA ZORYA v3.0 — PAINEL DE CONTROLE</div>
      <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">Coprocessador Neural Soberano • Bandeja: {tray_tooltip}</div>
    </div>
    <div>
      <span class="status-badge">● ONLINE • Tailscale: {ts_ip}:{port}</span>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0; color:var(--gold); font-size:16px;">📊 Telemetria de Hardware & Acesso Mobile Tailscale</h3>
    <div class="grid">
      <div class="pill"><strong>GPU:</strong> {gpu_name}</div>
      <div class="pill"><strong>VRAM:</strong> {vram_used} MB / {vram_total} MB</div>
      <div class="pill"><strong>CPU:</strong> {cpu_brand}</div>
      <div class="pill"><strong>RAM:</strong> {ram_used} MB / {ram_total} MB</div>
      <div class="pill"><strong>Link Local (PC):</strong> <a href="http://127.0.0.1:{port}/settings" style="color:var(--gold);">http://127.0.0.1:{port}</a></div>
      <div class="pill"><strong>Link Celular (Tailscale):</strong> <a href="http://{ts_ip}:{port}/settings" style="color:var(--gold);">http://{ts_ip}:{port}</a></div>
    </div>
  </div>

  <form id="configForm" onsubmit="saveConfig(event)">
    <div class="card">
      <h3 style="margin-top:0; color:var(--gold); font-size:16px;">🔑 Cofre de Chaves de IA (Agon / Groq / Gemini / OpenAI)</h3>
      <p style="font-size:12px; color:var(--muted); margin-top:0;">Insira uma chave de API por linha. O Zorya rotaciona automaticamente entre as chaves válidas quando o modelo local GGUF não está ativo.</p>
      <div class="grid">
        <div>
          <label>Chaves Groq API (1 por linha)</label>
          <textarea name="groq_keys" placeholder="gsk_...">{groq_keys}</textarea>
        </div>
        <div>
          <label>Chaves Google Gemini API (1 por linha)</label>
          <textarea name="gemini_keys" placeholder="AIzaSy...">{gemini_keys}</textarea>
        </div>
        <div>
          <label>Chaves OpenAI API (Opcional)</label>
          <textarea name="openai_keys" placeholder="sk-...">{openai_keys}</textarea>
        </div>
        <div>
          <label>Modelo Groq Principal</label>
          <input name="groq_primary_model" value="{groq_primary}">
          <label style="margin-top:8px;">Modelo Groq Rápido</label>
          <input name="groq_fast_model" value="{groq_fast}">
          <label style="margin-top:8px;">Modelo Groq Multilíngue</label>
          <input name="groq_multilingual_model" value="{groq_multi}">
        </div>
      </div>
    </div>

    <div class="card">
      <h3 style="margin-top:0; color:var(--gold); font-size:16px;">🧠 Modelos Locais GGUF & Inferência</h3>
      <div class="grid">
        <div>
          <label>Diretório de Modelos</label>
          <input name="models_dir" value="{models_dir}">
        </div>
        <div>
          <label>URL do Runtime Local (Ollama / llama-server)</label>
          <input name="ollama_url" value="{ollama_url}">
        </div>
        <div>
          <label>Modelo Rápido (Fast GGUF)</label>
          <input name="fast_model" value="{fast_model}">
        </div>
        <div>
          <label>Modelo Profundo (Deep GGUF)</label>
          <input name="deep_model" value="{deep_model}">
        </div>
        <div>
          <label>Camadas na GPU (GPU Layers)</label>
          <input type="number" name="gpu_layers" value="{gpu_layers}">
        </div>
        <div>
          <label>Contexto (Tokens)</label>
          <input type="number" name="context_size" value="{ctx_size}">
        </div>
        <div>
          <label>Máximo de Tokens de Saída</label>
          <input type="number" name="max_tokens" value="{max_tokens}">
        </div>
        <div>
          <label>TTL do Modelo em VRAM (Segundos)</label>
          <input type="number" name="ttl_seconds" value="{ttl_sec}">
        </div>
      </div>
    </div>

    <div class="card">
      <h3 style="margin-top:0; color:var(--gold); font-size:16px;">🌐 Rede Tailscale, Acesso Mobile & Cluster VPS</h3>
      <div class="grid">
        <div>
          <label>Host de Escuta (0.0.0.0 permite Tailscale/Celular)</label>
          <input name="host" value="{host}">
        </div>
        <div>
          <label>Porta do Servidor</label>
          <input type="number" name="port" value="{port}">
        </div>
        <div>
          <label>IP Tailscale deste Nó (Nitro 5)</label>
          <input name="tailscale_peer_hostname" value="{ts_ip}">
        </div>
        <div>
          <label>Nome do Nó</label>
          <input name="node_name" value="{node_name}">
        </div>
        <div>
          <label>Endpoint da VPS (Cluster Peer)</label>
          <input name="peer_endpoint" value="{peer_endpoint}">
        </div>
        <div>
          <label>Módulos ArtCraft Habilitados (separados por vírgula)</label>
          <input name="craft_enabled" value="{craft_enabled}">
        </div>
      </div>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
      <button type="button" class="btn-secondary" onclick="testSvitloAi()">🧪 Testar Geração Svitlo + OpenAlex Agora</button>
      <button type="submit" class="btn">💾 Salvar Todas as Configurações no zorya.toml</button>
    </div>
  </form>

  <div id="testResultBox" class="card" style="display:none; margin-top:20px;">
    <h3 style="margin-top:0; color:var(--gold); font-size:15px;">Resultado do Teste de Inferência + OpenAlex</h3>
    <pre id="testResultPre" style="white-space:pre-wrap; font-size:12px; color:#E5E7EB; max-height:320px; overflow:auto;"></pre>
  </div>
</div>
<script>
async function saveConfig(e) {{
  e.preventDefault();
  const form = document.getElementById('configForm');
  const data = Object.fromEntries(new FormData(form).entries());
  const res = await fetch('/api/v1/config/save', {{
    method: 'POST',
    headers: {{ 'Content-Type': 'application/json' }},
    body: JSON.stringify(data)
  }});
  const out = await res.json();
  if (res.ok) alert('✅ ' + (out.message || 'Configurações salvas com sucesso!'));
  else alert('❌ Erro: ' + (out.message || 'Falha ao salvar.'));
}}

async function testSvitloAi() {{
  const box = document.getElementById('testResultBox');
  const pre = document.getElementById('testResultPre');
  box.style.display = 'block';
  pre.textContent = '⏳ Consultando OpenAlex, fazendo triagem e gerando síntese com IA...';
  try {{
    const res = await fetch('/api/v1/svitlo/generate', {{
      method: 'POST',
      headers: {{ 'Content-Type': 'application/json' }},
      body: JSON.stringify({{ content: 'Afasia de Broca', topic: 'Afasia de Broca', mode: 'both' }})
    }});
    const data = await res.json();
    pre.textContent = JSON.stringify(data, null, 2);
  }} catch (err) {{
    pre.textContent = 'Erro no teste: ' + err.message;
  }}
}}
</script>
</body>
</html>"#,
        port = cfg.server.port,
        host = cfg.server.host,
        ts_ip = ts_ip_str,
        node_name = cfg.network.node_name,
        tray_tooltip = tray_snap.tooltip,
        gpu_name = telem.gpu_model,
        vram_used = telem.gpu_vram_total_mb.saturating_sub(telem.gpu_vram_free_mb),
        vram_total = telem.gpu_vram_total_mb,
        cpu_brand = telem.cpu_brand,
        ram_used = telem.ram_used_mb,
        ram_total = telem.ram_total_mb,
        models_dir = cfg.models.models_dir,
        ollama_url = cfg.inference.ollama_url,
        fast_model = cfg.models.fast_model,
        deep_model = cfg.models.deep_model,
        gpu_layers = cfg.models.gpu_layers,
        ctx_size = cfg.models.context_size,
        max_tokens = cfg.models.max_tokens,
        ttl_sec = cfg.models.ttl_seconds,
        groq_keys = groq_keys_str,
        gemini_keys = gemini_keys_str,
        openai_keys = openai_keys_str,
        groq_primary = cfg.credentials.groq_primary_model.as_deref().unwrap_or("openai/gpt-oss-120b"),
        groq_fast = cfg.credentials.groq_fast_model.as_deref().unwrap_or("openai/gpt-oss-20b"),
        groq_multi = cfg.credentials.groq_multilingual_model.as_deref().unwrap_or("qwen/qwen3.8-27b"),
        peer_endpoint = cfg.cluster.peer_endpoint,
        craft_enabled = craft_enabled_str,
    );

    (
        axum::http::StatusCode::OK,
        [(axum::http::header::CONTENT_TYPE, "text/html; charset=utf-8")],
        html,
    )
}

pub async fn handle_save_config(
    State(_state): State<AppState>,
    Json(payload): Json<serde_json::Value>,
) -> impl IntoResponse {
    let mut current_cfg = crate::resolve_config_path();

    if let Some(md) = payload.get("models_dir").and_then(|v| v.as_str()) {
        current_cfg.models.models_dir = md.trim().to_string();
    }
    if let Some(ou) = payload.get("ollama_url").and_then(|v| v.as_str()) {
        current_cfg.inference.ollama_url = ou.trim().to_string();
    }
    if let Some(fm) = payload.get("fast_model").and_then(|v| v.as_str()) {
        current_cfg.models.fast_model = fm.trim().to_string();
    }
    if let Some(dm) = payload.get("deep_model").and_then(|v| v.as_str()) {
        current_cfg.models.deep_model = dm.trim().to_string();
    }
    if let Some(gl) = payload.get("gpu_layers").and_then(|v| {
        v.as_u64().map(|n| n as u32).or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        current_cfg.models.gpu_layers = gl;
    }
    if let Some(ctx) = payload.get("context_size").and_then(|v| {
        v.as_u64().map(|n| n as u32).or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        current_cfg.models.context_size = ctx;
    }
    if let Some(mt) = payload.get("max_tokens").and_then(|v| {
        v.as_u64().map(|n| n as u32).or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        current_cfg.models.max_tokens = mt;
    }
    if let Some(ttl) = payload.get("ttl_seconds").and_then(|v| {
        v.as_u64().or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        current_cfg.models.ttl_seconds = ttl;
    }
    if let Some(host) = payload.get("host").and_then(|v| v.as_str()) {
        current_cfg.server.host = host.trim().to_string();
    }
    if let Some(port) = payload.get("port").and_then(|v| {
        v.as_u64().map(|n| n as u16).or_else(|| v.as_str().and_then(|s| s.parse().ok()))
    }) {
        current_cfg.server.port = port;
    }
    if let Some(ts_peer) = payload.get("tailscale_peer_hostname").and_then(|v| v.as_str()) {
        current_cfg.network.tailscale_peer_hostname = Some(ts_peer.trim().to_string());
        current_cfg.network.fallback_nitro_ip = Some(ts_peer.trim().to_string());
    }
    if let Some(node_name) = payload.get("node_name").and_then(|v| v.as_str()) {
        current_cfg.network.node_name = node_name.trim().to_string();
    }
    if let Some(pe) = payload.get("peer_endpoint").and_then(|v| v.as_str()) {
        current_cfg.cluster.peer_endpoint = pe.trim().to_string();
    }
    if let Some(gk) = payload.get("groq_keys").and_then(|v| v.as_str()) {
        current_cfg.credentials.groq_keys = gk
            .lines()
            .map(|s| s.trim().to_string())
            .filter(|s| !s.is_empty())
            .collect();
    }
    if let Some(mk) = payload.get("gemini_keys").and_then(|v| v.as_str()) {
        current_cfg.credentials.gemini_keys = mk
            .lines()
            .map(|s| s.trim().to_string())
            .filter(|s| !s.is_empty())
            .collect();
    }
    if let Some(ok) = payload.get("openai_keys").and_then(|v| v.as_str()) {
        current_cfg.credentials.openai_keys = ok
            .lines()
            .map(|s| s.trim().to_string())
            .filter(|s| !s.is_empty())
            .collect();
    }
    if let Some(gp) = payload.get("groq_primary_model").and_then(|v| v.as_str()) {
        current_cfg.credentials.groq_primary_model = Some(gp.trim().to_string());
    }
    if let Some(gf) = payload.get("groq_fast_model").and_then(|v| v.as_str()) {
        current_cfg.credentials.groq_fast_model = Some(gf.trim().to_string());
    }
    if let Some(gm) = payload.get("groq_multilingual_model").and_then(|v| v.as_str()) {
        current_cfg.credentials.groq_multilingual_model = Some(gm.trim().to_string());
    }
    if let Some(ce) = payload.get("craft_enabled").and_then(|v| v.as_str()) {
        current_cfg.craft.enabled = ce
            .split(',')
            .map(|s| s.trim().to_string())
            .filter(|s| !s.is_empty())
            .collect();
    }

    match current_cfg.save_default() {
        Ok(_) => (
            StatusCode::OK,
            Json(json!({
                "status": "success",
                "message": "Configurações salvas no zorya.toml com sucesso!"
            })),
        )
            .into_response(),
        Err(e) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(json!({
                "status": "error",
                "message": format!("Erro ao salvar zorya.toml: {}", e)
            })),
        )
            .into_response(),
    }
}

