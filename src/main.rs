#![deny(warnings)]
// Zorya v2.1.0 Sovereign Rust Daemon / Daemon Rust Soberano Zorya v2.1.0
// Zero Go, Zero Python in final binary / Zero Go, Zero Python no binário final

pub mod config;
pub mod craft;
pub mod mcp;
pub mod network;
pub mod ollama;
pub mod server;
pub mod systray;
pub mod telemetry;

use std::net::SocketAddr;
use std::sync::atomic::AtomicUsize;
use std::sync::Arc;
use tokio::sync::{broadcast, RwLock};
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

use crate::config::ZoryaConfig;
use crate::network::WireGuardManager;
use crate::server::AppState;
use crate::systray::SystrayController;
use crate::telemetry::TelemetryCollector;

// Configure environment for dedicated GTX 1650 4GB VRAM / Configura variáveis de ambiente para os 4GB de VRAM dedicados da GTX 1650
fn configure_dedicated_gpu_environment(vram_mb: u64, gpu_index: u32) {
    // Force CUDA to target the dedicated NVIDIA GTX 1650 while iGPU drives the display / Força o CUDA a usar a GTX 1650 dedicada enquanto a iGPU cuida do display
    std::env::set_var("CUDA_VISIBLE_DEVICES", gpu_index.to_string());
    std::env::set_var("CUDA_DEVICE_ORDER", "PCI_BUS_ID");
    // Offload all possible transformer layers into the 4096 MB dedicated VRAM / Descarrega o máximo de camadas na VRAM dedicada de 4096 MB
    std::env::set_var("OLLAMA_NUM_GPU", "999");
    std::env::set_var("OLLAMA_FLASH_ATTENTION", "1");
    std::env::set_var("OLLAMA_KV_CACHE_TYPE", "q8_0");
    // Prevent spillover into shared RAM when model fits in 4GB VRAM / Impede vazamento para RAM compartilhada quando o modelo cabe nos 4GB de VRAM
    std::env::set_var("GGML_CUDA_ENABLE_UNIFIED_MEMORY", "0");
    std::env::set_var("ZORYA_DEDICATED_VRAM_MB", vram_mb.to_string());
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    // Initialize structured logging / Inicializa o sistema de logs estruturados
    tracing_subscriber::registry()
        .with(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| "zorya=info,tower_http=info".into()),
        )
        .with(tracing_subscriber::fmt::layer())
        .init();

    // Load zorya.toml configuration / Carrega a configuração do arquivo zorya.toml
    let config_path = std::env::var("ZORYA_CONFIG").unwrap_or_else(|_| "zorya.toml".to_string());
    let config = ZoryaConfig::load_or_default(&config_path)?;

    // Optimize hardware environment for GTX 1650 4GB VRAM / Otimiza ambiente de hardware para a GTX 1650 4GB VRAM
    configure_dedicated_gpu_environment(
        config.inference.dedicated_vram_mb.unwrap_or(4096),
        config.inference.gpu_device_index.unwrap_or(0),
    );

    tracing::info!("================================================================");
    tracing::info!("  ZORYA v2.1.0 — SOVEREIGN RUST DAEMON & MCP SERVER             ");
    tracing::info!("  Node / Nó:        {}                                          ", config.network.node_name);
    tracing::info!("  Headscale URL:    {}                                          ", config.network.headscale_url);
    tracing::info!("  Allowed VPS IP:   {}                                          ", config.network.allowed_ip);
    tracing::info!("  Ollama Engine:    {}                                          ", config.inference.ollama_url);
    tracing::info!("  ArtCraft Suite:   {:?}                                        ", config.craft.enabled);
    tracing::info!("  GPU Optimization: GTX 1650 (4096 MB Dedicated VRAM Locked)    ");
    tracing::info!("================================================================");

    // Shared atomic counter of active processing jobs for Systray LED / Contador atômico compartilhado de tarefas ativas para o LED do Systray
    let active_jobs = Arc::new(AtomicUsize::new(0));

    // Spawn Systray controller (gray in idle, blinks #10b981 and #f59e0b every 500ms when active) / Inicia controlador da bandeja (cinza em idle, pisca verde #10b981 e âmbar #f59e0b a cada 500ms em processamento)
    let systray = SystrayController::spawn(Arc::clone(&active_jobs));

    // Start boringtun userspace WireGuard + Headscale coordination loop / Inicia túnel WireGuard em userspace via boringtun + coordenação Headscale
    let wg_manager = Arc::new(WireGuardManager::new(config.network.clone())?);
    wg_manager.spawn_background_tunnel();

    // Initialize system telemetry collector / Inicializa coletor de telemetria do sistema
    let telemetry = Arc::new(RwLock::new(TelemetryCollector::new(
        config.network.node_name.clone(),
        config.inference.dedicated_vram_mb.unwrap_or(4096),
    )));

    // Create SSE broadcast channel for MCP JSON-RPC notifications / Cria canal broadcast SSE para notificações MCP JSON-RPC
    let (mcp_tx, _mcp_rx) = broadcast::channel::<String>(256);

    // Build shared application state / Constrói o estado compartilhado da aplicação
    let state = AppState {
        config: Arc::new(config.clone()),
        telemetry,
        wg_manager,
        active_jobs,
        systray,
        http_client: reqwest::Client::builder()
            .timeout(std::time::Duration::from_secs(180))
            .build()?,
        mcp_tx,
    };

    // Build unified Axum router on single port 8085 / Constrói roteador Axum unificado na porta única 8085
    let port = config.network.listen_port.unwrap_or(8085);
    let app = server::create_router(state);
    let addr = SocketAddr::from(([0, 0, 0, 0], port));

    tracing::info!("Listening on unified HTTP + MCP SSE port / Escutando na porta única HTTP + MCP SSE: http://{}", addr);
    let listener = tokio::net::TcpListener::bind(addr).await?;
    axum::serve(
        listener,
        app.into_make_service_with_connect_info::<SocketAddr>(),
    )
    .await?;

    Ok(())
}
