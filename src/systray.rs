// PT: Gerenciador de Systray Vivo do Zorya v3.0 com Animações Procedurais e Debounce de Navegador
// EN: Zorya v3.0 Dynamic Systray Manager with Procedural Animations and Browser Launch Debouncing
// AI: @llm-module systray | @llm-context Ícone procedural vivo com respiração em standby, cintilância mística em IA e LED RJ45 em rede

use serde::{Deserialize, Serialize};
#[cfg(all(target_os = "windows", feature = "systray"))]
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, RwLock};
#[cfg(all(target_os = "windows", feature = "systray"))]
use std::time::{Duration, SystemTime, UNIX_EPOCH};

#[cfg(all(target_os = "windows", feature = "systray"))]
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

    /// PT: Carrega o ícone oficial a partir de assets/icon.png embutido e aplica indicador estático por estado
    /// EN: Loads official icon from embedded assets/icon.png and applies static status badge per state
    /// AI: @llm-func generate_state_icon @llm-perf zero-gdi-leak
    #[cfg(all(target_os = "windows", feature = "systray"))]
    fn generate_state_icon(base_bytes: &[u8], state: SystrayState) -> Option<tray_icon::Icon> {
        let dyn_img = image::load_from_memory(base_bytes).ok()?;
        let mut resized = dyn_img
            .resize_exact(32, 32, image::imageops::FilterType::Lanczos3)
            .to_rgba8();

        // Badge indicador 6x6 no canto inferior direito para refletir estado de saúde
        let (badge_r, badge_g, badge_b) = match state {
            SystrayState::Idle => (34, 197, 94),            // Verde #22C55E
            SystrayState::InferenceActive => (234, 179, 8), // Dourado/Âmbar #EAB308
            SystrayState::NetworkActive => (6, 182, 212),   // Ciano #06B6D4
            SystrayState::DegradedFallback => (249, 115, 22), // Laranja #F97316
            SystrayState::ExhaustedError => (239, 68, 68),  // Vermelho #EF4444
            SystrayState::Offline => (156, 163, 175),       // Cinza #9CA3AF
        };

        for y in 24..30 {
            for x in 24..30 {
                let dx = (x as f32) - 26.5;
                let dy = (y as f32) - 26.5;
                if dx * dx + dy * dy <= 6.25 {
                    resized.put_pixel(x, y, image::Rgba([badge_r, badge_g, badge_b, 255]));
                }
            }
        }

        let (w, h) = resized.dimensions();
        tray_icon::Icon::from_rgba(resized.into_raw(), w, h).ok()
    }

    /// PT: Abre navegador com proteção contra múltiplas abas concorrentes (debounce de 2000ms)
    /// EN: Open browser with concurrent tab debounce protection (2000ms debounce)
    /// AI: @llm-func open_browser_debounced @llm-debounce 2000ms
    #[cfg(all(target_os = "windows", feature = "systray"))]
    fn open_browser_debounced(url: &str) {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .unwrap_or_default()
            .as_millis() as u64;
        let last = LAST_BROWSER_OPEN_MS.load(Ordering::Relaxed);
        if now.saturating_sub(last) < 2000 {
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
            let item_open =
                MenuItem::new("⚡ Abrir Painel de Controle (localhost:8085)", true, None);
            let item_tailscale = MenuItem::new(
                "📱 Acesso Mobile Tailscale (100.106.120.35:8085)",
                true,
                None,
            );
            let item_quit = MenuItem::new("❌ Encerrar Usina Zorya", true, None);
            let _ = tray_menu.append(&item_open);
            let _ = tray_menu.append(&item_tailscale);
            let _ = tray_menu.append(&item_quit);

            let open_id = item_open.id().clone();
            let ts_id = item_tailscale.id().clone();
            let quit_id = item_quit.id().clone();

            const ICON_BYTES: &[u8] = include_bytes!("../assets/icon.png");

            // PT: Pré-renderiza os 6 ícones estáticos no início para eliminar alocação e vazamento de handles Win32 HICON
            // EN: Pre-render 6 static state icons on startup to eliminate Win32 HICON handle leak
            let icon_idle = Self::generate_state_icon(ICON_BYTES, SystrayState::Idle);
            let icon_inference =
                Self::generate_state_icon(ICON_BYTES, SystrayState::InferenceActive);
            let icon_network = Self::generate_state_icon(ICON_BYTES, SystrayState::NetworkActive);
            let icon_degraded =
                Self::generate_state_icon(ICON_BYTES, SystrayState::DegradedFallback);
            let icon_error = Self::generate_state_icon(ICON_BYTES, SystrayState::ExhaustedError);
            let icon_offline = Self::generate_state_icon(ICON_BYTES, SystrayState::Offline);

            let mut builder = TrayIconBuilder::new()
                .with_tooltip("Usina Zorya v3.0 — Painel de Controle")
                .with_menu(Box::new(tray_menu));

            if let Some(ref ic) = icon_idle {
                builder = builder.with_icon(ic.clone());
            }

            let tray_icon = match builder.build() {
                Ok(t) => t,
                Err(e) => {
                    tracing::warn!("Não foi possível inicializar a bandeja do Windows: {}", e);
                    return;
                }
            };

            let mut last_state: Option<SystrayState> = Some(SystrayState::Idle);
            let mut last_icon_change = std::time::Instant::now();
            let mut last_tooltip = String::new();

            loop {
                unsafe {
                    let mut msg: MSG = std::mem::zeroed();
                    while PeekMessageW(&mut msg, std::ptr::null_mut(), 0, 0, PM_REMOVE) != 0 {
                        TranslateMessage(&msg);
                        DispatchMessageW(&msg);
                    }
                }

                // Processa clique no menu com debounce de 2 segundos
                if let Ok(event) = MenuEvent::receiver().try_recv() {
                    if event.id == open_id {
                        Self::open_browser_debounced("http://127.0.0.1:8085/settings");
                    } else if event.id == ts_id {
                        Self::open_browser_debounced("http://100.106.120.35:8085/settings");
                    } else if event.id == quit_id {
                        std::process::exit(0);
                    }
                }

                // Processa clique no ícone: APENAS DoubleClick (descartando Click simples para evitar aba dupla)
                while let Ok(tray_evt) = TrayIconEvent::receiver().try_recv() {
                    if let TrayIconEvent::DoubleClick {
                        button: tray_icon::MouseButton::Left,
                        ..
                    } = tray_evt
                    {
                        Self::open_browser_debounced("http://127.0.0.1:8085/settings");
                    }
                }

                // Atualiza ícone apenas se o estado de saúde mudar (eliminando vazamento de HICON na GDI)
                if let Ok(guard) = status_ref.read() {
                    if last_state != Some(guard.state)
                        && last_icon_change.elapsed() >= Duration::from_millis(1000)
                    {
                        let selected_icon = match guard.state {
                            SystrayState::Idle => &icon_idle,
                            SystrayState::InferenceActive => &icon_inference,
                            SystrayState::NetworkActive => &icon_network,
                            SystrayState::DegradedFallback => &icon_degraded,
                            SystrayState::ExhaustedError => &icon_error,
                            SystrayState::Offline => &icon_offline,
                        };
                        if let Some(ic) = selected_icon {
                            let _ = tray_icon.set_icon(Some(ic.clone()));
                        }
                        last_state = Some(guard.state);
                        last_icon_change = std::time::Instant::now();
                    }

                    if guard.tooltip != last_tooltip {
                        let _ = tray_icon.set_tooltip(Some(&guard.tooltip));
                        last_tooltip = guard.tooltip.clone();
                    }
                }

                std::thread::sleep(Duration::from_millis(200));
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
        self.transition(
            SystrayState::InferenceActive,
            "Processando IA / Inferência Neural",
        );
        SystrayGuard {
            controller: self.clone(),
            restore_state: SystrayState::Idle,
            restore_reason: "Pronto",
        }
    }

    pub fn begin_network_work(&self) -> SystrayGuard {
        self.transition(
            SystrayState::NetworkActive,
            "Sincronizando malha de rede / cluster",
        );
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
                guard.tooltip =
                    "Zorya v3.0 — Nó peer offline, operando em modo autônomo".to_string();
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
        self.controller
            .transition(self.restore_state, self.restore_reason);
    }
}
