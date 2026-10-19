#![warn(unused_must_use)]
// PT: Zorya v3.0 — Daemon Rust Soberano, Servidor MCP & Coprocessador Multimídia
// EN: Zorya v3.0 — Sovereign Rust Daemon, MCP Server & Multimedia Coprocessor
// AI: @llm-context Coordena inference (GGUF llama-cpp-2), hardware auto-tuning, agent autônomo, servidor MCP duplo, cluster e rede desacoplada

pub mod agent;
pub mod cluster;
pub mod config;
pub mod craft;
pub mod hardware;
pub mod inference;
pub mod mcp;
pub mod network;
pub mod p2p_future;
pub mod server;
pub mod systray;
pub mod telemetry;
pub mod video;

use std::net::SocketAddr;
use std::path::{Path, PathBuf};
use std::sync::atomic::AtomicUsize;
use std::sync::Arc;
use tokio::net::{TcpListener, TcpSocket};
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

/// Resolve o caminho de configuração buscando ZORYA_CONFIG, ./zorya.toml e exe_dir/zorya.toml
/// Retorna ZoryaConfig carregado ou o fallback ZoryaConfig::default() sem abortar o processo
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

    tracing::warn!("zorya.toml não localizado nos caminhos padrão. Utilizando ZoryaConfig::default() soberano.");
    ZoryaConfig::default()
}

pub fn main_config_snapshot() -> ZoryaConfig {
    resolve_config_path()
}

/// Cria um listener TCP resiliente com SO_REUSEADDR imediatamente em LISTENING
pub fn bind_resilient_listener(host: &str, port: u16) -> anyhow::Result<TcpListener> {
    let addr: SocketAddr = format!("{}:{}", host, port)
        .parse()
        .unwrap_or_else(|_| SocketAddr::from(([0, 0, 0, 0], port)));

    let socket = if addr.is_ipv4() {
        TcpSocket::new_v4()?
    } else {
        TcpSocket::new_v6()?
    };

    socket.set_reuseaddr(true)?;
    #[cfg(unix)]
    {
        let _ = socket.set_reuseport(true);
    }

    socket.bind(addr)?;
    let listener = socket.listen(1024)?;
    Ok(listener)
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
    let config = resolve_config_path();

    // 2. Garante que a porta 8085 entre imediatamente em LISTENING antes de tarefas demoradas
    let port = config.server.port;
    let host = &config.server.host;
    let listener = if !is_mcp_stdio {
        let l = bind_resilient_listener(host, port)?;
        tracing::info!("Unified TCP socket bound & listening immediately on http://{}:{}", host, port);
        Some(l)
    } else {
        None
    };

    // 3. Sonda de hardware executada com a porta já em LISTENING
    let hw = HardwareProfile::detect();

    if !is_mcp_stdio {
        tracing::info!("================================================================");
        tracing::info!("  ZORYA v3.0 — SOVEREIGN RUST DAEMON & NATIVE INFERENCE          ");
        tracing::info!("  Node / Nó:        {}                                          ", config.network.node_name);
        tracing::info!("  Profile / Perfil: {:?} (Backend: {:?})                        ", hw.profile_kind, hw.active_backend);
        tracing::info!("  CPU:              {} ({} cores)                              ", hw.cpu_brand, hw.logical_cores);
        tracing::info!("  GPU Model:        {:?} ({} MB VRAM)                          ", hw.dgpu_model, hw.dgpu_vram_mb);
        tracing::info!("  Recommended Model:{} (ctx {})                                ", hw.recommended_model, hw.recommended_ctx_size);
        tracing::info!("  ArtCraft Suite:   {:?}                                        ", config.craft.enabled);
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
    let agent = ZoryaAgent::new(Arc::new(config.clone()), Arc::clone(&inference), systray.clone());

    // Inicializa coletor de telemetria do sistema alimentado pelo HardwareProfile real
    let telemetry = Arc::new(RwLock::new(
        TelemetryCollector::new(
            config.network.node_name.clone(),
            hw.dgpu_vram_mb,
        ).with_hw_profile(hw.dgpu_model.clone(), hw.dgpu_vram_mb),
    ));

    // Contador atômico compartilhado de tarefas
    let active_jobs = Arc::new(AtomicUsize::new(0));

    // Canal broadcast SSE para notificações MCP JSON-RPC
    let (mcp_tx, _mcp_rx) = broadcast::channel::<String>(256);

    // Constrói o estado global compartilhado da aplicação
    let state = AppState {
        config: Arc::new(config.clone()),
        telemetry,
        network,
        inference,
        cluster,
        agent,
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

    tracing::info!("Serving Axum HTTP + MCP SSE router on http://{}:{}", host, port);
    axum::serve(
        listener,
        app.into_make_service_with_connect_info::<SocketAddr>(),
    )
    .await?;

    Ok(())
}
