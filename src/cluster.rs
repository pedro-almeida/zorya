// PT: Orquestrador de Cluster Zorya v3.0 (Mini Zorya VPS <-> Zorya Nitro GTX 1650)
// EN: Zorya v3.0 Cluster Orchestrator (Mini Zorya VPS <-> Zorya Nitro GTX 1650)
// AI: @llm-context Anuncia GET /api/v1/cluster/capabilities, realiza handshake, balanceia carga (leve->VPS, pesada->Nitro) e faz health check a cada 30s
// AI: @llm-invariant Failover automático: se um nó cair no health check de 30s, o outro assume todas as tarefas sem erro para o cliente

use std::sync::Arc;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use axum::{extract::State, http::StatusCode, response::{IntoResponse, Response}, Json};
use serde::{Deserialize, Serialize};
use serde_json::json;
use tokio::sync::RwLock;

use crate::config::ZoryaConfig;
use crate::hardware::{HardwareProfile, NodeProfileKind};
use crate::network::NetworkManager;
use crate::server::AppState;
use crate::systray::SystrayController;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NodeCapabilities {
    pub node_name: String,
    pub role: String, // "nitro" ou "vps"
    pub version: String,
    pub cpu_brand: String,
    pub cpu_cores: usize,
    pub ram_total_mb: u64,
    pub ram_available_mb: u64,
    pub gpu_model: Option<String>,
    pub gpu_vram_mb: u64,
    pub active_backend: String,
    pub available_models: Vec<String>,
    pub craft_modules: Vec<String>,
    pub listen_port: u16,
    pub timestamp: u64,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PeerStatus {
    pub online: bool,
    pub endpoint: String,
    pub capabilities: Option<NodeCapabilities>,
    pub last_heartbeat_epoch: u64,
    pub failover_active: bool,
}

pub struct ClusterCoordinator {
    pub local_capabilities: NodeCapabilities,
    pub peer_status: RwLock<PeerStatus>,
    pub network: Arc<NetworkManager>,
    pub http_client: reqwest::Client,
    pub systray: SystrayController,
}

impl ClusterCoordinator {
    pub fn new(
        config: &ZoryaConfig,
        hw: &HardwareProfile,
        network: Arc<NetworkManager>,
        systray: SystrayController,
    ) -> Arc<Self> {
        let role = match hw.profile_kind {
            NodeProfileKind::NitroGtx1650 => "nitro".to_string(),
            NodeProfileKind::VpsMini => "vps".to_string(),
            NodeProfileKind::GenericWorkstation => config.node.role.clone().unwrap_or_else(|| "nitro".to_string()),
        };

        let local_capabilities = NodeCapabilities {
            node_name: config.network.node_name.clone(),
            role,
            version: "3.0.0".to_string(),
            cpu_brand: hw.cpu_brand.clone(),
            cpu_cores: hw.logical_cores,
            ram_total_mb: hw.total_ram_mb,
            ram_available_mb: hw.available_ram_mb,
            gpu_model: hw.dgpu_model.clone(),
            gpu_vram_mb: hw.dgpu_vram_mb,
            active_backend: hw.active_backend.as_str().to_string(),
            available_models: vec![hw.recommended_model.clone()],
            craft_modules: config.craft.enabled.clone(),
            listen_port: config.network.listen_port.unwrap_or(8085),
            timestamp: SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs(),
        };

        let peer_endpoint = format!(
            "http://{}:{}",
            config.network.allowed_ip,
            config.network.listen_port.unwrap_or(8085)
        );

        let coordinator = Arc::new(Self {
            local_capabilities,
            peer_status: RwLock::new(PeerStatus {
                online: false,
                endpoint: peer_endpoint,
                capabilities: None,
                last_heartbeat_epoch: 0,
                failover_active: true,
            }),
            network,
            http_client: reqwest::Client::builder()
                .timeout(Duration::from_secs(4))
                .build()
                .unwrap_or_default(),
            systray,
        });

        coordinator.spawn_health_check_loop();
        coordinator
    }

    // PT: Loop de health check e handshake a cada 30 segundos
    // EN: 30-second health check and handshake loop
    pub fn spawn_health_check_loop(self: &Arc<Self>) {
        let cluster = Arc::clone(self);
        tokio::spawn(async move {
            let mut ticker = tokio::time::interval(Duration::from_secs(30));
            loop {
                ticker.tick().await;
                let _ = cluster.probe_and_handshake_peer().await;
            }
        });
    }

    pub async fn probe_and_handshake_peer(&self) -> PeerStatus {
        let net_snap = self.network.status_snapshot().await;
        let port = self.local_capabilities.listen_port;
        let peer_url = format!("http://{}:{}", net_snap.preferred_peer_endpoint, port);
        let cap_url = format!("{}/api/v1/cluster/capabilities", peer_url);
        let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs();

        let _net_guard = self.systray.begin_network_work();

        if let Ok(resp) = self.http_client.get(&cap_url).send().await {
            if resp.status().is_success() {
                if let Ok(peer_caps) = resp.json::<NodeCapabilities>().await {
                    // Envia handshake informando nossas próprias capacidades ao peer
                    let hs_url = format!("{}/api/v1/cluster/handshake", peer_url);
                    let _ = self.http_client.post(&hs_url).json(&self.local_capabilities).send().await;

                    let next = PeerStatus {
                        online: true,
                        endpoint: peer_url,
                        capabilities: Some(peer_caps),
                        last_heartbeat_epoch: now,
                        failover_active: false,
                    };
                    self.systray.set_vps_online(true);
                    *self.peer_status.write().await = next.clone();
                    return next;
                }
            }
        }

        // Se o nó remoto caiu, ativa failover local (este nó assume 100% das tarefas)
        let fallback = PeerStatus {
            online: false,
            endpoint: peer_url,
            capabilities: None,
            last_heartbeat_epoch: now,
            failover_active: true,
        };
        self.systray.set_vps_online(false);
        *self.peer_status.write().await = fallback.clone();
        fallback
    }

    // PT: Decide se a tarefa deve ser delegada ao peer (Nitro delega leve -> VPS; VPS escala pesada -> Nitro)
    // EN: Decides whether task should be delegated to peer (Nitro offloads light -> VPS; VPS scales heavy -> Nitro)
    pub async fn target_for_task(&self, is_heavy_task: bool) -> Option<String> {
        let peer = self.peer_status.read().await;
        if !peer.online {
            return None; // Failover ativo: executa localmente
        }
        let my_role = self.local_capabilities.role.as_str();
        if my_role == "nitro" && !is_heavy_task {
            // Nitro delega tarefas leves para o Mini Zorya VPS para preservar os 4GB de VRAM
            return Some(peer.endpoint.clone());
        }
        if my_role == "vps" && is_heavy_task {
            // Mini Zorya VPS escala tarefas pesadas para a GTX 1650 do Nitro
            return Some(peer.endpoint.clone());
        }
        None
    }
}

// GET /api/v1/cluster/capabilities
pub async fn handle_cluster_capabilities(State(state): State<AppState>) -> Response {
    let peer = state.cluster.peer_status.read().await.clone();
    (
        StatusCode::OK,
        Json(json!({
            "local": state.cluster.local_capabilities,
            "peer": peer
        })),
    )
        .into_response()
}

// POST /api/v1/cluster/handshake
pub async fn handle_cluster_handshake(
    State(state): State<AppState>,
    Json(incoming_peer): Json<NodeCapabilities>,
) -> Response {
    let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs();
    let mut peer = state.cluster.peer_status.write().await;
    peer.online = true;
    peer.capabilities = Some(incoming_peer);
    peer.last_heartbeat_epoch = now;
    peer.failover_active = false;
    state.systray.set_vps_online(true);

    (
        StatusCode::OK,
        Json(json!({
            "status": "handshake_accepted",
            "responder": state.cluster.local_capabilities
        })),
    )
        .into_response()
}
