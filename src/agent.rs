// PT: Agente de IA Interno Zorya v3.0 (Qwen 2.5 7B como cérebro + Tool Calling via JSON Schema)
// EN: Zorya v3.0 Internal AI Agent (Qwen 2.5 7B brain + JSON Schema Tool Calling)
// AI: @llm-context Orquestra 12 ferramentas internas, roteamento em 5 níveis e rotação automática de chaves com cache 24h
// AI: @llm-invariant Lê chaves de /var/www/mcp-server/credentials/credentials.json (VPS) ou zorya.toml (Nitro) e alerta via systray quando esgotadas

use std::collections::HashMap;
use std::path::Path;
use std::sync::Arc;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tokio::sync::RwLock;

use crate::config::ZoryaConfig;
use crate::inference::{ChatCompletionRequest, ChatMessage, NativeInferenceEngine};
use crate::systray::SystrayController;

pub const VALID_GROQ_MODELS: &[&str] = &[
    "openai/gpt-oss-120b",
    "openai/gpt-oss-20b",
    "qwen/qwen3.8-27b",
    "whisper-large-v3",
];

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ToolDefinition {
    pub name: String,
    pub description: String,
    pub parameters: Value,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct KeyHealthEntry {
    pub key: String,
    pub role: String,
    pub valid: bool,
    pub last_checked_epoch: u64,
    pub exhausted: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AgentTaskRequest {
    pub prompt: String,
    pub system: Option<String>,
    pub heavy_task: Option<bool>,
    pub preferred_tool: Option<String>,
    pub tool_args: Option<Value>,
}

pub struct KeyVaultManager {
    pub keys: RwLock<HashMap<String, Vec<KeyHealthEntry>>>,
    pub cursor: RwLock<HashMap<String, usize>>,
    pub http_client: reqwest::Client,
    pub systray: SystrayController,
}

impl KeyVaultManager {
    pub fn load_from_sources(config: &ZoryaConfig, systray: SystrayController) -> Arc<Self> {
        let mut map: HashMap<String, Vec<KeyHealthEntry>> = HashMap::new();
        let vps_creds_path = Path::new("/var/www/mcp-server/credentials/credentials.json");

        if vps_creds_path.exists() {
            if let Ok(raw) = std::fs::read_to_string(vps_creds_path) {
                if let Ok(parsed) = serde_json::from_str::<Value>(&raw) {
                    for provider in ["groq", "gemini", "openai"] {
                        let mut entries = Vec::new();
                        if let Some(obj) = parsed.get(provider).and_then(|v| v.as_object()) {
                            for (role, val) in obj {
                                if let Some(k) = val.as_str() {
                                    if k.len() > 10 {
                                        entries.push(KeyHealthEntry {
                                            key: k.trim().to_string(),
                                            role: role.clone(),
                                            valid: true,
                                            last_checked_epoch: 0,
                                            exhausted: false,
                                        });
                                    }
                                }
                            }
                        }
                        if !entries.is_empty() {
                            map.insert(provider.to_string(), entries);
                        }
                    }
                }
            }
        }

        // Fallback ou complemento com chaves de zorya.toml ([credentials])
        for (provider, list) in [
            ("groq", &config.credentials.groq_keys),
            ("gemini", &config.credentials.gemini_keys),
            ("openai", &config.credentials.openai_keys),
        ] {
            let entry_vec = map.entry(provider.to_string()).or_default();
            for (idx, k) in list.iter().enumerate() {
                if !k.trim().is_empty() && !entry_vec.iter().any(|e| e.key == k.trim()) {
                    entry_vec.push(KeyHealthEntry {
                        key: k.trim().to_string(),
                        role: format!("toml_{}", idx),
                        valid: true,
                        last_checked_epoch: 0,
                        exhausted: false,
                    });
                }
            }
        }

        Arc::new(Self {
            keys: RwLock::new(map),
            cursor: RwLock::new(HashMap::new()),
            http_client: reqwest::Client::builder()
                .timeout(Duration::from_secs(20))
                .build()
                .unwrap_or_default(),
            systray,
        })
    }

    // PT: Seleciona chave saudável com cache de validação de 24h e rotação round-robin
    // EN: Selects a healthy key with 24h validation cache and round-robin rotation
    pub async fn acquire_key(&self, provider: &str) -> anyhow::Result<String> {
        let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs();
        let mut keys_guard = self.keys.write().await;
        let entries = keys_guard
            .get_mut(provider)
            .ok_or_else(|| anyhow::anyhow!("Provedor '{}' sem chaves configuradas", provider))?;

        if entries.is_empty() {
            self.systray.notify_keys_exhausted(provider);
            anyhow::bail!("Nenhuma chave cadastrada para {}", provider);
        }

        let mut cursor_guard = self.cursor.write().await;
        let total_entries = entries.len();
        let start_idx = *cursor_guard.get(provider).unwrap_or(&0) % total_entries;

        for offset in 0..total_entries {
            let idx = (start_idx + offset) % total_entries;
            let entry = &mut entries[idx];
            if entry.exhausted {
                continue;
            }
            // Cache de validação 24h (86400s)
            if now.saturating_sub(entry.last_checked_epoch) > 86400 {
                entry.valid = self.probe_key(provider, &entry.key).await;
                entry.last_checked_epoch = now;
            }
            if entry.valid {
                let key = entry.key.clone();
                cursor_guard.insert(provider.to_string(), (idx + 1) % total_entries);
                return Ok(key);
            }
        }

        self.systray.notify_keys_exhausted(provider);
        anyhow::bail!(
            "Todas as chaves do provedor '{}' estão esgotadas. Sugestão: adicione uma nova chave em zorya.toml ou aguarde o reset diário de cota.",
            provider
        )
    }

    pub async fn mark_key_exhausted(&self, provider: &str, key: &str) {
        let mut keys_guard = self.keys.write().await;
        if let Some(entries) = keys_guard.get_mut(provider) {
            for e in entries.iter_mut() {
                if e.key == key {
                    e.exhausted = true;
                }
            }
            if entries.iter().all(|e| e.exhausted || !e.valid) {
                self.systray.notify_keys_exhausted(provider);
            }
        }
    }

    async fn probe_key(&self, provider: &str, key: &str) -> bool {
        match provider {
            "groq" => self
                .http_client
                .get("https://api.groq.com/openai/v1/models")
                .bearer_auth(key)
                .send()
                .await
                .map(|r| r.status().is_success())
                .unwrap_or(false),
            "gemini" => self
                .http_client
                .get(format!("https://generativelanguage.googleapis.com/v1beta/models?key={}", key))
                .send()
                .await
                .map(|r| r.status().is_success())
                .unwrap_or(false),
            "openai" => self
                .http_client
                .get("https://api.openai.com/v1/models")
                .bearer_auth(key)
                .send()
                .await
                .map(|r| r.status().is_success())
                .unwrap_or(false),
            _ => false,
        }
    }
}

pub struct ZoryaAgent {
    pub config: Arc<ZoryaConfig>,
    pub inference: Arc<NativeInferenceEngine>,
    pub key_vault: Arc<KeyVaultManager>,
    pub http_client: reqwest::Client,
}

impl ZoryaAgent {
    pub fn new(
        config: Arc<ZoryaConfig>,
        inference: Arc<NativeInferenceEngine>,
        systray: SystrayController,
    ) -> Arc<Self> {
        let key_vault = KeyVaultManager::load_from_sources(&config, systray);
        Arc::new(Self {
            config,
            inference,
            key_vault,
            http_client: reqwest::Client::builder()
                .timeout(Duration::from_secs(45))
                .build()
                .unwrap_or_default(),
        })
    }

    // PT: Retorna o catálogo JSON Schema das 12 ferramentas internas do Agente Zorya
    // EN: Returns the JSON Schema catalog of all 12 internal Zorya Agent tools
    pub fn tool_schemas() -> Vec<ToolDefinition> {
        vec![
            ToolDefinition {
                name: "call_local_model".to_string(),
                description: "Chama o modelo GGUF local via llama-cpp-2".to_string(),
                parameters: json!({"type": "object", "properties": {"prompt": {"type": "string"}, "model": {"type": "string"}}, "required": ["prompt"]}),
            },
            ToolDefinition {
                name: "call_groq".to_string(),
                description: "Chama API Groq com rotação automática de chaves (openai/gpt-oss-120b, openai/gpt-oss-20b, qwen/qwen3.8-27b, whisper-large-v3)".to_string(),
                parameters: json!({"type": "object", "properties": {"prompt": {"type": "string"}, "model": {"type": "string"}}, "required": ["prompt"]}),
            },
            ToolDefinition {
                name: "call_gemini".to_string(),
                description: "Chama API Google Gemini com rotação automática de chaves".to_string(),
                parameters: json!({"type": "object", "properties": {"prompt": {"type": "string"}, "model": {"type": "string"}}, "required": ["prompt"]}),
            },
            ToolDefinition {
                name: "call_openai".to_string(),
                description: "Chama API OpenAI com rotação de chaves".to_string(),
                parameters: json!({"type": "object", "properties": {"prompt": {"type": "string"}, "model": {"type": "string"}}, "required": ["prompt"]}),
            },
            ToolDefinition {
                name: "extract_pdf".to_string(),
                description: "Usa PdfCraft para extrair texto fiel, tabelas, fórmulas e OCR de PDF".to_string(),
                parameters: json!({"type": "object", "properties": {"file_path": {"type": "string"}, "pdf_base64": {"type": "string"}}}),
            },
            ToolDefinition {
                name: "generate_svg".to_string(),
                description: "Usa VectorCraft para gerar diagrama SVG rico e escalável".to_string(),
                parameters: json!({"type": "object", "properties": {"title": {"type": "string"}, "nodes": {"type": "array"}}, "required": ["title"]}),
            },
            ToolDefinition {
                name: "process_audio".to_string(),
                description: "Usa SoundCraft para normalizar EBU R128 (-14 LUFS) e limpar ruído de áudio".to_string(),
                parameters: json!({"type": "object", "properties": {"input_path": {"type": "string"}, "target_lufs": {"type": "number"}}, "required": ["input_path"]}),
            },
            ToolDefinition {
                name: "clone_voice".to_string(),
                description: "Síntese de voz TTS com timbre clonado via SoundCraft (XTTS v2 / Kokoro ONNX)".to_string(),
                parameters: json!({"type": "object", "properties": {"text": {"type": "string"}, "profile_id": {"type": "string"}}, "required": ["text"]}),
            },
            ToolDefinition {
                name: "denoise_image".to_string(),
                description: "Denoise clássico de sinal via LightCraft (bilateral, Wiener, unsharp mask)".to_string(),
                parameters: json!({"type": "object", "properties": {"image_path": {"type": "string"}, "method": {"type": "string"}, "intensity": {"type": "number"}}, "required": ["image_path"]}),
            },
            ToolDefinition {
                name: "route_to_vps".to_string(),
                description: "Delega tarefa leve para o Mini Zorya na VPS".to_string(),
                parameters: json!({"type": "object", "properties": {"endpoint": {"type": "string"}, "payload": {"type": "object"}}, "required": ["endpoint", "payload"]}),
            },
            ToolDefinition {
                name: "route_to_nitro".to_string(),
                description: "Delega tarefa pesada para o Zorya do Nitro (GTX 1650)".to_string(),
                parameters: json!({"type": "object", "properties": {"endpoint": {"type": "string"}, "payload": {"type": "object"}}, "required": ["endpoint", "payload"]}),
            },
            ToolDefinition {
                name: "manage_keys".to_string(),
                description: "Lista, testa e rotaciona chaves de API nos provedores configurados".to_string(),
                parameters: json!({"type": "object", "properties": {"action": {"type": "string", "enum": ["list", "test", "rotate"]}, "provider": {"type": "string"}}, "required": ["action"]}),
            },
        ]
    }

    // PT: Roteamento inteligente em 5 níveis conforme disponibilidade de Nitro, VPS e chaves Cloud
    // EN: 5-tier intelligent routing based on Nitro, VPS and Cloud keys availability
    pub async fn execute_routed(&self, req: AgentTaskRequest, nitro_peer_url: Option<String>, vps_peer_url: Option<String>) -> Value {
        let is_heavy = req.heavy_task.unwrap_or_else(|| req.prompt.len() > 1200);
        let t0 = Instant::now();

        // 1. Nitro online via Tailscale ou IP direto -> usar para tarefas pesadas
        if is_heavy {
            if let Some(nitro_url) = nitro_peer_url.as_deref() {
                if let Ok(res) = self.delegate_remote(nitro_url, "/v1/chat/completions", &req).await {
                    return json!({
                        "status": "ok",
                        "routed_to": "zorya_nitro",
                        "content": res,
                        "latency_ms": t0.elapsed().as_millis() as u64
                    });
                }
            }
        }

        // 2. Tarefa leve (ou este próprio nó é o Nitro) -> modelo local ou VPS
        if !is_heavy || self.inference.hardware.dgpu_vram_mb >= 4096 {
            let chat_req = ChatCompletionRequest {
                model: Some(self.inference.default_model.clone()),
                messages: vec![
                    ChatMessage {
                        role: "system".to_string(),
                        content: json!(req.system.clone().unwrap_or_else(|| "Você é o cérebro Qwen 2.5 do Zorya v3.0.".to_string())),
                        tool_calls: None,
                    },
                    ChatMessage {
                        role: "user".to_string(),
                        content: json!(req.prompt.clone()),
                        tool_calls: None,
                    },
                ],
                temperature: Some(0.3),
                top_p: None,
                max_tokens: Some(1536),
                stream: Some(false),
                tools: None,
                response_format: None,
            };
            if let Ok(local_resp) = self.inference.chat_completions(chat_req).await {
                return json!({
                    "status": "ok",
                    "routed_to": "local_gguf",
                    "model_used": self.inference.default_model,
                    "response": local_resp,
                    "latency_ms": t0.elapsed().as_millis() as u64
                });
            }
        }

        // 3. Nitro offline -> VPS com modelo menor (qwen2.5-3b-instruct-q4_k_m.gguf)
        if let Some(vps_url) = vps_peer_url.as_deref() {
            if let Ok(res) = self.delegate_remote(vps_url, "/v1/chat/completions", &req).await {
                return json!({
                    "status": "ok",
                    "routed_to": "mini_zorya_vps",
                    "content": res,
                    "latency_ms": t0.elapsed().as_millis() as u64
                });
            }
        }

        // 4. VPS e Nitro offline -> Groq / Gemini com chaves disponíveis e rotação automática
        if let Ok(groq_res) = self.call_groq(&req.prompt, req.system.as_deref(), Some("openai/gpt-oss-120b")).await {
            return json!({
                "status": "ok",
                "routed_to": "groq_cloud",
                "model_used": "openai/gpt-oss-120b",
                "content": groq_res,
                "latency_ms": t0.elapsed().as_millis() as u64
            });
        }

        if let Ok(gem_res) = self.call_gemini(&req.prompt, req.system.as_deref()).await {
            return json!({
                "status": "ok",
                "routed_to": "gemini_cloud",
                "model_used": "gemini-2.5-flash",
                "content": gem_res,
                "latency_ms": t0.elapsed().as_millis() as u64
            });
        }

        // 5. Todas as chaves esgotadas -> erro claro com sugestão
        json!({
            "status": "error",
            "routed_to": "none",
            "error": "Todos os nós locais (Nitro/VPS) estão indisponíveis e todas as chaves de API (Groq/Gemini/OpenAI) esgotaram suas cotas.",
            "suggestion": "Verifique se o modelo GGUF está em models/, inicie o Zorya Nitro na rede local/Tailscale ou adicione novas chaves em zorya.toml / credentials.json."
        })
    }

    pub async fn call_groq(&self, prompt: &str, system: Option<&str>, model: Option<&str>) -> anyhow::Result<String> {
        let chosen_model = model
            .filter(|m| VALID_GROQ_MODELS.contains(m))
            .unwrap_or("openai/gpt-oss-120b");
        let key = self.key_vault.acquire_key("groq").await?;

        let resp = self
            .http_client
            .post("https://api.groq.com/openai/v1/chat/completions")
            .bearer_auth(&key)
            .json(&json!({
                "model": chosen_model,
                "messages": [
                    {"role": "system", "content": system.unwrap_or("Responda em português de forma literal e precisa.")},
                    {"role": "user", "content": prompt}
                ],
                "temperature": 0.3
            }))
            .send()
            .await?;

        if resp.status().as_u16() == 429 || resp.status().as_u16() == 401 {
            self.key_vault.mark_key_exhausted("groq", &key).await;
            anyhow::bail!("Groq key rate-limited or unauthorized");
        }

        let data: Value = resp.json().await?;
        let text = data["choices"][0]["message"]["content"]
            .as_str()
            .unwrap_or("")
            .to_string();
        if text.is_empty() {
            anyhow::bail!("Empty response from Groq");
        }
        Ok(text)
    }

    pub async fn call_gemini(&self, prompt: &str, system: Option<&str>) -> anyhow::Result<String> {
        let key = self.key_vault.acquire_key("gemini").await?;
        let full_prompt = match system {
            Some(sys) => format!("{}\n\n{}", sys, prompt),
            None => prompt.to_string(),
        };
        let url = format!(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={}",
            key
        );
        let resp = self
            .http_client
            .post(&url)
            .json(&json!({
                "contents": [{"parts": [{"text": full_prompt}]}]
            }))
            .send()
            .await?;

        if resp.status().as_u16() == 429 || resp.status().as_u16() == 403 {
            self.key_vault.mark_key_exhausted("gemini", &key).await;
            anyhow::bail!("Gemini key rate-limited or forbidden");
        }

        let data: Value = resp.json().await?;
        let text = data["candidates"][0]["content"]["parts"][0]["text"]
            .as_str()
            .unwrap_or("")
            .to_string();
        if text.is_empty() {
            anyhow::bail!("Empty response from Gemini");
        }
        Ok(text)
    }

    async fn delegate_remote(&self, base_url: &str, endpoint: &str, req: &AgentTaskRequest) -> anyhow::Result<String> {
        let url = format!("{}/{}", base_url.trim_end_matches('/'), endpoint.trim_start_matches('/'));
        let resp = self
            .http_client
            .post(&url)
            .json(&json!({
                "messages": [
                    {"role": "system", "content": req.system.clone().unwrap_or_default()},
                    {"role": "user", "content": req.prompt}
                ]
            }))
            .send()
            .await?;
        if !resp.status().is_success() {
            anyhow::bail!("Remote node returned {}", resp.status());
        }
        let body: Value = resp.json().await?;
        Ok(body["choices"][0]["message"]["content"]
            .as_str()
            .unwrap_or_default()
            .to_string())
    }
}

// POST /api/v1/agent/execute
pub async fn handle_agent_execute(
    axum::extract::State(state): axum::extract::State<crate::server::AppState>,
    axum::Json(req): axum::Json<AgentTaskRequest>,
) -> axum::response::Response {
    use axum::response::IntoResponse;
    let _guard = state.systray.begin_ai_work();
    let net = state.network.status_snapshot().await;
    let peer_status = state.cluster.peer_status.read().await;
    let peer_url = if peer_status.online {
        Some(peer_status.endpoint.clone())
    } else {
        None
    };

    let result = state
        .agent
        .execute_routed(req, peer_url.clone(), Some(net.preferred_peer_endpoint))
        .await;

    (axum::http::StatusCode::OK, axum::Json(result)).into_response()
}
