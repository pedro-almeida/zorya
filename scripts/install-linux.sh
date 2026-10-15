#!/usr/bin/env bash
# Install Zorya v2.1.0 as a systemd daemon on Linux / Instala o Zorya v2.1.0 como daemon systemd no Linux
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

echo "==> [1/4] Checking Rust toolchain / Verificando Rust toolchain..."
if ! command -v cargo >/dev/null 2>&1; then
    curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y
    source "${HOME}/.cargo/env"
fi

echo "==> [2/4] Building Zorya v2.1.0 release binary / Compilando binário Zorya v2.1.0..."
cd "${REPO_ROOT}"
cargo build --release

echo "==> [3/4] Installing binary and configuration / Instalando binário e configuração..."
install -m 0755 "${REPO_ROOT}/target/release/zorya" /usr/local/bin/zorya
mkdir -p /etc/zorya
if [ ! -f /etc/zorya/zorya.toml ]; then
    install -m 0640 "${REPO_ROOT}/zorya.toml" /etc/zorya/zorya.toml
fi

echo "==> [4/4] Creating /etc/systemd/system/zorya.service / Criando /etc/systemd/system/zorya.service..."
cat <<'EOF' > /etc/systemd/system/zorya.service
[Unit]
Description=Zorya v2.1.0 Sovereign Rust Daemon & MCP Server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
Environment="ZORYA_CONFIG=/etc/zorya/zorya.toml"
Environment="CUDA_VISIBLE_DEVICES=0"
Environment="OLLAMA_NUM_GPU=999"
Environment="OLLAMA_FLASH_ATTENTION=1"
WorkingDirectory=/etc/zorya
ExecStart=/usr/local/bin/zorya
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable zorya.service
echo "✅ Zorya v2.1.0 installed! Run scripts/start.sh to start / Zorya v2.1.0 instalado! Execute scripts/start.sh para iniciar."
