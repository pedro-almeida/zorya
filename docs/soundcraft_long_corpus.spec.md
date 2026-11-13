# Especificação Técnica: SoundCraft Long Corpus Miner + RVC Trainer (GTX 1650) + Estúdio de Audiobook GPU

## 1. Visão Geral & Hardware Alvo
- **Nó Local:** Acer Nitro 5 (Windows 11)
- **GPU Dedicada (100% livre para CUDA):** NVIDIA GeForce GTX 1650 (4 GB VRAM, Turing `sm_75`) — o display do sistema roda isolado na iGPU AMD Radeon.
- **Memória do Sistema:** 32 GB RAM (utilizados para manter horas de áudio em memória e cache dos índices FAISS do RVC).
- **Motor Python CUDA:** `C:\mriya\mriya\audiobook_tts\.venv\Scripts\python.exe` (PyTorch 2.6 + CUDA 12.4).
- **Daemon HTTP Soberano:** Zorya v3.1 (`http://127.0.0.1:8085` e Tailscale `100.106.120.35:8085`).

---

## 2. Estrutura Canônica em Disco
Todos os dados de corpus vocal e modelos treinados residem sob `datasets/voices/`:

```text
datasets/voices/
├── profiles.json                         # Registro persistente de perfis vocais (JSON)
├── profiles/
│   └── <voice_id>/
│       ├── urls.txt                      # Lista de URLs do YouTube submetidas
│       ├── raw/                          # Áudios brutos baixados via yt-dlp ou upload multipart
│       │   ├── yt_001.wav
│       │   └── yt_001_vocals.wav         # Stem de voz seca isolado via Demucs (htdemucs)
│       ├── wavs/                         # Clipes limpos fatiados por VAD (4s–12s, 40kHz mono PCM_16)
│       │   ├── seg_00001.wav
│       │   └── ...
│       └── rvc/                          # Artefatos RVC treinados na GTX 1650 ou importados
│           ├── model.pth
│           └── added.index
└── audiobooks/
    └── <job_id>/
        ├── input.txt                     # Texto/roteiro submetido
        └── output.mp3                    # Audiobook final masterizado (-14 LUFS)
```

### Schema de `datasets/voices/profiles.json`
```json
{
  "profiles": [
    {
      "voice_id": "clovis-barros",
      "display_name": "Prof. Clóvis de Barros Filho",
      "clip_count": 320,
      "total_duration_seconds": 2560.0,
      "has_rvc_model": true,
      "rvc_model_path": "datasets/voices/profiles/clovis-barros/rvc/model.pth",
      "rvc_index_path": "datasets/voices/profiles/clovis-barros/rvc/added.index",
      "updated_at_epoch": 1794440000
    }
  ]
}
```

---

## 3. Contratos de Endpoints HTTP (`src/server.rs` + `src/craft/voice.rs`)

### 3.1. `GET /api/v1/voice/profiles`
Retorna a lista de perfis persistidos em `datasets/voices/profiles.json` (combinada com perfis padrão).

### 3.2. `POST /api/v1/voice/youtube-ingest`
Dispara em background o download via `yt-dlp`, isolamento vocal opcional via `demucs` (`htdemucs` na GTX 1650) e fatiamento VAD 40kHz mono em `wavs/`.
- **Request JSON:**
  ```json
  {
    "voice_id": "clovis-barros",
    "display_name": "Prof. Clóvis de Barros",
    "youtube_urls": ["https://www.youtube.com/watch?v=..."],
    "isolate_vocals": true
  }
  ```
- **Response (`202 Accepted`):**
  ```json
  {
    "job_id": "ingest_clovis-barros_1794440000",
    "voice_id": "clovis-barros",
    "status": "queued",
    "stage": "initializing",
    "clips_generated": 0,
    "total_duration_seconds": 0.0,
    "error": null
  }
  ```

### 3.3. `GET /api/v1/voice/youtube-ingest/:job_id`
Consulta status em tempo real de um job de ingestão (YouTube ou upload local).

### 3.4. `POST /api/v1/voice/corpus-upload`
Upload Multipart (`DefaultBodyLimit::max(1024 * 1024 * 1024)` — até 1 GB) para arquivos longos locais (`.mp3`, `.wav`, `.m4a`, `.mp4`, `.ogg`, `.flac`), com campo opcional `voice_id`, `display_name` e `isolate_vocals`. Salva em `raw/`, executa Demucs (se solicitado) e fatia via VAD em `wavs/`.

### 3.5. `POST /api/v1/voice/rvc-train`
Inicia o treino local de RVC na GTX 1650 (`batch_size=1`, `fp16=True`, pitch `rmvpe`, índice FAISS nos 32 GB RAM) ou registra/valida artefatos locais.
- **Request JSON:**
  ```json
  {
    "voice_id": "clovis-barros",
    "epochs": 120,
    "sample_rate": "40k"
  }
  ```
- **Response (`202 Accepted`):** retorna `RvcTrainJob` com `job_id`.

### 3.6. `GET /api/v1/voice/rvc-train/:job_id`
Retorna progresso (`current_epoch`, `total_epochs`, `percent`, `stage`, `status`, `error`) de treino RVC.

### 3.7. `POST /api/v1/voice/rvc-import`
Multipart opcional para importar diretamente `.pth` e `.index` já treinados para `datasets/voices/profiles/<voice_id>/rvc/`.

### 3.8. `POST /api/v1/voice/audiobook`
Gera um audiobook completo em MP3 utilizando `scripts/zorya_voice_worker.py` em um dos 3 motores:
- `kokoro_rvc` (Padrão para vozes treinadas: Kokoro 82M PT-BR + conversão de timbre RVC)
- `xtts_multi` (Imediato sem treino: seleciona os 15 melhores clipes em `wavs/` como `speaker_wav=[...]`)
- `kokoro` (Nativo: vozes brasileiras `pf_dora`, `pm_alex`, `pm_santa`)
- **Request JSON:**
  ```json
  {
    "voice_id": "clovis-barros",
    "engine": "kokoro_rvc",
    "base_voice": "pm_alex",
    "speed": 1.0,
    "text": "Texto completo do audiobook..."
  }
  ```

### 3.9. `GET /api/v1/voice/audiobook/:job_id`
Retorna progresso (`percent`, `stage`, `status`, `download_url`, `duration_seconds`, `error`).

### 3.10. `GET /api/v1/voice/audiobook/:job_id/download`
Serve o arquivo `.mp3` gerado com `Content-Type: audio/mpeg` e `Content-Disposition`.

