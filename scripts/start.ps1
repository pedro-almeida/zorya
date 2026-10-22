# Start Zorya v3.0 Daemon on Windows (GTX 1650 4GB VRAM Locked) / Inicia o Daemon Zorya v3.0 no Windows (GTX 1650 4GB VRAM Dedicada)
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
$ReleaseExe = Join-Path $RepoRoot "target\release\zorya.exe"
$InstalledExe = Join-Path $InstallDir "zorya.exe"

# Sincronização resiliente: se target\release\zorya.exe for mais recente, sobrescreve C:\ProgramData\Zorya\zorya.exe
if (Test-Path $ReleaseExe) {
    if (Test-Path $InstallDir) {
        if (-not (Test-Path $InstalledExe) -or ((Get-Item $ReleaseExe).LastWriteTime -gt (Get-Item $InstalledExe).LastWriteTime)) {
            Write-Host "[Zorya] Sincronizando $InstalledExe mais recente a partir de $ReleaseExe..." -ForegroundColor Cyan
            Copy-Item -Path $ReleaseExe -Destination $InstalledExe -Force
        }
        $RepoToml = Join-Path $RepoRoot "zorya.toml"
        $InstalledToml = Join-Path $InstallDir "zorya.toml"
        if (Test-Path $RepoToml) {
            if (-not (Test-Path $InstalledToml) -or ((Get-Item $RepoToml).LastWriteTime -gt (Get-Item $InstalledToml).LastWriteTime)) {
                Copy-Item -Path $RepoToml -Destination $InstalledToml -Force
            }
        }
    }
}

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
    Write-Host "Starting Zorya v3.0 in background / Iniciando Zorya v3.0 em background..." -ForegroundColor Green
    Start-Process -FilePath "$InstallDir\zorya.exe" -WorkingDirectory $InstallDir -WindowStyle Hidden
} else {
    Write-Host "Starting Zorya v3.0 from target\release\zorya.exe / Iniciando Zorya v3.0 a partir de target\release\zorya.exe..." -ForegroundColor Green
    Start-Process -FilePath "$RepoRoot\target\release\zorya.exe" -WorkingDirectory $RepoRoot -WindowStyle Hidden
}
