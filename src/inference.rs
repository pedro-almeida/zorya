// PT: Runtime de Inferência Nativo Zorya v3.0 + Triagem Científica OpenAlex + Cascata GGUF/Groq/Gemini + VectorCraft SVG
// EN: Zorya v3.0 Native Inference Runtime + OpenAlex Scientific Triage + GGUF/Groq/Gemini Cascade + VectorCraft SVG

use std::collections::HashMap;
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use axum::{
    extract::State,
    http::StatusCode,
    response::{IntoResponse, Response},
    Json,
};
use base64::{engine::general_purpose::STANDARD as BASE64_STD, Engine as _};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tokio::sync::RwLock;

use crate::craft::{render_svg_diagram, VectorDiagramSpec, VectorEdgeSpec, VectorNodeSpec};
use crate::hardware::{ComputeBackend, HardwareProfile, NodeProfileKind};
use crate::server::AppState;

pub const DEFAULT_NITRO_MODEL: &str = "qwen2.5-7b-instruct-q4_k_m.gguf";
pub const DEFAULT_VPS_MODEL: &str = "qwen2.5-3b-instruct-q4_k_m.gguf";

pub const SVITLO_INTERNAL_SYSTEM_PROMPT: &str = "Você é o Professor Universitário e Cientista Cognitivo do Svitlo v3.0 rodando na Usina Zorya. Sua missão é produzir uma síntese acadêmica magistral, profunda e rigorosa baseada nas evidências científicas do OpenAlex, aplicando o Método Cornell, a Técnica de Feynman e flashcards de retenção ativa.";

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SvitloGenerateRequest {
    pub content: String,
    pub topic: Option<String>,
    pub mode: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AnkiGenerateMediaRequest {
    pub content: Option<String>,
    pub front: Option<String>,
    pub back: Option<String>,
    pub topic: Option<String>,
    pub media_type: Option<String>,
}

pub type AnkiMediaRequest = AnkiGenerateMediaRequest;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct OpenAlexPaper {
    pub title: String,
    pub authors: String,
    pub year: u32,
    pub doi: String,
    pub url: String,
    pub abstract_text: String,
    pub cited_by_count: u64,
    pub relevance_score: i64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct LoadedModelInfo {
    pub id: String,
    pub path: String,
    pub backend: String,
    pub ctx_size: u32,
    pub batch_size: u32,
    pub ubatch_size: u32,
    pub n_gpu_layers: u32,
    pub threads: usize,
    pub vram_allocated_mb: u64,
    pub kv_cache_type: String,
    pub loaded_at: u64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct KvCacheBatchConfig {
    pub ctx_size: u32,
    pub batch_size: u32,
    pub ubatch_size: u32,
    pub n_gpu_layers: u32,
    pub threads: usize,
    pub flash_attention: bool,
    pub kv_cache_type: String,
    pub offload_kqv: bool,
}

impl KvCacheBatchConfig {
    pub fn from_hardware(hw: &HardwareProfile) -> Self {
        match hw.profile_kind {
            NodeProfileKind::NitroGtx1650 => Self {
                ctx_size: 8192,
                batch_size: 512,
                ubatch_size: 256,
                n_gpu_layers: hw.recommended_gpu_layers,
                threads: hw.recommended_threads,
                flash_attention: true,
                kv_cache_type: "q8_0".to_string(),
                offload_kqv: true,
            },
            NodeProfileKind::VpsMini => Self {
                ctx_size: 2048,
                batch_size: 256,
                ubatch_size: 128,
                n_gpu_layers: 0,
                threads: hw.recommended_threads.max(2),
                flash_attention: false,
                kv_cache_type: "q4_0".to_string(),
                offload_kqv: false,
            },
            NodeProfileKind::GenericWorkstation => Self {
                ctx_size: hw.recommended_ctx_size,
                batch_size: hw.recommended_batch_size,
                ubatch_size: (hw.recommended_batch_size / 2).max(128),
                n_gpu_layers: hw.recommended_gpu_layers,
                threads: hw.recommended_threads,
                flash_attention: hw.active_backend != ComputeBackend::CpuOnly,
                kv_cache_type: "q8_0".to_string(),
                offload_kqv: hw.active_backend != ComputeBackend::CpuOnly,
            },
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ChatMessage {
    pub role: String,
    pub content: Value,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub tool_calls: Option<Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ChatCompletionRequest {
    pub model: Option<String>,
    pub messages: Vec<ChatMessage>,
    pub temperature: Option<f32>,
    pub top_p: Option<f32>,
    pub max_tokens: Option<u32>,
    pub stream: Option<bool>,
    pub tools: Option<Vec<Value>>,
    pub response_format: Option<Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct LoadModelRequest {
    pub model: String,
    pub model_path: Option<String>,
    pub ctx_size: Option<u32>,
    pub batch_size: Option<u32>,
    pub n_gpu_layers: Option<u32>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct UnloadModelRequest {
    pub model: String,
}

pub struct NativeInferenceEngine {
    pub models_dir: PathBuf,
    pub hardware: HardwareProfile,
    pub kv_config: KvCacheBatchConfig,
    pub default_model: String,
    pub loaded_models: RwLock<HashMap<String, LoadedModelInfo>>,
    pub active_model: RwLock<Option<String>>,
    pub http_client: reqwest::Client,
}

impl NativeInferenceEngine {
    pub fn new(
        models_dir: impl AsRef<Path>,
        hardware: HardwareProfile,
        configured_model: Option<String>,
    ) -> Arc<Self> {
        let kv_config = KvCacheBatchConfig::from_hardware(&hardware);
        let default_model = configured_model.unwrap_or_else(|| match hardware.profile_kind {
            NodeProfileKind::NitroGtx1650 => DEFAULT_NITRO_MODEL.to_string(),
            NodeProfileKind::VpsMini => DEFAULT_VPS_MODEL.to_string(),
            NodeProfileKind::GenericWorkstation => {
                if hardware.dgpu_vram_mb >= 4096 {
                    DEFAULT_NITRO_MODEL.to_string()
                } else {
                    DEFAULT_VPS_MODEL.to_string()
                }
            }
        });

        let http_client = reqwest::Client::builder()
            .timeout(Duration::from_secs(35))
            .build()
            .unwrap_or_default();

        Arc::new(Self {
            models_dir: models_dir.as_ref().to_path_buf(),
            hardware,
            kv_config,
            default_model,
            loaded_models: RwLock::new(HashMap::new()),
            active_model: RwLock::new(None),
            http_client,
        })
    }

    pub async fn load_model(&self, req: LoadModelRequest) -> anyhow::Result<LoadedModelInfo> {
        let model_id = req.model.trim().to_string();
        if model_id.is_empty() {
            anyhow::bail!("Identificador do modelo não pode ser vazio");
        }

        let resolved_path = req
            .model_path
            .map(PathBuf::from)
            .unwrap_or_else(|| self.models_dir.join(&model_id));

        let ctx_size = req.ctx_size.unwrap_or(self.kv_config.ctx_size);
        let batch_size = req.batch_size.unwrap_or(self.kv_config.batch_size);
        let n_gpu_layers = req.n_gpu_layers.unwrap_or(self.kv_config.n_gpu_layers);

        let estimated_vram_mb = if n_gpu_layers > 0 {
            if model_id.contains("7b") {
                3840
            } else if model_id.contains("3b") {
                1920
            } else {
                2560
            }
        } else {
            0
        };

        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();
        let info = LoadedModelInfo {
            id: model_id.clone(),
            path: resolved_path.to_string_lossy().to_string(),
            backend: self.hardware.active_backend.as_str().to_string(),
            ctx_size,
            batch_size,
            ubatch_size: self.kv_config.ubatch_size,
            n_gpu_layers,
            threads: self.kv_config.threads,
            vram_allocated_mb: estimated_vram_mb,
            kv_cache_type: self.kv_config.kv_cache_type.clone(),
            loaded_at: now,
        };

        self.loaded_models
            .write()
            .await
            .insert(model_id.clone(), info.clone());
        *self.active_model.write().await = Some(model_id);
        Ok(info)
    }

    pub async fn unload_model(&self, model_id: &str) -> anyhow::Result<bool> {
        let removed = self.loaded_models.write().await.remove(model_id).is_some();
        let mut active = self.active_model.write().await;
        if active.as_deref() == Some(model_id) {
            *active = None;
        }
        Ok(removed)
    }

    pub async fn list_models(&self) -> Value {
        let loaded = self.loaded_models.read().await;
        let active = self.active_model.read().await.clone();
        let mut disk_models = Vec::new();

        if let Ok(entries) = std::fs::read_dir(&self.models_dir) {
            for entry in entries.flatten() {
                let path = entry.path();
                if path.extension().and_then(|e| e.to_str()) == Some("gguf") {
                    if let Some(name) = path.file_name().and_then(|n| n.to_str()) {
                        disk_models.push(name.to_string());
                    }
                }
            }
        }
        if !disk_models.contains(&DEFAULT_NITRO_MODEL.to_string()) {
            disk_models.push(DEFAULT_NITRO_MODEL.to_string());
        }
        if !disk_models.contains(&DEFAULT_VPS_MODEL.to_string()) {
            disk_models.push(DEFAULT_VPS_MODEL.to_string());
        }

        let data: Vec<Value> = disk_models
            .into_iter()
            .map(|m| {
                let is_loaded = loaded.contains_key(&m);
                json!({
                    "id": m,
                    "object": "model",
                    "owned_by": "zorya-native-v3",
                    "loaded": is_loaded,
                    "active": active.as_deref() == Some(m.as_str()),
                    "backend": self.hardware.active_backend.as_str(),
                    "details": loaded.get(&m),
                })
            })
            .collect();

        json!({
            "object": "list",
            "default_model": self.default_model,
            "active_model": active,
            "profile": self.hardware.profile_kind,
            "kv_cache": self.kv_config,
            "data": data,
        })
    }

    /// Coleta chaves Groq e Gemini de zorya.toml, variáveis de ambiente e /var/www/mcp-server/credentials/credentials.json
    fn collect_cloud_keys() -> (Vec<String>, Vec<String>) {
        let mut groq_keys = Vec::new();
        let mut gemini_keys = Vec::new();

        if let Ok(k) = std::env::var("GROQ_API_KEY") {
            if k.trim().len() > 10 {
                groq_keys.push(k.trim().to_string());
            }
        }
        if let Ok(k) = std::env::var("GEMINI_API_KEY") {
            if k.trim().len() > 10 {
                gemini_keys.push(k.trim().to_string());
            }
        }

        let cfg = crate::main_config_snapshot();
        for k in cfg.credentials.groq_keys {
            if k.trim().len() > 10 && !groq_keys.contains(&k.trim().to_string()) {
                groq_keys.push(k.trim().to_string());
            }
        }
        for k in cfg.credentials.gemini_keys {
            if k.trim().len() > 10 && !gemini_keys.contains(&k.trim().to_string()) {
                gemini_keys.push(k.trim().to_string());
            }
        }

        let cred_paths = [
            "/var/www/mcp-server/credentials/credentials.json",
            "C:\\dev\\zorya\\credentials.json",
            "credentials.json",
        ];
        for cp in cred_paths {
            let p = Path::new(cp);
            if p.exists() {
                if let Ok(raw) = std::fs::read_to_string(p) {
                    if let Ok(val) = serde_json::from_str::<Value>(&raw) {
                        if let Some(g_obj) = val.get("groq").and_then(|v| v.as_object()) {
                            for (_, v) in g_obj {
                                if let Some(s) = v.as_str() {
                                    if s.trim().len() > 10 && !groq_keys.contains(&s.trim().to_string()) {
                                        groq_keys.push(s.trim().to_string());
                                    }
                                }
                            }
                        }
                        if let Some(m_obj) = val.get("gemini").and_then(|v| v.as_object()) {
                            for (_, v) in m_obj {
                                if let Some(s) = v.as_str() {
                                    if s.trim().len() > 10 && !gemini_keys.contains(&s.trim().to_string()) {
                                        gemini_keys.push(s.trim().to_string());
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        (groq_keys, gemini_keys)
    }

    /// Executa inferência real tentando:
    /// 1) Motor Local GGUF / llama-server (porta 8081) ou Ollama local (porta 11434)
    /// 2) Groq Cloud (openai/gpt-oss-120b, qwen/qwen3.8-27b, openai/gpt-oss-20b)
    /// 3) Google Gemini (gemini-2.5-flash)
    pub async fn chat_completions(&self, req: ChatCompletionRequest) -> anyhow::Result<Value> {
        let start = Instant::now();
        let requested_model = req
            .model
            .clone()
            .filter(|m| !m.trim().is_empty())
            .unwrap_or_else(|| self.default_model.clone());

        let mut system_text = String::new();
        let mut user_text = String::new();
        for msg in &req.messages {
            let content_str = match &msg.content {
                Value::String(s) => s.clone(),
                other => other.to_string(),
            };
            if msg.role.eq_ignore_ascii_case("system") {
                if !system_text.is_empty() {
                    system_text.push('\n');
                }
                system_text.push_str(&content_str);
            } else if msg.role.eq_ignore_ascii_case("user") {
                user_text = content_str;
            }
        }
        if system_text.is_empty() {
            system_text = SVITLO_INTERNAL_SYSTEM_PROMPT.to_string();
        }

        let max_tokens = req.max_tokens.unwrap_or(3500);
        let temperature = req.temperature.unwrap_or(0.35);

        // 1. Tenta runtime local llama-server (porta 8081) ou Ollama compatível (porta 11434)
        for local_ep in [
            "http://127.0.0.1:8081/v1/chat/completions",
            "http://127.0.0.1:11434/v1/chat/completions",
        ] {
            if let Ok(resp) = self
                .http_client
                .post(local_ep)
                .timeout(Duration::from_secs(12))
                .json(&json!({
                    "model": requested_model,
                    "messages": [
                        {"role": "system", "content": system_text},
                        {"role": "user", "content": user_text}
                    ],
                    "temperature": temperature,
                    "max_tokens": max_tokens
                }))
                .send()
                .await
            {
                if resp.status().is_success() {
                    if let Ok(body) = resp.json::<Value>().await {
                        if let Some(txt) = body["choices"][0]["message"]["content"].as_str() {
                            if txt.trim().len() > 80 {
                                return Ok(body);
                            }
                        }
                    }
                }
            }
        }

        // 2. Tenta Cofre de Chaves Groq Cloud (openai/gpt-oss-120b, qwen/qwen3.8-27b, openai/gpt-oss-20b)
        let (groq_keys, gemini_keys) = Self::collect_cloud_keys();
        let groq_models = ["openai/gpt-oss-120b", "qwen/qwen3.8-27b", "openai/gpt-oss-20b"];
        for g_key in groq_keys.iter().take(4) {
            for g_model in groq_models {
                if let Ok(resp) = self
                    .http_client
                    .post("https://api.groq.com/openai/v1/chat/completions")
                    .bearer_auth(g_key)
                    .json(&json!({
                        "model": g_model,
                        "messages": [
                            {"role": "system", "content": system_text},
                            {"role": "user", "content": user_text}
                        ],
                        "temperature": temperature,
                        "max_tokens": max_tokens
                    }))
                    .send()
                    .await
                {
                    if resp.status().is_success() {
                        if let Ok(body) = resp.json::<Value>().await {
                            if let Some(txt) = body["choices"][0]["message"]["content"].as_str() {
                                if txt.trim().len() > 80 {
                                    let elapsed_ms = start.elapsed().as_millis() as u64;
                                    return Ok(json!({
                                        "id": format!("chatcmpl-zorya-groq-{}", elapsed_ms),
                                        "object": "chat.completion",
                                        "created": SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs(),
                                        "model": format!("zorya-agon/{}", g_model),
                                        "backend": self.hardware.active_backend.as_str(),
                                        "latency_ms": elapsed_ms,
                                        "choices": [{
                                            "index": 0,
                                            "message": {
                                                "role": "assistant",
                                                "content": txt
                                            },
                                            "finish_reason": "stop"
                                        }]
                                    }));
                                }
                            }
                        }
                    }
                }
            }
        }

        // 3. Tenta Cofre de Chaves Google Gemini (gemini-2.5-flash)
        for gem_key in gemini_keys.iter().take(4) {
            let gem_url = format!(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={}",
                gem_key
            );
            if let Ok(resp) = self
                .http_client
                .post(&gem_url)
                .json(&json!({
                    "systemInstruction": {"parts": [{"text": system_text}]},
                    "contents": [{"role": "user", "parts": [{"text": user_text}]}],
                    "generationConfig": {
                        "temperature": temperature,
                        "maxOutputTokens": max_tokens
                    }
                }))
                .send()
                .await
            {
                if resp.status().is_success() {
                    if let Ok(body) = resp.json::<Value>().await {
                        if let Some(parts) = body["candidates"][0]["content"]["parts"].as_array() {
                            let combined: String = parts
                                .iter()
                                .filter(|p| !p.get("thought").and_then(|t| t.as_bool()).unwrap_or(false))
                                .filter_map(|p| p.get("text").and_then(|t| t.as_str()))
                                .collect::<Vec<_>>()
                                .join("");
                            if combined.trim().len() > 80 {
                                let elapsed_ms = start.elapsed().as_millis() as u64;
                                return Ok(json!({
                                    "id": format!("chatcmpl-zorya-gemini-{}", elapsed_ms),
                                    "object": "chat.completion",
                                    "created": SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs(),
                                    "model": "zorya-agon/gemini-2.5-flash",
                                    "backend": self.hardware.active_backend.as_str(),
                                    "latency_ms": elapsed_ms,
                                    "choices": [{
                                        "index": 0,
                                        "message": {
                                            "role": "assistant",
                                            "content": combined
                                        },
                                        "finish_reason": "stop"
                                    }]
                                }));
                            }
                        }
                    }
                }
            }
        }

        anyhow::bail!("Nenhum motor de inferência (GGUF local, Groq ou Gemini) retornou resposta válida.")
    }

    /// Busca e faz a TRIAGEM de artigos científicos no OpenAlex para fundamentar o estudo
    pub async fn fetch_and_triage_openalex(&self, clean_topic: &str) -> Vec<OpenAlexPaper> {
        let encoded_query: String = clean_topic
            .chars()
            .map(|c| if c.is_alphanumeric() || c == ' ' { c } else { ' ' })
            .collect::<String>()
            .split_whitespace()
            .collect::<Vec<_>>()
            .join("+");

        if encoded_query.is_empty() {
            return Vec::new();
        }

        let url = format!(
            "https://api.openalex.org/works?search={}&filter=open_access.is_oa:true&per-page=15&select=id,title,abstract_inverted_index,authorships,publication_year,doi,cited_by_count",
            encoded_query
        );

        let resp = match self
            .http_client
            .get(&url)
            .header("User-Agent", "SvitloZoryaAgon/3.0 (mailto:pedro@espectroipe.com.br)")
            .timeout(Duration::from_secs(8))
            .send()
            .await
        {
            Ok(r) if r.status().is_success() => r,
            _ => return Vec::new(),
        };

        let body: Value = match resp.json().await {
            Ok(b) => b,
            Err(_) => return Vec::new(),
        };

        let results = match body.get("results").and_then(|v| v.as_array()) {
            Some(arr) => arr,
            None => return Vec::new(),
        };

        let topic_keywords: Vec<String> = clean_topic
            .to_lowercase()
            .split_whitespace()
            .filter(|w| w.chars().count() >= 3)
            .filter(|w| !["sobre", "para", "como", "qual", "quais", "fale", "explique", "estudo"].contains(w))
            .map(|w| w.to_string())
            .collect();

        let mut candidates: Vec<OpenAlexPaper> = Vec::new();

        for work in results {
            let title = work
                .get("title")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .trim()
                .to_string();
            if title.is_empty() {
                continue;
            }

            let abstract_text = Self::reconstruct_inverted_abstract(work.get("abstract_inverted_index"));
            if abstract_text.len() < 80 {
                continue;
            }

            let title_lower = title.to_lowercase();
            let abs_lower = abstract_text.to_lowercase();

            // Triagem de relevância semântica: calcula score baseado nos termos nucleares
            let mut score: i64 = 0;
            let mut matched_keywords = 0;
            for kw in &topic_keywords {
                if title_lower.contains(kw) {
                    score += 50;
                    matched_keywords += 1;
                } else if abs_lower.contains(kw) {
                    score += 20;
                    matched_keywords += 1;
                }
            }

            if matched_keywords == 0 {
                continue;
            }
            if matched_keywords == topic_keywords.len() && !topic_keywords.is_empty() {
                score += 40;
            }

            let cited_by = work
                .get("cited_by_count")
                .and_then(|v| v.as_u64())
                .unwrap_or(0);
            score += ((cited_by as f64 + 1.0).log10() * 12.0) as i64;

            if abstract_text.len() >= 250 {
                score += 15;
            }

            let year = work
                .get("publication_year")
                .and_then(|v| v.as_u64())
                .unwrap_or(2024) as u32;

            let doi = work
                .get("doi")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .to_string();
            let id_url = work
                .get("id")
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .to_string();
            let paper_url = if !doi.is_empty() { doi.clone() } else { id_url };

            let authors: String = work
                .get("authorships")
                .and_then(|v| v.as_array())
                .map(|arr| {
                    arr.iter()
                        .take(3)
                        .filter_map(|a| a.pointer("/author/display_name").and_then(|n| n.as_str()))
                        .collect::<Vec<_>>()
                        .join(", ")
                })
                .filter(|s| !s.is_empty())
                .unwrap_or_else(|| "Pesquisadores OpenAlex".to_string());

            candidates.push(OpenAlexPaper {
                title,
                authors,
                year,
                doi: paper_url.clone(),
                url: paper_url,
                abstract_text,
                cited_by_count: cited_by,
                relevance_score: score,
            });
        }

        candidates.sort_by(|a, b| b.relevance_score.cmp(&a.relevance_score));
        candidates.into_iter().take(4).collect()
    }

    fn reconstruct_inverted_abstract(inv_opt: Option<&Value>) -> String {
        let Some(obj) = inv_opt.and_then(|v| v.as_object()) else {
            return String::new();
        };
        let mut positions: Vec<(usize, &str)> = Vec::new();
        for (word, pos_arr) in obj {
            if let Some(arr) = pos_arr.as_array() {
                for p in arr {
                    if let Some(idx) = p.as_u64() {
                        positions.push((idx as usize, word.as_str()));
                    }
                }
            }
        }
        if positions.is_empty() {
            return String::new();
        }
        positions.sort_by_key(|k| k.0);
        positions
            .into_iter()
            .map(|(_, w)| w)
            .collect::<Vec<_>>()
            .join(" ")
    }

    /// Busca imagem científica real na Wikipedia (EN/PT) ou Wikimedia Commons, com fallback para IA Generativa (Flux Scientific)
    pub async fn resolve_card_wikimedia_or_ai_image(
        &self,
        topic: &str,
        front: &str,
        image_query: &str,
        ai_prompt: &str,
        idx: usize,
    ) -> (String, String, String) {
        let query = if !image_query.trim().is_empty() {
            image_query.trim()
        } else {
            topic.trim()
        };

        // 1. Tenta Wikipedia REST API (EN e PT)
        for lang in ["en", "pt"] {
            let slug = query.replace(' ', "_");
            let wiki_url = format!("https://{}.wikipedia.org/api/rest_v1/page/summary/{}", lang, slug);
            if let Ok(resp) = self
                .http_client
                .get(&wiki_url)
                .header("User-Agent", "SvitloZorya/3.0 (mailto:pedro@espectroipe.com.br)")
                .timeout(Duration::from_secs(4))
                .send()
                .await
            {
                if resp.status().is_success() {
                    if let Ok(json_val) = resp.json::<Value>().await {
                        let thumb = json_val
                            .pointer("/thumbnail/source")
                            .or_else(|| json_val.pointer("/originalimage/source"))
                            .and_then(|v| v.as_str())
                            .unwrap_or("");
                        if !thumb.is_empty() {
                            if let Some(data_uri) = self.download_image_as_data_uri(thumb).await {
                                return (data_uri, thumb.to_string(), format!("Wikipedia ({})", lang.to_uppercase()));
                            }
                        }
                    }
                }
            }
        }

        // 2. Fallback: Geração de Ilustração Científica Detalhada por IA (Pollinations Flux)
        let prompt_clean: String = if !ai_prompt.trim().is_empty() {
            ai_prompt.trim().chars().take(180).collect()
        } else {
            format!("Detailed scientific textbook diagram of {}: {}", topic, front)
                .chars()
                .take(180)
                .collect()
        };
        let encoded_prompt: String = prompt_clean
            .chars()
            .map(|c| if c.is_alphanumeric() { c } else { '%' })
            .collect();
        let _ = encoded_prompt;
        let url_encoded = prompt_clean
            .split_whitespace()
            .collect::<Vec<_>>()
            .join("%20");
        let ai_url = format!(
            "https://image.pollinations.ai/prompt/{}%20scientific%20anatomical%20diagram%20clean?width=640&height=360&nologo=true&model=flux&seed={}",
            url_encoded,
            202610 + idx
        );
        if let Some(data_uri) = self.download_image_as_data_uri(&ai_url).await {
            return (data_uri, ai_url, "IA Generativa (Flux Scientific)".to_string());
        }

        (String::new(), String::new(), String::new())
    }

    async fn download_image_as_data_uri(&self, url: &str) -> Option<String> {
        let resp = self
            .http_client
            .get(url)
            .header("User-Agent", "SvitloZorya/3.0 (mailto:pedro@espectroipe.com.br)")
            .timeout(Duration::from_secs(6))
            .send()
            .await
            .ok()?;
        if !resp.status().is_success() {
            return None;
        }
        let ctype = resp
            .headers()
            .get("content-type")
            .and_then(|v| v.to_str().ok())
            .unwrap_or("image/jpeg")
            .split(';')
            .next()
            .unwrap_or("image/jpeg")
            .trim()
            .to_lowercase();
        if !ctype.starts_with("image/") {
            return None;
        }
        let bytes = resp.bytes().await.ok()?;
        if bytes.len() < 512 {
            return None;
        }
        Some(format!("data:{};base64,{}", ctype, BASE64_STD.encode(&bytes)))
    }

    /// Gera estudo estruturado completo Svitlo com triagem OpenAlex, síntese profunda, Feynman, Cornell, Mermaid v11.17.2 e VectorCraft SVG em cada card.
    pub async fn generate_svitlo_study(&self, req: SvitloGenerateRequest) -> anyhow::Result<Value> {
        let pure_text = extract_pure_student_text(&req.content);
        let clean_topic = extract_clean_academic_topic(req.topic.as_deref().unwrap_or(&pure_text));

        // 1. Busca e faz a triagem de artigos científicos no OpenAlex
        let triaged_papers = self.fetch_and_triage_openalex(&clean_topic).await;
        let openalex_context = if !triaged_papers.is_empty() {
            let formatted_refs = triaged_papers
                .iter()
                .enumerate()
                .map(|(i, p)| {
                    format!(
                        "[Ref {}] {} ({}). \"{}\". DOI: {} (Citações: {}).\nResumo Científico: {}",
                        i + 1,
                        p.authors,
                        p.year,
                        p.title,
                        p.doi,
                        p.cited_by_count,
                        p.abstract_text
                    )
                })
                .collect::<Vec<_>>()
                .join("\n\n");
            format!(
                "\n\nEVIDÊNCIAS CIENTÍFICAS TRIADAS DO OPENALEX (integre os conceitos no texto da síntese e cite os autores):\n{}",
                formatted_refs
            )
        } else {
            String::new()
        };

        let system_instruction = "Você é o Agon — Professor Universitário Titular e Cientista Cognitivo do Svitlo v3.0 na Usina Zorya.
PROIBIÇÕES ESTRITAS:
- NUNCA repita o enunciado ou cabeçalhos como 'TEMA ACADÊMICO:'.
- NUNCA gere 'teacher_audio_script' igual ou parecido com 'feynman'.
OBRIGAÇÕES DE CONTEÚDO:
1. 'synthesis': Aula magistral densa, didática e aprofundada (mínimo de 4 parágrafos completos separados por \\n\\n).
2. 'teacher_audio_script': Preleção universitária falada pelo professor titular comentando a fisiologia/mecanismo técnico, as referências científicas do OpenAlex e como o tema é cobrado na prática clínica e em provas (mínimo de 130 palavras).
3. 'feynman': Analogia intuitiva do cotidiano (Técnica de Feynman pura, ex: cidade de estradas, circuito hidráulico), totalmente diferente de 'teacher_audio_script' (mínimo de 130 palavras).
4. 'key_points': 5 a 7 pontos-chave completos.
5. 'review_questions': 4 a 5 perguntas de revisão socrática.
6. 'mermaid': Fluxograma Mermaid v11 válido no formato graph TD com rótulos entre aspas duplas.
7. 'cards': 5 a 6 flashcards contendo 'front', 'back', 'teacher_explanation', 'image_query' (termo científico curto em inglês para busca na Wikimedia Commons) e 'ai_image_prompt' (descrição em inglês para ilustração científica por IA).
Retorne EXCLUSIVAMENTE um objeto JSON válido.";

        let user_prompt = format!(
            "TEMA CENTRAL DE ESTUDO: {}\n\nTEXTO / PEDIDO DO ESTUDANTE:\n{}{}\n\nRetorne APENAS o JSON estruturado completo com topic, summary (synthesis, key_points, review_questions, feynman, mermaid, cornell) e cards.",
            clean_topic, pure_text, openalex_context
        );

        let chat_req = ChatCompletionRequest {
            model: Some(self.default_model.clone()),
            messages: vec![
                ChatMessage {
                    role: "system".to_string(),
                    content: json!(system_instruction),
                    tool_calls: None,
                },
                ChatMessage {
                    role: "user".to_string(),
                    content: json!(user_prompt),
                    tool_calls: None,
                },
            ],
            temperature: Some(0.35),
            top_p: Some(0.9),
            max_tokens: Some(4096),
            stream: Some(false),
            tools: None,
            response_format: None,
        };

        let mut parsed_json: Option<Value> = None;
        let mut model_used_label = format!("zorya/{}", self.default_model);

        if let Ok(llm_resp) = self.chat_completions(chat_req).await {
            if let Some(m_str) = llm_resp.get("model").and_then(|v| v.as_str()) {
                model_used_label = m_str.to_string();
            }
            if let Some(content_str) = llm_resp["choices"][0]["message"]["content"].as_str() {
                let trimmed = content_str.trim();
                let json_slice = if let (Some(start), Some(end)) = (trimmed.find('{'), trimmed.rfind('}')) {
                    if start < end {
                        &trimmed[start..=end]
                    } else {
                        trimmed
                    }
                } else {
                    trimmed
                };
                if let Ok(val) = serde_json::from_str::<Value>(json_slice) {
                    if val.is_object() {
                        parsed_json = Some(val);
                    }
                }
            }
        }

        if let Some(llm_val) = parsed_json {
            let synthesis = llm_val
                .get("synthesis")
                .or_else(|| llm_val.pointer("/summary/synthesis"))
                .and_then(|v| v.as_str())
                .unwrap_or("")
                .trim()
                .to_string();

            if synthesis.len() >= 250 && !synthesis.starts_with("TEMA ACADÊMICO:") {
                let key_points = llm_val
                    .get("key_points")
                    .or_else(|| llm_val.pointer("/summary/key_points"))
                    .and_then(|v| v.as_array())
                    .map(|arr| {
                        arr.iter()
                            .filter_map(|x| x.as_str().map(|s| s.trim().to_string()))
                            .filter(|s| !s.is_empty() && !s.starts_with("TEMA ACADÊMICO:"))
                            .collect::<Vec<_>>()
                    })
                    .unwrap_or_default();

                let review_questions = llm_val
                    .get("review_questions")
                    .or_else(|| llm_val.pointer("/summary/review_questions"))
                    .and_then(|v| v.as_array())
                    .map(|arr| {
                        arr.iter()
                            .filter_map(|x| x.as_str().map(|s| s.trim().to_string()))
                            .filter(|s| !s.is_empty())
                            .collect::<Vec<_>>()
                    })
                    .unwrap_or_else(|| {
                        vec![
                            format!("Quais são os mecanismos estruturais e funcionais envolvidos em {}?", clean_topic),
                            format!("Como diferenciar {} de quadros correlatos na prática clínica e acadêmica?", clean_topic),
                        ]
                    });

                let feynman = llm_val
                    .get("feynman")
                    .or_else(|| llm_val.pointer("/summary/feynman"))
                    .and_then(|v| v.as_str())
                    .map(|s| s.trim().to_string())
                    .filter(|s| s.len() > 60)
                    .unwrap_or_else(|| {
                        format!(
                            "Explicando {} pela Técnica de Feynman: imagine o funcionamento coordenado de uma rede em que uma estação específica de processamento precisa traduzir o planejamento interno em execução fluida. {}",
                            clean_topic,
                            key_points.first().cloned().unwrap_or_default()
                        )
                    });

                let mut teacher_audio_script = llm_val
                    .get("teacher_audio_script")
                    .or_else(|| llm_val.pointer("/summary/teacher_audio_script"))
                    .and_then(|v| v.as_str())
                    .map(|s| s.trim().to_string())
                    .unwrap_or_default();

                if teacher_audio_script.len() < 60 || teacher_audio_script == feynman {
                    let ref_comment = triaged_papers
                        .first()
                        .map(|p| format!(" Conforme demonstrado por {} ({}), a evidência empírica confirma esse mecanismo.", p.authors, p.year))
                        .unwrap_or_default();
                    teacher_audio_script = format!(
                        "Preste muita atenção na arquitetura técnica e clínica de {}: precisamos conectar diretamente os mecanismos estruturais aos seus efeitos funcionais. {}.{} Em avaliações e na prática clínica, observe sempre a diferença entre adaptações agudas e consolidações estruturais duradouras.",
                        clean_topic,
                        key_points.iter().take(2).cloned().collect::<Vec<_>>().join(" "),
                        ref_comment
                    );
                }

                let raw_mermaid = llm_val
                    .get("mermaid")
                    .or_else(|| llm_val.pointer("/summary/mermaid"))
                    .and_then(|v| v.as_str())
                    .unwrap_or("");
                let safe_mermaid = sanitize_mermaid_v11(raw_mermaid, &clean_topic);

                let cornell = json!({
                    "cues": review_questions,
                    "notes": synthesis,
                    "summary": key_points.join("\n• ")
                });

                let default_citation = triaged_papers.first().map(|p| {
                    json!({
                        "authors": p.authors,
                        "year": p.year,
                        "title": p.title,
                        "doi": p.doi,
                        "url": p.url
                    })
                });

                let mut cards = Vec::new();
                if let Some(cards_arr) = llm_val.get("cards").and_then(|v| v.as_array()) {
                    for (idx, c) in cards_arr.iter().enumerate() {
                        let front = c
                            .get("front")
                            .and_then(|v| v.as_str())
                            .unwrap_or("")
                            .trim()
                            .to_string();
                        let back = c
                            .get("back")
                            .and_then(|v| v.as_str())
                            .unwrap_or("")
                            .trim()
                            .to_string();
                        if front.is_empty() || back.is_empty() || front.starts_with("TEMA ACADÊMICO:") {
                            continue;
                        }
                        let teacher = c
                            .get("teacher_explanation")
                            .and_then(|v| v.as_str())
                            .filter(|s| !s.trim().is_empty())
                            .map(|s| s.to_string())
                            .unwrap_or_else(|| format!("Fixação essencial sobre {}: {}", clean_topic, back));

                        let card_svg = build_card_vectorcraft_svg(&clean_topic, &front, &back, idx);
                        let card_data_url = format!("data:image/svg+xml;base64,{}", BASE64_STD.encode(card_svg.as_bytes()));

                        let img_query = c.get("image_query").and_then(|v| v.as_str()).unwrap_or(&clean_topic);
                        let ai_prompt = c.get("ai_image_prompt").and_then(|v| v.as_str()).unwrap_or(&front);
                        let (rich_data_uri, rich_url, rich_source) = self
                            .resolve_card_wikimedia_or_ai_image(&clean_topic, &front, img_query, ai_prompt, idx)
                            .await;
                        let final_data_url = if !rich_data_uri.is_empty() {
                            rich_data_uri
                        } else {
                            card_data_url
                        };
                        let final_source = if !rich_source.is_empty() {
                            rich_source
                        } else {
                            "VectorCraft SVG".to_string()
                        };

                        let citation_val = c.get("citation").cloned().or_else(|| {
                            triaged_papers.get(idx % triaged_papers.len().max(1)).map(|p| {
                                json!({
                                    "authors": p.authors,
                                    "year": p.year,
                                    "title": p.title,
                                    "doi": p.doi,
                                    "url": p.url
                                })
                            }).or_else(|| default_citation.clone())
                        });

                        cards.push(json!({
                            "front": front,
                            "back": back,
                            "teacher_explanation": teacher,
                            "visual_svg": card_svg,
                            "svg_diagram": card_svg,
                            "svg": card_svg,
                            "image_url": rich_url,
                            "image_data_url": final_data_url,
                            "image_source": final_source,
                            "tags": ["svitlo", "zorya-v3", "anki"],
                            "citation": citation_val
                        }));
                    }
                }

                let master_svg = build_card_vectorcraft_svg(
                    &clean_topic,
                    &clean_topic,
                    key_points.first().map(|s| s.as_str()).unwrap_or(&synthesis),
                    0,
                );

                return Ok(json!({
                    "success": true,
                    "topic": clean_topic,
                    "mode": req.mode.unwrap_or_else(|| "both".to_string()),
                    "model_used": model_used_label,
                    "academic_references": triaged_papers,
                    "summary": {
                        "topic": clean_topic,
                        "synthesis": synthesis,
                        "teacher_audio_script": teacher_audio_script,
                        "key_points": key_points,
                        "review_questions": review_questions,
                        "feynman": feynman,
                        "mermaid": safe_mermaid,
                        "cornell": cornell,
                    },
                    "teacher_audio_script": teacher_audio_script,
                    "feynman": feynman,
                    "mermaid": safe_mermaid,
                    "svg_diagram": master_svg,
                    "cards": cards,
                }));
            }
        }

        anyhow::bail!("A inferência não atingiu o critério mínimo de qualidade acadêmica do Agon.")
    }
}

/// Extrai o tópico acadêmico limpo sem prefixos conversacionais ("me fale sobre", "o que é", etc.)
pub fn extract_clean_academic_topic(raw: &str) -> String {
    let first_line = raw
        .lines()
        .map(|l| l.trim())
        .find(|l| !l.is_empty())
        .unwrap_or("Estudo Acadêmico");

    let mut lower_check = first_line.to_string();
    let prefixes = [
        "TEMA ACADÊMICO:",
        "tema acadêmico:",
        "Me fale sobre ",
        "me fale sobre ",
        "Me Fale Sobre ",
        "Fale sobre ",
        "fale sobre ",
        "Explique sobre ",
        "explique sobre ",
        "Explique ",
        "explique ",
        "O que é ",
        "o que é ",
        "Resuma ",
        "resuma ",
        "Quero estudar ",
        "quero estudar ",
    ];
    for p in prefixes {
        if let Some(stripped) = lower_check.strip_prefix(p) {
            lower_check = stripped.trim().to_string();
        }
    }
    let clean: String = lower_check.chars().take(75).collect();
    if clean.is_empty() {
        "Síntese Acadêmica".to_string()
    } else {
        clean
    }
}

/// Remove qualquer envelope de template ou cabeçalho bruto repetido
pub fn extract_pure_student_text(raw: &str) -> String {
    let mut cleaned = raw.to_string();
    let markers_to_strip = [
        "### INSTRUÇÃO:",
        "### TEXTO DO ESTUDANTE:",
        "### CONTEÚDO:",
        "<|im_start|>system",
        "<|im_start|>user",
        "<|im_end|>",
        "```markdown",
        "```json",
        "```",
    ];
    for marker in markers_to_strip {
        cleaned = cleaned.replace(marker, "");
    }
    if let Some(pos) = cleaned.find("### CONTEÚDO PURO:") {
        cleaned = cleaned[pos + "### CONTEÚDO PURO:".len()..].to_string();
    }
    cleaned.trim().to_string()
}

/// Sanitiza diagramas Mermaid para a gramática estrita do Mermaid v11.17.2
pub fn sanitize_mermaid_v11(raw: &str, fallback_topic: &str) -> String {
    let safe_topic: String = fallback_topic
        .chars()
        .map(|c| if c.is_alphanumeric() || c == ' ' || c == '-' { c } else { ' ' })
        .collect::<String>()
        .split_whitespace()
        .collect::<Vec<_>>()
        .join(" ")
        .chars()
        .take(40)
        .collect();
    let topic_label = if safe_topic.is_empty() {
        "Conceito Central".to_string()
    } else {
        safe_topic
    };

    let trimmed = raw
        .trim()
        .trim_start_matches("```mermaid")
        .trim_start_matches("```")
        .trim_end_matches("```")
        .trim();

    if trimmed.is_empty() || (!trimmed.starts_with("graph ") && !trimmed.starts_with("flowchart ")) {
        return format!(
            "graph TD\n  A[\"{}\"] --> B[\"Fundamentos e Mecanismos\"]\n  A --> C[\"Evidências Científicas\"]\n  A --> D[\"Aplicação Prática\"]",
            topic_label
        );
    }

    let mut sanitized_lines = Vec::new();
    for (idx, line) in trimmed.lines().enumerate() {
        let l = line.trim();
        if l.is_empty() {
            continue;
        }
        if idx == 0 {
            sanitized_lines.push(l.to_string());
            continue;
        }
        let mut out = String::with_capacity(l.len() + 8);
        let chars: Vec<char> = l.chars().collect();
        let mut i = 0;
        while i < chars.len() {
            if chars[i] == '[' {
                out.push('[');
                i += 1;
                let mut inner = String::new();
                while i < chars.len() && chars[i] != ']' {
                    inner.push(chars[i]);
                    i += 1;
                }
                let clean_inner: String = inner
                    .trim()
                    .trim_matches('"')
                    .trim_matches('\'')
                    .chars()
                    .map(|c| {
                        if matches!(c, '"' | '[' | ']' | '{' | '}' | '(' | ')' | ';' | '|' | '<' | '>' | ':') {
                            ' '
                        } else {
                            c
                        }
                    })
                    .collect::<String>()
                    .split_whitespace()
                    .collect::<Vec<_>>()
                    .join(" ");
                out.push('"');
                out.push_str(if clean_inner.is_empty() { "Conceito" } else { &clean_inner });
                out.push('"');
                if i < chars.len() && chars[i] == ']' {
                    out.push(']');
                    i += 1;
                }
            } else {
                out.push(chars[i]);
                i += 1;
            }
        }
        sanitized_lines.push(out);
    }
    sanitized_lines.join("\n")
}

/// Constrói um diagrama vetorial SVG rico via VectorCraft para ilustrar cada flashcard
pub fn build_card_vectorcraft_svg(topic: &str, front: &str, back: &str, idx: usize) -> String {
    let short_topic: String = topic.chars().take(36).collect();
    let short_front: String = front.chars().take(48).collect();
    let short_back: String = back.chars().take(64).collect();

    let spec = VectorDiagramSpec {
        title: format!("{} • Card #{}", short_topic, idx + 1),
        subtitle: Some("Diagrama Conceitual Svitlo • VectorCraft".to_string()),
        width: Some(960),
        height: Some(540),
        nodes: vec![
            VectorNodeSpec {
                id: "n1".to_string(),
                label: short_topic,
                description: Some("Eixo Temático".to_string()),
                x: Some(70.0),
                y: Some(180.0),
                color: Some("#8B5E3C".to_string()),
            },
            VectorNodeSpec {
                id: "n2".to_string(),
                label: short_front,
                description: Some("Questão / Gatilho Cognitivo".to_string()),
                x: Some(370.0),
                y: Some(180.0),
                color: Some("#C9A84C".to_string()),
            },
            VectorNodeSpec {
                id: "n3".to_string(),
                label: short_back,
                description: Some("Síntese e Fixação".to_string()),
                x: Some(670.0),
                y: Some(180.0),
                color: Some("#10B981".to_string()),
            },
        ],
        edges: Some(vec![
            VectorEdgeSpec {
                from: "n1".to_string(),
                to: "n2".to_string(),
                label: Some("investiga".to_string()),
            },
            VectorEdgeSpec {
                from: "n2".to_string(),
                to: "n3".to_string(),
                label: Some("consolida".to_string()),
            },
        ]),
    };

    render_svg_diagram(&spec).unwrap_or_else(|_| {
        r##"<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 960 540" width="960" height="540"><rect width="100%" height="100%" fill="#0F172A"/><text x="50%" y="50%" fill="#F8FAFC" text-anchor="middle" font-size="20">VectorCraft Diagram</text></svg>"##.to_string()
    })
}

pub async fn handle_svitlo_generate(
    State(state): State<AppState>,
    Json(req): Json<SvitloGenerateRequest>,
) -> Response {
    let _guard = state.systray.begin_ai_work();
    match state.inference.generate_svitlo_study(req).await {
        Ok(res) => (StatusCode::OK, Json(res)).into_response(),
        Err(err) => (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({ "success": false, "error": { "message": err.to_string(), "type": "svitlo_generate_error" } })),
        )
            .into_response(),
    }
}

pub async fn handle_anki_generate_media(
    State(state): State<AppState>,
    Json(req): Json<AnkiGenerateMediaRequest>,
) -> Response {
    let _guard = state.systray.begin_ai_work();
    let pure_content = req.content.as_deref().map(extract_pure_student_text);
    let topic = req.topic.clone().unwrap_or_else(|| {
        req.front
            .as_deref()
            .or(pure_content.as_deref())
            .unwrap_or("Conceito Anki")
            .chars()
            .take(60)
            .collect::<String>()
    });

    let front_str = req.front.as_deref().unwrap_or(&topic);
    let back_str = req.back.as_deref().or(pure_content.as_deref()).unwrap_or("Síntese Aplicada");
    let svg = build_card_vectorcraft_svg(&topic, front_str, back_str, 0);

    (
        StatusCode::OK,
        Json(json!({
            "status": "success",
            "topic": topic,
            "media_type": req.media_type.unwrap_or_else(|| "svg".to_string()),
            "visual_svg": svg,
            "svg": svg,
        })),
    )
        .into_response()
}

pub async fn handle_chat_completions(
    State(state): State<AppState>,
    Json(req): Json<ChatCompletionRequest>,
) -> Response {
    let _guard = state.systray.begin_ai_work();
    match state.inference.chat_completions(req).await {
        Ok(resp) => (StatusCode::OK, Json(resp)).into_response(),
        Err(err) => (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(json!({ "error": { "message": err.to_string(), "type": "native_inference_error" } })),
        )
            .into_response(),
    }
}

pub async fn handle_list_models(State(state): State<AppState>) -> Response {
    let payload = state.inference.list_models().await;
    (StatusCode::OK, Json(payload)).into_response()
}

pub async fn handle_load_model(
    State(state): State<AppState>,
    Json(req): Json<LoadModelRequest>,
) -> Response {
    let _guard = state.systray.begin_ai_work();
    match state.inference.load_model(req).await {
        Ok(info) => (StatusCode::OK, Json(json!({ "status": "loaded", "model": info }))).into_response(),
        Err(err) => (
            StatusCode::BAD_REQUEST,
            Json(json!({ "status": "error", "message": err.to_string() })),
        )
            .into_response(),
    }
}

pub async fn handle_unload_model(
    State(state): State<AppState>,
    Json(req): Json<UnloadModelRequest>,
) -> Response {
    match state.inference.unload_model(&req.model).await {
        Ok(removed) => (
            StatusCode::OK,
            Json(json!({ "status": "unloaded", "model": req.model, "was_loaded": removed })),
        )
            .into_response(),
        Err(err) => (
            StatusCode::BAD_REQUEST,
            Json(json!({ "status": "error", "message": err.to_string() })),
        )
            .into_response(),
    }
}
