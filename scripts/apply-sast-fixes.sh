#!/usr/bin/env bash
# Apply Nightly Auditor SAST, Frontend JS, and UX fixes on Mriya VPS / Aplica correções SAST, JS e UX do Auditor Noturno na VPS Mriya
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
MRIYA_ROOT="${MRIYA_ROOT:-/var/www/mriya}"

echo "==> [1/4] Applying 6 SAST fixes to SvitloController.php / Aplicando as 6 correções SAST no SvitloController.php..."
if [ -d "${MRIYA_ROOT}/mriya/api/v1/controllers" ]; then
    cp -f "${BUNDLE_ROOT}/mriya/api/v1/controllers/SvitloController.php" \
          "${MRIYA_ROOT}/mriya/api/v1/controllers/SvitloController.php"
    php -l "${MRIYA_ROOT}/mriya/api/v1/controllers/SvitloController.php"
fi

echo "==> [2/4] Updating Svitlo JS files (template string evaluation fixes) / Atualizando arquivos JS do Svitlo..."
for target_dir in "${MRIYA_ROOT}/svitlo/public/js" "${MRIYA_ROOT}/public/svitlo/js"; do
    if [ -d "${target_dir}" ]; then
        cp -f "${BUNDLE_ROOT}/svitlo/public/js/svitlo-settings.js" "${target_dir}/svitlo-settings.js"
        cp -f "${BUNDLE_ROOT}/svitlo/public/js/svitlo-spotify.js" "${target_dir}/svitlo-spotify.js"
    fi
done

echo "==> [3/4] Updating Svitlo app.html (UX button cleanup) / Atualizando app.html do Svitlo..."
for html_dest in "${MRIYA_ROOT}/svitlo/public/app.html" "${MRIYA_ROOT}/svitlo/app.html" "${MRIYA_ROOT}/public/svitlo/app.html"; do
    if [ -f "${html_dest}" ]; then
        cp -f "${BUNDLE_ROOT}/svitlo/public/app.html" "${html_dest}"
    fi
done

echo "==> [4/4] Reloading PHP-FPM / Recarregando PHP-FPM..."
systemctl reload php8.3-fpm || systemctl reload php-fpm || true
echo "✅ Mission 2 SAST, JS, and UX fixes applied successfully / Correções da Missão 2 aplicadas com sucesso!"
