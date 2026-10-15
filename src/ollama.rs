// OpenAI-compatible proxy to local Ollama with SSE streaming & GTX 1650 VRAM tuning / Proxy compatível com OpenAI para o Ollama local com streaming SSE e otimização de VRAM GTX 1650

use std::convert::Infallible;
use axum::{
    body::Body,
    http::{header, StatusCode},
    response::{IntoResponse, Response},
};
use bytes::Bytes;
use futures_util::StreamExt;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

use crate::server::AppState;

// Chat message in OpenAI format / Mensagem de chat no formato OpenAI
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ChatMessage {
    pub role: String,
    pub content: Value,
}

// OpenAI-compatible chat completion request / Requisição de chat completion compatível com OpenAI
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ChatCompletionRequest {
    pub model: Option<String>,
    pub messages: Vec<ChatMessage>,
    pub stream: Option<bool>,
    pub temperature: Option<f32>,
    pub max_tokens: Option<u32>,
}

// Forward chat completions to Ollama (streaming SSE or JSON) / Encaminha chat completions para o Ollama (streaming SSE ou JSON)
pub async fn proxy_chat_completions(
    state: AppState,
    mut payload: Value,
) -> Result<Response, (StatusCode, String)> {
    // Activate green/amber 500ms systray blink while inference runs / Ativa piscada verde/âmbar de 500ms na bandeja durante a inferência
    let _guard = state.systray.begin_work();

    let default_model = state
        .config
        .inference
        .default_model
        .clone()
        .unwrap_or_else(|| "qwen2.5-coder:7b-instruct-q4_K_M".to_string());

    if payload.get("model").and_then(|m| m.as_str()).unwrap_or("").is_empty() {
        payload["model"] = json!(default_model);
    }

    // Inject GTX 1650 4GB dedicated VRAM optimization options / Injeta opções de otimização para os 4GB de VRAM dedicados da GTX 1650
    if payload.get("options").is_none() {
        payload["options"] = json!({
            "num_gpu": 999,
            "num_ctx": 8192,
            "use_mmap": true,
            "use_mlock": false
        });
    }

    let is_stream = payload
        .get("stream")
        .and_then(|v| v.as_bool())
        .unwrap_or(false);

    let target_url = format!(
        "{}/v1/chat/completions",
        state.config.inference.ollama_url.trim_end_matches('/')
    );

    let upstream = state
        .http_client
        .post(&target_url)
        .header(header::CONTENT_TYPE, "application/json")
        .json(&payload)
        .send()
        .await
        .map_err(|e| {
            (
                StatusCode::BAD_GATEWAY,
                serde_json::to_string(&json!({
                    "error": {
                        "message": format!("Ollama upstream unreachable at {}: {}", target_url, e),
                        "type": "ollama_connection_error",
                        "node": state.config.network.node_name
                    }
                }))
                .unwrap_or_default(),
            )
        })?;

    let status = StatusCode::from_u16(upstream.status().as_u16()).unwrap_or(StatusCode::OK);

    if is_stream {
        // Stream SSE chunks directly to caller while keeping ProcessingGuard alive / Transmite chunks SSE diretamente ao cliente mantendo o ProcessingGuard ativo
        let byte_stream = upstream.bytes_stream().map(move |chunk_res| {
            let _keep_guard_alive = &_guard;
            match chunk_res {
                Ok(bytes) => Ok::<Bytes, Infallible>(bytes),
                Err(err) => {
                    let err_line = format!(
                        "data: {}\n\n",
                        json!({ "error": err.to_string() })
                    );
                    Ok(Bytes::from(err_line))
                }
            }
        });

        let response = Response::builder()
            .status(status)
            .header(header::CONTENT_TYPE, "text/event-stream; charset=utf-8")
            .header(header::CACHE_CONTROL, "no-cache")
            .header(header::CONNECTION, "keep-alive")
            .body(Body::from_stream(byte_stream))
            .map_err(|e| (StatusCode::INTERNAL_SERVER_ERROR, e.to_string()))?;

        Ok(response)
    } else {
        let body_bytes = upstream
            .bytes()
            .await
            .map_err(|e| (StatusCode::BAD_GATEWAY, e.to_string()))?;

        Ok((
            status,
            [(header::CONTENT_TYPE, "application/json; charset=utf-8")],
            body_bytes,
        )
            .into_response())
    }
}

// Helper for internal MCP tool zorya_chat / Auxiliar interno para a ferramenta MCP zorya_chat
pub async fn run_internal_chat(
    state: &AppState,
    prompt: &str,
    model_override: Option<&str>,
) -> anyhow::Result<String> {
    let _guard = state.systray.begin_work();
    let model = model_override
        .map(|s| s.to_string())
        .or_else(|| state.config.inference.default_model.clone())
        .unwrap_or_else(|| "qwen2.5-coder:7b-instruct-q4_K_M".to_string());

    let target_url = format!(
        "{}/v1/chat/completions",
        state.config.inference.ollama_url.trim_end_matches('/')
    );

    let payload = json!({
        "model": model,
        "stream": false,
        "messages": [
            {
                "role": "user",
                "content": prompt
            }
        ],
        "options": {
            "num_gpu": 999,
            "num_ctx": 8192
        }
    });

    let resp = state
        .http_client
        .post(&target_url)
        .json(&payload)
        .send()
        .await?;

    let val: Value = resp.json().await?;
    let content = val
        .get("choices")
        .and_then(|c| c.get(0))
        .and_then(|first| first.get("message"))
        .and_then(|msg| msg.get("content"))
        .and_then(|txt| txt.as_str())
        .unwrap_or("")
        .to_string();

    Ok(content)
}
