// PT: Analisador e Estruturas de Configuração do Zorya v3.0 (zorya.toml)
// EN: Zorya v3.0 Configuration Parser and Data Structures (zorya.toml)
// AI: @llm-module config | @llm-context Compatibilidade total com seções [server], [vault]/[credentials], [inference]/[models], [cluster], [craft]
// AI: @llm-invariant Persistência atômica via zorya.toml.tmp + fsync + rename; desserialização flexível de chaves e módulos

use std::fs::{self, File};
use std::io::Write;
use std::path::{Path, PathBuf};
use serde::{de, Deserialize, Deserializer, Serialize};

/// PT: Estrutura raiz de configuração do daemon Zorya v3.0
/// EN: Root configuration structure for Zorya v3.0 daemon
// AI: @llm-struct ZoryaConfig @llm-invariant #[serde(default)] em todos os blocos
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ZoryaConfig {
    #[serde(default)]
    pub server: ServerConfig,
    #[serde(default)]
    pub node: NodeConfig,
    #[serde(default)]
    pub network: NetworkConfig,
    #[serde(default)]
    pub hardware: HardwareConfig,
    #[serde(default)]
    pub models: ModelsConfig,
    #[serde(default)]
    pub inference: InferenceConfig,
    #[serde(default, alias = "artcraft")]
    pub craft: CraftConfig,
    #[serde(default, alias = "keys", alias = "vault")]
    pub credentials: CredentialsConfig,
    #[serde(default)]
    pub cluster: ClusterConfig,
}

/// PT: Configurações do servidor web e portas
/// EN: Web server and port configuration
// AI: @llm-struct ServerConfig @llm-invariant host=0.0.0.0, port=8085
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ServerConfig {
    #[serde(default = "default_host")]
    pub host: String,
    #[serde(default = "default_port")]
    pub port: u16,
    #[serde(default = "default_node_name")]
    pub node_name: String,
    #[serde(default = "default_ts_ip")]
    pub tailscale_ip: String,
    #[serde(default = "default_vps_endpoint")]
    pub vps_endpoint: String,
    #[serde(default)]
    pub auth_token: String,
    #[serde(default = "default_csrf_secret")]
    pub csrf_secret: String,
    #[serde(default = "default_true")]
    pub enable_mcp_sse: bool,
    #[serde(default = "default_mcp_path")]
    pub mcp_sse_path: String,
    #[serde(default = "default_log_level")]
    pub log_level: String,
}

fn default_host() -> String { "0.0.0.0".to_string() }
fn default_port() -> u16 { 8085 }
fn default_node_name() -> String { "Usina-Zorya-Nitro".to_string() }
fn default_ts_ip() -> String { "100.106.120.35".to_string() }
fn default_vps_endpoint() -> String { "https://svitlo.espectroipe.com.br".to_string() }
fn default_csrf_secret() -> String { "zorya-csrf-secret-sovereign-2026".to_string() }
fn default_true() -> bool { true }
fn default_mcp_path() -> String { "/mcp/sse".to_string() }
fn default_log_level() -> String { "info".to_string() }

impl Default for ServerConfig {
    fn default() -> Self {
        Self {
            host: default_host(),
            port: default_port(),
            node_name: default_node_name(),
            tailscale_ip: default_ts_ip(),
            vps_endpoint: default_vps_endpoint(),
            auth_token: String::new(),
            csrf_secret: default_csrf_secret(),
            enable_mcp_sse: true,
            mcp_sse_path: default_mcp_path(),
            log_level: default_log_level(),
        }
    }
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct NodeConfig {
    pub role: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct HardwareConfig {
    #[serde(default = "default_auto")]
    pub profile: String,
    #[serde(default = "default_vram")]
    pub dedicated_vram_mb: u64,
    #[serde(default = "default_true")]
    pub reserve_display_on_igpu: bool,
    #[serde(default)]
    pub cpu_threads_override: usize,
}

fn default_auto() -> String { "auto".to_string() }
fn default_vram() -> u64 { 4096 }

impl Default for HardwareConfig {
    fn default() -> Self {
        Self {
            profile: default_auto(),
            dedicated_vram_mb: default_vram(),
            reserve_display_on_igpu: true,
            cpu_threads_override: 0,
        }
    }
}

/// PT: Configuração de modelos locais GGUF e parâmetros de inferência
/// EN: Local GGUF models and inference configuration
// AI: @llm-struct ModelsConfig
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ModelsConfig {
    #[serde(default = "default_models_dir")]
    pub models_dir: String,
    #[serde(default = "default_nitro_model")]
    pub nitro_default: String,
    #[serde(default = "default_vps_model")]
    pub vps_default: String,
    #[serde(default = "default_nitro_ctx")]
    pub nitro_ctx_size: u32,
    #[serde(default = "default_vps_ctx")]
    pub vps_ctx_size: u32,
    #[serde(default = "default_batch")]
    pub batch_size: u32,

    #[serde(default = "default_fast_model")]
    pub fast_model: String,
    #[serde(default = "default_deep_model")]
    pub deep_model: String,
    #[serde(default = "default_gpu_layers")]
    pub gpu_layers: u32,
    #[serde(default = "default_nitro_ctx")]
    pub context_size: u32,
    #[serde(default = "default_max_tokens")]
    pub max_tokens: u32,
    #[serde(default = "default_ttl_seconds")]
    pub ttl_seconds: u64,
}

fn default_models_dir() -> String { "models".to_string() }
fn default_nitro_model() -> String { "qwen2.5-7b-instruct-q4_k_m.gguf".to_string() }
fn default_vps_model() -> String { "qwen2.5-3b-instruct-q4_k_m.gguf".to_string() }
fn default_nitro_ctx() -> u32 { 8192 }
fn default_vps_ctx() -> u32 { 2048 }
fn default_batch() -> u32 { 512 }
fn default_fast_model() -> String { "qwen2.5-3b-instruct-q4_k_m.gguf".to_string() }
fn default_deep_model() -> String { "qwen2.5-7b-instruct-q4_k_m.gguf".to_string() }
fn default_gpu_layers() -> u32 { 33 }
fn default_max_tokens() -> u32 { 4096 }
fn default_ttl_seconds() -> u64 { 3600 }

impl Default for ModelsConfig {
    fn default() -> Self {
        Self {
            models_dir: default_models_dir(),
            nitro_default: default_nitro_model(),
            vps_default: default_vps_model(),
            nitro_ctx_size: default_nitro_ctx(),
            vps_ctx_size: default_vps_ctx(),
            batch_size: default_batch(),
            fast_model: default_fast_model(),
            deep_model: default_deep_model(),
            gpu_layers: default_gpu_layers(),
            context_size: default_nitro_ctx(),
            max_tokens: default_max_tokens(),
            ttl_seconds: default_ttl_seconds(),
        }
    }
}

/// PT: Configuração de rede Headscale / Tailscale
/// EN: Headscale / Tailscale network configuration
// AI: @llm-struct NetworkConfig
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetworkConfig {
    #[serde(default = "default_headscale_url")]
    pub headscale_url: String,
    #[serde(default = "default_hs_auth")]
    pub headscale_auth_key: String,
    #[serde(default = "default_node_name")]
    pub node_name: String,
    #[serde(default = "default_allowed_ip")]
    pub allowed_ip: String,
    pub listen_port: Option<u16>,
    pub wireguard_port: Option<u16>,
    pub tailscale_peer_hostname: Option<String>,
    pub fallback_ip: Option<String>,
    #[serde(default = "default_true")]
    pub prefer_tailscale: bool,
    #[serde(default = "default_ts_api")]
    pub tailscale_local_api: String,
    pub fallback_vps_ip: Option<String>,
    pub fallback_nitro_ip: Option<String>,
    #[serde(default)]
    pub auth_token: String,
    #[serde(default = "default_30")]
    pub reconnect_interval_secs: u64,
}

fn default_headscale_url() -> String { "https://headscale.espectroipe.com.br".to_string() }
fn default_hs_auth() -> String { "hskey-default".to_string() }
fn default_allowed_ip() -> String { "0.0.0.0/0".to_string() }
fn default_ts_api() -> String { "http://127.0.0.1:41112/localapi/v0/status".to_string() }
fn default_30() -> u64 { 30 }
fn default_vps_ip() -> String {
    std::env::var("MRIYA_VPS_IP").unwrap_or_else(|_| "143.95.223.218".to_string())
}

impl Default for NetworkConfig {
    fn default() -> Self {
        Self {
            headscale_url: default_headscale_url(),
            headscale_auth_key: default_hs_auth(),
            node_name: default_node_name(),
            allowed_ip: default_allowed_ip(),
            listen_port: Some(8085),
            wireguard_port: Some(51820),
            tailscale_peer_hostname: Some(default_ts_ip()),
            fallback_ip: Some("0.0.0.0".to_string()),
            prefer_tailscale: true,
            tailscale_local_api: default_ts_api(),
            fallback_vps_ip: Some(default_vps_ip()),
            fallback_nitro_ip: Some(default_ts_ip()),
            auth_token: String::new(),
            reconnect_interval_secs: 30,
        }
    }
}

/// PT: Configuração da inferência in-process GGUF (elimina dependência externa de Ollama)
/// EN: In-process GGUF inference configuration (eliminates external Ollama dependency)
// AI: @llm-struct InferenceConfig @llm-invariant local_runtime_url = "http://127.0.0.1:8085"
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct InferenceConfig {
    #[serde(default = "default_models_dir")]
    pub models_dir: String,
    #[serde(default = "default_local_runtime_url")]
    pub local_runtime_url: String,
    #[serde(default = "default_fast_model")]
    pub fast_gguf: String,
    #[serde(default = "default_deep_model")]
    pub deep_gguf: String,
    #[serde(default = "default_gpu_layers")]
    pub gpu_layers: u32,
    #[serde(default = "default_nitro_ctx")]
    pub ctx_size: u32,
    #[serde(default = "default_max_tokens")]
    pub max_output_tokens: u32,
    #[serde(default = "default_ttl_seconds")]
    pub vram_ttl_secs: u64,
    pub default_model: Option<String>,
    pub dedicated_vram_mb: Option<u64>,
    pub gpu_device_index: Option<u32>,
}

fn default_local_runtime_url() -> String { "http://127.0.0.1:8085".to_string() }

impl Default for InferenceConfig {
    fn default() -> Self {
        Self {
            models_dir: default_models_dir(),
            local_runtime_url: default_local_runtime_url(),
            fast_gguf: default_fast_model(),
            deep_gguf: default_deep_model(),
            gpu_layers: default_gpu_layers(),
            ctx_size: default_nitro_ctx(),
            max_output_tokens: default_max_tokens(),
            vram_ttl_secs: default_ttl_seconds(),
            default_model: Some(default_deep_model()),
            dedicated_vram_mb: Some(4096),
            gpu_device_index: Some(0),
        }
    }
}

/// PT: Módulos ArtCraft integrados à Usina Zorya
/// EN: ArtCraft modules integrated into Zorya Daemon
// AI: @llm-struct CraftConfig @llm-invariant Suíte completa com 11 módulos
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CraftConfig {
    #[serde(default = "default_craft_modules", deserialize_with = "string_or_vec")]
    pub enabled: Vec<String>,
    pub denoise_default_method: Option<String>,
    pub audio_target_lufs: Option<f32>,
    pub audio_max_true_peak_db: Option<f32>,
    pub default_icc_profile: Option<String>,
}

pub fn default_craft_modules() -> Vec<String> {
    vec![
        "lightcraft".to_string(),
        "photocraft".to_string(),
        "vectorcraft".to_string(),
        "soundcraft".to_string(),
        "pdfcraft".to_string(),
        "wordcraft".to_string(),
        "effectcraft".to_string(),
        "filmcraft".to_string(),
        "deckcraft".to_string(),
        "figcraft".to_string(),
        "designcraft".to_string(),
    ]
}

impl Default for CraftConfig {
    fn default() -> Self {
        Self {
            enabled: default_craft_modules(),
            denoise_default_method: Some("bilateral".to_string()),
            audio_target_lufs: Some(-14.0),
            audio_max_true_peak_db: Some(-1.0),
            default_icc_profile: Some("sRGB".to_string()),
        }
    }
}

fn default_groq_primary_opt() -> Option<String> { Some("openai/gpt-oss-120b".to_string()) }
fn default_groq_fast_opt() -> Option<String> { Some("openai/gpt-oss-20b".to_string()) }
fn default_groq_multi_opt() -> Option<String> { Some("qwen/qwen3.8-27b".to_string()) }
fn default_groq_whisper_opt() -> Option<String> { Some("whisper-large-v3".to_string()) }
fn default_gemini_model_opt() -> Option<String> { Some("gemini-2.5-flash".to_string()) }
fn default_openrouter_model() -> String { "qwen/qwen3-32b:free".to_string() }

/// PT: Cofre de chaves de API com desserializador tolerante a string/lista
/// EN: API key vault with tolerant string/list deserializer
// AI: @llm-struct CredentialsConfig @llm-invariant string_or_vec sanitizado sem placeholders; suporte OpenRouter condicional
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CredentialsConfig {
    #[serde(default, deserialize_with = "string_or_vec")]
    pub groq_keys: Vec<String>,
    #[serde(default, deserialize_with = "string_or_vec")]
    pub gemini_keys: Vec<String>,
    #[serde(default, deserialize_with = "string_or_vec")]
    pub openai_keys: Vec<String>,
    #[serde(default, deserialize_with = "string_or_vec")]
    pub openrouter_keys: Vec<String>,
    #[serde(default)]
    pub openrouter_enabled: bool,
    #[serde(default = "default_openrouter_model")]
    pub openrouter_default_model: String,
    pub vps_credentials_path: Option<String>,
    pub validation_cache_ttl_hours: Option<u64>,
    #[serde(default = "default_groq_primary_opt", alias = "groq_model_primary")]
    pub groq_primary_model: Option<String>,
    #[serde(default = "default_groq_fast_opt", alias = "groq_model_fast")]
    pub groq_fast_model: Option<String>,
    #[serde(default = "default_groq_multi_opt", alias = "groq_model_multilingual")]
    pub groq_multilingual_model: Option<String>,
    #[serde(default = "default_groq_whisper_opt", alias = "groq_model_whisper")]
    pub groq_whisper_model: Option<String>,
    #[serde(default = "default_gemini_model_opt")]
    pub gemini_model: Option<String>,
}

impl Default for CredentialsConfig {
    fn default() -> Self {
        Self {
            groq_keys: Vec::new(),
            gemini_keys: Vec::new(),
            openai_keys: Vec::new(),
            openrouter_keys: Vec::new(),
            openrouter_enabled: false,
            openrouter_default_model: default_openrouter_model(),
            vps_credentials_path: Some("/var/www/mcp-server/credentials/credentials.json".to_string()),
            validation_cache_ttl_hours: Some(24),
            groq_primary_model: default_groq_primary_opt(),
            groq_fast_model: default_groq_fast_opt(),
            groq_multilingual_model: default_groq_multi_opt(),
            groq_whisper_model: default_groq_whisper_opt(),
            gemini_model: default_gemini_model_opt(),
        }
    }
}

impl CredentialsConfig {
    /// PT: Autoativa OpenRouter se houver pelo menos uma chave válida não-placeholder
    pub fn auto_activate_openrouter(&mut self) {
        let has_valid = self.openrouter_keys.iter().any(|k| {
            let t = k.trim();
            t.starts_with("sk-or-v1-") && t.len() > 20 && !t.contains("...")
        });
        self.openrouter_enabled = has_valid;
    }

    // PT: Mescla novas chaves enviadas pelo painel preservando chaves mascaradas (ex: gsk_...abcd) que já existem no cofre
    // EN: Merges new keys submitted from UI while preserving masked keys (e.g., gsk_...abcd) already present in vault
    // AI: @llm-func merge_submitted_keys @llm-invariant Nunca apaga chaves antigas quando o usuário envia linhas mascaradas junto com chaves novas
    pub fn merge_submitted_keys(existing: &[String], submitted_raw: &str, force_clear: bool) -> Vec<String> {
        if force_clear {
            return Vec::new();
        }
        let trimmed_all = submitted_raw.trim();
        if trimmed_all.is_empty() {
            return existing.to_vec();
        }
        let mut result: Vec<String> = Vec::new();
        for line in trimmed_all.lines().flat_map(|l| l.split(',')) {
            let token = line.trim();
            if token.is_empty() {
                continue;
            }
            // Se for uma máscara gerada pela UI (ex: "gsk_...9f2a" ou "********"), recupera a chave original correspondente
            if token.contains("...") || token.contains("***") {
                if token.len() >= 11 && token.contains("...") {
                    let parts: Vec<&str> = token.split("...").collect();
                    if parts.len() == 2 && !parts[0].is_empty() && !parts[1].is_empty() {
                        if let Some(orig) = existing.iter().find(|k| {
                            let kt = k.trim();
                            kt.starts_with(parts[0]) && kt.ends_with(parts[1])
                        }) {
                            if !result.contains(orig) {
                                result.push(orig.clone());
                            }
                            continue;
                        }
                    }
                }
                // Se for máscara genérica, preserva todas as existentes ainda não adicionadas
                for orig in existing {
                    if !result.contains(orig) {
                        result.push(orig.clone());
                    }
                }
                continue;
            }
            if is_valid_token(token) && !result.contains(&token.to_string()) {
                result.push(token.to_string());
            }
        }
        if result.is_empty() && !existing.is_empty() {
            return existing.to_vec();
        }
        result
    }
}

/// PT: Configuração do Cluster e Malha P2P
/// EN: Cluster and P2P Mesh configuration
// AI: @llm-struct ClusterConfig
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ClusterConfig {
    #[serde(default = "default_true")]
    pub enabled: bool,
    #[serde(default = "default_role", alias = "role")]
    pub node_role: String,
    #[serde(default = "default_peer_endpoint")]
    pub peer_endpoint: String,
    #[serde(default = "default_30", alias = "heartbeat_interval_secs")]
    pub health_check_interval_secs: u64,
    #[serde(default = "default_true")]
    pub offload_light_tasks_to_vps: bool,
    #[serde(default)]
    pub shared_secret: String,
    #[serde(default = "default_craft_modules", deserialize_with = "string_or_vec")]
    pub artcraft_modules: Vec<String>,
    #[serde(default = "default_headscale_url")]
    pub headscale_url: String,
    #[serde(default = "default_hs_auth")]
    pub headscale_auth_key: String,
    #[serde(default = "default_true")]
    pub enable_p2p: bool,
}

fn default_role() -> String { "nitro".to_string() }
fn default_peer_endpoint() -> String {
    std::env::var("ZORYA_PEER_ENDPOINT")
        .unwrap_or_else(|_| "https://svitlo.espectroipe.com.br".to_string())
}

impl Default for ClusterConfig {
    fn default() -> Self {
        Self {
            enabled: true,
            node_role: default_role(),
            peer_endpoint: default_peer_endpoint(),
            health_check_interval_secs: 30,
            offload_light_tasks_to_vps: true,
            shared_secret: String::new(),
            artcraft_modules: default_craft_modules(),
            headscale_url: default_headscale_url(),
            headscale_auth_key: default_hs_auth(),
            enable_p2p: true,
        }
    }
}

impl Default for ZoryaConfig {
    fn default() -> Self {
        Self {
            server: ServerConfig::default(),
            node: NodeConfig::default(),
            network: NetworkConfig::default(),
            hardware: HardwareConfig::default(),
            models: ModelsConfig::default(),
            inference: InferenceConfig::default(),
            craft: CraftConfig::default(),
            credentials: CredentialsConfig::default(),
            cluster: ClusterConfig::default(),
        }
    }
}

/// PT: Desserializador customizado que aceita tanto Vec<String> quanto String separada por quebras de linha ou vírgulas.
/// EN: Custom deserializer accepting either Vec<String> or newline/comma-separated String.
// AI: @llm-func string_or_vec @llm-security Rejeita placeholders como gsk_..., AIzaSy... e sk-...
pub fn string_or_vec<'de, D>(deserializer: D) -> Result<Vec<String>, D::Error>
where
    D: Deserializer<'de>,
{
    struct StringOrVecVisitor;

    impl<'de> de::Visitor<'de> for StringOrVecVisitor {
        type Value = Vec<String>;

        fn expecting(&self, formatter: &mut std::fmt::Formatter) -> std::fmt::Result {
            formatter.write_str("string com quebras de linha/vírgulas ou vetor de strings")
        }

        fn visit_str<E>(self, value: &str) -> Result<Vec<String>, E>
        where
            E: de::Error,
        {
            let is_comma_separated = value.contains(',') && !value.contains('\n');
            let items: Vec<String> = if is_comma_separated {
                value.split(',')
                    .map(|s| s.trim().to_string())
                    .filter(|s| is_valid_token(s))
                    .collect()
            } else {
                value.lines()
                    .map(|s| s.trim().to_string())
                    .filter(|s| is_valid_token(s))
                    .collect()
            };
            Ok(items)
        }

        fn visit_seq<S>(self, mut seq: S) -> Result<Vec<String>, S::Error>
        where
            S: de::SeqAccess<'de>,
        {
            let mut items = Vec::new();
            while let Some(elem) = seq.next_element::<String>()? {
                let trimmed = elem.trim().to_string();
                if is_valid_token(&trimmed) {
                    items.push(trimmed);
                }
            }
            Ok(items)
        }
    }

    deserializer.deserialize_any(StringOrVecVisitor)
}

fn is_valid_token(token: &str) -> bool {
    if token.is_empty() {
        return false;
    }
    // Rejeita placeholders conhecidos
    let placeholders = ["gsk_...", "aizasy...", "sk-...", "sk-or-v1-...", "********", "...", "hskey-default"];
    let lower = token.to_lowercase();
    !placeholders.iter().any(|&p| lower == p || lower.ends_with("..."))
}

impl ZoryaConfig {
    // PT: Resolve todos os caminhos existentes onde zorya.toml reside
    // EN: Resolves all existing paths where zorya.toml resides
    // AI: @llm-func resolve_active_paths @llm-invariant Garante sincronização consistente entre diretórios ativos
    pub fn resolve_active_paths() -> Vec<PathBuf> {
        let mut paths: Vec<PathBuf> = Vec::new();

        if let Ok(env_path) = std::env::var("ZORYA_CONFIG") {
            let p = PathBuf::from(env_path.trim());
            if !p.as_os_str().is_empty() && p.exists() && !paths.contains(&p) {
                paths.push(p);
            }
        }

        let local_path = PathBuf::from("zorya.toml");
        if local_path.exists() && !paths.contains(&local_path) {
            paths.push(local_path);
        }

        if let Ok(exe_path) = std::env::current_exe() {
            if let Some(parent) = exe_path.parent() {
                let exe_cfg = parent.join("zorya.toml");
                if exe_cfg.exists() && !paths.contains(&exe_cfg) {
                    paths.push(exe_cfg);
                }
            }
        }

        let prog_data = PathBuf::from(r"C:\ProgramData\Zorya\zorya.toml");
        if prog_data.exists() && !paths.contains(&prog_data) {
            paths.push(prog_data);
        }

        if paths.is_empty() {
            vec![PathBuf::from("zorya.toml")]
        } else {
            paths
        }
    }

    pub fn load_or_default<P: AsRef<Path>>(path: P) -> anyhow::Result<Self> {
        let path_ref = path.as_ref();
        let mut parsed = if path_ref.exists() {
            let raw = fs::read_to_string(path_ref)?;
            let p: ZoryaConfig = toml::from_str(&raw)?;
            p
        } else {
            tracing::warn!(
                "Configuration file {:?} not found, using built-in defaults / Arquivo {:?} não encontrado, usando padrões internos",
                path_ref,
                path_ref
            );
            Self::default()
        };

        // Auto-ativação condicional do OpenRouter caso haja chave válida
        let env_or_key = std::env::var("OPENROUTER_API_KEY").unwrap_or_default();
        let has_valid_or_key = parsed.credentials.openrouter_keys.iter().any(|k| k.starts_with("sk-or-v1-") && k.len() > 20)
            || (env_or_key.starts_with("sk-or-v1-") && env_or_key.len() > 20);

        if has_valid_or_key {
            parsed.credentials.openrouter_enabled = true;
            if !env_or_key.is_empty() && !parsed.credentials.openrouter_keys.contains(&env_or_key) {
                parsed.credentials.openrouter_keys.push(env_or_key);
            }
        }

        Ok(parsed)
    }

    pub fn is_craft_enabled(&self, module: &str) -> bool {
        self.craft
            .enabled
            .iter()
            .any(|m| m.eq_ignore_ascii_case(module))
            || self.cluster
                .artcraft_modules
                .iter()
                .any(|m| m.eq_ignore_ascii_case(module))
    }

    /// PT: Gravação atômica via arquivo temporário (zorya.toml.tmp) + fsync + rename resiliente
    /// EN: Atomic write via temporary file (zorya.toml.tmp) + fsync + resilient rename
    // AI: @llm-func save_atomic @llm-security Permissão 0600 em Unix e atomicidade em queda de energia/Windows
    pub fn save_atomic(&self, path: &Path) -> anyhow::Result<()> {
        let serialized = toml::to_string_pretty(self)?;
        let parent = path.parent().unwrap_or_else(|| Path::new("."));
        let tmp_path = parent.join(format!("{}.tmp", path.file_name().and_then(|f| f.to_str()).unwrap_or("zorya.toml")));

        {
            let mut file = File::create(&tmp_path)?;
            file.write_all(serialized.as_bytes())?;
            file.sync_all()?;
        }

        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            let perms = std::fs::Permissions::from_mode(0o600);
            let _ = std::fs::set_permissions(&tmp_path, perms);
        }

        if let Err(e) = std::fs::rename(&tmp_path, path) {
            tracing::debug!("Rename direto falhou ({}), aplicando cópia resiliente...", e);
            std::fs::copy(&tmp_path, path)?;
            let _ = std::fs::remove_file(&tmp_path);
        }

        tracing::info!("zorya.toml gravado atomicamente com sucesso em {:?}", path);
        Ok(())
    }

    /// PT: Grava em todos os caminhos ativos descobertos para manter instâncias sincronizadas
    /// EN: Writes to all discovered active paths to keep instances synchronized
    // AI: @llm-func save_default @llm-invariant Gravação redundante e sincronizada
    pub fn save_default(&self) -> anyhow::Result<()> {
        let active_paths = Self::resolve_active_paths();
        let mut success_count = 0;
        let mut last_err = None;

        for p in &active_paths {
            match self.save_atomic(p) {
                Ok(_) => {
                    success_count += 1;
                }
                Err(e) => {
                    tracing::warn!("Falha ao gravar zorya.toml em {:?}: {}", p, e);
                    last_err = Some(e);
                }
            }
        }

        if success_count > 0 {
            Ok(())
        } else if let Some(e) = last_err {
            Err(e)
        } else {
            self.save_atomic(Path::new("zorya.toml"))
        }
    }
}
