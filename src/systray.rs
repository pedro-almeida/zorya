// System Tray Indicator: Gray in idle, blinks Emerald Green (#10b981) and Amber (#f59e0b) every 500ms when active / Indicador de Bandeja: Cinza em idle, pisca Verde Esmeralda (#10b981) e Âmbar (#f59e0b) a cada 500ms em processamento

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;

// Visual state of the Zorya tray icon / Estado visual do ícone de bandeja do Zorya
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TrayVisualState {
    // Idle state: Neutral gray (#6b7280) / Estado ocioso: Cinza neutro (#6b7280)
    IdleGray,
    // Processing phase A: Emerald green (#10b981) / Fase de processamento A: Verde esmeralda (#10b981)
    ActiveGreen,
    // Processing phase B: Amber (#f59e0b) / Fase de processamento B: Âmbar (#f59e0b)
    ActiveAmber,
}

impl TrayVisualState {
    // Return RGB color tuple for the current state / Retorna a tupla de cor RGB para o estado atual
    pub const fn rgb(self) -> (u8, u8, u8) {
        match self {
            TrayVisualState::IdleGray => (0x6B, 0x72, 0x80),
            TrayVisualState::ActiveGreen => (0x10, 0xB9, 0x81),
            TrayVisualState::ActiveAmber => (0xF5, 0x9E, 0x0B),
        }
    }

    // Generate a 32x32 RGBA icon buffer with Ethernet-port style status LED / Gera um buffer de ícone RGBA 32x32 com LED estilo porta Ethernet
    pub fn build_rgba_icon(self) -> Vec<u8> {
        let width = 32usize;
        let height = 32usize;
        let mut rgba = vec![0u8; width * height * 4];
        let (r, g, b) = self.rgb();

        for y in 0..height {
            for x in 0..width {
                let idx = (y * width + x) * 4;
                let dx = x as isize - 16;
                let dy = y as isize - 16;
                let dist_sq = dx * dx + dy * dy;

                if dist_sq <= 144 {
                    // Inner LED core / Núcleo interno do LED
                    rgba[idx] = r;
                    rgba[idx + 1] = g;
                    rgba[idx + 2] = b;
                    rgba[idx + 3] = 255;
                } else if dist_sq <= 196 {
                    // Dark metallic bezel / Borda metálica escura
                    rgba[idx] = 0x1F;
                    rgba[idx + 1] = 0x29;
                    rgba[idx + 2] = 0x37;
                    rgba[idx + 3] = 255;
                }
            }
        }
        rgba
    }
}

// Guard that increments active job count on creation and decrements on drop / Guarda RAII que incrementa tarefas ativas ao iniciar e decrementa ao finalizar
pub struct ProcessingGuard {
    counter: Arc<AtomicUsize>,
}

impl ProcessingGuard {
    pub fn acquire(counter: Arc<AtomicUsize>) -> Self {
        counter.fetch_add(1, Ordering::SeqCst);
        Self { counter }
    }
}

impl Drop for ProcessingGuard {
    fn drop(&mut self) {
        self.counter.fetch_sub(1, Ordering::SeqCst);
    }
}

// Controller managing the 500ms blink loop / Controlador que gerencia o loop de piscada de 500ms
#[derive(Clone)]
pub struct SystrayController {
    active_jobs: Arc<AtomicUsize>,
}

impl SystrayController {
    // Spawn the 500ms background blinker thread / Inicia a thread em background com ciclo de 500ms
    pub fn spawn(active_jobs: Arc<AtomicUsize>) -> Self {
        let jobs_ref = Arc::clone(&active_jobs);

        std::thread::spawn(move || {
            let mut toggle = false;
            let mut last_state = TrayVisualState::IdleGray;

            #[cfg(feature = "systray")]
            let tray_handle = Self::init_native_tray(TrayVisualState::IdleGray);

            loop {
                std::thread::sleep(std::time::Duration::from_millis(500));
                let count = jobs_ref.load(Ordering::SeqCst);

                let next_state = if count == 0 {
                    TrayVisualState::IdleGray
                } else {
                    toggle = !toggle;
                    if toggle {
                        TrayVisualState::ActiveGreen
                    } else {
                        TrayVisualState::ActiveAmber
                    }
                };

                if next_state != last_state {
                    last_state = next_state;
                    #[cfg(feature = "systray")]
                    if let Some(ref tray) = tray_handle {
                        if let Ok(icon) = tray_icon::Icon::from_rgba(
                            next_state.build_rgba_icon(),
                            32,
                            32,
                        ) {
                            let _ = tray.set_icon(Some(icon));
                        }
                    }
                }
            }
        });

        Self { active_jobs }
    }

    #[cfg(feature = "systray")]
    fn init_native_tray(initial: TrayVisualState) -> Option<tray_icon::TrayIcon> {
        let icon = tray_icon::Icon::from_rgba(initial.build_rgba_icon(), 32, 32).ok()?;
        tray_icon::TrayIconBuilder::new()
            .with_tooltip("Zorya v2.1.0 — Sovereign Rust Coprocessor")
            .with_icon(icon)
            .build()
            .ok()
    }

    // Acquire an active-processing guard / Adquire uma guarda de processamento ativo
    pub fn begin_work(&self) -> ProcessingGuard {
        ProcessingGuard::acquire(Arc::clone(&self.active_jobs))
    }

    // Return number of currently executing jobs / Retorna o número de tarefas em execução
    pub fn active_count(&self) -> usize {
        self.active_jobs.load(Ordering::SeqCst)
    }
}
