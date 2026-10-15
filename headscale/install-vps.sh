#!/usr/bin/env bash
# Install and configure self-hosted Headscale on Mriya VPS (143.95.223.218) / Instala e configura Headscale self-hosted na VPS Mriya (143.95.223.218)
set -euo pipefail

HEADSCALE_VERSION="${HEADSCALE_VERSION:-0.23.0}"
HEADSCALE_ARCH="${HEADSCALE_ARCH:-amd64}"
DOMAIN="headscale.espectroipe.com.br"
NAMESPACE="espectroipe"

echo "==> [1/7] Downloading and installing Headscale v${HEADSCALE_VERSION} / Baixando e instalando Headscale v${HEADSCALE_VERSION}..."
DEB_FILE="/tmp/headscale_${HEADSCALE_VERSION}_linux_${HEADSCALE_ARCH}.deb"
wget -q --show-progress -O "${DEB_FILE}" \
  "https://github.com/juanfont/headscale/releases/download/v${HEADSCALE_VERSION}/headscale_${HEADSCALE_VERSION}_linux_${HEADSCALE_ARCH}.deb"
dpkg -i "${DEB_FILE}" || apt-get install -f -y
rm -f "${DEB_FILE}"

echo "==> [2/7] Writing /etc/headscale/config.yaml / Gravando /etc/headscale/config.yaml..."
mkdir -p /etc/headscale /var/lib/headscale /var/run/headscale
cat <<'EOF' > /etc/headscale/config.yaml
# Headscale Configuration for Mriya Ecosystem / Configuração do Headscale para o Ecossistema Mriya
server_url: https://headscale.espectroipe.com.br
listen_addr: 0.0.0.0:8080
metrics_listen_addr: 127.0.0.1:9090
grpc_listen_addr: 127.0.0.1:50443
grpc_allow_insecure: false

private_key_path: /var/lib/headscale/private.key
noise:
  private_key_path: /var/lib/headscale/noise_private.key

prefixes:
  v4: 100.64.0.0/10
  v6: fd7a:115c:a1e0::/48

derp:
  server:
    enabled: true
    region_id: 999
    region_code: "mriya"
    region_name: "Mriya VPS DERP"
    stun_listen_addr: "0.0.0.0:3478"
  urls: []
  auto_update_enabled: false

disable_check_updates: true
ephemeral_node_inactivity_timeout: 30m

database:
  type: sqlite3
  sqlite:
    path: /var/lib/headscale/db.sqlite
    write_ahead_log: true

dns:
  magic_dns: true
  base_domain: mriya.internal
  nameservers:
    global:
      - 1.1.1.1
      - 8.8.8.8

unix_socket: /var/run/headscale/headscale.sock
unix_socket_permission: "0770"
log:
  format: text
  level: info
EOF

chown -R headscale:headscale /etc/headscale /var/lib/headscale /var/run/headscale || true

echo "==> [3/7] Configuring and starting systemd service / Configurando e iniciando serviço systemd..."
systemctl daemon-reload
systemctl enable headscale
systemctl restart headscale
sleep 2

echo "==> [4/7] Creating namespace/user '${NAMESPACE}' / Criando namespace/usuário '${NAMESPACE}'..."
headscale users create "${NAMESPACE}" || headscale namespaces create "${NAMESPACE}" || true

echo "==> [5/7] Configuring Nginx reverse proxy for ${DOMAIN} / Configurando Nginx reverse proxy para ${DOMAIN}..."
cat <<EOF > /etc/nginx/sites-available/${DOMAIN}.conf
map \$http_upgrade \$connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_set_header Host \$server_name;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_buffering off;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }
}
EOF

ln -sf "/etc/nginx/sites-available/${DOMAIN}.conf" "/etc/nginx/sites-enabled/${DOMAIN}.conf"
nginx -t && systemctl reload nginx

echo "==> [6/7] Issuing SSL certificate via Certbot / Emitindo certificado SSL via Certbot..."
if command -v certbot >/dev/null 2>&1; then
    certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos -m pedroluistenorio@gmail.com --redirect || true
fi

echo "==> [7/7] Generating pre-auth key for Zorya (zorya.toml) / Gerando auth key para o Zorya (zorya.toml)..."
AUTH_KEY=$(headscale preauthkeys create --user "${NAMESPACE}" --reusable --expiration 8760h 2>/dev/null || headscale -n "${NAMESPACE}" preauthkeys create --reusable --expiration 8760h)

echo ""
echo "=================================================================="
echo "  HEADSCALE INSTALLED SUCCESSFULLY / HEADSCALE INSTALADO COM SUCESSO"
echo "  Coordinator URL: https://${DOMAIN}"
echo "  Namespace:       ${NAMESPACE}"
echo "  MagicDNS Domain: usina-zorya.mriya.internal"
echo "  Auth Key (copy into zorya.toml -> headscale_auth_key):"
echo "  ${AUTH_KEY}"
echo "=================================================================="
