# Start Zorya v2.1.0 Daemon on Windows (GTX 1650 4GB VRAM Locked) / Inicia o Daemon Zorya v2.1.0 no Windows (GTX 1650 4GB VRAM Dedicada)
$ErrorActionPreference = "Stop"

# Set dedicated GPU environment variables / Define variáveis de ambiente da GPU dedicada
$env:CUDA_VISIBLE_DEVICES = "0"
$env:CUDA_DEVICE_ORDER = "PCI_BUS_ID"
$env:OLLAMA_NUM_GPU = "999"
$env:OLLAMA_FLASH_ATTENTION = "1"
$env:OLLAMA_KV_CACHE_TYPE = "q8_0"
$env:GGML_CUDA_ENABLE_UNIFIED_MEMORY = "0"

$InstallDir = "C:\ProgramData\Zorya"
$RepoRoot = Split-Path -Parent $PSScriptRoot

if (Test-Path "$InstallDir\zorya.toml") {
    $env:ZORYA_CONFIG = "$InstallDir\zorya.toml"
} else {
    $env:ZORYA_CONFIG = "$RepoRoot\zorya.toml"
}

$ServiceName = "ZoryaDaemon"
if (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) {
    Write-Host "Starting Windows Service '$ServiceName' on port 8085 / Iniciando Serviço Windows '$ServiceName' na porta 8085..." -ForegroundColor Green
    Start-Service -Name $ServiceName
    Get-Service -Name $ServiceName
} elseif (Test-Path "$InstallDir\zorya.exe") {
    Write-Host "Starting Zorya v2.1.0 in background / Iniciando Zorya v2.1.0 em background..." -ForegroundColor Green
    Start-Process -FilePath "$InstallDir\zorya.exe" -WorkingDirectory $InstallDir -WindowStyle Hidden
} else {
    Write-Host "Starting Zorya v2.1.0 from target\release\zorya.exe / Iniciando Zorya v2.1.0 a partir de target\release\zorya.exe..." -ForegroundColor Green
    Start-Process -FilePath "$RepoRoot\target\release\zorya.exe" -WorkingDirectory $RepoRoot -WindowStyle Hidden
}
