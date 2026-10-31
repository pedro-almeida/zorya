<#
.SYNOPSIS
    Assinatura Automática Authenticode Não-Bloqueante (Headless-Safe) para o Projeto Zorya.
#>

[CmdletBinding()]
param(
    [switch]$Force
)

$ErrorActionPreference = "Continue"
$RepoRoot = Split-Path -Parent $PSScriptRoot

$Subject = "CN=Pedro Almeida, O=Zorya Project"
$CertStorePath = "Cert:\CurrentUser\My"

$ExistingCert = Get-ChildItem -Path $CertStorePath -CodeSigningCert -ErrorAction SilentlyContinue |
    Where-Object { $_.Subject -match "Pedro Almeida" -and $_.NotAfter -gt (Get-Date) } |
    Select-Object -First 1

if ($ExistingCert -and -not $Force) {
    $Cert = $ExistingCert
    Write-Host "[OK] Certificado encontrado: $($Cert.Thumbprint)" -ForegroundColor Green
} else {
    $Cert = New-SelfSignedCertificate `
        -Type CodeSigningCert `
        -Subject $Subject `
        -KeyAlgorithm RSA `
        -KeyLength 2048 `
        -HashAlgorithm SHA256 `
        -CertStoreLocation $CertStorePath `
        -NotAfter (Get-Date).AddYears(5) `
        -FriendlyName "Pedro Almeida - Zorya Project Code Signing"
    Write-Host "[OK] Novo certificado gerado: $($Cert.Thumbprint)" -ForegroundColor Green
}

# TrustedPublisher em CurrentUser NÃO abre modal de confirmação do Windows
try {
    $TpStore = New-Object System.Security.Cryptography.X509Certificates.X509Store("TrustedPublisher", "CurrentUser")
    $TpStore.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadWrite)
    $TpStore.Add($Cert)
    $TpStore.Close()
    Write-Host "[OK] Registrado em Cert:\CurrentUser\TrustedPublisher" -ForegroundColor Green
} catch {}

# Somente se já estiver como Administrador (não abre popup modal em LocalMachine)
$IsAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if ($IsAdmin) {
    foreach ($StoreName in @("TrustedPublisher", "Root")) {
        try {
            $MachineStore = New-Object System.Security.Cryptography.X509Certificates.X509Store($StoreName, "LocalMachine")
            $MachineStore.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadWrite)
            $MachineStore.Add($Cert)
            $MachineStore.Close()
            Write-Host "[OK] Registrado em Cert:\LocalMachine\$StoreName" -ForegroundColor Green
        } catch {}
    }
}

# Encerra zorya.exe em execução antes de copiar e assinar para evitar arquivo em uso
Get-Process -Name zorya -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Milliseconds 300

$ReleaseExe = Join-Path $RepoRoot "target\release\zorya.exe"

if (Test-Path $ReleaseExe) {
    Copy-Item -Path $ReleaseExe -Destination (Join-Path $RepoRoot "zorya.exe") -Force -ErrorAction SilentlyContinue
    if (Test-Path "C:\Zorya") { Copy-Item -Path $ReleaseExe -Destination "C:\Zorya\zorya.exe" -Force -ErrorAction SilentlyContinue }
    if (Test-Path "C:\ProgramData\Zorya") { Copy-Item -Path $ReleaseExe -Destination "C:\ProgramData\Zorya\zorya.exe" -Force -ErrorAction SilentlyContinue }
}

$Targets = @(
    $ReleaseExe,
    (Join-Path $RepoRoot "zorya.exe"),
    (Join-Path $RepoRoot "bin\zorya.exe"),
    "C:\Zorya\zorya.exe",
    "C:\ProgramData\Zorya\zorya.exe"
)

foreach ($Bin in $Targets) {
    if (Test-Path $Bin) {
        try {
            Unblock-File -Path $Bin -ErrorAction SilentlyContinue
            $Sig = Set-AuthenticodeSignature -FilePath $Bin -Certificate $Cert -HashAlgorithm SHA256 -ErrorAction Stop
            Write-Host "[ASSINADO] $Bin ($($Sig.Status))" -ForegroundColor Green
        } catch {
            Write-Host "[AVISO] $Bin : $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }
}

Write-Host "[CONCLUÍDO] Assinatura Authenticode finalizada sem bloqueio." -ForegroundColor Green
