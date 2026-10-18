// PT: Gerenciador de Systray de 4 estados do Zorya v3.0 com fallback Headless para VPS.
// EN: Zorya v3.0 4-state Systray manager with automatic Headless fallback for VPS.
// AI: @llm-module systray
// AI: @llm-states Idle,InferenceActive,DegradedFallback,ExhaustedError

use serde::{Deserialize, Serialize};
use std::sync::{Arc, RwLock};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[repr(u8)]
pub enum SystrayState {
    /// Cinza: Offline / Desconectado.
    Offline = 0,
    /// Verde: Pronto, modelo aquecido e cluster saudável.
    Idle = 1,
    /// Azul ("IA Ativa"): Executando inferência local GGUF ou DSP ArtCraft.
    InferenceActive = 2,
    /// Ciano ("Rede"): Sincronizando malha de rede / cluster / tráfego Tailscale.
    NetworkActive = 3,
    /// Âmbar: Operando em fallback (par do cluster offline ou usando API externa).
    DegradedFallback = 4,
    /// Vermelho: Alerta crítico (todas as chaves de API esgotadas ou erro de VRAM).
    ExhaustedError = 5,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct SystrayStatus {
    pub state: SystrayState,
    pub tooltip: String,
    pub active_model: String,
    pub vram_used_mb: u64,
    pub peer_connected: bool,
}

#[derive(Clone)]
pub struct SystrayController {
    inner: Arc<RwLock<SystrayStatus>>,
}

impl SystrayController {
    pub fn new(default_model: &str) -> Self {
        Self {
            inner: Arc::new(RwLock::new(SystrayStatus {
                state: SystrayState::Idle,
                tooltip: format!("Zorya v3.0 — Pronto ({default_model})"),
                active_model: default_model.to_string(),
                vram_used_mb: 0,
                peer_connected: false,
            })),
        }
    }

    pub fn transition(&self, new_state: SystrayState, reason: &str) {
        if let Ok(mut guard) = self.inner.write() {
            guard.state = new_state;
            guard.tooltip = format!("Zorya v3.0 [{:?}] — {}", new_state, reason);
        }
    }

    pub fn snapshot(&self) -> SystrayStatus {
        self.inner.read().unwrap().clone()
    }

    pub fn begin_ai_work(&self) -> SystrayGuard {
        self.transition(SystrayState::InferenceActive, "Processando inferência / tarefa de IA");
        SystrayGuard {
            controller: self.clone(),
            restore_state: SystrayState::Idle,
            restore_reason: "Pronto",
        }
    }

    pub fn begin_network_work(&self) -> SystrayGuard {
        self.transition(SystrayState::NetworkActive, "Sincronizando malha de rede / cluster");
        SystrayGuard {
            controller: self.clone(),
            restore_state: SystrayState::Idle,
            restore_reason: "Pronto",
        }
    }

    pub fn begin_work(&self) -> SystrayGuard {
        self.begin_ai_work()
    }

    pub fn notify_keys_exhausted(&self, provider: &str) {
        self.transition(
            SystrayState::ExhaustedError,
            &format!("Chaves esgotadas para {}", provider),
        );
    }

    pub fn set_vps_online(&self, online: bool) {
        if let Ok(mut guard) = self.inner.write() {
            guard.peer_connected = online;
            if !online && guard.state == SystrayState::Idle {
                guard.state = SystrayState::DegradedFallback;
                guard.tooltip = "Zorya v3.0 [DegradedFallback] — Nó peer offline, operando em fallback local".to_string();
            } else if online && guard.state == SystrayState::DegradedFallback {
                guard.state = SystrayState::Idle;
                guard.tooltip = format!("Zorya v3.0 — Pronto ({})", guard.active_model);
            }
        }
    }

    pub fn update_vps_status(&self, online: bool) {
        self.set_vps_online(online);
    }
}

pub struct SystrayGuard {
    controller: SystrayController,
    restore_state: SystrayState,
    restore_reason: &'static str,
}

impl Drop for SystrayGuard {
    fn drop(&mut self) {
        self.controller.transition(self.restore_state, self.restore_reason);
    }
}
