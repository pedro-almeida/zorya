// Userspace WireGuard (boringtun) + Headscale Coordinator / WireGuard em userspace (boringtun) + Coordenador Headscale
// Strictly uses self-hosted Headscale on Mriya VPS (zero Tailscale Inc. servers) / Usa estritamente Headscale self-hosted na VPS Mriya (zero servidores da Tailscale Inc.)

use std::net::{IpAddr, SocketAddr};
use std::sync::Arc;
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use boringtun::noise::{Tunn, TunnResult};
use rand_core::OsRng;
use serde::{Deserialize, Serialize};
use tokio::net::UdpSocket;
use tokio::sync::Mutex;
use x25519_dalek::{PublicKey, StaticSecret};

use crate::config::NetworkConfig;

// Headscale node registration payload / Payload de registro de nó no Headscale
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct HeadscaleRegisterRequest {
    pub node_key: String,
    pub hostname: String,
    pub auth_key: String,
    pub wireguard_pubkey: String,
    pub listen_port: u16,
}

// Headscale peer descriptor / Descritor de peer retornado pelo Headscale
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct HeadscalePeerResponse {
    pub peer_public_key: String,
    pub endpoint: String,
    pub assigned_ip: String,
}

// Manages boringtun WireGuard session and IP firewall / Gerencia sessão WireGuard boringtun e firewall de IP
pub struct WireGuardManager {
    config: NetworkConfig,
    local_private_key: StaticSecret,
    local_public_key: PublicKey,
    tunnel: Arc<Mutex<Option<Box<Tunn>>>>,
}

impl WireGuardManager {
    // Initialize keys and boringtun state / Inicializa chaves X25519 e estado do boringtun
    pub fn new(config: NetworkConfig) -> anyhow::Result<Self> {
        let local_private_key = StaticSecret::random_from_rng(OsRng);
        let local_public_key = PublicKey::from(&local_private_key);

        // Create initial peer public key from deterministic VPS seed until Headscale handshake completes / Cria chave pública inicial até concluir o handshake com o Headscale
        let peer_seed = StaticSecret::from([0x42u8; 32]);
        let peer_pub = PublicKey::from(&peer_seed);

        let tunn = Tunn::new(
            local_private_key.clone(),
            peer_pub,
            None,
            Some(25),
            0,
            None,
        )
        .map_err(|e| anyhow::anyhow!("Failed to initialize boringtun Tunn / Falha ao inicializar boringtun Tunn: {:?}", e))?;

        Ok(Self {
            config,
            local_private_key,
            local_public_key,
            tunnel: Arc::new(Mutex::new(Some(Box::new(tunn)))),
        })
    }

    // Return Base64-encoded WireGuard public key / Retorna chave pública WireGuard codificada em Base64
    pub fn public_key_base64(&self) -> String {
        BASE64.encode(self.local_public_key.as_bytes())
    }

    // Spawn background task for Headscale registration and UDP WireGuard keepalive / Inicia tarefa em background para registro no Headscale e keepalive UDP WireGuard
    pub fn spawn_background_tunnel(self: &Arc<Self>) {
        let manager = Arc::clone(self);
        tokio::spawn(async move {
            if let Err(err) = manager.run_tunnel_loop().await {
                tracing::warn!(
                    "WireGuard userspace loop warning / Aviso no loop WireGuard em userspace: {}",
                    err
                );
            }
        });
    }

    // Coordinate with Headscale and maintain boringtun UDP socket / Coordena com o Headscale e mantém socket UDP do boringtun
    async fn run_tunnel_loop(&self) -> anyhow::Result<()> {
        let wg_port = self.config.wireguard_port.unwrap_or(51820);
        let bind_addr = format!("0.0.0.0:{}", wg_port);
        let socket = match UdpSocket::bind(&bind_addr).await {
            Ok(s) => s,
            Err(_) => UdpSocket::bind("0.0.0.0:0").await?,
        };

        tracing::info!(
            "boringtun userspace WireGuard bound on {:?} (pubkey: {}) / boringtun WireGuard em userspace ativo em {:?} (chave pública: {})",
            socket.local_addr()?,
            self.public_key_base64(),
            socket.local_addr()?,
            self.public_key_base64()
        );

        // Register node with self-hosted Headscale coordinator / Registra nó no coordenador Headscale self-hosted
        self.register_with_headscale(wg_port).await;

        let peer_endpoint: SocketAddr = format!("{}:51820", self.config.allowed_ip)
            .parse()
            .unwrap_or_else(|_| SocketAddr::from(([143, 95, 223, 218], 51820)));

        let mut recv_buf = vec![0u8; 65535];
        let mut dst_buf = vec![0u8; 65535];
        let mut keepalive_interval = tokio::time::interval(std::time::Duration::from_secs(25));

        loop {
            tokio::select! {
                _ = keepalive_interval.tick() => {
                    let mut guard = self.tunnel.lock().await;
                    if let Some(ref mut tunn) = *guard {
                        match tunn.update_timers(&mut dst_buf) {
                            TunnResult::WriteToNetwork(packet) => {
                                let _ = socket.send_to(packet, peer_endpoint).await;
                            }
                            TunnResult::Err(e) => {
                                tracing::debug!("boringtun timer update notice / Aviso de timer boringtun: {:?}", e);
                            }
                            _ => {}
                        }
                    }
                }
                recv_res = socket.recv_from(&mut recv_buf) => {
                    if let Ok((len, src_addr)) = recv_res {
                        let mut guard = self.tunnel.lock().await;
                        if let Some(ref mut tunn) = *guard {
                            match tunn.decapsulate(Some(src_addr.ip()), &recv_buf[..len], &mut dst_buf) {
                                TunnResult::WriteToNetwork(packet) => {
                                    let _ = socket.send_to(packet, src_addr).await;
                                }
                                TunnResult::WriteToTunnelV4(packet, _) | TunnResult::WriteToTunnelV6(packet, _) => {
                                    tracing::debug!(
                                        "Decapsulated {} bytes from WireGuard peer {} / Decapsulados {} bytes do peer WireGuard {}",
                                        packet.len(),
                                        src_addr,
                                        packet.len(),
                                        src_addr
                                    );
                                }
                                _ => {}
                            }
                        }
                    }
                }
            }
        }
    }

    // Perform HTTP registration against Headscale server on VPS / Executa registro HTTP contra o servidor Headscale na VPS
    async fn register_with_headscale(&self, wg_port: u16) {
        let url = format!(
            "{}/api/v1/node/register",
            self.config.headscale_url.trim_end_matches('/')
        );
        let payload = HeadscaleRegisterRequest {
            node_key: self.public_key_base64(),
            hostname: self.config.node_name.clone(),
            auth_key: self.config.headscale_auth_key.clone(),
            wireguard_pubkey: self.public_key_base64(),
            listen_port: wg_port,
        };

        let client = match reqwest::Client::builder()
            .timeout(std::time::Duration::from_secs(10))
            .build()
        {
            Ok(c) => c,
            Err(_) => return,
        };

        match client
            .post(&url)
            .bearer_auth(&self.config.headscale_auth_key)
            .json(&payload)
            .send()
            .await
        {
            Ok(resp) if resp.status().is_success() => {
                if let Ok(peer) = resp.json::<HeadscalePeerResponse>().await {
                    if let Ok(raw_key) = BASE64.decode(peer.peer_public_key.as_bytes()) {
                        if let Ok(key_bytes) = <[u8; 32]>::try_from(raw_key.as_slice()) {
                            let peer_pub = PublicKey::from(key_bytes);
                            if let Ok(new_tunn) = Tunn::new(
                                self.local_private_key.clone(),
                                peer_pub,
                                None,
                                Some(25),
                                1,
                                None,
                            ) {
                                let mut guard = self.tunnel.lock().await;
                                *guard = Some(Box::new(new_tunn));
                                tracing::info!(
                                    "Registered with Headscale ({}), assigned mesh IP {} / Registrado no Headscale ({}), IP mesh atribuído {}",
                                    self.config.headscale_url,
                                    peer.assigned_ip,
                                    self.config.headscale_url,
                                    peer.assigned_ip
                                );
                            }
                        }
                    }
                }
            }
            Ok(resp) => {
                tracing::info!(
                    "Headscale endpoint {} responded HTTP {} (standing by for peer handshake) / Endpoint Headscale {} respondeu HTTP {} (aguardando handshake)",
                    url,
                    resp.status(),
                    url,
                    resp.status()
                );
            }
            Err(e) => {
                tracing::info!(
                    "Headscale coordinator unreachable at {} ({}), operating in local + direct VPS mode / Coordenador Headscale inacessível em {} ({}), operando em modo local + VPS direto",
                    url,
                    e,
                    url,
                    e
                );
            }
        }
    }

    // Validate whether incoming client IP is authorized (localhost, CGNAT 100.64.0.0/10 Headscale mesh, or allowed VPS IP) / Valida se o IP do cliente é autorizado (localhost, malha Headscale 100.64.0.0/10 ou IP da VPS permitido)
    pub fn is_ip_authorized(&self, ip: IpAddr) -> bool {
        let canonical = match ip {
            IpAddr::V6(v6) => v6
                .to_ipv4_mapped()
                .map(IpAddr::V4)
                .unwrap_or(IpAddr::V6(v6)),
            other => other,
        };

        if canonical.is_loopback() {
            return true;
        }

        if let Ok(allowed_vps) = self.config.allowed_ip.parse::<IpAddr>() {
            if canonical == allowed_vps {
                return true;
            }
        }

        if let IpAddr::V4(v4) = canonical {
            let octets = v4.octets();
            // Allow Headscale WireGuard mesh CGNAT range 100.64.0.0/10 and private LAN / Permite faixa CGNAT 100.64.0.0/10 do Headscale e LAN local
            if octets[0] == 100 && (64..=127).contains(&octets[1]) {
                return true;
            }
            if octets[0] == 127 || octets[0] == 10 || (octets[0] == 192 && octets[1] == 168) {
                return true;
            }
        }

        false
    }
}
