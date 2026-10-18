// PT: Analisador e Estruturas de Configuração do Zorya v3.0 (zorya.toml)
// EN: Zorya v3.0 Configuration Parser and Data Structures (zorya.toml)
// AI: @llm-module config | @llm-context Compatibilidade total com seções [server], [hardware], [models], [network], [cluster], [credentials]/[keys], [craft]/[artcraft], [youtube]

use std::fs;
use std::path::Path;
use serde::{Deserialize, Serialize};

// Root configuration structure / Estrutura raiz de configuração
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
    #[serde(default, alias = "keys")]
    pub credentials: CredentialsConfig,
    #[serde(default)]
    pub cluster: ClusterConfig,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ServerConfig {
    #[serde(default = "default_host")]
    pub host: String,
    #[serde(default = "default_port")]
    pub port: u16,
    #[serde(default = "default_true")]
    pub enable_mcp_sse: bool,
    #[serde(default = "default_mcp_path")]
    pub mcp_sse_path: String,
    #[serde(default = "default_log_level")]
    pub log_level: String,
}

fn default_host() -> String { "0.0.0.0".to_string() }
fn default_port() -> u16 { 8085 }
fn default_true() -> bool { true }
fn default_mcp_path() -> String { "/mcp/sse".to_string() }
fn default_log_level() -> String { "info".to_string() }

impl Default for ServerConfig {
    fn default() -> Self {
        Self {
            host: default_host(),
            port: default_port(),
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

    // Web Settings Dashboard compatibility fields
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
fn default_max_tokens() -> u32 { 2048 }
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

// Network and Headscale / Tailscale configuration
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
    #[serde(default = "default_30")]
    pub reconnect_interval_secs: u64,
}

fn default_headscale_url() -> String { "https://headscale.espectroipe.com.br".to_string() }
fn default_hs_auth() -> String { "hskey-default".to_string() }
fn default_node_name() -> String { "Usina-Zorya-Nitro".to_string() }
fn default_allowed_ip() -> String { "0.0.0.0/0".to_string() }
fn default_ts_api() -> String { "http://127.0.0.1:41112/localapi/v0/status".to_string() }
fn default_30() -> u64 { 30 }

impl Default for NetworkConfig {
    fn default() -> Self {
        Self {
            headscale_url: default_headscale_url(),
            headscale_auth_key: default_hs_auth(),
            node_name: default_node_name(),
            allowed_ip: default_allowed_ip(),
            listen_port: Some(8085),
            wireguard_port: Some(51820),
            tailscale_peer_hostname: Some("100.106.120.35".to_string()),
            fallback_ip: Some("0.0.0.0".to_string()),
            prefer_tailscale: true,
            tailscale_local_api: default_ts_api(),
            fallback_vps_ip: Some("72.60.144.210".to_string()),
            fallback_nitro_ip: Some("100.106.120.35".to_string()),
            reconnect_interval_secs: 30,
        }
    }
}

// Local inference configuration
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct InferenceConfig {
    #[serde(default = "default_ollama_url")]
    pub ollama_url: String,
    pub default_model: Option<String>,
    pub dedicated_vram_mb: Option<u64>,
    pub gpu_device_index: Option<u32>,
}

fn default_ollama_url() -> String { "http://127.0.0.1:11434".to_string() }

impl Default for InferenceConfig {
    fn default() -> Self {
        Self {
            ollama_url: default_ollama_url(),
            default_model: Some("qwen2.5-7b-instruct-q4_k_m.gguf".to_string()),
            dedicated_vram_mb: Some(4096),
            gpu_device_index: Some(0),
        }
    }
}

// ArtCraft Suite modules configuration
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CraftConfig {
    #[serde(default = "default_craft_modules")]
    pub enabled: Vec<String>,
    pub denoise_default_method: Option<String>,
    pub audio_target_lufs: Option<f32>,
    pub audio_max_true_peak_db: Option<f32>,
    pub default_icc_profile: Option<String>,
}

fn default_craft_modules() -> Vec<String> {
    vec![
        "lightcraft".to_string(),
        "photocraft".to_string(),
        "vectorcraft".to_string(),
        "soundcraft".to_string(),
        "pdfcraft".to_string(),
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

// Credentials configuration
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct CredentialsConfig {
    #[serde(default)]
    pub groq_keys: Vec<String>,
    #[serde(default)]
    pub gemini_keys: Vec<String>,
    #[serde(default)]
    pub openai_keys: Vec<String>,
    pub vps_credentials_path: Option<String>,
    pub validation_cache_ttl_hours: Option<u64>,
    pub groq_primary_model: Option<String>,
    pub groq_fast_model: Option<String>,
    pub groq_multilingual_model: Option<String>,
    pub groq_whisper_model: Option<String>,
}

// Cluster configuration
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ClusterConfig {
    #[serde(default = "default_role")]
    pub node_role: String,
    #[serde(default = "default_peer_endpoint")]
    pub peer_endpoint: String,
    #[serde(default = "default_30")]
    pub health_check_interval_secs: u64,
    #[serde(default = "default_true")]
    pub offload_light_tasks_to_vps: bool,
}

fn default_role() -> String { "nitro".to_string() }
fn default_peer_endpoint() -> String { "http://72.60.144.210:8085".to_string() }

impl Default for ClusterConfig {
    fn default() -> Self {
        Self {
            node_role: default_role(),
            peer_endpoint: default_peer_endpoint(),
            health_check_interval_secs: 30,
            offload_light_tasks_to_vps: true,
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

impl ZoryaConfig {
    pub fn load_or_default<P: AsRef<Path>>(path: P) -> anyhow::Result<Self> {
        let path_ref = path.as_ref();
        if path_ref.exists() {
            let raw = fs::read_to_string(path_ref)?;
            let parsed: ZoryaConfig = toml::from_str(&raw)?;
            Ok(parsed)
        } else {
            tracing::warn!(
                "Configuration file {:?} not found, using built-in defaults / Arquivo {:?} não encontrado, usando padrões internos",
                path_ref,
                path_ref
            );
            Ok(Self::default())
        }
    }

    pub fn is_craft_enabled(&self, module: &str) -> bool {
        self.craft
            .enabled
            .iter()
            .any(|m| m.eq_ignore_ascii_case(module))
    }

    pub fn save_to_file<P: AsRef<Path>>(&self, path: P) -> anyhow::Result<()> {
        let serialized = toml::to_string_pretty(self)?;
        fs::write(path, serialized)?;
        Ok(())
    }

    pub fn save_default(&self) -> anyhow::Result<()> {
        if let Ok(env_path) = std::env::var("ZORYA_CONFIG") {
            let p = std::path::PathBuf::from(&env_path);
            return self.save_to_file(p);
        }
        let local_path = Path::new("zorya.toml");
        self.save_to_file(local_path)
    }
}
