/**
 * =============================================================================
 * SVITANOK RAW ENGINE — Core Native Engine Main Orchestrator
 * Heterogeneous Hardware Switching (CUDA dGPU -> Vulkan Compute -> CPU Multi)
 * =============================================================================
 *
 * PT: Ponto de entrada da DLL nativa. Implementa as funções exportadas em C ABI
 *     para consumo pelo servidor em Go, gerenciando o chaveamento de hardware.
 * EN: Native DLL entry point. Implements C-ABI exported functions consumed by
 *     the Go server daemon, managing dynamic hardware switching.
 *
 * @llm-context Módulo Svitanok / Core C++ / C ABI Implementation
 * @llm-invariant Memória de buffers é alocada com malloc e liberada com free
 * @llm-rule Hardware switching: CUDA > Vulkan > CPU Multithreaded
 */

#define SVITANOK_EXPORTS
#include "../include/svitanok_raw_core.h"
#include "../include/cpu_decoder.h"
#include "../include/cuda_accelerator.h"
#include "../include/vulkan_compute.h"

#include <iostream>
#include <sstream>
#include <cstring>
#include <cstdlib>
#include <mutex>
#include <memory>
#include <thread>

#ifdef _WIN32
#include <windows.h>
#endif

namespace {
    static std::mutex g_engineMutex;
    static int g_activeBackend = SVITANOK_BACKEND_CPU_MULTI;
    static bool g_initialized = false;

    static std::unique_ptr<Svitanok::CpuDecoder> g_cpuDecoder;
    static std::unique_ptr<Svitanok::CudaAccelerator> g_cudaAccelerator;
    static std::unique_ptr<Svitanok::VulkanComputeEngine> g_vulkanEngine;
}

extern "C" {

SVITANOK_API int Svitanok_InitEngine(int preferredBackend) {
    std::lock_guard<std::mutex> lock(g_engineMutex);

    if (g_initialized) {
        return g_activeBackend;
    }

    g_cpuDecoder = std::make_unique<Svitanok::CpuDecoder>();
    g_cudaAccelerator = std::make_unique<Svitanok::CudaAccelerator>();
    g_vulkanEngine = std::make_unique<Svitanok::VulkanComputeEngine>();

    int chosen = SVITANOK_BACKEND_CPU_MULTI;

    // 1. Chaveamento de Hardware (Kantiano e Otimizado)
    if (preferredBackend == SVITANOK_BACKEND_CUDA || preferredBackend == SVITANOK_BACKEND_AUTO) {
        if (Svitanok::CudaAccelerator::isDriverAvailable() && g_cudaAccelerator->initialize()) {
            chosen = SVITANOK_BACKEND_CUDA;
        }
    }

    if (chosen == SVITANOK_BACKEND_CPU_MULTI && 
       (preferredBackend == SVITANOK_BACKEND_VULKAN || preferredBackend == SVITANOK_BACKEND_AUTO)) {
        if (Svitanok::VulkanComputeEngine::isDriverAvailable() && g_vulkanEngine->initialize()) {
            chosen = SVITANOK_BACKEND_VULKAN;
        }
    }

    g_activeBackend = chosen;
    g_initialized = true;

    return g_activeBackend;
}

SVITANOK_API int Svitanok_GetHardwareCaps(char* jsonBuffer, int bufferSize) {
    if (!jsonBuffer || bufferSize <= 0) {
        return SVITANOK_ERR_INVALID_PARAM;
    }

    std::lock_guard<std::mutex> lock(g_engineMutex);

    unsigned int cpuCores = std::thread::hardware_concurrency();
    if (cpuCores == 0) cpuCores = 4;

    uint64_t totalRamMb = 8192;
    uint64_t freeRamMb = 4096;

#ifdef _WIN32
    MEMORYSTATUSEX memInfo;
    memInfo.dwLength = sizeof(MEMORYSTATUSEX);
    if (GlobalMemoryStatusEx(&memInfo)) {
        totalRamMb = memInfo.ullTotalPhys / (1024 * 1024);
        freeRamMb = memInfo.ullAvailPhys / (1024 * 1024);
    }
#endif

    bool cudaPresent = Svitanok::CudaAccelerator::isDriverAvailable();
    Svitanok::CudaDeviceProps cudaProps = Svitanok::CudaAccelerator::probePrimaryDevice();

    bool vulkanPresent = Svitanok::VulkanComputeEngine::isDriverAvailable();
    Svitanok::VulkanDeviceProps vkProps = Svitanok::VulkanComputeEngine::probeDevice();

    std::string backendName = "CPU_MULTITHREAD";
    if (g_activeBackend == SVITANOK_BACKEND_CUDA) {
        backendName = "CUDA_NATIVE";
    } else if (g_activeBackend == SVITANOK_BACKEND_VULKAN) {
        backendName = "VULKAN_COMPUTE";
    }

    std::ostringstream ss;
    ss << "{"
       << "\"status\":\"active\","
       << "\"active_backend\":\"" << backendName << "\","
       << "\"cpu\":{"
       <<   "\"logical_cores\":" << cpuCores << ","
       <<   "\"architecture\":\"x86_64\""
       << "},"
       << "\"memory\":{"
       <<   "\"total_ram_mb\":" << totalRamMb << ","
       <<   "\"free_ram_mb\":" << freeRamMb << ","
       <<   "\"total_ram_gb\":" << (totalRamMb / 1024)
       << "},"
       << "\"cuda\":{"
       <<   "\"driver_available\":" << (cudaPresent ? "true" : "false") << ","
       <<   "\"device_name\":\"" << (cudaProps.available ? cudaProps.deviceName : "N/A") << "\","
       <<   "\"vram_mb\":" << (cudaProps.totalVRamBytes / (1024 * 1024)) << ","
       <<   "\"compute_capability\":\"" << cudaProps.computeCapabilityMajor << "." << cudaProps.computeCapabilityMinor << "\""
       << "},"
       << "\"vulkan\":{"
       <<   "\"driver_available\":" << (vulkanPresent ? "true" : "false") << ","
       <<   "\"device_name\":\"" << (vkProps.available ? vkProps.deviceName : "N/A") << "\","
       <<   "\"discrete_gpu\":" << (vkProps.isDiscrete ? "true" : "false")
       << "}"
       << "}";

    std::string jsonStr = ss.str();
    if (jsonStr.length() >= static_cast<size_t>(bufferSize)) {
        return SVITANOK_ERR_OUT_OF_MEMORY;
    }

    std::strncpy(jsonBuffer, jsonStr.c_str(), bufferSize - 1);
    jsonBuffer[bufferSize - 1] = '\0';

    return SVITANOK_SUCCESS;
}

SVITANOK_API int Svitanok_ProcessRawBuffer(
    const uint8_t* inRawData,
    size_t inSize,
    float denoiseStrength,
    float exposureCompensation,
    float colorTemperature,
    uint8_t** outRgbData,
    int* outWidth,
    int* outHeight,
    int* outChannels
) {
    if (!inRawData || inSize == 0 || !outRgbData || !outWidth || !outHeight || !outChannels) {
        return SVITANOK_ERR_INVALID_PARAM;
    }

    std::lock_guard<std::mutex> lock(g_engineMutex);

    if (!g_initialized) {
        Svitanok_InitEngine(SVITANOK_BACKEND_AUTO);
    }

    // 1. Decodificação inicial da estrutura RAW
    std::vector<uint8_t> rgbBuffer;
    Svitanok::RawImageMetadata meta;

    bool decoded = g_cpuDecoder->decodeRaw(inRawData, inSize, rgbBuffer, meta);
    if (!decoded || rgbBuffer.empty()) {
        return SVITANOK_ERR_DECODE_FAILED;
    }

    int width = meta.width;
    int height = meta.height;
    int channels = 3;

    // 2. Aceleração heterogênea: CUDA > Vulkan > CPU
    bool hwProcessed = false;

    if (g_activeBackend == SVITANOK_BACKEND_CUDA && g_cudaAccelerator) {
        hwProcessed = g_cudaAccelerator->processOnGpu(
            rgbBuffer.data(), width, height, channels,
            denoiseStrength, exposureCompensation, colorTemperature
        );
    }

    if (!hwProcessed && g_activeBackend == SVITANOK_BACKEND_VULKAN && g_vulkanEngine) {
        hwProcessed = g_vulkanEngine->dispatchCompute(
            rgbBuffer.data(), width, height, channels,
            denoiseStrength, exposureCompensation, colorTemperature
        );
    }

    // 3. Fallback multithreaded em CPU se a GPU não foi utilizada
    if (!hwProcessed && g_cpuDecoder) {
        if (denoiseStrength > 0.01f) {
            g_cpuDecoder->applyBilateralDenoise(rgbBuffer, width, height, denoiseStrength);
        }
        if (std::abs(exposureCompensation) > 0.01f || std::abs(colorTemperature) > 0.01f) {
            g_cpuDecoder->applyToneAdjustments(rgbBuffer, width, height, exposureCompensation, colorTemperature);
        }
    }

    // 4. Alocação em Heap C para devolução segura ao Go
    size_t totalBytes = rgbBuffer.size();
    uint8_t* outBuf = static_cast<uint8_t*>(std::malloc(totalBytes));
    if (!outBuf) {
        return SVITANOK_ERR_OUT_OF_MEMORY;
    }

    std::memcpy(outBuf, rgbBuffer.data(), totalBytes);

    *outRgbData = outBuf;
    *outWidth = width;
    *outHeight = height;
    *outChannels = channels;

    return SVITANOK_SUCCESS;
}

SVITANOK_API void Svitanok_FreeBuffer(uint8_t* buffer) {
    if (buffer) {
        std::free(buffer);
    }
}

SVITANOK_API void Svitanok_ShutdownEngine(void) {
    std::lock_guard<std::mutex> lock(g_engineMutex);
    if (g_cudaAccelerator) g_cudaAccelerator->shutdown();
    if (g_vulkanEngine) g_vulkanEngine->shutdown();
    g_cpuDecoder.reset();
    g_cudaAccelerator.reset();
    g_vulkanEngine.reset();
    g_initialized = false;
}

} // extern "C"
