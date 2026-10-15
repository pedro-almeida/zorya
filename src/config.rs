// Configuration parser for zorya.toml / Analisador de configuração para o zorya.toml
use std::fs;
use std::path::Path;
use serde::{Deserialize, Serialize};

// Root configuration structure / Estrutura raiz de configuração
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ZoryaConfig {
    pub network: NetworkConfig,
    pub inference: InferenceConfig,
    pub craft: CraftConfig,
}

// Network and Headscale configuration / Configuração de rede e Headscale
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetworkConfig {
    pub headscale_url: String,
    pub headscale_auth_key: String,
    pub node_name: String,
    pub allowed_ip: String,
    pub listen_port: Option<u16>,
    pub wireguard_port: Option<u16>,
}

// Local Ollama inference configuration / Configuração de inferência local Ollama
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct InferenceConfig {
    pub ollama_url: String,
    pub default_model: Option<String>,
    pub dedicated_vram_mb: Option<u64>,
    pub gpu_device_index: Option<u32>,
}

// ArtCraft Suite modules configuration / Configuração dos módulos da suíte ArtCraft
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CraftConfig {
    pub enabled: Vec<String>,
}

impl Default for ZoryaConfig {
    fn default() -> Self {
        Self {
            network: NetworkConfig {
                headscale_url: "https://headscale.espectroipe.com.br".to_string(),
                headscale_auth_key: "hskey-default".to_string(),
                node_name: "usina-zorya".to_string(),
                allowed_ip: "143.95.223.218".to_string(),
                listen_port: Some(8085),
                wireguard_port: Some(51820),
            },
            inference: InferenceConfig {
                ollama_url: "http://127.0.0.1:11434".to_string(),
                default_model: Some("qwen2.5-coder:7b-instruct-q4_K_M".to_string()),
                dedicated_vram_mb: Some(4096),
                gpu_device_index: Some(0),
            },
            craft: CraftConfig {
                enabled: vec![
                    "lightcraft".to_string(),
                    "photocraft".to_string(),
                    "vectorcraft".to_string(),
                    "soundcraft".to_string(),
                ],
            },
        }
    }
}

impl ZoryaConfig {
    // Load configuration from disk or fall back to defaults / Carrega configuração do disco ou usa valores padrão
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

    // Check whether a specific ArtCraft module is enabled / Verifica se um módulo ArtCraft específico está habilitado
    pub fn is_craft_enabled(&self, module: &str) -> bool {
        self.craft
            .enabled
            .iter()
            .any(|m| m.eq_ignore_ascii_case(module))
    }
}
