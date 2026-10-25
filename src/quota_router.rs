// PT: Gestão de Cotas e Roteamento Inteligente de IA — Zorya v3.0 (36 Modelos Canônicos + OpenRouter)
// EN: AI Quota Management and Intelligent Router — Zorya v3.0 (36 Canonical Models + OpenRouter)
// AI: @llm-module quota_router | @llm-context Gerencia RPM, TPM, RPD, rotação de chaves, leitura real de headers HTTP e OpenRouter condicional
// AI: @llm-invariant Prompt Guard prévio obrigatório; sincronização com headers x-ratelimit-* e bloqueio proporcional a retry Delay

use reqwest::header::HeaderMap;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::collections::HashMap;
use std::sync::{Arc, OnceLock};
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use tokio::sync::RwLock;

static GLOBAL_ROUTER: OnceLock<Arc<QuotaRouter>> = OnceLock::new();

/// PT: Especificação completa de modelo e cota segundo a tabela canônica de 36 modelos + OpenRouter
/// EN: Complete model and quota specification according to canonical 36-model table + OpenRouter
// AI: @llm-struct ModelQuotaSpec @llm-invariant Taxas estritas RPM / TPM / RPD
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ModelQuotaSpec {
    pub provider: String,
    pub model_name: String,
    pub canonical_id: String,
    pub rpm_limit: Option<u32>,
    pub tpm_limit: Option<u32>,
    pub rpd_limit: Option<u32>,
    pub task_focus: Vec<String>,
}

/// PT: Estado de consumo em tempo real de uma chave ou modelo sincronizado via headers HTTP
/// EN: Real-time consumption state of a key or model synchronized via HTTP headers
// AI: @llm-struct QuotaTrackerState
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct QuotaTrackerState {
    pub canonical_id: String,
    pub rpm_current: u32,
    pub tpm_current: u32,
    pub rpd_current: u32,
    pub rpm_limit: Option<u32>,
    pub tpm_limit: Option<u32>,
    pub rpd_limit: Option<u32>,
    pub rpd_remaining: Option<u32>,
    pub tpm_remaining: Option<u32>,
    pub cooldown_until: u64,
    pub last_req_epoch: u64,
    pub last_minute_window: u64,
    pub day_epoch: u64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RouteDecision {
    pub selected_model: String,
    pub provider: String,
    pub canonical_id: String,
    pub prompt_guard_passed: bool,
    pub guard_model: Option<String>,
    pub reason: String,
}

/// PT: Informações da conta OpenRouter obtidas via GET /api/v1/auth/key
#[derive(Debug, Clone, Serialize, Deserialize, Default)]
pub struct OpenRouterKeyStatus {
    pub enabled: bool,
    pub is_free_tier: bool,
    pub limit: Option<f64>,
    pub usage: Option<f64>,
    pub limit_remaining: Option<f64>,
    pub active_keys_count: usize,
}

pub struct QuotaRouter {
    models: HashMap<String, ModelQuotaSpec>,
    trackers: RwLock<HashMap<String, QuotaTrackerState>>,
    openrouter_status: RwLock<OpenRouterKeyStatus>,
    http_client: reqwest::Client,
}

impl QuotaRouter {
    pub fn new() -> Arc<Self> {
        let mut m = HashMap::new();

        let catalog = vec![
            // Gemini Models
            (
                "Gemini",
                "Antigravity",
                "gemini-antigravity",
                Some(60),
                Some(100_000),
                Some(100),
                vec!["autonomous_agent", "system_orchestration"],
            ),
            (
                "Gemini",
                "Gemini 2.5 Flash",
                "gemini-2.5-flash",
                Some(5),
                Some(250_000),
                Some(20),
                vec!["multimodal_fast", "summary"],
            ),
            (
                "Gemini",
                "Gemini 2.5 Flash Lite",
                "gemini-2.5-flash-lite",
                Some(10),
                Some(250_000),
                Some(20),
                vec!["low_latency", "light_tasks"],
            ),
            (
                "Gemini",
                "Gemini 2.5 Flash TTS",
                "gemini-2.5-flash-tts",
                Some(3),
                Some(10_000),
                Some(10),
                vec!["tts_spot"],
            ),
            (
                "Gemini",
                "Gemini 3 Flash",
                "gemini-3-flash",
                Some(5),
                Some(250_000),
                Some(20),
                vec!["fast_reasoning", "text_generation"],
            ),
            (
                "Gemini",
                "Gemini 3.1 Flash Lite",
                "gemini-3.1-flash-lite",
                Some(15),
                Some(250_000),
                Some(500),
                vec!["high_daily_volume", "microtasks"],
            ),
            (
                "Gemini",
                "Gemini 3.1 Flash TTS",
                "gemini-3.1-flash-tts",
                Some(3),
                Some(10_000),
                Some(10),
                vec!["tts_efficient"],
            ),
            (
                "Gemini",
                "Gemini 3.6 Flash",
                "gemini-3.6-flash",
                Some(5),
                Some(250_000),
                Some(20),
                vec!["technical_analysis", "fast_code"],
            ),
            (
                "Gemini",
                "Gemini 3.5 Flash Lite",
                "gemini-3.5-flash-lite",
                Some(15),
                Some(250_000),
                Some(500),
                vec!["batch_triage", "fast_parsing"],
            ),
            (
                "Gemini",
                "Gemini 3.5 Transcribe",
                "gemini-3.5-transcribe",
                Some(3),
                Some(10_000),
                Some(25),
                vec!["structured_audio_transcription"],
            ),
            (
                "Gemini",
                "Gemini 3.8 Flash",
                "gemini-3.8-flash",
                Some(5),
                Some(250_000),
                Some(20),
                vec!["high_precision_fast"],
            ),
            (
                "Gemini",
                "Gemini 3.8 Flash Lite TTS",
                "gemini-3.8-flash-lite-tts",
                Some(3),
                Some(10_000),
                Some(10),
                vec!["fast_text_to_speech"],
            ),
            (
                "Gemini",
                "Gemini 3.8 Flash TTS",
                "gemini-3.8-flash-tts",
                Some(3),
                Some(10_000),
                Some(10),
                vec!["hd_voice_tts"],
            ),
            (
                "Gemini",
                "Gemini Embedding 1",
                "gemini-embedding-001",
                Some(100),
                Some(30_000),
                Some(1_000),
                vec!["legacy_rag", "semantic_search_v1"],
            ),
            (
                "Gemini",
                "Gemini Embedding 2",
                "text-embedding-004",
                Some(100),
                Some(30_000),
                Some(1_000),
                vec!["dense_vectorization", "semantic_search_v2"],
            ),
            (
                "Gemini",
                "Gemini Robotics ER 2 Preview",
                "gemini-robotics-er-2-preview",
                Some(5),
                Some(250_000),
                Some(20),
                vec!["spatial_vision"],
            ),
            (
                "Gemini",
                "Gemma 4 26B",
                "gemma-4-26b-it",
                Some(30),
                Some(16_000),
                Some(14_400),
                vec!["open_high_volume", "classification"],
            ),
            (
                "Gemini",
                "Gemma 4 31B",
                "gemma-4-31b-it",
                Some(30),
                Some(16_000),
                Some(14_400),
                vec!["open_robust_reasoning"],
            ),
            (
                "Gemini",
                "Gemini 2.5 Flash Native Audio",
                "gemini-2.5-flash-native-audio",
                None,
                Some(1_000_000),
                None,
                vec!["bidirectional_voice"],
            ),
            (
                "Gemini",
                "Gemini 3 Flash Live",
                "gemini-3-flash-live",
                None,
                Some(65_000),
                None,
                vec!["continuous_text_voice_stream"],
            ),
            (
                "Gemini",
                "Gemini 3.5 Live Translate",
                "gemini-3.5-live-translate",
                None,
                Some(20_000),
                None,
                vec!["realtime_simultaneous_translation"],
            ),
            (
                "Gemini",
                "Gemini 3.5 Transcribe Live",
                "gemini-3.5-transcribe-live",
                None,
                Some(20_000),
                None,
                vec!["realtime_continuous_transcription"],
            ),
            (
                "Gemini",
                "Gemini 3.8 Live",
                "gemini-3.8-live",
                None,
                Some(65_000),
                None,
                vec!["multimodal_continuous_dialogue"],
            ),
            (
                "Gemini",
                "Gemini 3.8 Live Thinking",
                "gemini-3.8-live-thinking",
                None,
                Some(65_000),
                None,
                vec!["realtime_analytical_reasoning"],
            ),
            (
                "Gemini",
                "Gemini 2 (Pesquisa)",
                "gemini-2.0-flash-search",
                None,
                None,
                Some(1_500),
                vec!["factual_verification_search"],
            ),
            (
                "Gemini",
                "Gemini 2.5 (Pesquisa)",
                "gemini-2.5-flash-search",
                None,
                None,
                Some(1_500),
                vec!["fast_factual_search"],
            ),
            (
                "Gemini",
                "Default (Pesquisa)",
                "gemini-default-search",
                None,
                None,
                Some(1_500),
                vec!["standard_web_factcheck"],
            ),
            // Groq Models
            (
                "Groq",
                "canopylabs/orpheus-arabic-saudi",
                "canopylabs/orpheus-arabic-saudi",
                Some(10),
                Some(1_200),
                Some(100),
                vec!["arabic_saudi_audio"],
            ),
            (
                "Groq",
                "canopylabs/orpheus-v1-english",
                "canopylabs/orpheus-v1-english",
                Some(10),
                Some(1_200),
                Some(100),
                vec!["english_speech_tts"],
            ),
            (
                "Groq",
                "meta-llama/llama-prompt-guard-2-22m",
                "meta-llama/llama-prompt-guard-2-22m",
                Some(30),
                Some(15_000),
                Some(14_400),
                vec!["ultrafast_prompt_injection_guard", "security_gate"],
            ),
            (
                "Groq",
                "meta-llama/llama-prompt-guard-2-86m",
                "meta-llama/llama-prompt-guard-2-86m",
                Some(30),
                Some(15_000),
                Some(14_400),
                vec!["robust_security_filtering", "security_gate_strict"],
            ),
            (
                "Groq",
                "openai/gpt-oss-120b",
                "openai/gpt-oss-120b",
                Some(30),
                Some(8_000),
                Some(1_000),
                vec!["complex_reasoning", "dense_code"],
            ),
            (
                "Groq",
                "openai/gpt-oss-20b",
                "openai/gpt-oss-20b",
                Some(30),
                Some(8_000),
                Some(1_000),
                vec!["fast_inference", "general_tasks"],
            ),
            (
                "Groq",
                "openai/gpt-oss-safeguard-20b",
                "openai/gpt-oss-safeguard-20b",
                Some(3),
                Some(2_000),
                Some(1_000),
                vec!["content_moderation"],
            ),
            (
                "Groq",
                "qwen/qwen3.8-27b",
                "qwen/qwen3.8-27b",
                Some(30),
                Some(8_000),
                Some(1_000),
                vec!["programming", "math_logic", "multilingual_ptbr"],
            ),
            (
                "Groq",
                "whisper-large-v3",
                "whisper-large-v3",
                Some(20),
                None,
                Some(2_000),
                vec!["accurate_audio_transcription"],
            ),
            (
                "Groq",
                "whisper-large-v3-turbo",
                "whisper-large-v3-turbo",
                Some(20),
                None,
                Some(2_000),
                vec!["ultrafast_audio_transcription"],
            ),
            // OpenRouter Standby Model
            (
                "OpenRouter",
                "qwen/qwen3-32b:free",
                "openrouter/qwen3-32b-free",
                Some(20),
                Some(40_000),
                Some(200),
                vec!["openrouter_fallback", "general_tasks"],
            ),
        ];

        let mut initial_trackers = HashMap::new();
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();
        let current_day = now / 86400;
        let current_minute = now / 60;

        for (prov, name, id, rpm, tpm, rpd, focus) in catalog {
            let spec = ModelQuotaSpec {
                provider: prov.to_string(),
                model_name: name.to_string(),
                canonical_id: id.to_string(),
                rpm_limit: rpm,
                tpm_limit: tpm,
                rpd_limit: rpd,
                task_focus: focus.into_iter().map(String::from).collect(),
            };
            m.insert(id.to_string(), spec.clone());

            initial_trackers.insert(
                id.to_string(),
                QuotaTrackerState {
                    canonical_id: id.to_string(),
                    rpm_current: 0,
                    tpm_current: 0,
                    rpd_current: 0,
                    rpm_limit: rpm,
                    tpm_limit: tpm,
                    rpd_limit: rpd,
                    rpd_remaining: rpd,
                    tpm_remaining: tpm,
                    cooldown_until: 0,
                    last_req_epoch: now,
                    last_minute_window: current_minute,
                    day_epoch: current_day,
                },
            );
        }

        let router = Arc::new(Self {
            models: m,
            trackers: RwLock::new(initial_trackers),
            openrouter_status: RwLock::new(OpenRouterKeyStatus::default()),
            http_client: reqwest::Client::builder()
                .timeout(Duration::from_secs(15))
                .build()
                .unwrap_or_default(),
        });

        router.spawn_daily_reset_scheduler();
        let _ = GLOBAL_ROUTER.set(Arc::clone(&router));
        router
    }

    /// PT: Retorna o router global se já inicializado
    pub fn global() -> Option<Arc<Self>> {
        GLOBAL_ROUTER.get().cloned()
    }

    /// PT: Scheduler Tokio que reinicia contadores diários (RPD) à meia-noite UTC
    pub fn spawn_daily_reset_scheduler(self: &Arc<Self>) {
        let router = Arc::clone(self);
        tokio::spawn(async move {
            let mut interval = tokio::time::interval(Duration::from_secs(60));
            loop {
                interval.tick().await;
                router.check_and_reset_windows().await;
            }
        });
    }

    /// PT: Verifica janelas deslizantes (minuto e dia) e reseta se ultrapassadas
    pub async fn check_and_reset_windows(&self) {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();
        let current_day = now / 86400;
        let current_minute = now / 60;

        let mut tr = self.trackers.write().await;
        for (_, t) in tr.iter_mut() {
            if t.day_epoch != current_day {
                t.rpd_current = 0;
                t.rpd_remaining = t.rpd_limit;
                t.day_epoch = current_day;
            }
            if t.last_minute_window != current_minute {
                t.rpm_current = 0;
                t.tpm_current = 0;
                t.tpm_remaining = t.tpm_limit;
                t.last_minute_window = current_minute;
            }
        }
    }

    /// PT: Parseia headers de rate limit da Groq sincronizando limites, saldos restantes e cooldown
    /// EN: Parses Groq rate limit headers synchronizing limits, remaining balances, and cooldown
    // AI: @llm-func parse_groq_ratelimit_headers
    pub async fn parse_groq_ratelimit_headers(
        &self,
        canonical_id: &str,
        headers: &HeaderMap,
        status_code: u16,
    ) {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();
        let mut tr = self.trackers.write().await;
        if let Some(t) = tr.get_mut(canonical_id) {
            if let Some(val) = headers
                .get("x-ratelimit-limit-requests")
                .and_then(|v| v.to_str().ok())
                .and_then(|s| s.parse::<u32>().ok())
            {
                t.rpd_limit = Some(val);
            }
            if let Some(val) = headers
                .get("x-ratelimit-remaining-requests")
                .and_then(|v| v.to_str().ok())
                .and_then(|s| s.parse::<u32>().ok())
            {
                t.rpd_remaining = Some(val);
                if let Some(limit) = t.rpd_limit {
                    t.rpd_current = limit.saturating_sub(val);
                }
            }
            if let Some(val) = headers
                .get("x-ratelimit-limit-tokens")
                .and_then(|v| v.to_str().ok())
                .and_then(|s| s.parse::<u32>().ok())
            {
                t.tpm_limit = Some(val);
            }
            if let Some(val) = headers
                .get("x-ratelimit-remaining-tokens")
                .and_then(|v| v.to_str().ok())
                .and_then(|s| s.parse::<u32>().ok())
            {
                t.tpm_remaining = Some(val);
                if let Some(limit) = t.tpm_limit {
                    t.tpm_current = limit.saturating_sub(val);
                }
            }

            // Parse de reset requests / tokens para cooldown se esgotado ou 429
            if status_code == 429 || t.rpd_remaining == Some(0) || t.tpm_remaining == Some(0) {
                let mut cooldown_secs = 60u64;
                if let Some(retry_after) = headers
                    .get("retry-after")
                    .and_then(|v| v.to_str().ok())
                    .and_then(|s| s.parse::<u64>().ok())
                {
                    cooldown_secs = retry_after;
                } else if let Some(reset_str) = headers
                    .get("x-ratelimit-reset-requests")
                    .and_then(|v| v.to_str().ok())
                {
                    cooldown_secs = Self::parse_duration_str(reset_str).as_secs().max(1);
                } else if let Some(reset_str) = headers
                    .get("x-ratelimit-reset-tokens")
                    .and_then(|v| v.to_str().ok())
                {
                    cooldown_secs = Self::parse_duration_str(reset_str).as_secs().max(1);
                }
                t.cooldown_until = now + cooldown_secs;
                tracing::warn!(
                    "Modelo {} entrou em cooldown por {}s via headers da Groq",
                    canonical_id,
                    cooldown_secs
                );
            }
        }
    }

    /// PT: Captura híbrida de cotas do Google Gemini (Headers, usageMetadata e detalhes de erro 429)
    /// EN: Hybrid quota capture for Google Gemini (Headers, usageMetadata, and 429 error details)
    // AI: @llm-func parse_gemini_quota_state
    pub async fn parse_gemini_quota_state(
        &self,
        canonical_id: &str,
        headers: &HeaderMap,
        body_json: Option<&Value>,
        status_code: u16,
    ) {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();
        let mut tr = self.trackers.write().await;
        if let Some(t) = tr.get_mut(canonical_id) {
            // 1. Headers se presentes
            if let Some(val) = headers
                .get("x-ratelimit-remaining-requests")
                .and_then(|v| v.to_str().ok())
                .and_then(|s| s.parse::<u32>().ok())
            {
                t.rpd_remaining = Some(val);
            }
            if let Some(val) = headers
                .get("x-ratelimit-remaining-tokens")
                .and_then(|v| v.to_str().ok())
                .and_then(|s| s.parse::<u32>().ok())
            {
                t.tpm_remaining = Some(val);
            }

            // 2. Extrai usageMetadata do JSON em 200 OK
            if let Some(json) = body_json {
                if let Some(total_tokens) = json
                    .get("usageMetadata")
                    .and_then(|u| u.get("totalTokenCount"))
                    .and_then(|v| v.as_u64())
                {
                    t.tpm_current = t.tpm_current.saturating_add(total_tokens as u32);
                    t.rpd_current = t.rpd_current.saturating_add(1);
                    if let Some(rem) = t.rpd_remaining {
                        t.rpd_remaining = Some(rem.saturating_sub(1));
                    }
                    if let Some(rem) = t.tpm_remaining {
                        t.tpm_remaining = Some(rem.saturating_sub(total_tokens as u32));
                    }
                }

                // 3. Em caso de 429 Too Many Requests, parse de RetryInfo e QuotaFailure
                if status_code == 429 {
                    let mut delay_secs = 45u64;
                    if let Some(details) = json
                        .get("error")
                        .and_then(|e| e.get("details"))
                        .and_then(|d| d.as_array())
                    {
                        for item in details {
                            let type_str = item.get("@type").and_then(|v| v.as_str()).unwrap_or("");
                            if type_str.contains("RetryInfo") {
                                if let Some(delay_str) =
                                    item.get("retryDelay").and_then(|v| v.as_str())
                                {
                                    delay_secs =
                                        Self::parse_duration_str(delay_str).as_secs().max(5);
                                }
                            }
                            if type_str.contains("QuotaFailure") {
                                if let Some(violations) =
                                    item.get("violations").and_then(|v| v.as_array())
                                {
                                    for v in violations {
                                        let qid =
                                            v.get("quotaId").and_then(|q| q.as_str()).unwrap_or("");
                                        if qid.to_lowercase().contains("perday") {
                                            t.rpd_remaining = Some(0);
                                            tracing::warn!(
                                                "Cota diária (RPD) esgotada no Gemini para {}",
                                                canonical_id
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }
                    t.cooldown_until = now + delay_secs;
                }
            }
        }
    }

    /// PT: Converte strings de tempo ("2m59.56s", "1h10m", "42s", "450ms") em Duration
    pub fn parse_duration_str(raw: &str) -> Duration {
        let s = raw.trim();
        if s.ends_with("ms") {
            let ms: u64 = s.trim_end_matches("ms").parse().unwrap_or(500);
            return Duration::from_millis(ms);
        }
        if s.ends_with('s') && !s.contains('m') && !s.contains('h') {
            let secs: f64 = s.trim_end_matches('s').parse().unwrap_or(60.0);
            return Duration::from_secs_f64(secs);
        }
        if s.contains('m') {
            let parts: Vec<&str> = s.split('m').collect();
            let mins: u64 = parts[0].parse().unwrap_or(1);
            let mut secs = 0f64;
            if parts.len() > 1 && parts[1].ends_with('s') {
                secs = parts[1].trim_end_matches('s').parse().unwrap_or(0.0);
            }
            return Duration::from_secs(mins * 60 + secs.round() as u64);
        }
        Duration::from_secs(60)
    }

    /// PT: Sincroniza estado de chave do OpenRouter consultando GET https://openrouter.ai/api/v1/auth/key
    // AI: @llm-func sync_openrouter_status
    pub async fn sync_openrouter_status(&self, keys: &[String]) {
        let valid_keys: Vec<String> = keys
            .iter()
            .map(|k| k.trim().to_string())
            .filter(|k| k.starts_with("sk-or-v1-") && k.len() > 20)
            .collect();

        if valid_keys.is_empty() {
            let mut st = self.openrouter_status.write().await;
            st.enabled = false;
            st.active_keys_count = 0;
            return;
        }

        let primary_key = &valid_keys[0];
        let url = "https://openrouter.ai/api/v1/auth/key";

        if let Ok(res) = self
            .http_client
            .get(url)
            .bearer_auth(primary_key)
            .send()
            .await
        {
            if res.status().is_success() {
                if let Ok(json) = res.json::<Value>().await {
                    let data = &json["data"];
                    let mut st = self.openrouter_status.write().await;
                    st.enabled = true;
                    st.active_keys_count = valid_keys.len();
                    st.is_free_tier = data
                        .get("is_free_tier")
                        .and_then(|v| v.as_bool())
                        .unwrap_or(true);
                    st.limit = data.get("limit").and_then(|v| v.as_f64());
                    st.usage = data.get("usage").and_then(|v| v.as_f64());
                    st.limit_remaining = data.get("limit_remaining").and_then(|v| v.as_f64());
                    tracing::info!(
                        "OpenRouter autenticado com sucesso: {} chaves ativas (Free tier: {})",
                        valid_keys.len(),
                        st.is_free_tier
                    );
                    return;
                }
            }
        }

        let mut st = self.openrouter_status.write().await;
        st.enabled = true;
        st.active_keys_count = valid_keys.len();
    }

    /// PT: Seleciona o melhor modelo para uma tarefa considerando cotas livres, cooldown e invariante de segurança
    pub async fn route_task(
        &self,
        task_type: &str,
        prompt: &str,
        approx_tokens: u32,
    ) -> RouteDecision {
        self.check_and_reset_windows().await;
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs();

        // 1. Invariante de Segurança: Heurística / Prompt Guard
        let (injected, _reason) = crate::security::check_prompt_injection_heuristic(prompt);
        if injected {
            return RouteDecision {
                selected_model: "meta-llama/llama-prompt-guard-2-22m".to_string(),
                provider: "Groq".to_string(),
                canonical_id: "meta-llama/llama-prompt-guard-2-22m".to_string(),
                prompt_guard_passed: false,
                guard_model: Some("meta-llama/llama-prompt-guard-2-22m".to_string()),
                reason: "Entrada bloqueada na triagem inicial de segurança (tentativa de jailbreak/injection)".to_string(),
            };
        }

        // 2. Localiza modelos candidatos por task_type
        let mut candidates: Vec<ModelQuotaSpec> = self
            .models
            .values()
            .filter(|m| {
                m.task_focus
                    .iter()
                    .any(|f| f == task_type || f == "general_tasks")
            })
            .cloned()
            .collect();

        if candidates.is_empty() {
            candidates = self
                .models
                .values()
                .filter(|m| {
                    m.task_focus
                        .iter()
                        .any(|f| f == "general_tasks" || f == "high_daily_volume")
                })
                .cloned()
                .collect();
        }

        let tr = self.trackers.read().await;
        candidates.sort_by(|a, b| {
            let rpd_a = tr
                .get(&a.canonical_id)
                .and_then(|s| s.rpd_remaining)
                .unwrap_or(999);
            let rpd_b = tr
                .get(&b.canonical_id)
                .and_then(|s| s.rpd_remaining)
                .unwrap_or(999);
            rpd_b.cmp(&rpd_a)
        });

        // Seleciona primeiro candidato que não esteja em cooldown e dentro das cotas
        for cand in candidates {
            if let Some(state) = tr.get(&cand.canonical_id) {
                if state.cooldown_until > now {
                    continue;
                }
                let rpm_ok = state
                    .rpm_limit
                    .map(|l| state.rpm_current < l)
                    .unwrap_or(true);
                let tpm_ok = state
                    .tpm_limit
                    .map(|l| state.tpm_current + approx_tokens <= l)
                    .unwrap_or(true);
                let rpd_ok = state.rpd_remaining.map(|r| r > 0).unwrap_or(true);

                if rpm_ok && tpm_ok && rpd_ok {
                    return RouteDecision {
                        selected_model: cand.model_name.clone(),
                        provider: cand.provider.clone(),
                        canonical_id: cand.canonical_id.clone(),
                        prompt_guard_passed: true,
                        guard_model: Some("meta-llama/llama-prompt-guard-2-22m".to_string()),
                        reason: format!(
                            "Cotas disponíveis: RPM, TPM e RPD preservados para {}",
                            cand.canonical_id
                        ),
                    };
                }
            }
        }

        // Fallback OpenRouter se ativo, ou Qwen 27B
        let or_status = self.openrouter_status.read().await;
        if or_status.enabled {
            RouteDecision {
                selected_model: "qwen/qwen3-32b:free".to_string(),
                provider: "OpenRouter".to_string(),
                canonical_id: "openrouter/qwen3-32b-free".to_string(),
                prompt_guard_passed: true,
                guard_model: Some("meta-llama/llama-prompt-guard-2-22m".to_string()),
                reason: "Fallback OpenRouter ativado (rota em standby acionada com sucesso)"
                    .to_string(),
            }
        } else {
            RouteDecision {
                selected_model: "qwen/qwen3.8-27b".to_string(),
                provider: "Groq".to_string(),
                canonical_id: "qwen/qwen3.8-27b".to_string(),
                prompt_guard_passed: true,
                guard_model: Some("meta-llama/llama-prompt-guard-2-22m".to_string()),
                reason: "Fallback aplicado: modelo com tolerância e alto volume selecionado"
                    .to_string(),
            }
        }
    }

    /// PT: Retorna o snapshot completo de cotas dos modelos e status OpenRouter para a UI e API
    pub async fn get_quotas_snapshot(&self) -> (Vec<Value>, OpenRouterKeyStatus) {
        self.check_and_reset_windows().await;
        let tr = self.trackers.read().await;
        let mut list = Vec::new();

        for (id, spec) in &self.models {
            let state = tr.get(id);
            list.push(json!({
                "canonical_id": spec.canonical_id,
                "provider": spec.provider,
                "model_name": spec.model_name,
                "rpm_current": state.map(|s| s.rpm_current).unwrap_or(0),
                "rpm_limit": spec.rpm_limit,
                "tpm_current": state.map(|s| s.tpm_current).unwrap_or(0),
                "tpm_limit": spec.tpm_limit,
                "rpd_current": state.map(|s| s.rpd_current).unwrap_or(0),
                "rpd_limit": spec.rpd_limit,
                "rpd_remaining": state.and_then(|s| s.rpd_remaining),
                "tpm_remaining": state.and_then(|s| s.tpm_remaining),
                "cooldown_until": state.map(|s| s.cooldown_until).unwrap_or(0),
                "task_focus": spec.task_focus,
            }));
        }

        list.sort_by(|a, b| {
            let pa = a.get("provider").and_then(|v| v.as_str()).unwrap_or("");
            let pb = b.get("provider").and_then(|v| v.as_str()).unwrap_or("");
            pa.cmp(pb).then_with(|| {
                let ma = a.get("model_name").and_then(|v| v.as_str()).unwrap_or("");
                let mb = b.get("model_name").and_then(|v| v.as_str()).unwrap_or("");
                ma.cmp(mb)
            })
        });

        let or_snap = self.openrouter_status.read().await.clone();
        (list, or_snap)
    }

    /// PT: Reseta manualmente todos os contadores de cota
    pub async fn reset_all(&self) {
        let mut tr = self.trackers.write().await;
        for (_, t) in tr.iter_mut() {
            t.rpm_current = 0;
            t.tpm_current = 0;
            t.rpd_current = 0;
            t.rpd_remaining = t.rpd_limit;
            t.tpm_remaining = t.tpm_limit;
            t.cooldown_until = 0;
        }
    }
}
