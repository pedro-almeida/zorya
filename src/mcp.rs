// PT: Servidor MCP Zorya v3.0 — JSON-RPC 2.0 sobre stdio (Antigravity local) e SSE (/mcp/sse)
// EN: Zorya v3.0 MCP Server — JSON-RPC 2.0 over stdio (local Antigravity) and SSE (/mcp/sse)
// AI: @llm-context Economiza cota do Antigravity expondo 7 ferramentas locais (chat, pdf, svg, denoise, audio, voice, system_info)
// AI: @llm-rule Registro CLI: claude mcp add zorya -- /caminho/para/zorya-cli mcp

use std::convert::Infallible;
use std::io::{self, BufRead, Write};
use std::time::Duration;

use axum::{
    extract::State,
    response::sse::{Event, KeepAlive, Sse},
    Json,
};
use futures_util::stream::Stream;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tokio_stream::wrappers::BroadcastStream;
use tokio_stream::StreamExt;

use crate::craft::{
    clone_and_synthesize_voice, denoise_image_classical, extract_pdf_document,
    master_audio_ebu_r128, render_vector_diagram_svg, ClassicalDenoiseMethod, VectorDiagramSpec,
    VoiceSynthesizeRequest,
};
use crate::inference::{ChatCompletionRequest, ChatMessage};
use crate::server::AppState;

pub const MCP_REGISTER_COMMAND: &str = "claude mcp add zorya -- /caminho/para/zorya-cli mcp";

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct JsonRpcRequest {
    pub jsonrpc: Option<String>,
    pub id: Option<Value>,
    pub method: String,
    pub params: Option<Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct JsonRpcResponse {
    pub jsonrpc: String,
    pub id: Value,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub result: Option<Value>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<Value>,
}

// PT: Executa loop MCP sobre stdio para integração direta com Antigravity / Claude Code
// EN: Runs MCP stdio loop for direct integration with Antigravity / Claude Code
pub async fn run_stdio_mcp_loop(state: AppState) -> anyhow::Result<()> {
    let stdin = io::stdin();
    let mut stdout = io::stdout();

    for line_res in stdin.lock().lines() {
        let line = line_res?;
        let trimmed = line.trim();
        if trimmed.is_empty() {
            continue;
        }
        let parsed: Result<JsonRpcRequest, _> = serde_json::from_str(trimmed);
        let response = match parsed {
            Ok(req) => {
                let req_id = req.id.clone().unwrap_or_else(|| json!(1));
                dispatch_mcp_method(&state, req, req_id).await
            }
            Err(err) => JsonRpcResponse {
                jsonrpc: "2.0".to_string(),
                id: Value::Null,
                result: None,
                error: Some(json!({ "code": -32700, "message": format!("Parse error: {}", err) })),
            },
        };
        let out_line = serde_json::to_string(&response)?;
        writeln!(stdout, "{}", out_line)?;
        stdout.flush()?;
    }
    Ok(())
}

// GET /mcp/sse
pub async fn mcp_sse_handler(
    State(state): State<AppState>,
) -> Sse<impl Stream<Item = Result<Event, Infallible>>> {
    let rx = state.mcp_tx.subscribe();
    let initial_event = futures_util::stream::once(async move {
        Ok::<Event, Infallible>(Event::default().event("endpoint").data("/mcp/message"))
    });
    let broadcast_events = BroadcastStream::new(rx).filter_map(|msg| match msg {
        Ok(json_str) => Some(Ok::<Event, Infallible>(
            Event::default().event("message").data(json_str),
        )),
        Err(_) => None,
    });
    Sse::new(initial_event.chain(broadcast_events)).keep_alive(
        KeepAlive::new()
            .interval(Duration::from_secs(15))
            .text("keep-alive"),
    )
}

// POST /mcp/message e POST /mcp
pub async fn mcp_jsonrpc_handler(
    State(state): State<AppState>,
    Json(req): Json<JsonRpcRequest>,
) -> Json<JsonRpcResponse> {
    let _guard = state.systray.begin_ai_work();
    let req_id = req.id.clone().unwrap_or_else(|| json!(1));
    let response = dispatch_mcp_method(&state, req, req_id).await;
    if let Ok(serialized) = serde_json::to_string(&response) {
        let _ = state.mcp_tx.send(serialized);
    }
    Json(response)
}

pub async fn dispatch_mcp_method(
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
                    "name": "zorya-mcp-server",
                    "version": "3.0.0",
                    "register_command": MCP_REGISTER_COMMAND
                },
                "capabilities": { "tools": { "listChanged": false } }
            })),
            error: None,
        },
        "tools/list" => JsonRpcResponse {
            jsonrpc: "2.0".to_string(),
            id: req_id,
            result: Some(json!({
                "tools": [
                    {
                        "name": "zorya_chat",
                        "description": "Inferência de texto local via llama-cpp-2 (Qwen 2.5 GGUF) ou roteamento inteligente",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "prompt": { "type": "string" },
                                "system": { "type": "string" },
                                "model": { "type": "string" }
                            },
                            "required": ["prompt"]
                        }
                    },
                    {
                        "name": "zorya_extract_pdf",
                        "description": "Extrai texto fiel, tabelas, fórmulas e metadados de PDF via PdfCraft (com OCR integrado)",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "file_path": { "type": "string" },
                                "pdf_base64": { "type": "string" },
                                "ocr": { "type": "boolean" }
                            }
                        }
                    },
                    {
                        "name": "zorya_generate_svg",
                        "description": "Gera diagrama vetorial SVG rico e escalável via VectorCraft",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "title": { "type": "string" },
                                "subtitle": { "type": "string" },
                                "nodes": { "type": "array" },
                                "edges": { "type": "array" }
                            },
                            "required": ["title", "nodes"]
                        }
                    },
                    {
                        "name": "zorya_denoise",
                        "description": "Denoise clássico determinístico de imagem via LightCraft (bilateral, Wiener, unsharp mask — zero IA)",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "image_base64": { "type": "string" },
                                "method": { "type": "string", "enum": ["bilateral", "wiener", "unsharp", "convolution"] },
                                "intensity": { "type": "number" }
                            },
                            "required": ["image_base64"]
                        }
                    },
                    {
                        "name": "zorya_normalize_audio",
                        "description": "Normalização vocal EBU R128 (-14 LUFS) e remoção de ruído via SoundCraft",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "wav_base64": { "type": "string" },
                                "target_lufs": { "type": "number" }
                            },
                            "required": ["wav_base64"]
                        }
                    },
                    {
                        "name": "zorya_clone_voice",
                        "description": "Síntese de voz TTS com timbre clonado via SoundCraft + pós-processamento EBU R128",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "text": { "type": "string" },
                                "profile_id": { "type": "string" },
                                "language": { "type": "string" }
                            },
                            "required": ["text"]
                        }
                    },
                    {
                        "name": "zorya_system_info",
                        "description": "Retorna hardware disponível (CPU, AVX2/AVX512, GPU VRAM), modelos GGUF carregados e status de rede/cluster",
                        "inputSchema": { "type": "object", "properties": {} }
                    },
                    {
                        "name": "svitlo_generate",
                        "description": "Gera estudo estruturado cognitivo Svitlo com texto puro do estudante, síntese, Feynman, Cornell, Mermaid, cartões Anki e diagrama SVG real",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "content": { "type": "string" },
                                "topic": { "type": "string" },
                                "mode": { "type": "string" }
                            },
                            "required": ["content"]
                        }
                    }
                ]
            })),
            error: None,
        },
        "tools/call" => {
            let params = req.params.unwrap_or_else(|| json!({}));
            let tool_name = params.get("name").and_then(|v| v.as_str()).unwrap_or("");
            let args = params.get("arguments").cloned().unwrap_or_else(|| json!({}));

            match execute_mcp_tool(state, tool_name, args).await {
                Ok(output) => JsonRpcResponse {
                    jsonrpc: "2.0".to_string(),
                    id: req_id,
                    result: Some(json!({
                        "content": [{ "type": "text", "text": serde_json::to_string_pretty(&output).unwrap_or_default() }],
                        "isError": false
                    })),
                    error: None,
                },
                Err(err) => JsonRpcResponse {
                    jsonrpc: "2.0".to_string(),
                    id: req_id,
                    result: Some(json!({
                        "content": [{ "type": "text", "text": format!("Erro na ferramenta {}: {}", tool_name, err) }],
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
            error: Some(json!({ "code": -32601, "message": format!("Method not found: {}", req.method) })),
        },
    }
}

async fn execute_mcp_tool(state: &AppState, tool_name: &str, args: Value) -> anyhow::Result<Value> {
    use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
    match tool_name {
        "zorya_chat" => {
            let prompt = args.get("prompt").and_then(|v| v.as_str()).unwrap_or("");
            let system = args
                .get("system")
                .and_then(|v| v.as_str())
                .unwrap_or("Você é o coprocessador soberano Zorya v3.0.");
            let model = args.get("model").and_then(|v| v.as_str()).map(|s| s.to_string());
            state
                .inference
                .chat_completions(ChatCompletionRequest {
                    model,
                    messages: vec![
                        ChatMessage { role: "system".to_string(), content: json!(system), tool_calls: None },
                        ChatMessage { role: "user".to_string(), content: json!(prompt), tool_calls: None },
                    ],
                    temperature: Some(0.3),
                    top_p: None,
                    max_tokens: Some(1536),
                    stream: Some(false),
                    tools: None,
                    response_format: None,
                })
                .await
        }
        "zorya_extract_pdf" => {
            let file_path = args.get("file_path").and_then(|v| v.as_str());
            let b64 = args.get("pdf_base64").and_then(|v| v.as_str());
            let ocr = args.get("ocr").and_then(|v| v.as_bool()).unwrap_or(true);
            let report = extract_pdf_document(file_path, b64, ocr)?;
            Ok(serde_json::to_value(report)?)
        }
        "zorya_generate_svg" => {
            let spec: VectorDiagramSpec = serde_json::from_value(args)?;
            let svg = render_vector_diagram_svg(&spec)?;
            Ok(json!({ "svg": svg }))
        }
        "zorya_denoise" => {
            let b64 = args.get("image_base64").and_then(|v| v.as_str()).unwrap_or("");
            let bytes = BASE64.decode(b64.trim())?;
            let method = ClassicalDenoiseMethod::from_str_lossy(
                args.get("method").and_then(|v| v.as_str()).unwrap_or("bilateral"),
            );
            let intensity = args.get("intensity").and_then(|v| v.as_f64()).unwrap_or(0.85) as f32;
            let processed = denoise_image_classical(&bytes, method, intensity)?;
            Ok(json!({ "method": method.as_str(), "image_base64": BASE64.encode(processed) }))
        }
        "zorya_normalize_audio" => {
            let b64 = args.get("wav_base64").and_then(|v| v.as_str()).unwrap_or("");
            let target_lufs = args.get("target_lufs").and_then(|v| v.as_f64()).unwrap_or(-14.0) as f32;
            let bytes = BASE64.decode(b64.trim())?;
            let report = master_audio_ebu_r128(&bytes, None, target_lufs)?;
            Ok(json!({
                "input_lufs": report.input_lufs,
                "output_lufs": report.output_lufs,
                "true_peak_dbtp": report.true_peak_dbtp,
                "mastered_wav_base64": BASE64.encode(&report.mastered_wav_bytes)
            }))
        }
        "zorya_clone_voice" => {
            let req: VoiceSynthesizeRequest = serde_json::from_value(args)?;
            let res = clone_and_synthesize_voice(&req)?;
            Ok(serde_json::to_value(res)?)
        }
        "zorya_system_info" => {
            let models = state.inference.list_models().await;
            let net = state.network.status_snapshot().await;
            Ok(json!({
                "version": "3.0.0",
                "hardware": state.inference.hardware,
                "models": models,
                "network": net
            }))
        }
        "svitlo_generate" => {
            let content = args.get("content").and_then(|v| v.as_str()).unwrap_or("").to_string();
            let student_text = args.get("student_text").and_then(|v| v.as_str()).map(|s| s.to_string());
            let system_prompt = args.get("system_prompt").and_then(|v| v.as_str()).map(|s| s.to_string());
            let topic = args.get("topic").and_then(|v| v.as_str()).map(|s| s.to_string());
            let mode = args.get("mode").and_then(|v| v.as_str()).map(|s| s.to_string());
            let res = state
                .inference
                .generate_svitlo_study(crate::inference::SvitloGenerateRequest {
                    content,
                    student_text,
                    system_prompt,
                    topic,
                    mode,
                })
                .await?;
            Ok(res)
        }
        _ => anyhow::bail!("Unknown MCP tool: {}", tool_name),
    }
}
