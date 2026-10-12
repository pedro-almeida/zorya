/**
 * =============================================================================
 * SVITANOK RAW ENGINE — CUDA High-Performance GPU Acceleration
 * Direct VRAM Allocation & Massively Parallel Denoise Kernel for NVIDIA dGPUs
 * =============================================================================
 *
 * PT: Acelerador nativo em CUDA C++ para placas NVIDIA (GeForce/RTX/Quadro).
 *     Transfere buffers para VRAM e executa denoise e tom com milhares de threads.
 * EN: Native CUDA C++ accelerator for NVIDIA dGPUs (GeForce/RTX/Quadro).
 *     Transfers buffers to VRAM and runs denoise and tone mapping with thousands of threads.
 *
 * @llm-context Módulo Svitanok / Core C++ / CUDA VRAM Accelerator
 * @llm-invariant Só é invocado se driver NVIDIA e placa dGPU forem validados com sucesso
 * @llm-rule Executa com blocos 2D de 16x16 threads para máxima ocupação de SMs
 */

#ifndef SVITANOK_CUDA_ACCELERATOR_H
#define SVITANOK_CUDA_ACCELERATOR_H

#include <cstdint>
#include <cstddef>
#include <string>

namespace Svitanok {

struct CudaDeviceProps {
    bool available = false;
    int deviceId = 0;
    std::string deviceName = "";
    size_t totalVRamBytes = 0;
    size_t freeVRamBytes = 0;
    int computeCapabilityMajor = 0;
    int computeCapabilityMinor = 0;
    int multiProcessorCount = 0;
    std::string driverVersion = "";
};

class CudaAccelerator {
public:
    static bool isDriverAvailable();
    static CudaDeviceProps probePrimaryDevice();

    CudaAccelerator();
    ~CudaAccelerator();

    bool initialize();
    void shutdown();

    /**
     * // PT: Transfere RGB para VRAM, aplica bilateral denoise & tone mapping e retorna para RAM
     * // EN: Transfers RGB to VRAM, applies bilateral denoise & tone mapping and returns to RAM
     */
    bool processOnGpu(
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
    int m_activeDeviceId = -1;
};

} // namespace Svitanok

#endif // SVITANOK_CUDA_ACCELERATOR_H
