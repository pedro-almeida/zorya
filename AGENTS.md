# 📜 AGENTS.md — Carta Magna da Usina Zorya v3.1 (Sovereign Rust Engine)

1. **Hardware Alvo & Limites Térmicos/VRAM:**
   - Acer Nitro 5 (Windows 11) com GPU dedicada **NVIDIA GeForce GTX 1650 (4 GB VRAM, Turing `sm_75`)**.
   - Porta canônica: `8085` (`127.0.0.1:8085` e Tailscale `100.106.120.35:8085`).
   - Todo processamento de vídeo deve usar aceleração `-c:v h264_nvenc` com duração explícita `-t <segundos>` calculada do cabeçalho RIFF WAVE (nunca usar `-loop 1` com `-shortest` sem `-t`, pois trava o FFmpeg no Windows).

2. **Padrão de Código e CI/CD Determinístico:**
   - Zero warnings no `cargo clippy --all-targets -- -D warnings`.
   - Formatação estrita via `cargo fmt --all -- --check`.
   - Proibido vazar handles GDI32/USER32 no `src/systray.rs` (sempre chamar `DestroyIcon` / `DeleteObject`).

3. **Módulos Soberanos Integrados & Paridade ArtCraft v0.20.2:**
   - **Suíte ArtCraft (`src/craft/`):** LightCraft (`culling.rs` com ROI guards e Rayon paralelo), PhotoCraft (`colorspace.rs` com `estimated_output_bytes`, `fit_image_dont_enlarge` anti-upscale destrutivo e `auto_black_white_mix` para retratos Svitanok), EffectCraft (`denoise.rs` bilateral Luma/Chroma com `spawn_blocking`), VectorCraft & FigCraft (`vector.rs` com `arrow_width`/`arrow_length` parametrizáveis), PdfCraft & DeckCraft (`pdf.rs`), WordCraft (`mod.rs`), FilmCraft (`video_teaser.rs`), DesignCraft (`mod.rs`) e SoundCraft (`audio_dsp.rs` EBU R128 -14 LUFS + `voice.rs` RVC/Audiobook GPU, pipeline de denoise espectral FFT `afftdn=nf=-25` com `dynaudnorm` e geração automática de `reference_clean.wav` pós-ingestão YouTube em `scripts/zorya_rvc_trainer.py`, além de fallback resiliente automático para `edge-tts` `pt-BR-FranciscaNeural`/`pt-BR-AntonioNeural` em `scripts/zorya_voice_worker.py`).
   - **Endpoint de Sincronização Radar Upstream:** `GET /api/v1/craft/upstream-status` reporta em tempo real a compatibilidade com a suíte ArtCraft (PhotoCraft v0.20.2, LightCraft v0.20.1, FilmCraft v0.20.1, SoundCraft v0.5.0, WordCraft v0.5.0) para os auditores noturnos da VPS.
   - **Svitlo Video Engine v2 (`src/video/`):** Avatar 3D hiper-realista animado + Narração "Modo Clóvis" via `gemini-2.5-flash-preview-tts` (header `x-goog-api-key`) + Minerador/Treinador de Voz em Rust (`voice_trainer.rs`) para ingestão automatizada de vídeos de referência e montagem PiP via FFmpeg NVENC.

4. **Higiene Git e Supressão de Processos no Windows:**
   - **Higiene de Scripts:** Proibido versionar scripts temporários de patch avulso (`patch_*.py`, `fix_*.sh`, etc.) em `scripts/`. A pasta `scripts/` deve conter exclusivamente ferramentas operacionais canônicas. Sempre rodar validação e sincronizar via `git commit` e `git push origin main` ao concluir tarefas.
   - **Supressão Estrita de Console (`CREATE_NO_WINDOW`):** Todo processo filho gerado via `std::process::Command` ou `tokio::process::Command` no Windows deve aplicar `.creation_flags(0x08000000)` para nunca piscar janelas de terminal na tela do usuário.

