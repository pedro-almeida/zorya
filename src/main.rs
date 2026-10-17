#![deny(warnings)]
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
use std::sync::atomic::AtomicUsize;
use std::sync::Arc;
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

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    let args: Vec<String> = std::env::args().collect();
    let is_mcp_stdio = args.len() > 1 && (args[1] == "mcp" || args[1] == "--mcp");

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

    // Load zorya.toml configuration / Carrega a configuração do arquivo zorya.toml
    let config_path = std::env::var("ZORYA_CONFIG").unwrap_or_else(|_| "zorya.toml".to_string());
    let config = ZoryaConfig::load_or_default(&config_path)?;

    // Sonda automática de hardware (CPU AVX2/AVX512, iGPU, dGPU GTX 1650 4GB VRAM dedicada)
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

    // Inicializa gerenciador de rede desacoplado (Tailscale LocalAPI opcional + IP direto)
    let network = NetworkManager::new(config.network.clone(), systray.clone()).await;

    // Inicializa orquestrador de cluster federado VPS + Nitro
    let cluster = ClusterCoordinator::new(&config, &hw, Arc::clone(&network), systray.clone());

    // Inicializa agente inteligente com cofre de chaves e tool calling
    let agent = ZoryaAgent::new(Arc::new(config.clone()), Arc::clone(&inference), systray.clone());

    // Inicializa coletor de telemetria do sistema
    let telemetry = Arc::new(RwLock::new(TelemetryCollector::new(
        config.network.node_name.clone(),
        hw.dgpu_vram_mb.max(4096),
    )));

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
    let port = config.server.port;
    let app = server::create_router(state);
    let addr = SocketAddr::from(([0, 0, 0, 0], port));

    tracing::info!("Listening on unified HTTP + MCP SSE port / Escutando na porta única: http://{}", addr);
    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(
        listener,
        app.into_make_service_with_connect_info::<SocketAddr>(),
    )
    .await?;

    Ok(())
}
