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
:: ETAPA 2: Compilacao do Daemon Go
:: -----------------------------------------------------------------------------
echo [2/2] Compilando daemon Go (Zorya)...

where go >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    pushd "%ROOT_DIR%"
    go build -ldflags="-s -w" -o "%BIN_DIR%\zorya.exe" ./cmd/zorya
    popd
    echo [*] Binario zorya.exe compilado em %BIN_DIR%\zorya.exe
) else (
    echo [AVISO] Go nao localizado no PATH.
)

echo =============================================================================
echo   Build finalizado!
echo =============================================================================
