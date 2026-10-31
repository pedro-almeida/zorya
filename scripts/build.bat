@echo off
setlocal enabledelayedexpansion

echo =============================================================================
echo   ZORYA RAW ENGINE - Build & Packaging Script
echo   High-Performance RAW Processing & Distributed Acceleration Node
echo =============================================================================

set SCRIPT_DIR=%~dp0
set ROOT_DIR=%SCRIPT_DIR%..
set CORE_DIR=%ROOT_DIR%\core_cpp
set BIN_DIR=%ROOT_DIR%\bin

if not exist "%BIN_DIR%" mkdir "%BIN_DIR%"

:: -----------------------------------------------------------------------------
:: ETAPA 1: Compilacao da DLL Nativa C++ (zorya_raw_core.dll)
:: -----------------------------------------------------------------------------
echo [1/2] Compilando modulo nativo C++ (Heterogeneous Engine)...

where g++ >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    echo [*] Compilador g++ detectado. Gerando DLL otimizada O3...
    g++ -shared -O3 -std=c++17 -DZORYA_EXPORTS -DSVITANOK_EXPORTS ^
        -I "%CORE_DIR%\include" ^
        "%CORE_DIR%\src\svitanok_raw_core.cpp" ^
        "%CORE_DIR%\src\cpu_decoder.cpp" ^
        "%CORE_DIR%\src\cuda_accelerator.cu" ^
        "%CORE_DIR%\src\vulkan_compute.cpp" ^
        -o "%BIN_DIR%\zorya_raw_core.dll" -lkernel32 -luser32
    if %ERRORLEVEL% NEQ 0 (
        echo [AVISO] Falha ao compilar DLL nativa C++. Continuando...
    ) else (
        echo [*] DLL nativa gerada com sucesso em %BIN_DIR%\zorya_raw_core.dll
    )
) else (
    echo [AVISO] g++ nao localizado no PATH. Pulando compilacao nativa C++.
)

:: -----------------------------------------------------------------------------
:: ETAPA 2: Compilacao do Daemon Rust Zorya v3.0
:: -----------------------------------------------------------------------------
echo [2/2] Compilando daemon Rust Zorya v3.0 (cargo build --release)...

pushd "%ROOT_DIR%"
cargo build --release
if %ERRORLEVEL% NEQ 0 (
    echo [ERRO] Falha ao compilar Zorya via Cargo!
    popd
    exit /b %ERRORLEVEL%
)

if exist "%ROOT_DIR%\target\release\zorya.exe" (
    copy /y "%ROOT_DIR%\target\release\zorya.exe" "%BIN_DIR%\zorya.exe" >nul
    copy /y "%ROOT_DIR%\target\release\zorya.exe" "%ROOT_DIR%\zorya.exe" >nul
    echo [*] Binario zorya.exe copiado para %BIN_DIR%\zorya.exe e %ROOT_DIR%\zorya.exe
    
    if exist "C:\ProgramData\Zorya" (
        copy /y "%ROOT_DIR%\target\release\zorya.exe" "C:\ProgramData\Zorya\zorya.exe" >nul
        echo [*] Binario zorya.exe sincronizado em C:\ProgramData\Zorya\zorya.exe
        if exist "%ROOT_DIR%\zorya.toml" (
            copy /y "%ROOT_DIR%\zorya.toml" "C:\ProgramData\Zorya\zorya.toml" >nul
            echo [*] Configuracao zorya.toml sincronizada em C:\ProgramData\Zorya\zorya.toml
        )
    )
    powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%create_dev_cert.ps1"
)
popd

echo =============================================================================
echo   Build finalizado com sucesso!
echo =============================================================================
