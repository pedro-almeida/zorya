#![warn(unused_must_use)]
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
// PT: Zorya v3.0 — Daemon Rust Soberano, Servidor MCP & Coprocessador Multimídia
// EN: Zorya v3.0 — Sovereign Rust Daemon, MCP Server & Multimedia Coprocessor
// AI: @llm-context Coordena inference (GGUF llama-cpp-2), hardware auto-tuning, agent autônomo, servidor MCP duplo, cluster e rede desacoplada

// Exportação de símbolos nativos C reconhecidos pelos drivers NVIDIA Optimus e AMD PowerXpress/Enduro para despertar dGPU do estado D3
#[cfg(target_os = "windows")]
#[no_mangle]
pub static NvOptimusEnablement: u32 = 0x00000001;

#[cfg(target_os = "windows")]
#[no_mangle]
pub static AmdPowerXpressRequestHighPerformance: i32 = 1;

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

use std::net::SocketAddr;
use std::path::PathBuf;
use std::sync::atomic::AtomicUsize;
use std::sync::Arc;
use tokio::net::TcpListener;
use tokio::sync::{broadcast, RwLock};
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

use crate::agent::ZoryaAgent;
use crate::cluster::ClusterCoordinator;
use crate::config::ZoryaConfig;
use crate::hardware::HardwareProfile;
use crate::inference::NativeInferenceEngine;
use crate::network::NetworkManager;
use crate::server::AppState;
use crate::systray::SystrayController;
use crate::telemetry::TelemetryCollector;

#[cfg(target_os = "windows")]
fn hide_console_window_on_windows(is_mcp_stdio: bool, force_console: bool) {
    if is_mcp_stdio || force_console {
        return;
    }
    unsafe {
        use windows_sys::Win32::System::Console::GetConsoleWindow;
        use windows_sys::Win32::UI::WindowsAndMessaging::{ShowWindow, SW_HIDE};
        let hwnd = GetConsoleWindow();
        if !hwnd.is_null() {
            ShowWindow(hwnd, SW_HIDE);
        }
    }
}

#[cfg(not(target_os = "windows"))]
fn hide_console_window_on_windows(_is_mcp_stdio: bool, _force_console: bool) {}

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

    tracing::warn!("zorya.toml não localizado nos caminhos padrão. Utilizando ZoryaConfig::default() soberano.");
    (ZoryaConfig::default(), PathBuf::from("zorya.toml"))
}

pub fn resolve_config_path() -> ZoryaConfig {
    resolve_config_path_with_target().0
}

pub fn main_config_snapshot() -> ZoryaConfig {
    resolve_config_path()
}

// PT: Realiza bind TCP resiliente na porta 8085 encerrando processos órfãos antes de abrir o socket.
// EN: Performs resilient TCP bind on port 8085 terminating orphan processes before opening the socket.
// AI: @llm-func bind_resilient_listener @llm-invariant Garante porta 8085 em LISTENING imediato
pub async fn bind_resilient_listener(addr: SocketAddr) -> anyhow::Result<TcpListener> {
    let mut last_err = None;
    for attempt in 1..=5 {
        match TcpListener::bind(addr).await {
            Ok(listener) => {
                tracing::info!(
                    "Socket TCP vinculado e em LISTENING ativo em {} (tentativa {}/5)",
                    addr,
                    attempt
                );
                return Ok(listener);
            }
            Err(e) => {
                tracing::warn!(
                    "Tentativa {}/5 de bind em {} falhou ({}). Limpando processos órfãos na porta {}...",
                    attempt, addr, e, addr.port()
                );
                #[cfg(target_os = "windows")]
                {
                    let _ = std::process::Command::new("powershell")
                        .args([
                            "-NoProfile",
                            "-Command",
                            &format!(
                                "Get-NetTCPConnection -LocalPort {} -ErrorAction SilentlyContinue | ForEach-Object {{ Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }}",
                                addr.port()
                            ),
                        ])
                        .output();
                }
                last_err = Some(e);
                tokio::time::sleep(std::time::Duration::from_millis(800 * attempt)).await;
            }
        }
    }
    Err(anyhow::anyhow!(
        "Falha ao vincular e escutar em {} após 5 tentativas: {:?}",
        addr,
        last_err
    ))
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    let args: Vec<String> = std::env::args().collect();
    let is_mcp_stdio = args.iter().any(|a| a == "mcp" || a == "--mcp");
    let force_console = args.iter().any(|a| a == "--console" || a == "--foreground");
    hide_console_window_on_windows(is_mcp_stdio, force_console);

    // Initialize structured logging / Inicializa o sistema de logs estruturados
    // No modo stdio, direciona logs para stderr para preservar stdout limpo para JSON-RPC 2.0
    if is_mcp_stdio {
        tracing_subscriber::registry()
            .with(
                tracing_subscriber::EnvFilter::try_from_default_env()
                    .unwrap_or_else(|_| "zorya=warn".into()),
            )
            .with(tracing_subscriber::fmt::layer().with_writer(std::io::stderr))
            .init();
    } else {
        tracing_subscriber::registry()
            .with(
                tracing_subscriber::EnvFilter::try_from_default_env()
                    .unwrap_or_else(|_| "zorya=info,tower_http=info".into()),
            )
            .with(tracing_subscriber::fmt::layer())
            .init();
    }

    // 1. Carrega configuração com fallback resiliente sem abortar processo
    let (config, config_path) = resolve_config_path_with_target();

    // 2. Garante que a porta 8085 entre imediatamente em LISTENING antes de tarefas demoradas
    let port = config.server.port;
    let host = &config.server.host;
    let addr: SocketAddr = format!("{}:{}", host, port)
        .parse()
        .unwrap_or_else(|_| SocketAddr::from(([0, 0, 0, 0], port)));
    let listener = if !is_mcp_stdio {
        let l = bind_resilient_listener(addr).await?;
        tracing::info!(
            "Unified TCP socket bound & listening immediately on http://{}:{}",
            host,
            port
        );
        Some(l)
    } else {
        None
    };

    // 3. Sonda de hardware executada com a porta já em LISTENING (timeout estrito de 4s)
    let hw = match tokio::time::timeout(
        std::time::Duration::from_secs(4),
        tokio::task::spawn_blocking(HardwareProfile::detect),
    )
    .await
    {
        Ok(Ok(profile)) => profile,
        _ => {
            tracing::warn!("Timeout na detecção de hardware durante o boot; aplicando perfil padrão GTX 1650 (4096 MB).");
            HardwareProfile {
                cpu_brand: "Nitro 5 CPU".to_string(),
                physical_cores: 4,
                logical_cores: 8,
                has_avx2: true,
                has_avx512: false,
                npu_detected: None,
                total_ram_mb: 16384,
                available_ram_mb: 8192,
                igpu_model: Some("Intel(R) UHD Graphics".to_string()),
                dgpu_model: Some("NVIDIA GeForce GTX 1650".to_string()),
                dgpu_vram_mb: 4096,
                dgpu_vram_free_mb: 4096,
                display_offloaded_to_igpu: true,
                compiled_features: vec!["cuda".to_string()],
                active_backend: crate::hardware::ComputeBackend::Cuda,
                profile_kind: crate::hardware::NodeProfileKind::NitroGtx1650,
                recommended_model: "qwen2.5-7b-instruct-q4_k_m.gguf".to_string(),
                recommended_ctx_size: 8192,
                recommended_batch_size: 512,
                recommended_threads: 4,
                recommended_gpu_layers: 33,
            }
        }
    };

    if !is_mcp_stdio {
        tracing::info!("================================================================");
        tracing::info!("  ZORYA v3.0 — SOVEREIGN RUST DAEMON & NATIVE INFERENCE          ");
        tracing::info!(
            "  Node / Nó:        {}                                          ",
            config.network.node_name
        );
        tracing::info!(
            "  Profile / Perfil: {:?} (Backend: {:?})                        ",
            hw.profile_kind,
            hw.active_backend
        );
        tracing::info!(
            "  CPU:              {} ({} cores)                              ",
            hw.cpu_brand,
            hw.logical_cores
        );
        tracing::info!(
            "  GPU Model:        {:?} ({} MB VRAM)                          ",
            hw.dgpu_model,
            hw.dgpu_vram_mb
        );
        tracing::info!(
            "  Recommended Model:{} (ctx {})                                ",
            hw.recommended_model,
            hw.recommended_ctx_size
        );
        tracing::info!(
            "  ArtCraft Suite:   {:?}                                        ",
            config.craft.enabled
        );
        tracing::info!("================================================================");
    }

    // Inicializa Systray com modelo padrão ativo
    let systray = SystrayController::new(&hw.recommended_model);

    // Inicializa motor de inferência nativo GGUF
    let inference = NativeInferenceEngine::new(
        &config.models.models_dir,
        hw.clone(),
        Some(hw.recommended_model.clone()),
    );

    // Inicializa gerenciador de rede desacoplado e não bloqueante
    let network = NetworkManager::new_nonblocking(config.network.clone(), systray.clone());

    // Inicializa orquestrador de cluster federado VPS + Nitro
    let cluster = ClusterCoordinator::new(&config, &hw, Arc::clone(&network), systray.clone());

    // Inicializa agente inteligente com cofre de chaves e tool calling
    let agent = ZoryaAgent::new(
        Arc::new(config.clone()),
        Arc::clone(&inference),
        systray.clone(),
    );

    // Inicializa coletor de telemetria do sistema alimentado pelo HardwareProfile real e snapshot consolidado
    let mut initial_collector =
        TelemetryCollector::new(config.network.node_name.clone(), hw.dgpu_vram_mb)
            .with_hw_profile(hw.dgpu_model.clone(), hw.dgpu_vram_mb);
    let initial_snapshot = initial_collector.collect_snapshot();

    let telemetry = Arc::new(RwLock::new(initial_collector));
    let system_snapshot = Arc::new(RwLock::new(initial_snapshot));

    // PT: Task de background assíncrona que atualiza o snapshot de telemetria a cada 3 segundos via spawn_blocking
    // EN: Async background task updating telemetry snapshot every 3 seconds via spawn_blocking
    // AI: @llm-task telemetry-background-updater @llm-interval 3s
    let telem_bg = Arc::clone(&telemetry);
    let snap_bg = Arc::clone(&system_snapshot);
    tokio::spawn(async move {
        let mut interval = tokio::time::interval(std::time::Duration::from_secs(3));
        loop {
            interval.tick().await;
            let telem_ref = Arc::clone(&telem_bg);
            let updated = tokio::task::spawn_blocking(move || {
                let mut guard = telem_ref.blocking_write();
                guard.collect_snapshot()
            })
            .await;
            if let Ok(new_snap) = updated {
                let mut snap_guard = snap_bg.write().await;
                *snap_guard = new_snap;
            }
        }
    });

    // Contador atômico compartilhado de tarefas
    let active_jobs = Arc::new(AtomicUsize::new(0));

    // Canal broadcast SSE para notificações MCP JSON-RPC
    let (mcp_tx, _mcp_rx) = broadcast::channel::<String>(256);

    // Inicializa roteador de cotas de IA (36 modelos canônicos)
    let quota_router = crate::quota_router::QuotaRouter::new();
    let qr_init = Arc::clone(&quota_router);
    let or_keys_init = config.credentials.openrouter_keys.clone();
    tokio::spawn(async move {
        qr_init.sync_openrouter_status(&or_keys_init).await;
    });

    // Constrói o estado global compartilhado da aplicação
    let state = AppState {
        config: Arc::new(RwLock::new(config.clone())),
        config_path: Arc::new(RwLock::new(config_path)),
        telemetry,
        system_snapshot,
        network,
        inference,
        cluster,
        agent,
        quota_router,
        active_jobs,
        systray,
        http_client: reqwest::Client::builder()
            .timeout(std::time::Duration::from_secs(180))
            .build()?,
        mcp_tx,
    };

    // Se iniciado em modo MCP stdio, executa loop JSON-RPC 2.0 sobre stdin/stdout
    if is_mcp_stdio {
        return mcp::run_stdio_mcp_loop(state).await;
    }

    // Constrói roteador Axum unificado na porta única 8085
    let app = server::create_router(state);
    let listener = listener.expect("Listener must be initialized for non-stdio mode");

    tracing::info!(
        "Serving Axum HTTP + MCP SSE router on http://{}:{}",
        host,
        port
    );
    axum::serve(
        listener,
        app.into_make_service_with_connect_info::<SocketAddr>(),
    )
    .await?;

    Ok(())
}
