// PT: Biblioteca Principal Zorya v3.0 (Coprocessador Neural & Suíte Multimídia)
// EN: Zorya v3.0 Main Library (Neural Coprocessor & Multimedia Suite)
// AI: @llm-module lib | @llm-context Exporta módulos para binários e testes de integração

pub mod agent;
pub mod cluster;
pub mod config;
pub mod craft;
pub mod hardware;
pub mod inference;
pub mod mcp;
pub mod network;
pub mod p2p_future;
pub mod quota_router;
pub mod security;
pub mod server;
pub mod systray;
pub mod telemetry;
pub mod ui;
pub mod video;

use std::path::{Path, PathBuf};
use crate::config::ZoryaConfig;

/// Resolve o caminho de configuração buscando ZORYA_CONFIG, ./zorya.toml e exe_dir/zorya.toml
pub fn resolve_config_path() -> ZoryaConfig {
    if let Ok(env_path) = std::env::var("ZORYA_CONFIG") {
        let p = PathBuf::from(&env_path);
        if p.exists() {
            if let Ok(cfg) = ZoryaConfig::load_or_default(&p) {
                return cfg;
            }
        }
    }

    let local_path = Path::new("zorya.toml");
    if local_path.exists() {
        if let Ok(cfg) = ZoryaConfig::load_or_default(local_path) {
            return cfg;
        }
    }

    if let Ok(exe_path) = std::env::current_exe() {
        if let Some(parent) = exe_path.parent() {
            let exe_cfg_path = parent.join("zorya.toml");
            if exe_cfg_path.exists() {
                if let Ok(cfg) = ZoryaConfig::load_or_default(&exe_cfg_path) {
                    return cfg;
                }
            }
        }
    }

    ZoryaConfig::default()
}

pub fn main_config_snapshot() -> ZoryaConfig {
    resolve_config_path()
}
