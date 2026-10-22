// PT: Gerenciador de Systray Vivo do Zorya v3.0 com Animações Procedurais e Debounce de Navegador
// EN: Zorya v3.0 Dynamic Systray Manager with Procedural Animations and Browser Launch Debouncing
// AI: @llm-module systray | @llm-context Ícone procedural vivo com respiração em standby, cintilância mística em IA e LED RJ45 em rede

use serde::{Deserialize, Serialize};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, RwLock};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

static LAST_BROWSER_OPEN_MS: AtomicU64 = AtomicU64::new(0);

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
    pub fn label_pt(&self) -> &'static str {
        match self {
            Self::Offline => "Offline",
            Self::Idle => "Standby • Pronto (Online na porta 8085)",
            Self::InferenceActive => "⚡ Processando IA (GTX 1650 Ativa)",
            Self::NetworkActive => "🌐 Sincronizando Malha Tailscale",
            Self::DegradedFallback => "Modo Autônomo / Nuvem",
            Self::ExhaustedError => "Atenção: Verificar Chaves de API",
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

    /// PT: Gera frame RGBA 32x32 da Insígnia Zorya com efeitos procedurais
    #[cfg(all(target_os = "windows", feature = "systray"))]
    fn render_procedural_frame(state: SystrayState, tick: u64) -> Option<tray_icon::Icon> {
        let size = 32usize;
        let mut rgba = vec![0u8; size * size * 4];
        let center = (size as f32) / 2.0;

        let t = (tick as f32) * 0.15;
        let breath = ((t.sin() + 1.0) * 0.5).clamp(0.0, 1.0);

        for y in 0..size {
            for x in 0..size {
                let dx = (x as f32) - center + 0.5;
                let dy = (y as f32) - center + 0.5;
                let dist = (dx * dx + dy * dy).sqrt();
                let angle = dy.atan2(dx);
                let idx = (y * size + x) * 4;

                let star_mod = (angle * 4.0 + if state == SystrayState::InferenceActive { t * 1.5 } else { 0.0 }).cos().abs() * 2.2;
                let max_radius = 11.5 + star_mod;

                if dist <= max_radius {
                    match state {
                        SystrayState::Idle => {
                            // Standby: Anel dourado e núcleo esmeralda com respiração suave
                            if dist >= max_radius - 2.0 {
                                rgba[idx] = 201; // #C9A84C Gold
                                rgba[idx + 1] = 168;
                                rgba[idx + 2] = 76;
                                rgba[idx + 3] = 255;
                            } else {
                                let g_val = (140.0 + breath * 60.0) as u8;
                                rgba[idx] = 16;
                                rgba[idx + 1] = g_val;
                                rgba[idx + 2] = 100;
                                rgba[idx + 3] = 240;
                            }
                        }
                        SystrayState::InferenceActive => {
                            // Processando IA: Cintilância mística / gradiente mágico rotativo
                            let magic_wave = ((t * 2.0 - dist * 0.5).sin() * 0.5 + 0.5).clamp(0.0, 1.0);
                            let r_pulse = (160.0 + magic_wave * 95.0) as u8;
                            let b_pulse = (210.0 + magic_wave * 45.0) as u8;
                            if dist >= max_radius - 2.5 {
                                rgba[idx] = 245; // Âmbar vivo
                                rgba[idx + 1] = 180;
                                rgba[idx + 2] = 35;
                                rgba[idx + 3] = 255;
                            } else {
                                rgba[idx] = r_pulse;     // Violeta elétrico / Magia
                                rgba[idx + 1] = 90;
                                rgba[idx + 2] = b_pulse;
                                rgba[idx + 3] = 255;
                            }
                        }
                        SystrayState::NetworkActive => {
                            // Tailscale / Rede: Piscar estilo LED RJ45 da placa de rede
                            let is_led_on = (tick % 6) < 3;
                            if is_led_on {
                                rgba[idx] = 6;      // Ciano brilhante / LED Link
                                rgba[idx + 1] = 214;
                                rgba[idx + 2] = 160;
                                rgba[idx + 3] = 255;
                            } else {
                                rgba[idx] = 245;    // Âmbar / LED Activity
                                rgba[idx + 1] = 158;
                                rgba[idx + 2] = 11;
                                rgba[idx + 3] = 255;
                            }
                        }
                        SystrayState::DegradedFallback => {
                            rgba[idx] = 217;
                            rgba[idx + 1] = 119;
                            rgba[idx + 2] = 6;
                            rgba[idx + 3] = 230;
                        }
                        SystrayState::ExhaustedError => {
                            rgba[idx] = 239;
                            rgba[idx + 1] = 68;
                            rgba[idx + 2] = 68;
                            rgba[idx + 3] = 255;
                        }
                        SystrayState::Offline => {
                            rgba[idx] = 120;
                            rgba[idx + 1] = 120;
                            rgba[idx + 2] = 120;
                            rgba[idx + 3] = 200;
                        }
                    }
                }
            }
        }
        tray_icon::Icon::from_rgba(rgba, size as u32, size as u32).ok()
    }

    /// PT: Abre navegador com proteção contra múltiplas abas concorrentes (debounce de 1500ms)
    fn open_browser_debounced(url: &str) {
        let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_millis() as u64;
        let last = LAST_BROWSER_OPEN_MS.load(Ordering::Relaxed);
        if now.saturating_sub(last) < 1500 {
            return;
        }
        LAST_BROWSER_OPEN_MS.store(now, Ordering::Relaxed);
        let _ = std::process::Command::new("cmd")
            .args(["/C", "start", url])
            .spawn();
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
            let item_open = MenuItem::new("⚡ Abrir Painel de Controle (localhost:8085)", true, None);
            let item_tailscale = MenuItem::new("📱 Acesso Mobile Tailscale (100.106.120.35:8085)", true, None);
            let item_quit = MenuItem::new("❌ Encerrar Usina Zorya", true, None);
            let _ = tray_menu.append(&item_open);
            let _ = tray_menu.append(&item_tailscale);
            let _ = tray_menu.append(&item_quit);

            let open_id = item_open.id().clone();
            let ts_id = item_tailscale.id().clone();
            let quit_id = item_quit.id().clone();

            let initial_icon = Self::render_procedural_frame(SystrayState::Idle, 0);
            let mut builder = TrayIconBuilder::new()
                .with_tooltip("Usina Zorya v3.0 — Painel de Controle")
                .with_menu(Box::new(tray_menu));

            if let Some(ic) = initial_icon {
                builder = builder.with_icon(ic);
            }

            let tray_icon = match builder.build() {
                Ok(t) => t,
                Err(e) => {
                    tracing::warn!("Não foi possível inicializar a bandeja do Windows: {}", e);
                    return;
                }
            };

            let mut tick = 0u64;
            loop {
                unsafe {
                    let mut msg: MSG = std::mem::zeroed();
                    while PeekMessageW(&mut msg, std::ptr::null_mut(), 0, 0, PM_REMOVE) != 0 {
                        TranslateMessage(&msg);
                        DispatchMessageW(&msg);
                    }
                }

                // Processa clique no menu com debounce
                if let Ok(event) = MenuEvent::receiver().try_recv() {
                    if event.id == open_id {
                        Self::open_browser_debounced("http://127.0.0.1:8085/settings");
                    } else if event.id == ts_id {
                        Self::open_browser_debounced("http://100.106.120.35:8085/settings");
                    } else if event.id == quit_id {
                        std::process::exit(0);
                    }
                }

                // Processa clique direto no ícone (apenas DoubleClick OU Click único, descartando o redundante)
                while let Ok(tray_evt) = TrayIconEvent::receiver().try_recv() {
                    match tray_evt {
                        TrayIconEvent::DoubleClick { .. } => {
                            Self::open_browser_debounced("http://127.0.0.1:8085/settings");
                        }
                        TrayIconEvent::Click { button: tray_icon::MouseButton::Left, .. } => {
                            Self::open_browser_debounced("http://127.0.0.1:8085/settings");
                        }
                        _ => {}
                    }
                }

                // Animação procedural viva a cada ~90ms
                if let Ok(guard) = status_ref.read() {
                    if let Some(frame) = Self::render_procedural_frame(guard.state, tick) {
                        let _ = tray_icon.set_icon(Some(frame));
                    }
                    if tick % 20 == 0 {
                        let _ = tray_icon.set_tooltip(Some(&guard.tooltip));
                    }
                }

                tick = tick.wrapping_add(1);
                std::thread::sleep(Duration::from_millis(90));
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
        self.transition(SystrayState::InferenceActive, "Processando IA / Inferência Neural");
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
