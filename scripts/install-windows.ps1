# Install and register Zorya v2.1.0 as a Windows Service on Nitro (GTX 1650) / Instala e registra o Zorya v2.1.0 como Serviço Windows no Nitro (GTX 1650)
$ErrorActionPreference = "Stop"

Write-Host "==> [1/4] Checking Rust toolchain / Verificando Rust toolchain..." -ForegroundColor Cyan
if (-not (Get-Command cargo -ErrorAction SilentlyContinue)) {
    Write-Host "Installing rustup toolchain / Instalando toolchain rustup..." -ForegroundColor Yellow
    $rustupUrl = "https://win.rustup.rs/x86_64"
    $rustupExe = "$env:TEMP\rustup-init.exe"
    Invoke-WebRequest -Uri $rustupUrl -OutFile $rustupExe
    Start-Process -FilePath $rustupExe -ArgumentList "-y --default-toolchain stable" -Wait -NoNewWindow
    $env:Path += ";$env:USERPROFILE\.cargo\bin"
}

$RepoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $RepoRoot

Write-Host "==> [2/4] Building Zorya v2.1.0 release binary (Pure Rust) / Compilando binário de produção Zorya v2.1.0 (Rust Puro)..." -ForegroundColor Cyan
cargo build --release

$InstallDir = "C:\ProgramData\Zorya"
New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
Copy-Item -Force "$RepoRoot\target\release\zorya.exe" "$InstallDir\zorya.exe"
if (-not (Test-Path "$InstallDir\zorya.toml")) {
    Copy-Item -Force "$RepoRoot\zorya.toml" "$InstallDir\zorya.toml"
}

Write-Host "==> [3/4] Configuring Firewall for Port 8085 and WireGuard 51820 / Configurando Firewall para Porta 8085 e WireGuard 51820..." -ForegroundColor Cyan
New-NetFirewallRule -DisplayName "Zorya v2.1.0 HTTP/MCP (8085)" -Direction Inbound -Protocol TCP -LocalPort 8085 -Action Allow -ErrorAction SilentlyContinue | Out-Null
New-NetFirewallRule -DisplayName "Zorya v2.1.0 WireGuard (51820)" -Direction Inbound -Protocol UDP -LocalPort 51820 -Action Allow -ErrorAction SilentlyContinue | Out-Null

Write-Host "==> [4/4] Registering Windows Service 'ZoryaDaemon' / Registrando Serviço Windows 'ZoryaDaemon'..." -ForegroundColor Cyan
$ServiceName = "ZoryaDaemon"
if (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) {
    Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
    sc.exe delete $ServiceName | Out-Null
    Start-Sleep -Seconds 2
}

sc.exe create $ServiceName binPath= "`"$InstallDir\zorya.exe`"" start= auto DisplayName= "Zorya v2.1.0 Sovereign Coprocessor" | Out-Null
sc.exe description $ServiceName "Sovereign Rust AI & Multimedia Daemon (boringtun + Headscale + ArtCraft + MCP SSE)" | Out-Null
sc.exe failure $ServiceName reset= 86400 actions= restart/5000/restart/5000/restart/5000 | Out-Null

Write-Host "✅ Zorya v2.1.0 installed at $InstallDir\zorya.exe / Zorya v2.1.0 instalado em $InstallDir\zorya.exe" -ForegroundColor Green
