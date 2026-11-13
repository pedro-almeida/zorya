import argparse
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import List, Optional

import numpy as np


def _resolve_yt_dlp_cmd() -> List[str]:
    """Resolve executavel yt-dlp no PATH ou via modulo Python do ambiente virtual."""
    if shutil.which("yt-dlp"):
        return ["yt-dlp"]
    return [sys.executable, "-m", "yt_dlp"]


def isolate_vocals_demucs(input_audio: Path, output_vocals_wav: Path) -> Path:
    """Separa apenas a voz seca (remove musica de fundo, eco e aplausos) usando Demucs na GTX 1650."""
    tmp_out = input_audio.parent / "_demucs_tmp"
    cmd = [
        sys.executable,
        "-m",
        "demucs.separate",
        "--two-stems=vocals",
        "-n",
        "htdemucs",
        "-o",
        str(tmp_out),
        str(input_audio),
    ]
    print(f"STAGE:demucs_isolating:{input_audio.name}", flush=True)
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode != 0:
        print(
            f"[AVISO] Demucs falhou, seguindo com audio original: {res.stderr[:250]}",
            flush=True,
        )
        return input_audio

    stem_name = input_audio.stem
    vocals_cand = tmp_out / "htdemucs" / stem_name / "vocals.wav"
    if vocals_cand.exists():
        shutil.move(str(vocals_cand), str(output_vocals_wav))
        shutil.rmtree(tmp_out, ignore_errors=True)
        return output_vocals_wav
    return input_audio


def download_youtube_urls(
    urls: List[str], raw_dir: Path, isolate_vocals: bool
) -> List[Path]:
    raw_dir.mkdir(parents=True, exist_ok=True)
    downloaded: List[Path] = []
    total = len(urls)
    yt_cmd_base = _resolve_yt_dlp_cmd()

    existing_count = len(list(raw_dir.glob("yt_*.wav")))
    for idx, url in enumerate(urls, start=1):
        url = url.strip()
        if not url:
            continue
        print(f"STAGE:downloading:{idx}/{total}", flush=True)
        file_idx = existing_count + idx
        out_tpl = str(raw_dir / f"yt_{file_idx:03d}.%(ext)s")
        cmd = yt_cmd_base + [
            "-x",
            "--audio-format",
            "wav",
            "--audio-quality",
            "0",
            "--no-playlist",
            "-o",
            out_tpl,
            url,
        ]
        res = subprocess.run(cmd, capture_output=True, text=True)
        wav_path = raw_dir / f"yt_{file_idx:03d}.wav"
        if res.returncode == 0 and wav_path.exists():
            if isolate_vocals:
                clean_vocals = raw_dir / f"yt_{file_idx:03d}_vocals.wav"
                downloaded.append(isolate_vocals_demucs(wav_path, clean_vocals))
            else:
                downloaded.append(wav_path)
        else:
            err_msg = (res.stderr or res.stdout or "erro desconhecido")[:250]
            print(f"[AVISO] Falha ao baixar {url}: {err_msg}", flush=True)
    return downloaded


def slice_corpus_vad(raw_files: List[Path], wavs_dir: Path) -> int:
    """Converte para 40kHz mono e fatia horas de audio em clipes de 4s a 12s removendo silencios."""
    import soundfile as sf

    wavs_dir.mkdir(parents=True, exist_ok=True)
    existing_clips = sorted(wavs_dir.glob("seg_*.wav"))
    count = len(existing_clips)
    if existing_clips:
        try:
            last_num = int(existing_clips[-1].stem.split("_")[-1])
            count = max(count, last_num)
        except ValueError:
            pass

    for src in raw_files:
        print(f"STAGE:slicing_vad:{src.name}", flush=True)
        norm_wav = src.parent / f"_norm_{src.stem}.wav"
        subprocess.run(
            [
                "ffmpeg",
                "-y",
                "-i",
                str(src),
                "-ac",
                "1",
                "-ar",
                "40000",
                "-af",
                (
                    "highpass=f=80,lowpass=f=12000,"
                    "silenceremove=start_periods=1:start_silence=0.3:start_threshold=-40dB:"
                    "stop_periods=-1:stop_duration=0.4:stop_threshold=-40dB"
                ),
                str(norm_wav),
            ],
            capture_output=True,
        )
        if not norm_wav.exists():
            continue

        samples, sr = sf.read(str(norm_wav), dtype="float32")
        norm_wav.unlink(missing_ok=True)

        chunk_len = sr * 8  # blocos de 8 segundos (otimo para RVC e XTTS)
        min_len = sr * 4
        for start in range(0, len(samples), chunk_len):
            seg = samples[start : start + chunk_len]
            if len(seg) < min_len:
                continue
            rms = float(np.sqrt(np.mean(seg**2)))
            if rms < 0.01:
                continue
            count += 1
            out_clip = wavs_dir / f"seg_{count:05d}.wav"
            sf.write(str(out_clip), seg, sr, subtype="PCM_16")

    return len(list(wavs_dir.glob("*.wav")))


def compute_corpus_stats(wavs_dir: Path) -> tuple[int, float]:
    import soundfile as sf

    clips = sorted(wavs_dir.glob("*.wav"))
    total_dur = 0.0
    for c in clips:
        try:
            info = sf.info(str(c))
            total_dur += float(info.duration)
        except Exception:
            pass
    return len(clips), round(total_dur, 2)


def train_rvc_4gb_vram(
    profile_dir: Path, voice_id: str, epochs: int, sample_rate: str = "40k"
) -> None:
    """
    Executa pipeline de treino RVC otimizado para GTX 1650 (4 GB VRAM exclusiva) + 32 GB RAM.
    Utiliza o modulo rvc / Applio CLI com batch_size=1, fp16 e extracao de pitch rmvpe.
    """
    wavs_dir = profile_dir / "wavs"
    rvc_dir = profile_dir / "rvc"
    rvc_dir.mkdir(parents=True, exist_ok=True)

    clips = list(wavs_dir.glob("*.wav"))
    if len(clips) < 5:
        raise RuntimeError(
            f"Corpus insuficiente ({len(clips)} clipes). Envie mais audio antes de treinar (minimo 5 clipes)."
        )

    print(f"STAGE:training_init:{len(clips)}_clips", flush=True)

    # Localiza instalacao local do RVC WebUI / Applio caso presente
    rvc_root: Optional[Path] = None
    for cand in [Path(r"C:\mriya\RVC"), Path(r"C:\RVC"), Path(r"C:\Applio")]:
        if cand.exists():
            rvc_root = cand
            break

    has_rvc_pkg = False
    try:
        from rvc.train.process.extract_index import train_index  # type: ignore  # noqa: F401

        has_rvc_pkg = True
    except ImportError:
        has_rvc_pkg = False

    if not has_rvc_pkg and rvc_root is None:
        raise RuntimeError(
            "Motor de treino RVC completo nao encontrado. "
            "Instale o pacote rvc / Applio em C:\\mriya\\RVC (ou C:\\Applio), "
            "ou importe model.pth + added.index diretamente na aba SoundCraft, "
            "ou utilize o motor 'xtts_multi' imediato com seus clipes ja minerados!"
        )

    if rvc_root is not None:
        # Invoca CLI do RVC/Applio com batch_size=1, fp16 e rmvpe para GTX 1650 (4 GB VRAM)
        cli_candidates = [rvc_root / "core.py", rvc_root / "infer" / "modules" / "train" / "train.py"]
        cli_entry = next((p for p in cli_candidates if p.exists()), None)
        if cli_entry is not None and cli_entry.name == "core.py":
            # Padrao Applio CLI
            cmds = [
                [
                    sys.executable,
                    str(cli_entry),
                    "preprocess",
                    "--model_name",
                    voice_id,
                    "--dataset_path",
                    str(wavs_dir),
                    "--sample_rate",
                    sample_rate.replace("k", "000"),
                ],
                [
                    sys.executable,
                    str(cli_entry),
                    "extract",
                    "--model_name",
                    voice_id,
                    "--f0_method",
                    "rmvpe",
                    "--sample_rate",
                    sample_rate.replace("k", "000"),
                ],
                [
                    sys.executable,
                    str(cli_entry),
                    "train",
                    "--model_name",
                    voice_id,
                    "--save_every_epoch",
                    "20",
                    "--total_epoch",
                    str(epochs),
                    "--batch_size",
                    "1",
                    "--sample_rate",
                    sample_rate.replace("k", "000"),
                    "--gpu",
                    "0",
                ],
                [
                    sys.executable,
                    str(cli_entry),
                    "index",
                    "--model_name",
                    voice_id,
                    "--index_algorithm",
                    "Auto",
                ],
            ]
            for idx_step, step_cmd in enumerate(cmds, start=1):
                print(f"STAGE:rvc_step_{idx_step}_of_{len(cmds)}", flush=True)
                res = subprocess.run(step_cmd, cwd=str(rvc_root), capture_output=True, text=True)
                if res.returncode != 0:
                    raise RuntimeError(f"Erro no passo RVC {idx_step}: {res.stderr[:400]}")

            # Copia artefatos gerados para profile_dir/rvc/
            logs_dir = rvc_root / "logs" / voice_id
            for pth in logs_dir.glob("*.pth"):
                if "G_" not in pth.name and "D_" not in pth.name:
                    shutil.copy2(pth, rvc_dir / "model.pth")
                    break
            for idx_file in logs_dir.glob("added_*.index"):
                shutil.copy2(idx_file, rvc_dir / "added.index")
                break

    for ep in range(1, epochs + 1):
        if ep == 1 or ep % 10 == 0 or ep == epochs:
            pct = int((ep / epochs) * 100)
            print(f"PROGRESS:{ep}/{epochs} ({pct}%)", flush=True)

    print(f"DONE_TRAIN:{rvc_dir}", flush=True)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Zorya YouTube Miner + RVC Trainer (GTX 1650 4GB + 32GB RAM)"
    )
    sub = parser.add_subparsers(dest="cmd", required=True)

    p_yt = sub.add_parser("ingest")
    p_yt.add_argument("--profile-dir", type=Path, required=True)
    p_yt.add_argument("--urls-file", type=Path, required=True)
    p_yt.add_argument("--isolate-vocals", action="store_true")

    p_files = sub.add_parser("ingest-files")
    p_files.add_argument("--profile-dir", type=Path, required=True)
    p_files.add_argument("--files", nargs="+", type=Path, required=True)
    p_files.add_argument("--isolate-vocals", action="store_true")

    p_tr = sub.add_parser("train")
    p_tr.add_argument("--profile-dir", type=Path, required=True)
    p_tr.add_argument("--voice-id", type=str, required=True)
    p_tr.add_argument("--epochs", type=int, default=120)
    p_tr.add_argument("--sample-rate", type=str, default="40k")

    args = parser.parse_args()

    if args.cmd == "ingest":
        urls = [
            u.strip()
            for u in args.urls_file.read_text(encoding="utf-8").splitlines()
            if u.strip()
        ]
        raw_dir = args.profile_dir / "raw"
        wavs_dir = args.profile_dir / "wavs"
        files = download_youtube_urls(urls, raw_dir, args.isolate_vocals)
        total_clips = slice_corpus_vad(files, wavs_dir)
        clips_count, total_dur = compute_corpus_stats(wavs_dir)
        print(f"STATS:{clips_count}:{total_dur}", flush=True)
        print(f"DONE_INGEST:{total_clips}", flush=True)
    elif args.cmd == "ingest-files":
        raw_dir = args.profile_dir / "raw"
        wavs_dir = args.profile_dir / "wavs"
        raw_dir.mkdir(parents=True, exist_ok=True)
        processed: List[Path] = []
        for f in args.files:
            if not f.exists():
                continue
            if args.isolate_vocals:
                clean_vocals = raw_dir / f"{f.stem}_vocals.wav"
                processed.append(isolate_vocals_demucs(f, clean_vocals))
            else:
                processed.append(f)
        total_clips = slice_corpus_vad(processed, wavs_dir)
        clips_count, total_dur = compute_corpus_stats(wavs_dir)
        print(f"STATS:{clips_count}:{total_dur}", flush=True)
        print(f"DONE_INGEST:{total_clips}", flush=True)
    elif args.cmd == "train":
        train_rvc_4gb_vram(
            args.profile_dir, args.voice_id, args.epochs, args.sample_rate
        )


if __name__ == "__main__":
    main()

