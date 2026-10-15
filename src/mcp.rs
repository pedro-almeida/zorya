// Real Model Context Protocol (MCP) Server: JSON-RPC 2.0 over Server-Sent Events (SSE) / Servidor MCP Real: JSON-RPC 2.0 sobre Server-Sent Events (SSE)
// Exposed tools / Ferramentas expostas:
// - zorya_raw_decode
// - zorya_vector_diagram
// - zorya_audio_master
// - zorya_system_info
// - zorya_chat

use std::convert::Infallible;
use std::time::Duration;
use axum::{
    extract::State,
    response::sse::{Event, KeepAlive, Sse},
    Json,
};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use futures_util::stream::Stream;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tokio_stream::wrappers::BroadcastStream;
use tokio_stream::StreamExt;

use crate::craft::{
    decode_raw_with_lightcraft, master_audio_ebu_r128, render_vector_diagram_svg, RawOutputFormat,
    VectorDiagramSpec,
};
use crate::ollama::run_internal_chat;
use crate::server::AppState;

// JSON-RPC 2.0 incoming request / Requisição de entrada JSON-RPC 2.0
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct JsonRpcRequest {
    pub jsonrpc: Option<String>,
    pub id: Option<Value>,
    pub method: String,
    pub params: Option<Value>,
}

// JSON-RPC 2.0 response envelope / Envelope de resposta JSON-RPC 2.0
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct JsonRpcResponse {
    pub jsonrpc: String,
    pub id: Value,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub result: Option<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<Value>,
}

// GET /mcp/sse — Establish persistent SSE transport for MCP clients / Estabelece transporte SSE persistente para clientes MCP
pub async fn mcp_sse_handler(
    State(state): State<AppState>,
) -> Sse<impl Stream<Item = Result<Event, Infallible>>> {
    let rx = state.mcp_tx.subscribe();

    // Send initial MCP endpoint event per MCP HTTP+SSE specification / Envia evento inicial de endpoint conforme especificação MCP HTTP+SSE
    let initial_event = futures_util::stream::once(async move {
        Ok::<Event, Infallible>(
            Event::default()
                .event("endpoint")
                .data("/mcp/message"),
        )
    });

    let broadcast_events = BroadcastStream::new(rx).filter_map(|msg| match msg {
        Ok(json_str) => Some(Ok::<Event, Infallible>(
            Event::default().event("message").data(json_str),
        )),
        Err(_) => None,
    });

    let combined = initial_event.chain(broadcast_events);

    Sse::new(combined).keep_alive(
        KeepAlive::new()
            .interval(Duration::from_secs(15))
            .text("keep-alive"),
    )
}

// POST /mcp/message and POST /mcp — Execute JSON-RPC 2.0 call and broadcast over SSE / Executa chamada JSON-RPC 2.0 e transmite no canal SSE
pub async fn mcp_jsonrpc_handler(
    State(state): State<AppState>,
    Json(req): Json<JsonRpcRequest>,
) -> Json<JsonRpcResponse> {
    let _guard = state.systray.begin_work();
    let req_id = req.id.clone().unwrap_or_else(|| json!(1));
    let response = dispatch_mcp_method(&state, req, req_id).await;

    if let Ok(serialized) = serde_json::to_string(&response) {
        let _ = state.mcp_tx.send(serialized);
    }

    Json(response)
}

// Dispatch MCP JSON-RPC methods / Despacha métodos JSON-RPC do protocolo MCP
async fn dispatch_mcp_method(
    state: &AppState,
    req: JsonRpcRequest,
    req_id: Value,
) -> JsonRpcResponse {
    match req.method.as_str() {
        "initialize" => JsonRpcResponse {
            jsonrpc: "2.0".to_string(),
            id: req_id,
            result: Some(json!({
                "protocolVersion": "2024-11-05",
                "serverInfo": {
                    "name": "zorya-sovereign-mcp",
                    "version": "2.1.0",
                    "node": state.config.network.node_name
                },
                "capabilities": {
                    "tools": { "listChanged": false }
                }
            })),
            error: None,
        },
        "tools/list" => JsonRpcResponse {
            jsonrpc: "2.0".to_string(),
            id: req_id,
            result: Some(json!({
                "tools": [
                    {
                        "name": "zorya_raw_decode",
                        "description": "Decode camera RAW (base64 or file path) via LightCraft + PhotoCraft into JPEG/PNG / Decodifica RAW de câmera via LightCraft + PhotoCraft para JPEG/PNG",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "raw_base64": { "type": "string", "description": "Base64-encoded RAW file bytes" },
                                "file_path": { "type": "string", "description": "Optional local path to RAW file" },
                                "format": { "type": "string", "enum": ["jpeg", "png"], "default": "jpeg" },
                                "quality": { "type": "integer", "default": 95 }
                            }
                        }
                    },
                    {
                        "name": "zorya_vector_diagram",
                        "description": "Generate an SVG 1.1 technical or pedagogical diagram via VectorCraft / Gera um diagrama SVG 1.1 técnico ou pedagógico via VectorCraft",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "title": { "type": "string" },
                                "subtitle": { "type": "string" },
                                "width": { "type": "integer", "default": 960 },
                                "height": { "type": "integer", "default": 540 },
                                "nodes": {
                                    "type": "array",
                                    "items": {
                                        "type": "object",
                                        "properties": {
                                            "id": { "type": "string" },
                                            "label": { "type": "string" },
                                            "description": { "type": "string" }
                                        },
                                        "required": ["id", "label"]
                                    }
                                },
                                "edges": { "type": "array" }
                            },
                            "required": ["title", "nodes"]
                        }
                    },
                    {
                        "name": "zorya_audio_master",
                        "description": "Master WAV/MP3 audio to EBU R128 (-14 LUFS, -1.0 dBTP) via SoundCraft DSP / Masteriza áudio WAV/MP3 no padrão EBU R128 via SoundCraft DSP",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "audio_base64": { "type": "string" },
                                "file_path": { "type": "string" },
                                "target_lufs": { "type": "number", "default": -14.0 }
                            }
                        }
                    },
                    {
                        "name": "zorya_system_info",
                        "description": "Retrieve complete system, CPU, RAM and GTX 1650 VRAM telemetry / Obtém telemetria completa de sistema, CPU, RAM e VRAM GTX 1650",
                        "inputSchema": {
                            "type": "object",
                            "properties": {}
                        }
                    },
                    {
                        "name": "zorya_chat",
                        "description": "Run local LLM inference on GTX 1650 via Ollama / Executa inferência LLM local na GTX 1650 via Ollama",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "prompt": { "type": "string" },
                                "model": { "type": "string" }
                            },
                            "required": ["prompt"]
                        }
                    }
                ]
            })),
            error: None,
        },
        "tools/call" => {
            let params = req.params.unwrap_or_else(|| json!({}));
            let tool_name = params
                .get("name")
                .and_then(|v| v.as_str())
                .unwrap_or_default();
            let args = params.get("arguments").cloned().unwrap_or_else(|| json!({}));

            match execute_mcp_tool(state, tool_name, args).await {
                Ok(tool_output) => JsonRpcResponse {
                    jsonrpc: "2.0".to_string(),
                    id: req_id,
                    result: Some(json!({
                        "content": [
                            {
                                "type": "text",
                                "text": serde_json::to_string(&tool_output).unwrap_or_default()
                            }
                        ],
                        "isError": false
                    })),
                    error: None,
                },
                Err(err) => JsonRpcResponse {
                    jsonrpc: "2.0".to_string(),
                    id: req_id,
                    result: Some(json!({
                        "content": [
                            {
                                "type": "text",
                                "text": format!("Tool execution error / Erro na execução da ferramenta: {}", err)
                            }
                        ],
                        "isError": true
                    })),
                    error: None,
                },
            }
        }
        _ => JsonRpcResponse {
            jsonrpc: "2.0".to_string(),
            id: req_id,
            result: None,
            error: Some(json!({
                "code": -32601,
                "message": format!("Method not found / Método não encontrado: {}", req.method)
            })),
        },
    }
}

// Execute one of the 5 sovereign MCP tools / Executa uma das 5 ferramentas soberanas MCP
async fn execute_mcp_tool(
    state: &AppState,
    tool_name: &str,
    args: Value,
) -> anyhow::Result<Value> {
    match tool_name {
        "zorya_raw_decode" => {
            let raw_bytes = if let Some(b64) = args.get("raw_base64").and_then(|v| v.as_str()) {
                BASE64.decode(b64.as_bytes())?
            } else if let Some(path) = args.get("file_path").and_then(|v| v.as_str()) {
                tokio::fs::read(path).await?
            } else {
                anyhow::bail!("Provide raw_base64 or file_path / Forneça raw_base64 ou file_path");
            };

            let fmt_str = args.get("format").and_then(|v| v.as_str()).unwrap_or("jpeg");
            let quality = args.get("quality").and_then(|v| v.as_u64()).unwrap_or(95) as u8;
            let fmt = RawOutputFormat::from_str_lossy(fmt_str);

            let encoded = tokio::task::spawn_blocking(move || {
                decode_raw_with_lightcraft(&raw_bytes, fmt, quality)
            })
            .await??;

            Ok(json!({
                "status": "ok",
                "format": fmt_str,
                "bytes_len": encoded.len(),
                "image_base64": BASE64.encode(&encoded)
            }))
        }
        "zorya_vector_diagram" => {
            let spec: VectorDiagramSpec = serde_json::from_value(args)?;
            let svg = render_vector_diagram_svg(&spec)?;
            Ok(json!({
                "status": "ok",
                "svg": svg
            }))
        }
        "zorya_audio_master" => {
            let audio_bytes = if let Some(b64) = args.get("audio_base64").and_then(|v| v.as_str()) {
                BASE64.decode(b64.as_bytes())?
            } else if let Some(path) = args.get("file_path").and_then(|v| v.as_str()) {
                tokio::fs::read(path).await?
            } else {
                anyhow::bail!("Provide audio_base64 or file_path / Forneça audio_base64 ou file_path");
            };

            let target_lufs = args
                .get("target_lufs")
                .and_then(|v| v.as_f64())
                .unwrap_or(-14.0) as f32;

            let report = tokio::task::spawn_blocking(move || {
                master_audio_ebu_r128(&audio_bytes, Some("wav"), target_lufs)
            })
            .await??;

            Ok(json!({
                "status": "ok",
                "input_lufs": report.input_lufs,
                "output_lufs": report.output_lufs,
                "true_peak_dbtp": report.true_peak_dbtp,
                "sample_rate": report.sample_rate,
                "channels": report.channels,
                "mastered_wav_base64": BASE64.encode(&report.mastered_wav_bytes)
            }))
        }
        "zorya_system_info" => {
            let mut guard = state.telemetry.write().await;
            let info = guard.collect_detailed();
            Ok(serde_json::to_value(info)?)
        }
        "zorya_chat" => {
            let prompt = args
                .get("prompt")
                .and_then(|v| v.as_str())
                .ok_or_else(|| anyhow::anyhow!("Missing 'prompt' argument / Argumento 'prompt' ausente"))?;
            let model = args.get("model").and_then(|v| v.as_str());
            let reply = run_internal_chat(state, prompt, model).await?;
            Ok(json!({
                "status": "ok",
                "reply": reply
            }))
        }
        other => anyhow::bail!("Unknown MCP tool / Ferramenta MCP desconhecida: {}", other),
    }
}
