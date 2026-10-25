// PT: Biblioteca Principal Zorya v3.0 (Coprocessador Neural & Suíte Multimídia)
// EN: Zorya v3.0 Main Library (Neural Coprocessor & Multimedia Suite)
// AI: @llm-module lib | @llm-context Exporta módulos para binários e testes de integração

#![allow(
    clippy::derivable_impls,
    clippy::manual_div_ceil,
    clippy::chunks_exact_to_as_chunks,
    clippy::unnecessary_sort_by,
    clippy::manual_pattern_char_comparison,
    clippy::needless_range_loop,
    clippy::for_kv_map,
    clippy::manual_map,
    clippy::let_and_return,
    clippy::unnecessary_lazy_evaluations
)]

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

use crate::config::ZoryaConfig;
use std::path::PathBuf;

/// Resolve o caminho de configuração buscando ZORYA_CONFIG, C:\ProgramData\Zorya\zorya.toml, ./zorya.toml e exe_dir/zorya.toml
pub fn resolve_config_path_with_target() -> (ZoryaConfig, PathBuf) {
    if let Ok(env_path) = std::env::var("ZORYA_CONFIG") {
        let p = PathBuf::from(env_path.trim());
        if p.exists() {
            if let Ok(cfg) = ZoryaConfig::load_or_default(&p) {
                return (cfg, p);
            }
        }
    }

    #[cfg(target_os = "windows")]
    {
        use std::path::Path;

        let has_keys = |c: &ZoryaConfig| -> bool {
            !c.credentials.groq_keys.is_empty()
                || !c.credentials.gemini_keys.is_empty()
                || !c.credentials.openrouter_keys.is_empty()
                || !c.credentials.openai_keys.is_empty()
        };

        let prog_dir = Path::new(r"C:\ProgramData\Zorya");
        let prog_file = prog_dir.join("zorya.toml");
        let local_file = PathBuf::from("zorya.toml");

        if prog_file.exists() && local_file.exists() {
            let cfg_prog = ZoryaConfig::load_or_default(&prog_file).unwrap_or_default();
            let cfg_local = ZoryaConfig::load_or_default(&local_file).unwrap_or_default();

            if has_keys(&cfg_prog) {
                let _ = cfg_prog.save_atomic(&local_file);
                return (cfg_prog, prog_file);
            } else if has_keys(&cfg_local) {
                let _ = cfg_local.save_atomic(&prog_file);
                return (cfg_local, prog_file);
            } else {
                return (cfg_prog, prog_file);
            }
        } else if prog_file.exists() {
            let cfg_prog = ZoryaConfig::load_or_default(&prog_file).unwrap_or_default();
            let _ = cfg_prog.save_atomic(&local_file);
            return (cfg_prog, prog_file);
        } else if local_file.exists() {
            let cfg_local = ZoryaConfig::load_or_default(&local_file).unwrap_or_default();
            if prog_dir.exists() {
                let _ = cfg_local.save_atomic(&prog_file);
            }
            return (cfg_local, local_file);
        }
    }

    let local_path = PathBuf::from("zorya.toml");
    if local_path.exists() {
        if let Ok(cfg) = ZoryaConfig::load_or_default(&local_path) {
            return (cfg, local_path);
        }
    }

    if let Ok(exe_path) = std::env::current_exe() {
        if let Some(parent) = exe_path.parent() {
            let exe_cfg_path = parent.join("zorya.toml");
            if exe_cfg_path.exists() {
                if let Ok(cfg) = ZoryaConfig::load_or_default(&exe_cfg_path) {
                    return (cfg, exe_cfg_path);
                }
            }
        }
    }

    (ZoryaConfig::default(), PathBuf::from("zorya.toml"))
}

pub fn resolve_config_path() -> ZoryaConfig {
    resolve_config_path_with_target().0
}

pub fn main_config_snapshot() -> ZoryaConfig {
    resolve_config_path()
}
