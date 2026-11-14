import argparse
import gc
import io
import os
import re
import subprocess
import sys
import wave
from pathlib import Path
from typing import List, Optional

import numpy as np
from pydub import AudioSegment


def log_device_check() -> str:
    try:
        import torch

        if torch.cuda.is_available():
            dev_name = torch.cuda.get_device_name(0)
            print(f"STAGE:device_check:cuda:{dev_name}", flush=True)
            return "cuda"
        else:
            print("STAGE:device_check:cpu", flush=True)
            return "cpu"
    except Exception:
        print("STAGE:device_check:cpu", flush=True)
        return "cpu"


def clean_text_for_narration(raw_text: str) -> str:
    text = re.sub(r"```[\s\S]*?```", " ", raw_text)
    text = re.sub(r"^#{1,6}\s+", "", text, flags=re.MULTILINE)
    text = re.sub(r"\*\*(.*?)\*\*", r"\1", text)
    text = re.sub(r"\*(.*?)\*", r"\1", text)
    text = re.sub(r"`(.*?)`", r"\1", text)
    text = re.sub(r"\[([^\]]+)\]\([^\)]+\)", r"\1", text)
    text = re.sub(r"^\s*[\*\•\-]\s+", "", text, flags=re.MULTILINE)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def split_into_natural_chunks(text: str, max_chars: int = 850) -> List[str]:
    paragraphs = [p.strip() for p in text.split("\n\n") if p.strip()]
    chunks: List[str] = []
    current = ""

    for para in paragraphs:
        if len(para) <= max_chars:
            if len(current) + len(para) + 2 <= max_chars:
                current = f"{current}\n\n{para}".strip()
            else:
                if current:
                    chunks.append(current)
                current = para
        else:
            sentences = re.split(r"(?<=[.!?])\s+", para)
            for sent in sentences:
                if len(current) + len(sent) + 1 <= max_chars:
                    current = f"{current} {sent}".strip()
                else:
                    if current:
                        chunks.append(current)
                    current = sent
    if current:
        chunks.append(current)
    return chunks if chunks else [text]


def float32_to_audio_segment(samples: np.ndarray, sample_rate: int = 24000) -> AudioSegment:
    clipped = np.clip(samples, -1.0, 1.0)
    int16_data = (clipped * 32767.0).astype(np.int16)
    wav_buffer = io.BytesIO()
    with wave.open(wav_buffer, "wb") as wf:
        wf.setnchannels(1)
        wf.setsampwidth(2)
        wf.setframerate(sample_rate)
        wf.writeframes(int16_data.tobytes())
    wav_buffer.seek(0)
    return AudioSegment.from_wav(wav_buffer)


def select_best_reference_clips(wavs_dir: Path, limit: int = 15) -> List[Path]:
    """Seleciona os 15 melhores clipes de voz seca em wavs/ ranqueados por energia RMS ideal e duracao."""
    import soundfile as sf

    candidates = []
    ref_clean = wavs_dir.parent / "reference_clean.wav"
    if ref_clean.exists():
        candidates.append((999.0, ref_clean))

    for clip in sorted(wavs_dir.glob("*.wav")):
        try:
            data, sr = sf.read(str(clip), dtype="float32")
            dur = len(data) / max(sr, 1)
            if dur < 3.5:
                continue
            rms = float(np.sqrt(np.mean(data**2)))
            if rms < 0.015:
                continue
            # Pontua clipes proximos de 7-9s e RMS claro sem clipar
            score = min(dur, 10.0) * (1.0 - abs(rms - 0.12))
            candidates.append((score, clip))
        except Exception:
            continue

    candidates.sort(key=lambda item: item[0], reverse=True)
    return [c[1] for c in candidates[:limit]]


VOICESTUDIO_PRESETS = {
    "narrador_energetico": ("pm_alex", 1.08),
    "estudio_academico": ("pm_alex", 0.96),
    "academico_calmo": ("pm_alex", 0.96),
    "podcast_grave": ("pm_santa", 1.0),
}


def synthesize_edge_tts_fallback(
    chunks: List[str], base_voice: str, speed: float
) -> AudioSegment:
    """Fallback de alta confiabilidade via edge-tts (Francisca / Antonio Neural) caso Kokoro nao esteja disponivel."""
    edge_voice = (
        "pt-BR-FranciscaNeural"
        if base_voice == "pf_dora"
        else "pt-BR-AntonioNeural"
    )
    rate_pct = int(round((speed - 1.0) * 100))
    rate_str = f"{rate_pct:+d}%"
    print("ENGINE_USED:fallback_edge_tts", flush=True)
    print(f"STAGE:edge_tts_init:{edge_voice}:{rate_str}", flush=True)

    final_audio = AudioSegment.empty()
    pause = AudioSegment.silent(duration=320)
    tmp_dir = Path("datasets/voices/_tmp_edge")
    tmp_dir.mkdir(parents=True, exist_ok=True)

    total = len(chunks)
    for idx, chunk in enumerate(chunks, start=1):
        pct = int((idx / max(total, 1)) * 88)
        print(f"PROGRESS:{idx}/{total} ({pct}%)", flush=True)
        out_mp3 = tmp_dir / f"edge_{os.getpid()}_{idx:04d}.mp3"
        cmd = [
            sys.executable,
            "-m",
            "edge_tts",
            "--voice",
            edge_voice,
            f"--rate={rate_str}",
            "--text",
            chunk,
            "--write-media",
            str(out_mp3),
        ]
        subprocess.run(cmd, capture_output=True, text=True)
        if out_mp3.exists():
            final_audio += AudioSegment.from_file(str(out_mp3)) + pause
            out_mp3.unlink(missing_ok=True)

    return final_audio


def synthesize_kokoro(
    chunks: List[str], base_voice: str, speed: float
) -> AudioSegment:
    if base_voice in VOICESTUDIO_PRESETS:
        base_voice, preset_speed = VOICESTUDIO_PRESETS[base_voice]
        if abs(speed - 1.0) < 1e-4:
            speed = preset_speed

    try:
        import torch
        from kokoro import KPipeline

        device = "cuda" if torch.cuda.is_available() else "cpu"
        voice = base_voice if base_voice in ("pf_dora", "pm_alex", "pm_santa") else "pm_alex"
        print(f"STAGE:kokoro_init:{device}:{voice}", flush=True)

        pipeline = KPipeline(lang_code="p", device=device)
        final_audio = AudioSegment.empty()
        pause = AudioSegment.silent(duration=350)

        total = len(chunks)
        for idx, chunk in enumerate(chunks, start=1):
            pct = int((idx / max(total, 1)) * 75)
            print(f"PROGRESS:{idx}/{total} ({pct}%)", flush=True)
            parts: List[np.ndarray] = []
            for _, _, audio in pipeline(chunk, voice=voice, speed=speed):
                if audio is not None and len(audio) > 0:
                    if hasattr(audio, "detach"):
                        audio = audio.detach().cpu().numpy()
                    parts.append(np.asarray(audio, dtype=np.float32))
            if parts:
                combined = np.concatenate(parts)
                final_audio += float32_to_audio_segment(combined, 24000) + pause

            try:
                if torch.cuda.is_available():
                    torch.cuda.empty_cache()
                gc.collect()
            except Exception:
                pass

        return final_audio
    except Exception as e:
        print(
            f"[AVISO] Kokoro indisponivel ou falhou ({e}). Acionando fallback edge-tts...",
            flush=True,
        )
        return synthesize_edge_tts_fallback(chunks, base_voice, speed)


def synthesize_xtts_multi(
    chunks: List[str], wavs_dir: Path, speed: float
) -> AudioSegment:
    try:
        import torch

        # Compatibilidade determinística com PyTorch 2.6+ onde weights_only defaultou para True
        if hasattr(torch, "load"):
            _orig_torch_load = torch.load

            def _torch_load_compat(*args, **kwargs):
                if "weights_only" not in kwargs:
                    kwargs["weights_only"] = False
                return _orig_torch_load(*args, **kwargs)

            torch.load = _torch_load_compat

        from TTS.api import TTS  # type: ignore
    except ImportError:
        print(
            "[AVISO] Pacote Coqui TTS ausente neste venv; acionando fallback neural local...",
            flush=True,
        )
        print("ENGINE_USED:fallback_kokoro", flush=True)
        try:
            return synthesize_kokoro(chunks, base_voice="pm_alex", speed=speed)
        except Exception as e:
            print(
                f"[AVISO] Kokoro fallback falhou ({e}); acionando fallback edge-tts...",
                flush=True,
            )
            print("ENGINE_USED:fallback_edge_tts", flush=True)
            return synthesize_edge_tts_fallback(chunks, base_voice="pm_alex", speed=speed)

    ref_clips = select_best_reference_clips(wavs_dir, limit=15)
    if not ref_clips:
        print(
            f"[AVISO] Nenhum clipe de referencia encontrado em {wavs_dir}. Acionando fallback neural local...",
            flush=True,
        )
        print("ENGINE_USED:fallback_kokoro", flush=True)
        return synthesize_kokoro(chunks, base_voice="pm_alex", speed=speed)

    device = "cuda" if torch.cuda.is_available() else "cpu"
    print(f"STAGE:xtts_multi_init:{device}:{len(ref_clips)}_clips", flush=True)
    if device == "cuda":
        print("ENGINE_USED:xtts_v2_cloned_cuda", flush=True)
    else:
        print("ENGINE_USED:xtts_v2_cloned_cpu", flush=True)

    try:
        # Coqui XTTS-v2 aceita lista de multiplos arquivos em speaker_wav
        os.environ.setdefault("COQUI_TOS_AGREED", "1")
        tts = TTS("tts_models/multilingual/multi-dataset/xtts_v2").to(device)

        final_audio = AudioSegment.empty()
        pause = AudioSegment.silent(duration=320)
        ref_paths = [str(p) for p in ref_clips]
        tmp_dir = wavs_dir.parent / "_tmp_xtts"
        tmp_dir.mkdir(parents=True, exist_ok=True)

        total = len(chunks)
        for idx, chunk in enumerate(chunks, start=1):
            pct = int((idx / max(total, 1)) * 90)
            print(f"PROGRESS:{idx}/{total} ({pct}%)", flush=True)
            out_chunk_wav = tmp_dir / f"chunk_{idx:04d}.wav"
            tts.tts_to_file(
                text=chunk,
                speaker_wav=ref_paths,
                language="pt",
                file_path=str(out_chunk_wav),
                speed=speed,
            )
            if out_chunk_wav.exists():
                final_audio += AudioSegment.from_wav(str(out_chunk_wav)) + pause
                out_chunk_wav.unlink(missing_ok=True)

            try:
                if torch.cuda.is_available():
                    torch.cuda.empty_cache()
                gc.collect()
            except Exception:
                pass

        return final_audio
    except Exception as e:
        print(
            f"[AVISO] XTTS falhou durante execucao ({e}); acionando fallback neural local...",
            flush=True,
        )
        print("ENGINE_USED:fallback_kokoro", flush=True)
        return synthesize_kokoro(chunks, base_voice="pm_alex", speed=speed)


def apply_rvc_conversion(
    base_audio: AudioSegment, rvc_dir: Path, tmp_dir: Path
) -> AudioSegment:
    model_pth = rvc_dir / "model.pth"
    if not model_pth.exists():
        # Tenta localizar qualquer .pth dentro de rvc_dir
        pths = list(rvc_dir.glob("*.pth"))
        if not pths:
            raise RuntimeError(
                f"Modelo RVC (model.pth) nao encontrado em {rvc_dir}."
            )
        model_pth = pths[0]

    index_path: Optional[Path] = rvc_dir / "added.index"
    if not index_path.exists():
        idxs = list(rvc_dir.glob("*.index"))
        index_path = idxs[0] if idxs else None

    tmp_dir.mkdir(parents=True, exist_ok=True)
    in_wav = tmp_dir / "kokoro_base.wav"
    out_wav = tmp_dir / "rvc_converted.wav"
    base_audio.export(str(in_wav), format="wav")

    print("STAGE:rvc_converting:85%", flush=True)

    import torch

    device = "cuda:0" if torch.cuda.is_available() else "cpu"
    if "cuda" in device:
        print("ENGINE_USED:rvc_cloned_cuda", flush=True)
    else:
        print("ENGINE_USED:rvc_cloned_cpu", flush=True)

    try:
        from rvc_python.infer import RVCInference  # type: ignore

        rvc = RVCInference(device=device)
        rvc.load_model(str(model_pth), index_path=str(index_path) if index_path else "")
        rvc.set_params(f0method="rmvpe", f0up_key=0, index_rate=0.75, protect=0.33)
        rvc.infer_file(str(in_wav), str(out_wav))
    except ImportError as exc:
        raise RuntimeError(
            "Pacote rvc-python nao instalado no ambiente virtual."
        ) from exc

    if not out_wav.exists():
        raise RuntimeError("Conversao RVC nao gerou arquivo de saida.")

    converted = AudioSegment.from_wav(str(out_wav))
    in_wav.unlink(missing_ok=True)
    out_wav.unlink(missing_ok=True)

    try:
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
        gc.collect()
    except Exception:
        pass

    return converted


def export_mastered_mp3(audio: AudioSegment, output_mp3: Path) -> float:
    """Exporta MP3 normalizado EBU R128 (-14 LUFS) via FFmpeg."""
    print("STAGE:mastering_ebu_r128:95%", flush=True)
    output_mp3.parent.mkdir(parents=True, exist_ok=True)
    tmp_wav = output_mp3.with_suffix(".tmp.wav")
    audio.export(str(tmp_wav), format="wav")

    cmd = [
        "ffmpeg",
        "-y",
        "-i",
        str(tmp_wav),
        "-af",
        "acompressor=threshold=-18dB:ratio=2.5,loudnorm=I=-14:TP=-1.0:LRA=11",
        "-codec:a",
        "libmp3lame",
        "-b:a",
        "192k",
        str(output_mp3),
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    tmp_wav.unlink(missing_ok=True)

    if res.returncode != 0 or not output_mp3.exists():
        # Fallback export direto via pydub
        audio.export(str(output_mp3), format="mp3", bitrate="192k")

    return round(len(audio) / 1000.0, 2)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Zorya SoundCraft Audiobook GPU Worker (Kokoro 82M / XTTS-v2 Multi / Kokoro+RVC)"
    )
    parser.add_argument("--text-file", type=Path, required=True)
    parser.add_argument("--output-mp3", type=Path, required=True)
    parser.add_argument(
        "--engine",
        type=str,
        default="kokoro",
        choices=["kokoro", "xtts_multi", "kokoro_rvc"],
    )
    parser.add_argument("--profile-dir", type=Path, required=False)
    parser.add_argument("--base-voice", type=str, default="narrador_energetico")
    parser.add_argument("--speed", type=float, default=1.0)
    args = parser.parse_args()

    log_device_check()

    raw_text = args.text_file.read_text(encoding="utf-8")
    cleaned = clean_text_for_narration(raw_text)
    if not cleaned:
        raise RuntimeError("Texto vazio apos limpeza para narracao.")

    max_chars = 350 if args.engine == "xtts_multi" else 850
    chunks = split_into_natural_chunks(cleaned, max_chars=max_chars)

    if args.engine == "kokoro":
        print("ENGINE_USED:kokoro_native", flush=True)
        audio = synthesize_kokoro(chunks, args.base_voice, args.speed)
    elif args.engine == "xtts_multi":
        if not args.profile_dir:
            raise RuntimeError("--profile-dir e obrigatorio para engine xtts_multi.")
        audio = synthesize_xtts_multi(chunks, args.profile_dir / "wavs", args.speed)
    elif args.engine == "kokoro_rvc":
        if not args.profile_dir:
            raise RuntimeError("--profile-dir e obrigatorio para engine kokoro_rvc.")
        rvc_dir = args.profile_dir / "rvc"
        wavs_dir = args.profile_dir / "wavs"
        has_pth = rvc_dir.exists() and any(rvc_dir.glob("*.pth"))
        if has_pth:
            base_audio = synthesize_kokoro(chunks, args.base_voice, args.speed)
            audio = apply_rvc_conversion(
                base_audio, rvc_dir, args.output_mp3.parent / "_tmp_rvc"
            )
        elif wavs_dir.exists() and len(list(wavs_dir.glob("*.wav"))) > 0:
            print(
                "[AVISO] model.pth RVC ausente; usando XTTS-v2 Multi-Referencia imediato com clipes em wavs/.",
                flush=True,
            )
            audio = synthesize_xtts_multi(chunks, wavs_dir, args.speed)
        else:
            raise RuntimeError(
                "Perfil sem modelo RVC treinado e sem clipes em wavs/. Minere ou envie audio primeiro."
            )
    else:
        raise RuntimeError(f"Motor desconhecido: {args.engine}")

    duration_s = export_mastered_mp3(audio, args.output_mp3)
    print(f"DONE_AUDIOBOOK:{duration_s}:{args.output_mp3}", flush=True)


if __name__ == "__main__":
    main()

