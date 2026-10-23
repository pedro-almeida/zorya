// PT: Detecção Automática de Hardware e Auto-Tuning de Inferência (Zorya v3.0)
// EN: Automatic Hardware Detection and Inference Auto-Tuning (Zorya v3.0)
// AI: @llm-context Detecta CPU (AVX2/AVX512/NPU), iGPU (OpenCL/wgpu), dGPU NVIDIA CUDA (GTX 1650 4GB dedicados) e dGPU AMD (ROCm/Vulkan)
// AI: @llm-invariant Perfil Nitro (GTX 1650 4GB -> CUDA, Q4_K_M 7B, ctx 8192) e Perfil VPS (2 cores, 4GB RAM -> CPU-only, Q4_K_M 3B, ctx 2048)

use std::process::Command;
#[cfg(target_os = "windows")]
use std::os::windows::process::CommandExt;

use serde::{Deserialize, Serialize};
use sysinfo::System;

/// Cria std::process::Command suprimindo janelas de prompt no Windows (CREATE_NO_WINDOW)
#[inline]
fn new_command(program: &str) -> Command {
    #[allow(unused_mut)]
    let mut cmd = Command::new(program);
    #[cfg(target_os = "windows")]
    cmd.creation_flags(0x08000000);
    cmd
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub enum ComputeBackend {
    Cuda,
    Vulkan,
    OpenCl,
    Rocm,
    CpuOnly,
}

impl ComputeBackend {
    pub const fn as_str(self) -> &'static str {
        match self {
            ComputeBackend::Cuda => "cuda",
            ComputeBackend::Vulkan => "vulkan",
            ComputeBackend::OpenCl => "opencl",
            ComputeBackend::Rocm => "rocm",
            ComputeBackend::CpuOnly => "cpu-only",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub enum NodeProfileKind {
    NitroGtx1650,
    VpsMini,
    GenericWorkstation,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct HardwareProfile {
    pub cpu_brand: String,
    pub physical_cores: usize,
    pub logical_cores: usize,
    pub has_avx2: bool,
    pub has_avx512: bool,
    pub npu_detected: Option<String>,
    pub total_ram_mb: u64,
    pub available_ram_mb: u64,
    pub igpu_model: Option<String>,
    pub dgpu_model: Option<String>,
    pub dgpu_vram_mb: u64,
    pub dgpu_vram_free_mb: u64,
    pub display_offloaded_to_igpu: bool,
    pub compiled_features: Vec<String>,
    pub active_backend: ComputeBackend,
    pub profile_kind: NodeProfileKind,
    pub recommended_model: String,
    pub recommended_ctx_size: u32,
    pub recommended_batch_size: u32,
    pub recommended_threads: usize,
    pub recommended_gpu_layers: u32,
}

impl HardwareProfile {
    // PT: Executa sonda completa de hardware e aplica variáveis de ambiente para isolamento da GTX 1650
    // EN: Runs full hardware probe and applies environment variables for GTX 1650 isolation
    pub fn detect() -> Self {
        let mut sys = System::new_all();
        sys.refresh_all();

        let physical_cores = sys.physical_core_count().unwrap_or(2).max(1);
        let logical_cores = sys.cpus().len().max(physical_cores);
        let cpu_brand = sys
            .cpus()
            .first()
            .map(|c| c.brand().trim().to_string())
            .unwrap_or_else(|| "Generic x86_64 CPU".to_string());

        let total_ram_mb = (sys.total_memory() / (1024 * 1024)).max(2048);
        let available_ram_mb = (sys.available_memory() / (1024 * 1024)).max(1024);

        #[cfg(any(target_arch = "x86", target_arch = "x86_64"))]
        let (has_avx2, has_avx512) = (
            std::is_x86_feature_detected!("avx2"),
            std::is_x86_feature_detected!("avx512f"),
        );
        #[cfg(not(any(target_arch = "x86", target_arch = "x86_64")))]
        let (has_avx2, has_avx512) = (false, false);

        let npu_detected = Self::probe_npu(&cpu_brand);
        let igpu_model = Self::probe_igpu();
        let (mut nvidia_model, mut nvidia_vram_mb, mut nvidia_free_mb) = Self::probe_nvidia_cuda();
        #[cfg(target_os = "windows")]
        if nvidia_model.is_none() {
            if let Some((name, vram)) = Self::detect_windows_wmi_gpu() {
                let lower = name.to_lowercase();
                if lower.contains("nvidia") || lower.contains("geforce") || lower.contains("gtx") || lower.contains("rtx") {
                    nvidia_model = Some(name);
                    nvidia_vram_mb = vram;
                    nvidia_free_mb = vram;
                }
            }
        }
        let amd_dgpu = if nvidia_model.is_none() {
            Self::probe_amd_rocm_vulkan()
        } else {
            None
        };

        let (mut dgpu_model, mut dgpu_vram_mb, mut dgpu_vram_free_mb) = if let Some(nv) = nvidia_model {
            (Some(nv), nvidia_vram_mb, nvidia_free_mb)
        } else if let Some((amd_name, amd_vram)) = amd_dgpu {
            (Some(amd_name), amd_vram, amd_vram)
        } else {
            (None, 0, 0)
        };

        let display_offloaded_to_igpu = dgpu_model.is_some() && igpu_model.is_some();
        let compiled_features = Self::collect_compiled_features();

        // Seleciona backend ativo respeitando feature flags e hardware real
        let mut active_backend = if dgpu_model
            .as_deref()
            .map(|m| m.to_ascii_uppercase().contains("NVIDIA") || m.to_ascii_uppercase().contains("GTX") || m.to_ascii_uppercase().contains("RTX"))
            .unwrap_or(false)
            && cfg!(feature = "cuda")
        {
            ComputeBackend::Cuda
        } else if dgpu_model.is_some() && cfg!(feature = "vulkan") {
            ComputeBackend::Vulkan
        } else if igpu_model.is_some() && cfg!(feature = "opencl") {
            ComputeBackend::OpenCl
        } else if dgpu_model
            .as_deref()
            .map(|m| m.to_ascii_uppercase().contains("NVIDIA") || m.to_ascii_uppercase().contains("GTX"))
            .unwrap_or(false)
        {
            ComputeBackend::Cuda
        } else {
            ComputeBackend::CpuOnly
        };

        // Verifica se zorya.toml define perfil nitro explicitamente
        let config_nitro = std::fs::read_to_string("zorya.toml")
            .or_else(|_| {
                if let Ok(path) = std::env::var("ZORYA_CONFIG") {
                    std::fs::read_to_string(path)
                } else {
                    std::fs::read_to_string(r"C:\ProgramData\Zorya\zorya.toml")
                }
            })
            .map(|c| {
                let lower = c.to_lowercase();
                lower.contains("role = \"nitro\"")
                    || lower.contains("profile = \"nitro\"")
                    || lower.contains("node_role = \"nitro\"")
            })
            .unwrap_or(false);

        // Determina Perfil: Nitro (GTX 1650 4GB VRAM) vs VPS (2 cores, 4GB RAM)
        let is_gtx_1650 = dgpu_model
            .as_deref()
            .map(|m| m.contains("1650"))
            .unwrap_or(false)
            || (active_backend == ComputeBackend::Cuda && dgpu_vram_mb == 4096)
            || config_nitro;

        let profile_kind = if is_gtx_1650 {
            NodeProfileKind::NitroGtx1650
        } else if physical_cores <= 2 && total_ram_mb <= 4608 && dgpu_vram_mb == 0 {
            NodeProfileKind::VpsMini
        } else {
            NodeProfileKind::GenericWorkstation
        };

        if profile_kind == NodeProfileKind::NitroGtx1650 {
            active_backend = ComputeBackend::Cuda;
            if dgpu_model.is_none() {
                dgpu_model = Some("NVIDIA GeForce GTX 1650".to_string());
                dgpu_vram_mb = 4096;
                dgpu_vram_free_mb = 4096;
            }
        }

        let (
            recommended_model,
            recommended_ctx_size,
            recommended_batch_size,
            recommended_threads,
            recommended_gpu_layers,
        ) = match profile_kind {
            NodeProfileKind::NitroGtx1650 => {
                // Isola 100% dos 4096MB da GTX 1650 para inferência (iGPU cuida do display)
                std::env::set_var("CUDA_VISIBLE_DEVICES", "0");
                std::env::set_var("CUDA_DEVICE_ORDER", "PCI_BUS_ID");
                std::env::set_var("GGML_CUDA_ENABLE_UNIFIED_MEMORY", "0");
                std::env::set_var("ZORYA_GPU_LAYERS", "33");
                std::env::set_var("GPU_DEVICE_ORDINAL", "0");
                (
                    "qwen2.5-7b-instruct-q4_k_m.gguf".to_string(),
                    8192,
                    512,
                    physical_cores.saturating_sub(1).max(4),
                    33, // Camadas que cabem perfeitamente nos 4GB dedicados com KV cache q8_0
                )
            }
            NodeProfileKind::VpsMini => (
                "qwen2.5-3b-instruct-q4_k_m.gguf".to_string(),
                2048,
                256,
                2,
                0,
            ),
            NodeProfileKind::GenericWorkstation => {
                let layers = if dgpu_vram_mb >= 8192 {
                    99
                } else if dgpu_vram_mb >= 4096 {
                    33
                } else {
                    0
                };
                let model = if dgpu_vram_mb >= 4096 || total_ram_mb >= 16384 {
                    "qwen2.5-7b-instruct-q4_k_m.gguf".to_string()
                } else {
                    "qwen2.5-3b-instruct-q4_k_m.gguf".to_string()
                };
                let ctx = if dgpu_vram_mb >= 4096 { 8192 } else { 2048 };
                (
                    model,
                    ctx,
                    512,
                    physical_cores.saturating_sub(1).max(2),
                    layers,
                )
            }
        };

        Self {
            cpu_brand,
            physical_cores,
            logical_cores,
            has_avx2,
            has_avx512,
            npu_detected,
            total_ram_mb,
            available_ram_mb,
            igpu_model,
            dgpu_model,
            dgpu_vram_mb,
            dgpu_vram_free_mb,
            display_offloaded_to_igpu,
            compiled_features,
            active_backend,
            profile_kind,
            recommended_model,
            recommended_ctx_size,
            recommended_batch_size,
            recommended_threads,
            recommended_gpu_layers,
        }
    }

    fn collect_compiled_features() -> Vec<String> {
        let mut feats = Vec::new();
        if cfg!(feature = "cuda") {
            feats.push("cuda".to_string());
        }
        if cfg!(feature = "vulkan") {
            feats.push("vulkan".to_string());
        }
        if cfg!(feature = "opencl") {
            feats.push("opencl".to_string());
        }
        if cfg!(feature = "cpu-only") || feats.is_empty() {
            feats.push("cpu-only".to_string());
        }
        feats
    }

    fn probe_npu(cpu_brand: &str) -> Option<String> {
        let upper = cpu_brand.to_ascii_uppercase();
        if upper.contains("ULTRA") || upper.contains("AI BOOST") {
            return Some("Intel AI Boost NPU".to_string());
        }
        if upper.contains("SNAPDRAGON") || upper.contains("ORYON") {
            return Some("Qualcomm Snapdragon Hexagon NPU".to_string());
        }
        None
    }

    fn probe_igpu() -> Option<String> {
        #[cfg(target_os = "windows")]
        {
            if let Ok(out) = new_command("powershell")
                .args([
                    "-NoProfile",
                    "-NonInteractive",
                    "-Command",
                    "Get-CimInstance Win32_VideoController | Select-Object -ExpandProperty Name",
                ])
                .output()
            {
                let text = String::from_utf8_lossy(&out.stdout);
                for line in text.lines() {
                    let trimmed = line.trim();
                    if trimmed.contains("Intel(R) UHD")
                        || trimmed.contains("Intel(R) Iris")
                        || trimmed.contains("AMD Radeon(TM) Graphics")
                    {
                        return Some(trimmed.to_string());
                    }
                }
            }
        }
        #[cfg(target_os = "linux")]
        {
            if let Ok(out) = new_command("lspci").output() {
                let text = String::from_utf8_lossy(&out.stdout);
                for line in text.lines() {
                    if (line.contains("VGA") || line.contains("Display"))
                        && (line.contains("Intel") || line.contains("Radeon"))
                    {
                        return Some(line.trim().to_string());
                    }
                }
            }
        }
        None
    }

    fn probe_nvidia_cuda() -> (Option<String>, u64, u64) {
        let candidates = [
            "nvidia-smi",
            r"C:\Windows\System32\nvidia-smi.exe",
            r"C:\Program Files\NVIDIA Corporation\NVSMI\nvidia-smi.exe",
        ];

        for cmd_path in candidates {
            if let Ok(out) = new_command(cmd_path)
                .args([
                    "--query-gpu=name,memory.total,memory.free",
                    "--format=csv,noheader,nounits",
                ])
                .output()
            {
                if out.status.success() {
                    let text = String::from_utf8_lossy(&out.stdout);
                    if let Some(first) = text.lines().next() {
                        let parts: Vec<&str> = first.split(',').map(|s| s.trim()).collect();
                        if parts.len() >= 3 {
                            let name = parts[0].to_string();
                            let total = parts[1].parse::<u64>().unwrap_or(4096);
                            let free = parts[2].parse::<u64>().unwrap_or(total);
                            return (Some(name), total, free);
                        }
                    }
                }
            }
        }
        (None, 0, 0)
    }

    fn probe_amd_rocm_vulkan() -> Option<(String, u64)> {
        if let Ok(out) = new_command("rocm-smi").args(["--showproductname"]).output() {
            if out.status.success() {
                let text = String::from_utf8_lossy(&out.stdout);
                if !text.trim().is_empty() {
                    return Some(("AMD Radeon dGPU (ROCm/Vulkan)".to_string(), 8192));
                }
            }
        }
        None
    }

    #[allow(dead_code)]
    fn detect_windows_wmi_gpu() -> Option<(String, u64)> {
        let out = new_command("powershell")
            .args([
                "-NoProfile",
                "-NonInteractive",
                "-Command",
                "Get-CimInstance Win32_VideoController | Where-Object { $_.Name -match 'NVIDIA|GeForce|GTX|RTX' } | Select-Object -First 1 Name,AdapterRAM | ConvertTo-Json -Compress",
            ])
            .output()
            .ok()?;
        if !out.status.success() {
            return None;
        }
        let text = String::from_utf8_lossy(&out.stdout);
        let val: serde_json::Value = serde_json::from_str(text.trim()).ok()?;
        let name = val.get("Name")?.as_str()?.trim().to_string();
        let ram_bytes = val.get("AdapterRAM").and_then(|v| v.as_u64()).unwrap_or(0);
        let vram_mb = if ram_bytes > 0 { ram_bytes / (1024 * 1024) } else { 4096 };
        Some((name, vram_mb))
    }
}
