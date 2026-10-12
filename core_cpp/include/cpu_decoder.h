/**
 * =============================================================================
 * SVITANOK RAW ENGINE — CPU Multithreaded Decoder (LibRaw Architecture)
 * High-Throughput Bayer Matrix Decoding Across Logical CPU Threads
 * =============================================================================
 *
 * PT: Decodificador em CPU utilizando std::thread particionando a matriz Bayer
 *     e os filtros de denoise bilateral em fatias espaciais independentes.
 * EN: CPU decoder using std::thread partitioning Bayer matrix and bilateral
 *     denoise filters into independent spatial slices across logical cores.
 *
 * @llm-context Módulo Svitanok / Core C++ / CPU LibRaw Architecture
 * @llm-invariant Zero overhead de sincronização dentro do loop de demosaicing
 * @llm-rule Utiliza std::thread::hardware_concurrency() para saturação linear de CPU
 */

#ifndef SVITANOK_CPU_DECODER_H
#define SVITANOK_CPU_DECODER_H

#include <vector>
#include <cstdint>
#include <cstddef>
#include <string>

namespace Svitanok {

struct RawImageMetadata {
    int width = 0;
    int height = 0;
    int channels = 3;
    int bitsPerSample = 14;
    std::string cameraMake;
    std::string cameraModel;
    int iso = 100;
    float shutterSpeed = 0.005f;
    float aperture = 2.8f;
};

class CpuDecoder {
public:
    CpuDecoder();
    ~CpuDecoder();

    /**
     * // PT: Decodifica buffer binário RAW da câmera em buffer RGB 8-bit ou 16-bit
     * // EN: Decodes raw camera binary buffer into RGB 8-bit or 16-bit buffer
     */
    bool decodeRaw(
        const uint8_t* rawData,
        size_t rawSize,
        std::vector<uint8_t>& outRgb,
        RawImageMetadata& outMeta
    );

    /**
     * // PT: Aplica denoise bilateral multithreaded particionando linhas na CPU
     * // EN: Applies multithreaded bilateral denoise partitioning rows across CPU
     */
    void applyBilateralDenoise(
        std::vector<uint8_t>& rgbData,
        int width,
        int height,
        float strength,
        unsigned int threadCount = 0
    );

    /**
     * // PT: Ajusta exposição e temperatura de cor de forma vetorial
     * // EN: Adjusts exposure and color temperature vectorially
     */
    void applyToneAdjustments(
        std::vector<uint8_t>& rgbData,
        int width,
        int height,
        float exposure,
        float temperature,
        unsigned int threadCount = 0
    );

private:
    // Extrai preview JPEG embutido ou reconstrói matriz Bayer em caso de arquivo bruto
    bool parseRawContainer(
        const uint8_t* rawData,
        size_t rawSize,
        std::vector<uint8_t>& outBuffer,
        RawImageMetadata& outMeta
    );

    // Demosaicing bilinear acelerado por blocos de linhas
    void demosaicBilinearBlock(
        const uint16_t* bayerData,
        uint8_t* outRgb,
        int width,
        int startY,
        int endY
    );
};

} // namespace Svitanok

#endif // SVITANOK_CPU_DECODER_H
