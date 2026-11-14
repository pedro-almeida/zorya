# ==============================================================================
# Zorya SoundCraft - Setup do Ambiente Virtual Python com GPU (GTX 1650 Turing)
# Cria / Atualiza C:\dev\zorya\.venv com PyTorch CUDA, Kokoro, Demucs, TTS e Edge-TTS
# ==============================================================================

[CmdletBinding()]
param(
    [string]$TargetDir = "C:\dev\zorya\.venv",
    [switch]$SkipTorchInstall
)

$ErrorActionPreference = "Stop"
Write-Host "===========================================================" -ForegroundColor Cyan
Write-Host " [Zorya] Setup do Ambiente Virtual GPU para Voz / Audiobook" -ForegroundColor Cyan
Write-Host " Alvo: $TargetDir" -ForegroundColor Cyan
Write-Host "===========================================================" -ForegroundColor Cyan

# 1. Localiza Python 3.11
$pythonCmd = $null
if (Get-Command "py" -ErrorAction SilentlyContinue) {
    $testPy = & py -3.11 -c "import sys; print(sys.version_info[:2])" 2>$null
    if ($testPy -match "3,\s*11") {
        $pythonCmd = "py -3.11"
    }
}

if (-not $pythonCmd -and (Get-Command "python" -ErrorAction SilentlyContinue)) {
    $ver = & python -c "import sys; print(sys.version_info[:2])" 2>$null
    if ($ver -match "3,\s*11") {
        $pythonCmd = "python"
    }
}

if (-not $pythonCmd) {
    Write-Warning "Python 3.11 nao foi detectado via 'py -3.11' ou 'python'. Usando 'python' padrao do PATH..."
    $pythonCmd = "python"
}

Write-Host "-> Utilizando interpretador base: $pythonCmd" -ForegroundColor Green

# 2. Cria venv se nao existir
$venvPy = Join-Path $TargetDir "Scripts\python.exe"
if (-not (Test-Path $venvPy)) {
    Write-Host "-> Criando ambiente virtual em $TargetDir..." -ForegroundColor Yellow
    if ($pythonCmd -match "py -3.11") {
        & py -3.11 -m venv $TargetDir
    } else {
        & python -m venv $TargetDir
    }
} else {
    Write-Host "-> Ambiente virtual ja existe em $TargetDir." -ForegroundColor Green
}

if (-not (Test-Path $venvPy)) {
    Write-Error "Falha ao criar ambiente virtual: $venvPy nao encontrado."
}

# 3. Atualiza gerenciadores de pacotes
Write-Host "-> Atualizando pip, setuptools e wheel..." -ForegroundColor Yellow
& $venvPy -m pip install --upgrade pip setuptools wheel

# 4. Instala PyTorch com suporte a CUDA 12.4 para GTX 1650 (Turing sm_75)
if (-not $SkipTorchInstall) {
    Write-Host "-> Instalando PyTorch CUDA 12.4 (compativel com GTX 1650 Turing)..." -ForegroundColor Yellow
    & $venvPy -m pip install torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cu124
}

# 5. Instala pacotes do pipeline vocal
Write-Host "-> Instalando pacotes do pipeline de voz (kokoro, demucs, yt-dlp, edge-tts, pydub, etc)..." -ForegroundColor Yellow
& $venvPy -m pip install kokoro kokoro-onnx demucs yt-dlp edge-tts pydub soundfile scipy numpy

# 6. Tenta instalar Coqui TTS (opcional, com fallback gracioso se faltar compilador C++)
Write-Host "-> Tentando instalar coqui-tts..." -ForegroundColor Yellow
try {
    & $venvPy -m pip install coqui-tts
} catch {
    Write-Warning "Coqui TTS falhou na instalacao direta. O worker zorya_voice_worker.py acionara o fallback Kokoro / edge-tts automaticamente."
}

# 7. Verificacao de CUDA e GPU
Write-Host "===========================================================" -ForegroundColor Cyan
Write-Host "-> Verificando suporte a GPU NVIDIA CUDA..." -ForegroundColor Yellow
$cudaCheck = & $venvPy -c "import torch; print('CUDA_OK:' + str(torch.cuda.is_available()) + '|DEVICE:' + (torch.cuda.get_device_name(0) if torch.cuda.is_available() else 'CPU'))"
Write-Host "Status GPU: $cudaCheck" -ForegroundColor Green

Write-Host "===========================================================" -ForegroundColor Cyan
Write-Host "-> Ambiente virtual pronto em $TargetDir" -ForegroundColor Green
Write-Host "Para fixar este ambiente no Zorya:" -ForegroundColor White
Write-Host "  `$env:ZORYA_PYTHON = '$venvPy'" -ForegroundColor Gray
Write-Host "===========================================================" -ForegroundColor Cyan
