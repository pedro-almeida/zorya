// PT: Gerenciador de Rede Desacoplado (Tailscale LocalAPI Opcional + Fallback IP Direto)
// EN: Decoupled Network Manager (Optional Tailscale LocalAPI + Direct IP Fallback)
// AI: @llm-context Sonda http://localhost:41112/localapi/v0/status; se ativo extrai 100.x.y.z e MagicDNS; caso contrário opera 100% via IP do zorya.toml
// AI: @llm-invariant Zero dependência obrigatória do Tailscale; reconexão automática em background se o Tailscale iniciar depois do Zorya

use std::net::IpAddr;
use std::sync::Arc;
use std::time::Duration;

use serde::{Deserialize, Serialize};
use serde_json::Value;
use tokio::sync::RwLock;

use crate::config::NetworkConfig;
use crate::systray::SystrayController;

pub const TAILSCALE_LOCALAPI_STATUS_URL: &str = "http://localhost:41112/localapi/v0/status";

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct NetworkStateSnapshot {
    pub tailscale_active: bool,
    pub tailscale_ip: Option<String>,
    pub magic_dns_hostname: Option<String>,
    pub preferred_bind_ip: String,
    pub preferred_peer_endpoint: String,
    pub mode: String, // "tailscale_mesh" ou "direct_ip"
}

pub struct NetworkManager {
    pub config: NetworkConfig,
    pub state: RwLock<NetworkStateSnapshot>,
    pub http_client: reqwest::Client,
    pub systray: SystrayController,
}

impl NetworkManager {
    /// Inicializa o gerenciador de rede de modo 100% não bloqueante (One-Shot / Instantâneo).
    /// O estado inicial é configurado como Loopback sem aguardar chamada HTTP da API do Tailscale,
    /// deixando qualquer sonda de conectividade exclusivamente para a tarefa de background.
    pub fn new_nonblocking(config: NetworkConfig, systray: SystrayController) -> Arc<Self> {
        let http_client = reqwest::Client::builder()
            .timeout(Duration::from_millis(900))
            .build()
            .unwrap_or_default();

        let fallback_ip = config
            .fallback_ip
            .clone()
            .unwrap_or_else(|| "127.0.0.1".to_string());

        let initial_snapshot = NetworkStateSnapshot {
            tailscale_active: false,
            tailscale_ip: None,
            magic_dns_hostname: None,
            preferred_bind_ip: fallback_ip,
            preferred_peer_endpoint: config.allowed_ip.clone(),
            mode: "loopback".to_string(),
        };

        let manager = Arc::new(Self {
            config,
            state: RwLock::new(initial_snapshot),
            http_client,
            systray,
        });

        manager.spawn_health_check_loop();
        manager
    }

    pub fn spawn_health_check_loop(self: &Arc<Self>) {
        self.spawn_auto_reconnect_watchdog();
    }

    pub async fn new(config: NetworkConfig, systray: SystrayController) -> Arc<Self> {
        Self::new_nonblocking(config, systray)
    }

    // PT: Sonda a API local do Tailscale (localhost:41112) ou faz fallback imediato para o IP de zorya.toml
    // EN: Probes local Tailscale API (localhost:41112) or immediately falls back to zorya.toml IP
    pub async fn probe_network_state(
        client: &reqwest::Client,
        config: &NetworkConfig,
    ) -> NetworkStateSnapshot {
        if let Ok(resp) = client.get(TAILSCALE_LOCALAPI_STATUS_URL).send().await {
            if resp.status().is_success() {
                if let Ok(json_body) = resp.json::<Value>().await {
                    let backend_state = json_body
                        .get("BackendState")
                        .and_then(|v| v.as_str())
                        .unwrap_or("");

                    let ts_ip = json_body
                        .get("TailscaleIPs")
                        .and_then(|v| v.as_array())
                        .and_then(|arr| {
                            arr.iter()
                                .filter_map(|ip| ip.as_str())
                                .find(|ip| ip.starts_with("100."))
                        })
                        .map(|s| s.to_string())
                        .or_else(|| {
                            json_body
                                .get("Self")
                                .and_then(|s| s.get("TailscaleIPs"))
                                .and_then(|v| v.as_array())
                                .and_then(|arr| {
                                    arr.iter()
                                        .filter_map(|ip| ip.as_str())
                                        .find(|ip| ip.starts_with("100."))
                                })
                                .map(|s| s.to_string())
                        });

                    let magic_dns = json_body
                        .get("Self")
                        .and_then(|s| s.get("DNSName"))
                        .and_then(|v| v.as_str())
                        .map(|s| s.trim_end_matches('.').to_string());

                    if backend_state == "Running" || ts_ip.is_some() {
                        let mesh_ip = ts_ip.unwrap_or_else(|| "100.64.0.1".to_string());
                        let peer_host = config
                            .tailscale_peer_hostname
                            .clone()
                            .unwrap_or_else(|| config.allowed_ip.clone());
                        return NetworkStateSnapshot {
                            tailscale_active: true,
                            tailscale_ip: Some(mesh_ip.clone()),
                            magic_dns_hostname: magic_dns,
                            preferred_bind_ip: mesh_ip,
                            preferred_peer_endpoint: peer_host,
                            mode: "tailscale_mesh".to_string(),
                        };
                    }
                }
            }
        }

        // Fallback 100% funcional sem Tailscale usando o IP de zorya.toml
        let fallback_ip = config
            .fallback_ip
            .clone()
            .unwrap_or_else(|| "0.0.0.0".to_string());
        NetworkStateSnapshot {
            tailscale_active: false,
            tailscale_ip: None,
            magic_dns_hostname: None,
            preferred_bind_ip: fallback_ip,
            preferred_peer_endpoint: config.allowed_ip.clone(),
            mode: "direct_ip".to_string(),
        }
    }

    // PT: Monitora a cada 15s e reconecta automaticamente se o Tailscale for iniciado depois do Zorya
    // EN: Monitors every 15s and automatically reconnects if Tailscale starts after Zorya
    pub fn spawn_auto_reconnect_watchdog(self: &Arc<Self>) {
        let manager = Arc::clone(self);
        tokio::spawn(async move {
            let mut interval = tokio::time::interval(Duration::from_secs(15));
            loop {
                interval.tick().await;
                let next_state =
                    Self::probe_network_state(&manager.http_client, &manager.config).await;
                let mut current = manager.state.write().await;
                if !current.tailscale_active && next_state.tailscale_active {
                    tracing::info!(
                        "Tailscale detectado dinamicamente! Alternando para malha {:?} ({:?})",
                        next_state.tailscale_ip,
                        next_state.magic_dns_hostname
                    );
                } else if current.tailscale_active && !next_state.tailscale_active {
                    tracing::warn!(
                        "Tailscale desconectado. Alternando sem interrupção para IP direto: {}",
                        next_state.preferred_peer_endpoint
                    );
                }
                *current = next_state;
            }
        });
    }

    pub async fn status_snapshot(&self) -> NetworkStateSnapshot {
        self.state.read().await.clone()
    }

    pub fn is_ip_authorized(&self, ip: IpAddr) -> bool {
        if ip.is_loopback() {
            return true;
        }
        if let Ok(allowed) = self.config.allowed_ip.parse::<IpAddr>() {
            if ip == allowed {
                return true;
            }
        }
        match ip {
            IpAddr::V4(v4) => {
                let octets = v4.octets();
                // Permite CGNAT Tailscale (100.64.0.0/10) e LAN privada (192.168.x.x / 10.x.x.x / 172.16-31.x.x)
                let is_tailscale = octets[0] == 100 && (octets[1] & 0b1100_0000) == 64;
                let is_private_lan = v4.is_private();
                is_tailscale || is_private_lan
            }
            IpAddr::V6(_) => false,
        }
    }
}
