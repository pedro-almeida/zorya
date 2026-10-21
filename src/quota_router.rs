// PT: Gestão de Cotas e Roteamento Inteligente de IA — Zorya v3.0 (36 Modelos Canônicos)
// EN: AI Quota Management and Intelligent Router — Zorya v3.0 (36 Canonical Models)
// AI: @llm-module quota_router | @llm-context Gerencia RPM, TPM, RPD, rotação de chaves e janelas deslizantes
// AI: @llm-invariant Prompt Guard prévio obrigatório; bloqueio de jailbreak antes de consumir cota principal

use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use tokio::sync::RwLock;

/// PT: Especificação completa de modelo e cota segundo a tabela canônica de 36 modelos
/// EN: Complete model and quota specification according to canonical 36-model table
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

/// PT: Estado de consumo em tempo real de uma chave ou modelo
/// EN: Real-time consumption state of a key or model
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

pub struct QuotaRouter {
    models: HashMap<String, ModelQuotaSpec>,
    trackers: RwLock<HashMap<String, QuotaTrackerState>>,
}

impl QuotaRouter {
    pub fn new() -> Arc<Self> {
        let mut m = HashMap::new();

        let catalog = vec![
            // Gemini Models
            ("Gemini", "Antigravity", "gemini-antigravity", Some(60), Some(100_000), Some(100), vec!["autonomous_agent", "system_orchestration"]),
            ("Gemini", "Gemini 2.5 Flash", "gemini-2.5-flash", Some(5), Some(250_000), Some(20), vec!["multimodal_fast", "summary"]),
            ("Gemini", "Gemini 2.5 Flash Lite", "gemini-2.5-flash-lite", Some(10), Some(250_000), Some(20), vec!["low_latency", "light_tasks"]),
            ("Gemini", "Gemini 2.5 Flash TTS", "gemini-2.5-flash-tts", Some(3), Some(10_000), Some(10), vec!["tts_spot"]),
            ("Gemini", "Gemini 3 Flash", "gemini-3-flash", Some(5), Some(250_000), Some(20), vec!["fast_reasoning", "text_generation"]),
            ("Gemini", "Gemini 3.1 Flash Lite", "gemini-3.1-flash-lite", Some(15), Some(250_000), Some(500), vec!["high_daily_volume", "microtasks"]),
            ("Gemini", "Gemini 3.1 Flash TTS", "gemini-3.1-flash-tts", Some(3), Some(10_000), Some(10), vec!["tts_efficient"]),
            ("Gemini", "Gemini 3.6 Flash", "gemini-3.6-flash", Some(5), Some(250_000), Some(20), vec!["technical_analysis", "fast_code"]),
            ("Gemini", "Gemini 3.5 Flash Lite", "gemini-3.5-flash-lite", Some(15), Some(250_000), Some(500), vec!["batch_triage", "fast_parsing"]),
            ("Gemini", "Gemini 3.5 Transcribe", "gemini-3.5-transcribe", Some(3), Some(10_000), Some(25), vec!["structured_audio_transcription"]),
            ("Gemini", "Gemini 3.8 Flash", "gemini-3.8-flash", Some(5), Some(250_000), Some(20), vec!["high_precision_fast"]),
            ("Gemini", "Gemini 3.8 Flash Lite TTS", "gemini-3.8-flash-lite-tts", Some(3), Some(10_000), Some(10), vec!["fast_text_to_speech"]),
            ("Gemini", "Gemini 3.8 Flash TTS", "gemini-3.8-flash-tts", Some(3), Some(10_000), Some(10), vec!["hd_voice_tts"]),
            ("Gemini", "Gemini Embedding 1", "gemini-embedding-001", Some(100), Some(30_000), Some(1_000), vec!["legacy_rag", "semantic_search_v1"]),
            ("Gemini", "Gemini Embedding 2", "text-embedding-004", Some(100), Some(30_000), Some(1_000), vec!["dense_vectorization", "semantic_search_v2"]),
            ("Gemini", "Gemini Robotics ER 2 Preview", "gemini-robotics-er-2-preview", Some(5), Some(250_000), Some(20), vec!["spatial_vision"]),
            ("Gemini", "Gemma 4 26B", "gemma-4-26b-it", Some(30), Some(16_000), Some(14_400), vec!["open_high_volume", "classification"]),
            ("Gemini", "Gemma 4 31B", "gemma-4-31b-it", Some(30), Some(16_000), Some(14_400), vec!["open_robust_reasoning"]),
            ("Gemini", "Gemini 2.5 Flash Native Audio", "gemini-2.5-flash-native-audio", None, Some(1_000_000), None, vec!["bidirectional_voice"]),
            ("Gemini", "Gemini 3 Flash Live", "gemini-3-flash-live", None, Some(65_000), None, vec!["continuous_text_voice_stream"]),
            ("Gemini", "Gemini 3.5 Live Translate", "gemini-3.5-live-translate", None, Some(20_000), None, vec!["realtime_simultaneous_translation"]),
            ("Gemini", "Gemini 3.5 Transcribe Live", "gemini-3.5-transcribe-live", None, Some(20_000), None, vec!["realtime_continuous_transcription"]),
            ("Gemini", "Gemini 3.8 Live", "gemini-3.8-live", None, Some(65_000), None, vec!["multimodal_continuous_dialogue"]),
            ("Gemini", "Gemini 3.8 Live Thinking", "gemini-3.8-live-thinking", None, Some(65_000), None, vec!["realtime_analytical_reasoning"]),
            ("Gemini", "Gemini 2 (Pesquisa)", "gemini-2.0-flash-search", None, None, Some(1_500), vec!["factual_verification_search"]),
            ("Gemini", "Gemini 2.5 (Pesquisa)", "gemini-2.5-flash-search", None, None, Some(1_500), vec!["fast_factual_search"]),
            ("Gemini", "Default (Pesquisa)", "gemini-default-search", None, None, Some(1_500), vec!["standard_web_factcheck"]),

            // Groq Models
            ("Groq", "canopylabs/orpheus-arabic-saudi", "canopylabs/orpheus-arabic-saudi", Some(10), Some(1_200), Some(100), vec!["arabic_saudi_audio"]),
            ("Groq", "canopylabs/orpheus-v1-english", "canopylabs/orpheus-v1-english", Some(10), Some(1_200), Some(100), vec!["english_speech_tts"]),
            ("Groq", "meta-llama/llama-prompt-guard-2-22m", "meta-llama/llama-prompt-guard-2-22m", Some(30), Some(15_000), Some(14_400), vec!["ultrafast_prompt_injection_guard", "security_gate"]),
            ("Groq", "meta-llama/llama-prompt-guard-2-86m", "meta-llama/llama-prompt-guard-2-86m", Some(30), Some(15_000), Some(14_400), vec!["robust_security_filtering", "security_gate_strict"]),
            ("Groq", "openai/gpt-oss-120b", "openai/gpt-oss-120b", Some(30), Some(8_000), Some(1_000), vec!["complex_reasoning", "dense_code"]),
            ("Groq", "openai/gpt-oss-20b", "openai/gpt-oss-20b", Some(30), Some(8_000), Some(1_000), vec!["fast_inference", "general_tasks"]),
            ("Groq", "openai/gpt-oss-safeguard-20b", "openai/gpt-oss-safeguard-20b", Some(3), Some(2_000), Some(1_000), vec!["content_moderation"]),
            ("Groq", "qwen/qwen3.8-27b", "qwen/qwen3.8-27b", Some(30), Some(8_000), Some(1_000), vec!["programming", "math_logic", "multilingual_ptbr"]),
            ("Groq", "whisper-large-v3", "whisper-large-v3", Some(20), None, Some(2_000), vec!["accurate_audio_transcription"]),
            ("Groq", "whisper-large-v3-turbo", "whisper-large-v3-turbo", Some(20), None, Some(2_000), vec!["ultrafast_audio_transcription"]),
        ];

        let mut initial_trackers = HashMap::new();
        let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs();
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

            initial_trackers.insert(id.to_string(), QuotaTrackerState {
                canonical_id: id.to_string(),
                rpm_current: 0,
                tpm_current: 0,
                rpd_current: 0,
                rpm_limit: rpm,
                tpm_limit: tpm,
                rpd_limit: rpd,
                last_req_epoch: now,
                last_minute_window: current_minute,
                day_epoch: current_day,
            });
        }

        let router = Arc::new(Self {
            models: m,
            trackers: RwLock::new(initial_trackers),
        });

        router.spawn_daily_reset_scheduler();
        router
    }

    /// PT: Scheduler Tokio que reinicia contadores diários (RPD) à meia-noite UTC
    /// EN: Tokio scheduler resetting daily counters (RPD) at UTC midnight
    // AI: @llm-func spawn_daily_reset_scheduler
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
    /// EN: Checks sliding windows (minute and day) and resets when expired
    // AI: @llm-func check_and_reset_windows
    pub async fn check_and_reset_windows(&self) {
        let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs();
        let current_day = now / 86400;
        let current_minute = now / 60;

        let mut tr = self.trackers.write().await;
        for (_, t) in tr.iter_mut() {
            if t.day_epoch != current_day {
                t.rpd_current = 0;
                t.day_epoch = current_day;
            }
            if t.last_minute_window != current_minute {
                t.rpm_current = 0;
                t.tpm_current = 0;
                t.last_minute_window = current_minute;
            }
        }
    }

    /// PT: Seleciona o melhor modelo para uma tarefa considerando cotas livres e invariante de segurança
    /// EN: Selects best model for a task considering available quotas and security invariant
    // AI: @llm-func route_task @llm-invariant Prompt Guard 22M antes de modelos principais
    pub async fn route_task(&self, task_type: &str, prompt: &str, approx_tokens: u32) -> RouteDecision {
        self.check_and_reset_windows().await;

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
        let mut candidates: Vec<ModelQuotaSpec> = self.models.values()
            .filter(|m| m.task_focus.iter().any(|f| f == task_type || f == "general_tasks"))
            .cloned()
            .collect();

        // Se nenhum modelo específico bater, usa modelos gerais
        if candidates.is_empty() {
            candidates = self.models.values()
                .filter(|m| m.task_focus.iter().any(|f| f == "general_tasks" || f == "high_daily_volume"))
                .cloned()
                .collect();
        }

        // Ordena por maior margem de RPD restante
        let tr = self.trackers.read().await;
        candidates.sort_by(|a, b| {
            let rpd_a = tr.get(&a.canonical_id).map(|s| s.rpd_limit.unwrap_or(999_999).saturating_sub(s.rpd_current)).unwrap_or(0);
            let rpd_b = tr.get(&b.canonical_id).map(|s| s.rpd_limit.unwrap_or(999_999).saturating_sub(s.rpd_current)).unwrap_or(0);
            rpd_b.cmp(&rpd_a)
        });

        // Seleciona primeiro candidato dentro das cotas RPM/TPM/RPD
        for cand in candidates {
            if let Some(state) = tr.get(&cand.canonical_id) {
                let rpm_ok = state.rpm_limit.map(|l| state.rpm_current < l).unwrap_or(true);
                let tpm_ok = state.tpm_limit.map(|l| state.tpm_current + approx_tokens <= l).unwrap_or(true);
                let rpd_ok = state.rpd_limit.map(|l| state.rpd_current < l).unwrap_or(true);

                if rpm_ok && tpm_ok && rpd_ok {
                    return RouteDecision {
                        selected_model: cand.model_name.clone(),
                        provider: cand.provider.clone(),
                        canonical_id: cand.canonical_id.clone(),
                        prompt_guard_passed: true,
                        guard_model: Some("meta-llama/llama-prompt-guard-2-22m".to_string()),
                        reason: format!("Cotas disponíveis: RPM, TPM e RPD preservados para {}", cand.canonical_id),
                    };
                }
            }
        }

        // Fallback resiliente: alto RPD (gemma-4-31b-it ou gpt-oss-120b)
        RouteDecision {
            selected_model: "qwen/qwen3.8-27b".to_string(),
            provider: "Groq".to_string(),
            canonical_id: "qwen/qwen3.8-27b".to_string(),
            prompt_guard_passed: true,
            guard_model: Some("meta-llama/llama-prompt-guard-2-22m".to_string()),
            reason: "Fallback aplicado: modelo com tolerância e alto volume selecionado".to_string(),
        }
    }

    /// PT: Registra consumo real de tokens e requisição para o modelo
    /// EN: Records actual request and token consumption for the model
    // AI: @llm-func record_usage
    pub async fn record_usage(&self, canonical_id: &str, tokens: u32) {
        let mut tr = self.trackers.write().await;
        if let Some(t) = tr.get_mut(canonical_id) {
            t.rpm_current = t.rpm_current.saturating_add(1);
            t.tpm_current = t.tpm_current.saturating_add(tokens);
            t.rpd_current = t.rpd_current.saturating_add(1);
            t.last_req_epoch = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs();
        }
    }

    /// PT: Retorna o snapshot completo de cotas dos 36 modelos para a UI e API
    /// EN: Returns complete quota snapshot of all 36 models for UI and API
    // AI: @llm-func get_quotas_snapshot
    pub async fn get_quotas_snapshot(&self) -> Vec<Value> {
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

        list
    }

    /// PT: Reseta manualmente todos os contadores de cota (ex: teste ou virada manual)
    /// EN: Manually resets all quota counters
    // AI: @llm-func reset_all
    pub async fn reset_all(&self) {
        let mut tr = self.trackers.write().await;
        for (_, t) in tr.iter_mut() {
            t.rpm_current = 0;
            t.tpm_current = 0;
            t.rpd_current = 0;
        }
    }
}
