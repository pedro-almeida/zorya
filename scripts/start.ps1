param([switch]$Background)
$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

Write-Host "[Zorya] Encerrando processos zorya antigos para liberar a porta 8085..." -ForegroundColor Yellow
Get-Process zorya -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Milliseconds 600

# Configuracao da GPU Dedicada GTX 1650 (Isolamento da VRAM de 4096MB)
$env:CUDA_VISIBLE_DEVICES = "0"
$env:CUDA_DEVICE_ORDER = "PCI_BUS_ID"
$env:GGML_CUDA_ENABLE_UNIFIED_MEMORY = "0"
$env:ZORYA_GPU_LAYERS = "33"
$env:ZORYA_PORT = "8085"

$ReleaseExe = Join-Path $RepoRoot "target\release\zorya.exe"
if (-not (Test-Path $ReleaseExe) -or ((Get-ChildItem -Path "$RepoRoot\src" -Recurse | Measure-Object -Property LastWriteTime -Maximum).Maximum -gt (Get-Item $ReleaseExe).LastWriteTime)) {
    Write-Host "[Zorya] Compilando binario de release com novo Painel Svitlo e Systray animado..." -ForegroundColor Cyan
    cargo build --release
}

$InstallDir = "C:\ProgramData\Zorya"
if (Test-Path $InstallDir) {
    Copy-Item -Path $ReleaseExe -Destination "$InstallDir\zorya.exe" -Force -ErrorAction SilentlyContinue
    if (-not (Test-Path "$InstallDir\zorya.toml")) {
        Copy-Item -Path "$RepoRoot\zorya.toml" -Destination "$InstallDir\zorya.toml" -ErrorAction SilentlyContinue
    }
}

Write-Host "[Zorya] Iniciando Usina Zorya v3.0 na porta 8085..." -ForegroundColor Green
if ($Background) {
    Start-Process -FilePath $ReleaseExe -WorkingDirectory $RepoRoot -WindowStyle Hidden
    Write-Host "[Zorya] Executando em segundo plano. Acesse http://127.0.0.1:8085/settings" -ForegroundColor Green
} else {
    & $ReleaseExe
}
