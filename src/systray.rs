// PT: Gerenciador de Systray do Zorya v3.0 com ícone nativo na Bandeja do Windows e fallback Headless para VPS.
// EN: Zorya v3.0 Systray manager with native Windows System Tray icon and automatic Headless fallback for VPS.

use serde::{Deserialize, Serialize};
use std::sync::{Arc, RwLock};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[repr(u8)]
pub enum SystrayState {
    Offline = 0,
    Idle = 1,
    InferenceActive = 2,
    NetworkActive = 3,
    DegradedFallback = 4,
    ExhaustedError = 5,
}

impl SystrayState {
    pub fn rgba_color(&self) -> [u8; 4] {
        match self {
            Self::Offline => [156, 163, 175, 255],          // Cinza
            Self::Idle => [16, 185, 129, 255],              // Verde Esmeralda
            Self::InferenceActive => [59, 130, 246, 255],   // Azul Royal
            Self::NetworkActive => [6, 182, 212, 255],      // Ciano Tailscale
            Self::DegradedFallback => [245, 158, 11, 255],  // Âmbar Ouro
            Self::ExhaustedError => [239, 68, 68, 255],     // Vermelho Alerta
        }
    }

    pub fn label_pt(&self) -> &'static str {
        match self {
            Self::Offline => "Offline",
            Self::Idle => "Pronto (Online na porta 8085)",
            Self::InferenceActive => "IA Ativa (Processando Conteúdo)",
            Self::NetworkActive => "Rede Tailscale / Sincronização Ativa",
            Self::DegradedFallback => "Modo Fallback Cloud Ativo",
            Self::ExhaustedError => "Alerta: Verificar Chaves de API",
        }
    }
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
        let controller = Self {
            inner: Arc::new(RwLock::new(SystrayStatus {
                state: SystrayState::Idle,
                tooltip: format!("Usina Zorya v3.0 — Pronto ({default_model})"),
                active_model: default_model.to_string(),
                vram_used_mb: 0,
                peer_connected: false,
            })),
        };
        controller.spawn_native_windows_tray();
        controller
    }

    #[cfg(all(target_os = "windows", feature = "systray"))]
    fn build_tray_icon_rgba(color: [u8; 4]) -> Option<tray_icon::Icon> {
        let size = 32usize;
        let mut rgba = vec![0u8; size * size * 4];
        let center = (size as f32) / 2.0;
        let radius = 13.0f32;
        for y in 0..size {
            for x in 0..size {
                let dx = (x as f32) - center + 0.5;
                let dy = (y as f32) - center + 0.5;
                let dist = (dx * dx + dy * dy).sqrt();
                let idx = (y * size + x) * 4;
                if dist <= radius {
                    // Borda dourada (#C9A84C) e núcleo na cor do estado
                    if dist >= radius - 2.2 {
                        rgba[idx] = 201;
                        rgba[idx + 1] = 168;
                        rgba[idx + 2] = 76;
                        rgba[idx + 3] = 255;
                    } else {
                        rgba[idx] = color[0];
                        rgba[idx + 1] = color[1];
                        rgba[idx + 2] = color[2];
                        rgba[idx + 3] = color[3];
                    }
                }
            }
        }
        tray_icon::Icon::from_rgba(rgba, size as u32, size as u32).ok()
    }

    #[cfg(all(target_os = "windows", feature = "systray"))]
    pub fn spawn_native_windows_tray(&self) {
        let status_ref = Arc::clone(&self.inner);
        std::thread::spawn(move || {
            use tray_icon::{
                menu::{Menu, MenuEvent, MenuItem},
                TrayIconBuilder, TrayIconEvent,
            };
            use windows_sys::Win32::System::Com::{CoInitializeEx, COINIT_APARTMENTTHREADED};
            use windows_sys::Win32::UI::WindowsAndMessaging::{
                DispatchMessageW, PeekMessageW, TranslateMessage, MSG, PM_REMOVE,
            };

            unsafe {
                CoInitializeEx(std::ptr::null_mut(), COINIT_APARTMENTTHREADED as u32);
            }

            let tray_menu = Menu::new();
            let item_open = MenuItem::new("⚡ Abrir Painel de Configurações (localhost:8085)", true, None);
            let item_tailscale = MenuItem::new("📱 Abrir via Tailscale (100.106.120.35:8085)", true, None);
            let item_quit = MenuItem::new("❌ Encerrar Usina Zorya", true, None);
            let _ = tray_menu.append(&item_open);
            let _ = tray_menu.append(&item_tailscale);
            let _ = tray_menu.append(&item_quit);

            let open_id = item_open.id().clone();
            let ts_id = item_tailscale.id().clone();
            let quit_id = item_quit.id().clone();

            let initial_icon = Self::build_tray_icon_rgba(SystrayState::Idle.rgba_color());
            let mut builder = TrayIconBuilder::new()
                .with_tooltip("Usina Zorya v3.0 — Clique para abrir o Painel de Configurações")
                .with_menu(Box::new(tray_menu));

            if let Some(ic) = initial_icon {
                builder = builder.with_icon(ic);
            }

            let tray_icon = match builder.build() {
                Ok(t) => t,
                Err(e) => {
                    tracing::warn!("Não foi possível criar ícone na bandeja do Windows: {}", e);
                    return;
                }
            };

            let mut last_state = SystrayState::Idle;
            loop {
                unsafe {
                    let mut msg: MSG = std::mem::zeroed();
                    while PeekMessageW(&mut msg, std::ptr::null_mut(), 0, 0, PM_REMOVE) != 0 {
                        TranslateMessage(&msg);
                        DispatchMessageW(&msg);
                    }
                }

                if let Ok(event) = MenuEvent::receiver().try_recv() {
                    if event.id == open_id {
                        let _ = std::process::Command::new("cmd")
                            .args(["/C", "start", "http://127.0.0.1:8085/settings"])
                            .spawn();
                    } else if event.id == ts_id {
                        let _ = std::process::Command::new("cmd")
                            .args(["/C", "start", "http://100.106.120.35:8085/settings"])
                            .spawn();
                    } else if event.id == quit_id {
                        std::process::exit(0);
                    }
                }

                if let Ok(TrayIconEvent::DoubleClick { .. } | TrayIconEvent::Click { button: tray_icon::MouseButton::Left, .. }) =
                    TrayIconEvent::receiver().try_recv()
                {
                    let _ = std::process::Command::new("cmd")
                        .args(["/C", "start", "http://127.0.0.1:8085/settings"])
                        .spawn();
                }

                if let Ok(guard) = status_ref.read() {
                    if guard.state != last_state {
                        last_state = guard.state;
                        if let Some(new_ic) = Self::build_tray_icon_rgba(last_state.rgba_color()) {
                            let _ = tray_icon.set_icon(Some(new_ic));
                        }
                        let _ = tray_icon.set_tooltip(Some(&guard.tooltip));
                    }
                }

                std::thread::sleep(std::time::Duration::from_millis(120));
            }
        });
    }

    #[cfg(not(all(target_os = "windows", feature = "systray")))]
    pub fn spawn_native_windows_tray(&self) {}

    pub fn transition(&self, new_state: SystrayState, reason: &str) {
        if let Ok(mut guard) = self.inner.write() {
            guard.state = new_state;
            guard.tooltip = format!("Zorya v3.0 [{}] — {}", new_state.label_pt(), reason);
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
                guard.tooltip = "Zorya v3.0 — Nó peer offline, operando em modo autônomo".to_string();
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
