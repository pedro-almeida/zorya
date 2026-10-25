// Hardware and System Telemetry via sysinfo + NVIDIA GTX 1650 VRAM probe / Telemetria de Hardware e Sistema via sysinfo + sonda de VRAM NVIDIA GTX 1650
use serde::{Deserialize, Serialize};
use std::process::Command;
use sysinfo::{Disks, System};

// Compact health response required for GET /health & GET /api/status
// Compact health response required for GET /health & GET /api/status (compatible with Mriya probeZorya)
// PT: Estrutura de resposta do endpoint /health compatível com o probeZorya do Mriya.
// EN: /health endpoint response structure compatible with Mriya's probeZorya.
// AI: @llm-struct HealthResponse @llm-route GET /health
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct HealthResponse {
    pub status: String,
    pub service: String,
    pub version: String,
    pub port: u16,
    pub active_model: String,
    pub gpu_name: String,
    pub gpu_vram_mb: u64,
    pub gpu_vram_total_mb: u64,
    pub ram_total_mb: u64,
    pub ram_available_mb: u64,
    pub recommended_tier: String,
    pub timestamp: String,
    #[serde(default)]
    pub online: bool,
    #[serde(default)]
    pub node: String,
    #[serde(default)]
    pub cpu_usage: f32,
    #[serde(default)]
    pub ram_mb: u64,
}

// Detailed system telemetry for GET /api/v1/system/info / Telemetria detalhada do sistema para GET /api/v1/system/info
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DetailedSystemInfo {
    pub status: String,
    pub node: String,
    pub version: String,
    pub os_name: String,
    pub os_version: String,
    pub host_name: String,
    pub cpu_brand: String,
    pub cpu_physical_cores: usize,
    pub cpu_logical_cores: usize,
    pub cpu_usage_percent: f32,
    pub ram_total_mb: u64,
    pub ram_used_mb: u64,
    pub ram_available_mb: u64,
    pub disk_total_gb: u64,
    pub disk_available_gb: u64,
    pub gpu_model: String,
    pub gpu_vram_total_mb: u64,
    pub gpu_vram_free_mb: u64,
    pub gpu_display_offloaded_to_igpu: bool,
}

// PT: Snapshot consolidado de telemetria para leitura instantânea O(1) sem contole de lock de escrita
// EN: Consolidated telemetry snapshot for instantaneous O(1) reading without write lock contention
// AI: @llm-struct DetailedSystemSnapshot @llm-perf zero-lock-contention
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct DetailedSystemSnapshot {
    pub health: HealthResponse,
    pub detailed: DetailedSystemInfo,
}

use std::time::{Duration, Instant};

// Stateful collector wrapping sysinfo::System / Coletor com estado encapsulando sysinfo::System
// PT: Coletor de métricas do sistema com cache em memória para evitar gargalos de I/O e refresh excessivo.
// EN: System metrics collector with in-memory cache to prevent I/O bottlenecks and excessive refresh calls.
// AI: @llm-struct TelemetryCollector @llm-cache in-memory-ttl
pub struct TelemetryCollector {
    sys: System,
    node_name: String,
    default_vram_mb: u64,
    gpu_name: Option<String>,
    // PT: Cache em memória da telemetria detalhada (TTL 10s).
    // EN: In-memory cache for detailed telemetry (TTL 10s).
    // AI: @llm-cache detailed-telemetry
    cached_detailed: Option<(DetailedSystemInfo, Instant)>,
    // PT: Cache em memória da sonda nvidia-smi (TTL 30s).
    // EN: In-memory cache for nvidia-smi probe (TTL 30s).
    // AI: @llm-cache nvidia-vram
    cached_gpu: Option<((String, u64, u64), Instant)>,
}

impl TelemetryCollector {
    // Create and prime the telemetry collector / Cria e inicializa o coletor de telemetria
    pub fn new(node_name: String, default_vram_mb: u64) -> Self {
        let mut sys = System::new_all();
        sys.refresh_all();
        Self {
            sys,
            node_name,
            default_vram_mb,
            gpu_name: None,
            cached_detailed: None,
            cached_gpu: None,
        }
    }

    pub fn with_hw_profile(mut self, gpu_name: Option<String>, vram_mb: u64) -> Self {
        self.gpu_name = gpu_name;
        self.default_vram_mb = vram_mb;
        self
    }

    // PT: Retorna o resultado da sonda de VRAM com cache de 30 segundos.
    // EN: Returns VRAM probe result with a 30-second in-memory cache.
    // AI: @llm-func probe_nvidia_vram_cached @llm-cache 30s
    pub fn probe_nvidia_vram_cached(&mut self) -> (String, u64, u64) {
        if let Some((ref gpu, inst)) = self.cached_gpu {
            if inst.elapsed() < Duration::from_secs(30) {
                return gpu.clone();
            }
        }

        let res = Self::probe_nvidia_vram(self.default_vram_mb, self.gpu_name.as_deref());
        self.cached_gpu = Some((res.clone(), Instant::now()));
        res
    }

    // Collect compact metrics for GET /health / Coleta métricas compactas para GET /health
    pub fn collect_health(&mut self) -> HealthResponse {
        self.sys.refresh_cpu_usage();
        self.sys.refresh_memory();

        let cpu_usage = (self.sys.global_cpu_usage() * 10.0).round() / 10.0;
        let ram_total_mb = self.sys.total_memory() / (1024 * 1024);
        let ram_available_mb = self.sys.available_memory() / (1024 * 1024);
        let (gpu_name, vram_total_mb, _) = self.probe_nvidia_vram_cached();

        let ram_mb = if ram_total_mb > 0 {
            ram_total_mb
        } else {
            16384
        };
        let ram_avail = if ram_available_mb > 0 {
            ram_available_mb
        } else {
            8192
        };

        HealthResponse {
            status: "ok".to_string(),
            service: "zorya-edge-daemon".to_string(),
            version: env!("CARGO_PKG_VERSION").to_string(),
            port: 8085,
            active_model: "qwen2.5-7b-instruct-q4_k_m.gguf".to_string(),
            gpu_name,
            gpu_vram_mb: vram_total_mb,
            gpu_vram_total_mb: vram_total_mb,
            ram_total_mb: ram_mb,
            ram_available_mb: ram_avail,
            recommended_tier: "Medium3BTo7B".to_string(),
            timestamp: chrono::Utc::now().to_rfc3339(),
            online: true,
            node: if self.node_name.is_empty() {
                "usina-zorya".to_string()
            } else {
                self.node_name.clone()
            },
            cpu_usage,
            ram_mb,
        }
    }

    // Collect full telemetry for GET /api/v1/system/info / Coleta telemetria completa para GET /api/v1/system/info
    // PT: Coleta telemetria detalhada utilizando cache de 10s para eliminar overhead em threads assíncronas do Axum.
    // EN: Collects detailed telemetry using a 10s cache to eliminate overhead on Axum async threads.
    // AI: @llm-func collect_detailed @llm-cache 10s
    pub fn collect_detailed(&mut self) -> DetailedSystemInfo {
        if let Some((ref info, inst)) = self.cached_detailed {
            if inst.elapsed() < Duration::from_secs(10) {
                return info.clone();
            }
        }

        self.sys.refresh_all();

        let cpu_usage = (self.sys.global_cpu_usage() * 10.0).round() / 10.0;
        let ram_total_mb = self.sys.total_memory() / (1024 * 1024);
        let ram_used_mb = self.sys.used_memory() / (1024 * 1024);
        let ram_available_mb = self.sys.available_memory() / (1024 * 1024);

        let disks = Disks::new_with_refreshed_list();
        let (disk_total_bytes, disk_avail_bytes) = disks.iter().fold((0u64, 0u64), |acc, d| {
            (acc.0 + d.total_space(), acc.1 + d.available_space())
        });

        let cpu_brand = self
            .sys
            .cpus()
            .first()
            .map(|c| c.brand().to_string())
            .unwrap_or_else(|| "x86_64 Processor".to_string());

        let (gpu_model, vram_total_mb, vram_free_mb) = self.probe_nvidia_vram_cached();

        let info = DetailedSystemInfo {
            status: "ok".to_string(),
            node: if self.node_name.is_empty() {
                "usina-zorya".to_string()
            } else {
                self.node_name.clone()
            },
            version: env!("CARGO_PKG_VERSION").to_string(),
            os_name: System::name().unwrap_or_else(|| std::env::consts::OS.to_string()),
            os_version: System::os_version().unwrap_or_else(|| "unknown".to_string()),
            host_name: System::host_name().unwrap_or_else(|| self.node_name.clone()),
            cpu_brand,
            cpu_physical_cores: self.sys.physical_core_count().unwrap_or(4),
            cpu_logical_cores: self.sys.cpus().len().max(1),
            cpu_usage_percent: cpu_usage,
            ram_total_mb,
            ram_used_mb,
            ram_available_mb,
            disk_total_gb: disk_total_bytes / (1024 * 1024 * 1024),
            disk_available_gb: disk_avail_bytes / (1024 * 1024 * 1024),
            gpu_model,
            gpu_vram_total_mb: vram_total_mb,
            gpu_vram_free_mb: vram_free_mb,
            gpu_display_offloaded_to_igpu: true,
        };

        self.cached_detailed = Some((info.clone(), Instant::now()));
        info
    }

    // PT: Coleta snapshot consolidado executado estritamente em thread bloqueante
    // EN: Collect consolidated snapshot executed strictly on blocking thread
    // AI: @llm-func collect_snapshot
    pub fn collect_snapshot(&mut self) -> DetailedSystemSnapshot {
        let health = self.collect_health();
        let detailed = self.collect_detailed();
        DetailedSystemSnapshot { health, detailed }
    }

    // Query nvidia-smi without console window or fall back to hw_profile or CPU-Only
    pub fn probe_nvidia_vram(
        default_vram_mb: u64,
        profile_gpu_name: Option<&str>,
    ) -> (String, u64, u64) {
        let mut cmd = Command::new("nvidia-smi");
        cmd.args([
            "--query-gpu=name,memory.total,memory.free",
            "--format=csv,noheader,nounits",
        ]);

        #[cfg(target_os = "windows")]
        {
            use std::os::windows::process::CommandExt;
            const CREATE_NO_WINDOW: u32 = 0x08000000;
            cmd.creation_flags(CREATE_NO_WINDOW);
        }

        if let Ok(output) = cmd.output() {
            if output.status.success() {
                let text = String::from_utf8_lossy(&output.stdout);
                if let Some(first_line) = text.lines().next() {
                    let parts: Vec<&str> = first_line.split(',').map(|s| s.trim()).collect();
                    if parts.len() >= 3 {
                        let name = parts[0].to_string();
                        let total = parts[1].parse::<u64>().unwrap_or(default_vram_mb);
                        let free = parts[2].parse::<u64>().unwrap_or(default_vram_mb);
                        return (name, total, free);
                    }
                }
            }
        }

        if let Some(name) = profile_gpu_name {
            if !name.trim().is_empty() && default_vram_mb > 0 {
                return (name.to_string(), default_vram_mb, default_vram_mb);
            }
        }

        ("CPU-Only (No Dedicated GPU Detected)".to_string(), 0, 0)
    }
}
