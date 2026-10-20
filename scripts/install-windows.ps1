# PT: Script de instalacao e inicializacao do Zorya no Windows (Nitro 5).
# EN: Zorya Windows installation and deployment script (Nitro 5).

$ErrorActionPreference = "Continue"

$InstallDir = "C:\Zorya"
$ModelsDir  = "$InstallDir\models"
$LogsDir    = "$InstallDir\logs"

Write-Host "[Zorya] Preparando diretorios em $InstallDir..." -ForegroundColor Cyan
New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
New-Item -ItemType Directory -Force -Path $ModelsDir  | Out-Null
New-Item -ItemType Directory -Force -Path $LogsDir    | Out-Null

if (Test-Path ".\target\release\zorya.exe") {
    Copy-Item ".\target\release\zorya.exe" "$InstallDir\zorya.exe" -Force
}
if (Test-Path ".\zorya.toml") {
    Copy-Item ".\zorya.toml" "$InstallDir\zorya.toml" -Force
}

# Registra inicializacao automatica no login do usuario atual (HKCU)
$RegPath = "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run"
Set-ItemProperty -Path $RegPath -Name "Zorya" -Value "`"$InstallDir\zorya.exe`" --background" -ErrorAction SilentlyContinue

# Se executado como Administrador, configura Firewall e Servico/Task
$IsAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if ($IsAdmin) {
    Write-Host "[Zorya] Privilegios de administrador detectados: configurando Firewall..." -ForegroundColor Cyan
    Remove-NetFirewallRule -DisplayName "Zorya Edge AI 8085" -ErrorAction SilentlyContinue
    New-NetFirewallRule -DisplayName "Zorya Edge AI 8085" `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort 8085 `
        -Profile Any -ErrorAction SilentlyContinue | Out-Null

    sc.exe stop ZoryaDaemon 2>$null | Out-Null
    sc.exe delete ZoryaDaemon 2>$null | Out-Null
}

# Se o daemon nao estiver ativo, inicia o processo
$Existing = Get-Process -Name zorya -ErrorAction SilentlyContinue
if (-not $Existing) {
    Write-Host "[Zorya] Iniciando daemon zorya.exe..." -ForegroundColor Cyan
    Start-Process "$InstallDir\zorya.exe" -WorkingDirectory $InstallDir
    Start-Sleep -Seconds 2
}

# Valida escuta na porta 8085
$Conn = Get-NetTCPConnection -LocalPort 8085 -State Listen -ErrorAction SilentlyContinue
if ($Conn) {
    Write-Host "[Zorya] Daemon ativo e escutando na porta 8085!" -ForegroundColor Green
    try {
        $Health = Invoke-RestMethod -Uri "http://127.0.0.1:8085/health" -TimeoutSec 3
        Write-Host "[Zorya] Health check OK: Status=$($Health.status), Node=$($Health.node), GPU=$($Health.gpu_name)" -ForegroundColor Green
    } catch {
        Write-Host "[Zorya] Aviso: Porta aberta, aguardando inicializacao total do endpoint HTTP..." -ForegroundColor Yellow
    }
} else {
    Write-Host "[Zorya] Atencao: Porta 8085 ainda nao detectada em LISTEN." -ForegroundColor Yellow
}
