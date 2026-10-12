/**
 * =============================================================================
 * SVITANOK RAW ENGINE — Core Native Engine C ABI Interface
 * High-Performance Heterogeneous RAW Processing & Hardware Switching
 * =============================================================================
 *
 * PT: Cabeçalho canônico C/C++ expondo a interface FFI para carregamento dinâmico
 *     pelo daemon Go, com chaveamento dinâmico entre CUDA, Vulkan e CPU multithreaded.
 * EN: Canonical C/C++ header exporting FFI C-ABI interface for dynamic loading
 *     by Go daemon, with dynamic switching between CUDA, Vulkan and multithreaded CPU.
 *
 * @llm-context Módulo Svitanok / Core C++ / FFI & Heterogeneous Hardware
 * @llm-invariant Memória alocada em C++ deve ser liberada exclusivamente via Svitanok_FreeBuffer
 * @llm-rule Thread-safe para múltiplas requisições simultâneas de processamento
 */

#ifndef SVITANOK_RAW_CORE_H
#define SVITANOK_RAW_CORE_H

#include <stdint.h>
#include <stddef.h>

#ifdef _WIN32
    #ifdef SVITANOK_EXPORTS
        #define SVITANOK_API __declspec(dllexport)
    #else
        #define SVITANOK_API __declspec(dllimport)
    #endif
#else
    #define SVITANOK_API __attribute__((visibility("default")))
#endif

#ifdef __cplusplus
extern "C" {
#endif

/**
 * Backends de Aceleração Gráfica Heterogênea
 */
typedef enum {
    SVITANOK_BACKEND_AUTO      = 0,
    SVITANOK_BACKEND_CPU_MULTI = 1,
    SVITANOK_BACKEND_CUDA      = 2,
    SVITANOK_BACKEND_VULKAN    = 3
} SvitanokBackendType;

/**
 * Códigos de Retorno do Motor
 */
typedef enum {
    SVITANOK_SUCCESS           =  0,
    SVITANOK_ERR_INVALID_PARAM = -1,
    SVITANOK_ERR_OUT_OF_MEMORY = -2,
    SVITANOK_ERR_DECODE_FAILED = -3,
    SVITANOK_ERR_HW_UNAVAILABLE= -4,
    SVITANOK_ERR_UNKNOWN       = -99
} SvitanokResult;

/**
 * // PT: Inicializa o subsistema de aceleração detectando o hardware disponível
 * // EN: Initializes acceleration subsystem by probing available hardware
 * // AI: @llm-context Inicialização do motor C++/CUDA/Vulkan
 * // AI: @llm-invariant Detecta NVIDIA dGPU antes de tentar alocação VRAM
 * // AI: @llm-rule Retorna backend selecionado (1, 2 ou 3) ou código de erro negativo
 */
SVITANOK_API int Svitanok_InitEngine(int preferredBackend);

/**
 * // PT: Retorna JSON contendo os recursos reais de hardware detectados pelo C++
 * // EN: Returns JSON string containing real hardware capabilities probed by C++
 * // AI: @llm-context Detecção nativa de CPU, RAM, CUDA e Vulkan
 * // AI: @llm-invariant Preenche até bufferSize-1 com terminação nula
 */
SVITANOK_API int Svitanok_GetHardwareCaps(char* jsonBuffer, int bufferSize);

/**
 * // PT: Processa buffer de arquivo RAW aplicando demosaicing, denoise e ajustes tonais
 * // EN: Processes RAW camera buffer applying demosaicing, denoise and tone adjustments
 * // AI: @llm-context Pipeline completo de decodificação e tratamento
 * // AI: @llm-invariant Buffer de saída alocado em heap nativo, liberar com Svitanok_FreeBuffer
 * // AI: @llm-rule Executa em VRAM se CUDA ativo, caso contrário Vulkan Compute ou CPU multithreaded
 */
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
);

/**
 * // PT: Libera buffer de imagem alocado pelo motor C++
 * // EN: Frees image buffer allocated by C++ engine
 * // AI: @llm-context Gerenciamento de memória nativa FFI
 * // AI: @llm-invariant Evita memory leaks entre Go e C++
 */
SVITANOK_API void Svitanok_FreeBuffer(uint8_t* buffer);

/**
 * // PT: Encerra subsistemas de aceleração e libera contextos CUDA/Vulkan
 * // EN: Shuts down acceleration subsystems and releases CUDA/Vulkan contexts
 */
SVITANOK_API void Svitanok_ShutdownEngine(void);

#ifdef __cplusplus
}
#endif

#endif // SVITANOK_RAW_CORE_H
