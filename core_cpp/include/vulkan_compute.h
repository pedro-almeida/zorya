/**
 * =============================================================================
 * SVITANOK RAW ENGINE — Vulkan Compute Heterogeneous Fallback
 * Cross-Vendor GPU Compute Acceleration (AMD, Intel, Qualcom, Generic dGPU/iGPU)
 * =============================================================================
 *
 * PT: Pipeline de computação em Vulkan Compute para aceleração em GPUs não-NVIDIA
 *     (AMD Radeon, Intel Iris/Arc) utilizando compute pipelines e buffers de armazenamento.
 * EN: Vulkan Compute pipeline for hardware acceleration on non-NVIDIA GPUs
 *     (AMD Radeon, Intel Iris/Arc) using compute pipelines and storage buffers.
 *
 * @llm-context Módulo Svitanok / Core C++ / Vulkan Compute Pipeline
 * @llm-invariant Utiliza Vulkan 1.1+ sem requerer extensões proprietárias de fabricante
 * @llm-rule Atua como fallback quando CUDA não está presente antes de recorrer à CPU pura
 */

#ifndef SVITANOK_VULKAN_COMPUTE_H
#define SVITANOK_VULKAN_COMPUTE_H

#include <cstdint>
#include <cstddef>
#include <string>

namespace Svitanok {

struct VulkanDeviceProps {
    bool available = false;
    std::string deviceName = "";
    std::string vendorName = "";
    uint32_t apiVersion = 0;
    uint32_t driverVersion = 0;
    size_t dedicatedVRamBytes = 0;
    bool isDiscrete = false;
};

class VulkanComputeEngine {
public:
    static bool isDriverAvailable();
    static VulkanDeviceProps probeDevice();

    VulkanComputeEngine();
    ~VulkanComputeEngine();

    bool initialize();
    void shutdown();

    /**
     * // PT: Executa shader de processamento (Denoise & Tone Map) via Vulkan Compute
     * // EN: Dispatches processing shader (Denoise & Tone Map) via Vulkan Compute
     */
    bool dispatchCompute(
        uint8_t* hostRgbData,
        int width,
        int height,
        int channels,
        float denoiseStrength,
        float exposure,
        float temperature
    );

private:
    bool m_initialized = false;
};

} // namespace Svitanok

#endif // SVITANOK_VULKAN_COMPUTE_H
