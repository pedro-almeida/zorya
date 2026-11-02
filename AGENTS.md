# 📜 AGENTS.md — Carta Magna da Usina Zorya v3.1 (Sovereign Rust Engine)

1. **Hardware Alvo & Limites Térmicos/VRAM:**
   - Acer Nitro 5 (Windows 11) com GPU dedicada **NVIDIA GeForce GTX 1650 (4 GB VRAM, Turing `sm_75`)**.
   - Porta canônica: `8085` (`127.0.0.1:8085` e Tailscale `100.106.120.35:8085`).
   - Todo processamento de vídeo deve usar aceleração `-c:v h264_nvenc` com duração explícita `-t <segundos>` calculada do cabeçalho RIFF WAVE (nunca usar `-loop 1` com `-shortest` sem `-t`, pois trava o FFmpeg no Windows).

2. **Padrão de Código e CI/CD Determinístico:**
   - Zero warnings no `cargo clippy --all-targets -- -D warnings`.
   - Formatação estrita via `cargo fmt --all -- --check`.
   - Proibido vazar handles GDI32/USER32 no `src/systray.rs` (sempre chamar `DestroyIcon` / `DeleteObject`).

3. **Módulos Soberanos Integrados:**
   - **Suíte ArtCraft (`src/craft/`):** LightCraft (`culling.rs`), PhotoCraft (`colorspace.rs`), EffectCraft (`denoise.rs` bilateral Luma/Chroma estilo Lightroom Clássico), VectorCraft & FigCraft (`vector.rs`), PdfCraft & DeckCraft (`pdf.rs`), WordCraft (`mod.rs`), FilmCraft (`video_teaser.rs`), DesignCraft (`mod.rs`) e SoundCraft (`audio_dsp.rs` EBU R128 -14 LUFS + `voice.rs`).
   - **Svitlo Video Engine v2 (`src/video/`):** Avatar 3D hiper-realista animado + Narração "Modo Clóvis" via `gemini-2.5-flash-preview-tts` (header `x-goog-api-key`) + Minerador/Treinador de Voz em Rust (`voice_trainer.rs`) para ingestão automatizada de vídeos de referência e montagem PiP via FFmpeg NVENC.

