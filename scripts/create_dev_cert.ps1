<#
.SYNOPSIS
    Instalação do Certificado de Desenvolvedor Pedro Almeida para o Projeto Zorya.

.DESCRIPTION
    Cria um certificado de assinatura de código pessoal para Pedro Almeida (Zorya Project)
    e registra no repositório de Editores Confiáveis (TrustedPublisher) do usuário atual.
    Isso elimina bloqueios do SmartScreen e antivírus local ao executar os binários gerados pelo projeto.
#>

[CmdletBinding()]
param(
    [switch]$Force
)

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "  ZORYA OPEN SOURCE - CERTIFICADO PESSOAL DE DESENVOLVEDOR" -ForegroundColor Cyan
Write-Host "  Autor: Pedro Almeida | Projeto: Zorya" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$Subject = "CN=Pedro Almeida, O=Zorya Project"
$CertStorePath = "Cert:\CurrentUser\My"
$TrustedPublisherStore = "Cert:\CurrentUser\TrustedPublisher"

# Verifica se o certificado já existe
$ExistingCert = Get-ChildItem -Path $CertStorePath -Recurse -CodeSigningCert -ErrorAction SilentlyContinue |
    Where-Object { $_.Subject -match "Pedro Almeida" } |
    Select-Object -First 1

if ($ExistingCert -and -not $Force) {
    Write-Host "[OK] Certificado de Pedro Almeida ja existente:" -ForegroundColor Green
    Write-Host "     Thumbprint: $($ExistingCert.Thumbprint)" -ForegroundColor Gray
    Write-Host "     Validade:   $($ExistingCert.NotAfter)" -ForegroundColor Gray
    $Cert = $ExistingCert
} else {
    Write-Host "[+] Gerando novo certificado de Code Signing autoassinado..." -ForegroundColor Yellow
    $Cert = New-SelfSignedCertificate `
        -Type CodeSigningCert `
        -Subject $Subject `
        -KeyLength 2048 `
        -HashAlgorithm SHA256 `
        -CertStoreLocation $CertStorePath `
        -NotAfter (Get-Date).AddYears(5) `
        -FriendlyName "Pedro Almeida - Zorya Project Code Signing"

    Write-Host "[OK] Certificado criado com sucesso!" -ForegroundColor Green
    Write-Host "     Thumbprint: $($Cert.Thumbprint)" -ForegroundColor Gray
}

# Adicionar aos Editores Confiáveis do Usuário Atual (TrustedPublisher)
Write-Host "[+] Registrando em Editores Confiáveis (TrustedPublisher)..." -ForegroundColor Yellow
try {
    $Store = New-Object System.Security.Cryptography.X509Certificates.X509Store("TrustedPublisher", "CurrentUser")
    $Store.Open([System.Security.Cryptography.X509Certificates.OpenFlags]::ReadWrite)
    $Store.Add($Cert)
    $Store.Close()
    Write-Host "[OK] Registrado com sucesso em TrustedPublisher!" -ForegroundColor Green
} catch {
Write-Host ""
Write-Host "Para assinar manualmente qualquer binario local, execute:" -ForegroundColor Cyan
Write-Host "Set-AuthenticodeSignature -FilePath .\target\release\zorya.exe -Certificate (Get-Item 'Cert:\CurrentUser\My\$($Cert.Thumbprint)') -TimestampServer 'http://timestamp.digicert.com'" -ForegroundColor White
Write-Host ""
Write-Host "[CONCLUIDO] Ambiente configurado para desenvolvimento local seguro." -ForegroundColor Green
