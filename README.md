# Ecossistema Mriya — Pacote Unificado Zorya v2.1.0, Correções SAST/UX/Nginx & Templo Jedi

Este pacote contém todos os arquivos completos, prontos para produção e compiláveis das 3 Missões:

1. **Missão 1 — Zorya v2.1.0 (Daemon Rust Soberano)**:
   - `Cargo.toml`, `zorya.toml`, `src/main.rs` (`#![deny(warnings)]`), `src/config.rs`, `src/network.rs` (`boringtun` + Headscale), `src/server.rs` (Axum na porta única `8085`), `src/mcp.rs` (JSON-RPC 2.0 sobre SSE expondo `zorya_raw_decode`, `zorya_vector_diagram`, `zorya_audio_master`, `zorya_system_info`, `zorya_chat`), `src/systray.rs` (cinza em idle, pisca verde `#10b981` e âmbar `#f59e0b` a cada 500ms em processamento), `src/telemetry.rs` (`sysinfo` + VRAM GTX 1650 4GB), `src/craft.rs` (integração LightCraft, PhotoCraft, VectorCraft e SoundCraft EBU R128) e `src/ollama.rs` (proxy OpenAI SSE).
   - Scripts de provisionamento e deploy: `headscale/install-vps.sh`, `scripts/install-windows.ps1`, `scripts/install-linux.sh`, `scripts/start.ps1`, `scripts/start.sh`.

2. **Missão 2 — Correções do Auditor Noturno (15/10/2026)**:
   - `mriya/api/v1/controllers/SvitloController.php` (e `patches/SvitloController.sast.patch`) com as 6 correções SAST aplicadas.
   - `svitlo/public/js/svitlo-settings.js` e `svitlo/public/js/svitlo-spotify.js` (e `patches/svitlo-frontend.patch`) com avaliação prévia de variáveis de URL antes do envio ao Nginx.
   - `svitlo/public/app.html` com remoção do botão órfão "Compartilhar no LinkedIn" e ativação semântica (`hidden` + handlers ativos) dos botões "Remover Chave" e "Ver Resultado".
   - `nginx/security.conf` com bloqueio `444` para arquivos sensíveis e rate-limit de `10 req/min` por IP em rotas `404`.
   - `scripts/apply-sast-fixes.sh` para aplicação automatizada na VPS.

3. **Missão 3 — Templo Jedi (Auto-Auditoria e Auto-Correção)**:
   - `mriya/scripts/templo_jedi.py` (orquestrador `TemploJedi` com limite de 5 branches abertas, validação `php -l` / `cargo check`, proteção estrita de `router.php`, schemas SQL e `little-hedla`, e abertura automática de issue no Linear).
   - `mriya/scripts/templo_jedi_prompt.py` (`build_prompt` com janela de ±50 linhas e retorno JSON estrito).
   - `mriya/scripts/nightly_auditor.py` atualizado para salvar `/var/www/mriya/mriya/logs/audit_latest.json`.
   - `systemd/templo-jedi.service`, `cron/templo-jedi.cron` e `scripts/install-templo-jedi.sh`.

## DEPLOY VPS (executar na ordem)

```bash
1. bash headscale/install-vps.sh
2. bash scripts/apply-sast-fixes.sh
3. bash scripts/install-templo-jedi.sh
4. Copiar bloco nginx para /etc/nginx/conf.d/security.conf && nginx -s reload
```

## DEPLOY WINDOWS/NITRO (executar na ordem)

```powershell
1. Editar zorya.toml com a auth key gerada no passo 1
2. .\scripts\install-windows.ps1
3. .\scripts\start.ps1
```

## VERIFICAÇÃO

- Da VPS: `curl http://usina-zorya:8085/health`
- Do Nitro: `curl http://143.95.223.218/api/v1/health`
- Templo Jedi: `systemctl status templo-jedi.service`
