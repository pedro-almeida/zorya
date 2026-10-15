// Hardware and System Telemetry via sysinfo + NVIDIA GTX 1650 VRAM probe / Telemetria de Hardware e Sistema via sysinfo + sonda de VRAM NVIDIA GTX 1650
use std::process::Command;
use serde::{Deserialize, Serialize};
use sysinfo::{Disks, System};

// Compact health response required for GET /health / Resposta compacta de saúde exigida para GET /health
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct HealthResponse {
    pub status: String,
    pub node: String,
    pub version: String,
    pub cpu_usage: f32,
    pub ram_mb: u64,
    pub gpu_vram_mb: u64,
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

// Stateful collector wrapping sysinfo::System / Coletor com estado encapsulando sysinfo::System
pub struct TelemetryCollector {
    sys: System,
    node_name: String,
    default_vram_mb: u64,
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
        }
    }

    // Collect compact metrics for GET /health / Coleta métricas compactas para GET /health
    pub fn collect_health(&mut self) -> HealthResponse {
        self.sys.refresh_cpu_usage();
        self.sys.refresh_memory();

        let cpu_usage = (self.sys.global_cpu_usage() * 10.0).round() / 10.0;
        let ram_mb = self.sys.total_memory() / (1024 * 1024);
        let (_, vram_total_mb, _) = Self::probe_nvidia_vram(self.default_vram_mb);

        HealthResponse {
            status: "ok".to_string(),
            node: self.node_name.clone(),
            version: "2.1.0".to_string(),
            cpu_usage,
            ram_mb: if ram_mb > 0 { ram_mb } else { 4096 },
            gpu_vram_mb: vram_total_mb,
        }
    }

    // Collect full telemetry for GET /api/v1/system/info / Coleta telemetria completa para GET /api/v1/system/info
    pub fn collect_detailed(&mut self) -> DetailedSystemInfo {
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

        let (gpu_model, vram_total_mb, vram_free_mb) = Self::probe_nvidia_vram(self.default_vram_mb);

        DetailedSystemInfo {
            status: "ok".to_string(),
            node: self.node_name.clone(),
            version: "2.1.0".to_string(),
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
        }
    }

    // Query nvidia-smi without console window or fall back to GTX 1650 4096 MB profile / Consulta nvidia-smi sem abrir janela ou usa o perfil padrão GTX 1650 4096 MB
    fn probe_nvidia_vram(default_vram_mb: u64) -> (String, u64, u64) {
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

        (
            "NVIDIA GeForce GTX 1650 (4GB Dedicated VRAM)".to_string(),
            default_vram_mb,
            default_vram_mb,
        )
    }
}
