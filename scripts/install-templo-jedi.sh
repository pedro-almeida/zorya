#!/usr/bin/env bash
# Install Templo Jedi service, scripts, and 04:00 cron job on Mriya VPS / Instala o serviço, scripts e cron às 04:00 do Templo Jedi na VPS Mriya
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
MRIYA_SCRIPTS="/var/www/mriya/mriya/scripts"
MRIYA_LOGS="/var/www/mriya/mriya/logs"

echo "==> [1/4] Creating directories and log file / Criando diretórios e arquivo de log..."
mkdir -p "${MRIYA_SCRIPTS}" "${MRIYA_LOGS}"
touch "${MRIYA_LOGS}/templo_jedi.log"
chown -R www-data:www-data "${MRIYA_LOGS}" || true

echo "==> [2/4] Installing templo_jedi.py, templo_jedi_prompt.py, and updated nightly_auditor.py..."
install -m 0755 "${BUNDLE_ROOT}/mriya/scripts/templo_jedi_prompt.py" "${MRIYA_SCRIPTS}/templo_jedi_prompt.py"
install -m 0755 "${BUNDLE_ROOT}/mriya/scripts/templo_jedi.py" "${MRIYA_SCRIPTS}/templo_jedi.py"
install -m 0755 "${BUNDLE_ROOT}/mriya/scripts/nightly_auditor.py" "${MRIYA_SCRIPTS}/nightly_auditor.py"
chown www-data:www-data "${MRIYA_SCRIPTS}/templo_jedi_prompt.py" "${MRIYA_SCRIPTS}/templo_jedi.py" "${MRIYA_SCRIPTS}/nightly_auditor.py" || true

echo "==> [3/4] Installing /etc/systemd/system/templo-jedi.service..."
install -m 0644 "${BUNDLE_ROOT}/systemd/templo-jedi.service" /etc/systemd/system/templo-jedi.service
systemctl daemon-reload
systemctl enable templo-jedi.service || true

echo "==> [4/4] Adding 04:00 cron job (0 4 * * * systemctl start templo-jedi.service)..."
CRON_LINE="0 4 * * * systemctl start templo-jedi.service"
(crontab -l 2>/dev/null | grep -v "templo-jedi.service" || true; echo "${CRON_LINE}") | crontab -

echo "✅ Templo Jedi installed and scheduled for 04:00 daily / Templo Jedi instalado e agendado para 04:00 diariamente!"
