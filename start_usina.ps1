# Script de Inicialização da Usina Zorya (Windows PowerShell)
# Pode ser executado em segundo plano ou registrado no Agendador de Tarefas do Windows

param (
    [switch]$Background,
    [switch]$InstallTask
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$NodeScript = Join-Path $ScriptDir "zorya_node.py"
$LogFile = Join-Path $ScriptDir "zorya_node.log"

if ($InstallTask) {
    Write-Host "Criando Tarefa Agendada no Windows para iniciar Zorya no Logon..." -ForegroundColor Cyan
    $Action = New-ScheduledTaskAction -Execute "pythonw.exe" -Argument "`"$NodeScript`"" -WorkingDirectory $ScriptDir
    $Trigger = New-ScheduledTaskTrigger -AtLogOn
    $Principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Highest
    $Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
    
    Register-ScheduledTask -TaskName "ZoryaUsinaNode" -Action $Action -Trigger $Trigger -Principal $Principal -Settings $Settings -Force
    Write-Host "Tarefa Agendada 'ZoryaUsinaNode' registrada com sucesso!" -ForegroundColor Green
    exit 0
}

if ($Background) {
    Write-Host "Iniciando Zorya Node em segundo plano (background)..." -ForegroundColor Cyan
    Start-Process -FilePath "python" -ArgumentList "`"$NodeScript`"" -WorkingDirectory $ScriptDir -RedirectStandardOutput $LogFile -RedirectStandardError $LogFile -WindowStyle Hidden
    Write-Host "Processo iniciado. Logs sendo gravados em: $LogFile" -ForegroundColor Green
    exit 0
}

Write-Host "Iniciando Zorya Node em primeiro plano (Ctrl+C para encerrar)..." -ForegroundColor Yellow
python "$NodeScript"
