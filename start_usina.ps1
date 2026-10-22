param([switch]$Background)
$ErrorActionPreference = "SilentlyContinue"

$RepoDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ReleaseExe = Join-Path $RepoDir "target\release\zorya.exe"
$ProgramDataDir = "C:\ProgramData\Zorya"
$ProgramDataExe = Join-Path $ProgramDataDir "zorya.exe"

# Sincronização resiliente de binário mais recente para C:\ProgramData\Zorya\zorya.exe
if (Test-Path $ReleaseExe) {
    if (Test-Path $ProgramDataDir) {
        if (-not (Test-Path $ProgramDataExe) -or ((Get-Item $ReleaseExe).LastWriteTime -gt (Get-Item $ProgramDataExe).LastWriteTime)) {
            Write-Host "[Zorya] Sincronizando binario mais recente para $ProgramDataExe..." -ForegroundColor Cyan
            Copy-Item -Path $ReleaseExe -Destination $ProgramDataExe -Force
        }
        $RepoToml = Join-Path $RepoDir "zorya.toml"
        $ProgramDataToml = Join-Path $ProgramDataDir "zorya.toml"
        if (Test-Path $RepoToml) {
            if (-not (Test-Path $ProgramDataToml) -or ((Get-Item $RepoToml).LastWriteTime -gt (Get-Item $ProgramDataToml).LastWriteTime)) {
                Copy-Item -Path $RepoToml -Destination $ProgramDataToml -Force
            }
        }
    }
}

$TargetExe = if (Test-Path $ReleaseExe) { $ReleaseExe } elseif (Test-Path $ProgramDataExe) { $ProgramDataExe } else { Join-Path $RepoDir "zorya.exe" }
$LogOut = Join-Path $RepoDir "zorya_node.log"
$LogErr = Join-Path $RepoDir "zorya_err.log"

Get-Process zorya -ErrorAction SilentlyContinue | Stop-Process -Force

if ($Background) {
    Start-Process -FilePath $TargetExe -WorkingDirectory $RepoDir -RedirectStandardOutput $LogOut -RedirectStandardError $LogErr -WindowStyle Hidden
    Write-Host "[Zorya] Usina iniciada em background a partir de $TargetExe!" -ForegroundColor Green
    exit 0
}

& $TargetExe
