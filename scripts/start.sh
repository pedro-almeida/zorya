#!/usr/bin/env bash
# Start Zorya v2.1.0 Daemon on Linux / Inicia o Daemon Zorya v2.1.0 no Linux
set -euo pipefail

export CUDA_VISIBLE_DEVICES=0
export CUDA_DEVICE_ORDER=PCI_BUS_ID
export OLLAMA_NUM_GPU=999
export OLLAMA_FLASH_ATTENTION=1
export OLLAMA_KV_CACHE_TYPE=q8_0
export GGML_CUDA_ENABLE_UNIFIED_MEMORY=0

if systemctl list-unit-files | grep -q "^zorya.service"; then
    echo "Starting zorya.service via systemd / Iniciando zorya.service via systemd..."
    systemctl restart zorya.service
    systemctl status zorya.service --no-pager
else
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
    export ZORYA_CONFIG="${ZORYA_CONFIG:-${REPO_ROOT}/zorya.toml}"
    echo "Starting Zorya v2.1.0 binary on port 8085 / Iniciando binário Zorya v2.1.0 na porta 8085..."
    exec "${REPO_ROOT}/target/release/zorya"
fi
