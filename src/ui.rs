// PT: Interface Web do Painel de Controle Zorya v3.0 no Svitlo Design System
// EN: Zorya v3.0 Control Panel Web UI in Svitlo Design System
// AI: @llm-module ui | @llm-context Paleta Carvão Profundo (#0E0E0E), Âmbar (#F5A623), Verde (#2ECC71), Borda (#3A3A3C)
// AI: @llm-invariant Grid de alta densidade, botões com feedback de estado assíncrono, persistência atômica validada

use crate::config::ZoryaConfig;
use crate::telemetry::DetailedSystemInfo;

/// PT: Renderiza a página HTML completa de /settings no padrão estético Svitlo Design System
/// EN: Renders complete HTML page for /settings in Svitlo Design System standard
// AI: @llm-func render_svitlo_dashboard_html @llm-ux Alta densidade, responsivo, sem truncamento de campos
pub fn render_svitlo_dashboard_html(
    cfg: &ZoryaConfig,
    telem: &DetailedSystemInfo,
    ts_ip_str: &str,
    tray_tooltip: &str,
    csrf_token: &str,
    quotas_json: &str,
) -> String {
    let mask_keys = |keys: &[String]| -> String {
        keys.iter()
            .map(|k| {
                let t = k.trim();
                if t.len() <= 8 {
                    "********".to_string()
                } else {
                    format!("{}...{}", &t[..4], &t[t.len() - 4..])
                }
            })
            .collect::<Vec<_>>()
            .join("\n")
    };

    let groq_keys_str = mask_keys(&cfg.credentials.groq_keys);
    let gemini_keys_str = mask_keys(&cfg.credentials.gemini_keys);
    let openai_keys_str = mask_keys(&cfg.credentials.openai_keys);
    let craft_enabled_str = cfg.craft.enabled.join(", ");

    let vram_total = telem.gpu_vram_total_mb.max(4096);
    let vram_used = telem.gpu_vram_total_mb.saturating_sub(telem.gpu_vram_free_mb);
    let vram_pct = ((vram_used as f64 / vram_total as f64) * 100.0).clamp(0.0, 100.0) as u32;

    let ram_total = telem.ram_total_mb.max(16384);
    let ram_used = telem.ram_used_mb;
    let ram_pct = ((ram_used as f64 / ram_total as f64) * 100.0).clamp(0.0, 100.0) as u32;

    format!(r#"<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>⚡ USINA ZORYA v3.0 — PAINEL DE CONTROLE</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,600;0,9..40,700;1,9..40,400&family=JetBrains+Mono:wght@400;600&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
<style>
  :root {{
    --bg: #0E0E0E;
    --bg2: #1C1C1E;
    --bg3: #2C2C2E;
    --amber: #F5A623;
    --amber-hover: #D98E16;
    --green: #2ECC71;
    --danger: #E74C3C;
    --white: #F0F0F0;
    --gray: #8A8A8E;
    --border: #3A3A3C;
    --font-heading: 'Space Grotesk', -apple-system, sans-serif;
    --font-body: 'DM Sans', -apple-system, sans-serif;
    --font-mono: 'JetBrains Mono', monospace;
  }}
  * {{ box-sizing: border-box; }}
  body {{
    margin: 0;
    padding: 0;
    background: var(--bg);
    color: var(--white);
    font-family: var(--font-body);
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
  }}
  /* Topbar Fixa */
  .topbar {{
    position: sticky;
    top: 0;
    z-index: 100;
    background: rgba(28, 28, 30, 0.95);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
  }}
  .topbar-brand {{
    display: flex;
    align-items: center;
    gap: 12px;
  }}
  .topbar-title {{
    font-family: var(--font-heading);
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.5px;
    color: var(--white);
  }}
  .topbar-badge {{
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(46, 204, 113, 0.15);
    border: 1px solid rgba(46, 204, 113, 0.3);
    color: var(--green);
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    font-family: var(--font-mono);
  }}
  .pulse-dot {{
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 8px var(--green);
    animation: pulse 2s infinite;
  }}
  @keyframes pulse {{
    0%, 100% {{ opacity: 1; transform: scale(1); }}
    50% {{ opacity: 0.4; transform: scale(0.85); }}
  }}
  .topbar-actions {{
    display: flex;
    align-items: center;
    gap: 12px;
  }}
  /* Botões */
  .btn {{
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 8px;
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    text-decoration: none;
  }}
  .btn-primary {{
    background: var(--amber);
    color: #0E0E0E;
  }}
  .btn-primary:hover {{
    background: var(--amber-hover);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245, 166, 35, 0.3);
  }}
  .btn-secondary {{
    background: var(--bg3);
    color: var(--white);
    border: 1px solid var(--border);
  }}
  .btn-secondary:hover {{
    background: #3A3A3C;
    border-color: #555;
  }}
  .btn:disabled {{
    opacity: 0.5;
    cursor: not-allowed;
    transform: none !important;
  }}
  /* Container e Grids */
  .main-content {{
    max-width: 1440px;
    margin: 0 auto;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 24px;
  }}
  /* Linha 1: KPI Cards de Telemetria */
  .kpi-row {{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
  }}
  .kpi-card {{
    background: var(--bg2);
    border: 1px solid var(--border);
    border-top: 3px solid var(--amber);
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }}
  .kpi-title {{
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--gray);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }}
  .kpi-value {{
    font-family: var(--font-heading);
    font-size: 20px;
    font-weight: 700;
    color: var(--white);
  }}
  .kpi-sub {{
    font-size: 12px;
    color: var(--gray);
  }}
  .progress-bar-bg {{
    width: 100%;
    height: 6px;
    background: var(--bg3);
    border-radius: 3px;
    overflow: hidden;
  }}
  .progress-bar-fill {{
    height: 100%;
    background: var(--amber);
    border-radius: 3px;
    transition: width 0.3s ease;
  }}
  /* Linha 2: Grid 2 Colunas Assimétrico */
  .grid-2col {{
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
  }}
  @media (max-width: 1024px) {{
    .grid-2col {{ grid-template-columns: 1fr; }}
  }}
  .panel {{
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 20px;
  }}
  .panel-header {{
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
    padding-bottom: 12px;
  }}
  .panel-title {{
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 700;
    color: var(--amber);
    display: flex;
    align-items: center;
    gap: 8px;
  }}
  .field-group {{
    display: flex;
    flex-direction: column;
    gap: 6px;
  }}
  label {{
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray);
    display: flex;
    justify-content: space-between;
  }}
  .key-count {{
    color: var(--amber);
    font-family: var(--font-mono);
  }}
  input, select, textarea {{
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--white);
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13.5px;
    font-family: var(--font-mono);
    transition: border-color 0.2s;
  }}
  input:focus, select:focus, textarea:focus {{
    outline: none;
    border-color: var(--amber);
    box-shadow: 0 0 0 2px rgba(245, 166, 35, 0.2);
  }}
  textarea {{
    min-height: 84px;
    resize: vertical;
    line-height: 1.4;
  }}
  .input-grid-2 {{
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
  }}
  @media (max-width: 640px) {{
    .input-grid-2 {{ grid-template-columns: 1fr; }}
  }}
  /* Tabela de Cotas */
  .quotas-table-container {{
    max-height: 280px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg);
  }}
  table {{
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    text-align: left;
  }}
  th {{
    position: sticky;
    top: 0;
    background: var(--bg3);
    color: var(--white);
    padding: 8px 12px;
    font-weight: 600;
    border-bottom: 1px solid var(--border);
  }}
  td {{
    padding: 8px 12px;
    border-bottom: 1px solid rgba(58, 58, 60, 0.5);
    font-family: var(--font-mono);
  }}
  tr:hover td {{
    background: rgba(245, 166, 35, 0.05);
  }}
  .badge-quota {{
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
  }}
  .badge-ok {{ background: rgba(46, 204, 113, 0.15); color: var(--green); }}
  .badge-warn {{ background: rgba(245, 166, 35, 0.15); color: var(--amber); }}
  /* Toast Flutuante */
  #toast {{
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 1000;
    background: var(--bg2);
    border: 1px solid var(--green);
    color: var(--white);
    padding: 14px 20px;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8);
    display: none;
    align-items: center;
    gap: 12px;
    font-size: 13.5px;
    animation: slideUp 0.3s ease;
  }}
  @keyframes slideUp {{
    from {{ opacity: 0; transform: translateY(20px); }}
    to {{ opacity: 1; transform: translateY(0); }}
  }}
  .toast-icon {{
    font-size: 18px;
    color: var(--green);
  }}
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-brand">
    <div class="topbar-title">⚡ USINA ZORYA v3.0 — PAINEL DE CONTROLE</div>
    <div class="topbar-badge">
      <span class="pulse-dot"></span>
      ONLINE • Tailscale: {ts_ip}:{port}
    </div>
  </div>
  <div class="topbar-actions">
    <button type="button" class="btn btn-secondary" onclick="testSvitloAi()">🧪 Testar Svitlo + OpenAlex</button>
    <button type="button" class="btn btn-primary" id="btnSaveTop" onclick="submitConfigForm()">💾 Salvar no zorya.toml</button>
  </div>
</header>

<main class="main-content">

  <!-- LINHA 1: KPI Cards de Telemetria -->
  <section class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-title">GPU & VRAM Dedicada <span>CUDA #0</span></div>
      <div class="kpi-value">{gpu_name}</div>
      <div class="kpi-sub">VRAM: <strong>{vram_used} MB</strong> / {vram_total} MB ({vram_pct}%)</div>
      <div class="progress-bar-bg">
        <div class="progress-bar-fill" style="width: {vram_pct}%;"></div>
      </div>
      <div style="font-size: 11px; color: var(--gray);">Display isolado na iGPU AMD Radeon</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">CPU & RAM do Sistema <span>AVX2 ATIVO</span></div>
      <div class="kpi-value">{cpu_brand}</div>
      <div class="kpi-sub">RAM: <strong>{ram_used} MB</strong> / {ram_total} MB ({ram_pct}%)</div>
      <div class="progress-bar-bg">
        <div class="progress-bar-fill" style="width: {ram_pct}%;"></div>
      </div>
      <div style="font-size: 11px; color: var(--gray);">{cpu_cores} núcleos físicos/lógicos monitorados</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">Malha Tailscale & Cluster <span>HEADSCALE</span></div>
      <div class="kpi-value">Nó: {node_name}</div>
      <div style="font-size: 12px; display: flex; flex-direction: column; gap: 4px;">
        <div>Local: <a href="http://127.0.0.1:{port}/settings" style="color:var(--amber); text-decoration:none;">http://127.0.0.1:{port}</a></div>
        <div>Mobile: <a href="http://{ts_ip}:{port}/settings" style="color:var(--amber); text-decoration:none;">http://{ts_ip}:{port}</a></div>
        <div style="color: var(--gray);">Peer VPS: {vps_endpoint}</div>
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">Motor de Inferência <span>GGUF + CLOUD</span></div>
      <div class="kpi-value" style="font-size:16px;">{fast_model}</div>
      <div class="kpi-sub">Bandeja: {tray_tooltip}</div>
      <div style="display:flex; gap:8px; margin-top:4px;">
        <button type="button" class="btn btn-secondary" style="padding:6px 12px; font-size:11px;" onclick="loadModelVram()">Carregar VRAM</button>
        <button type="button" class="btn btn-secondary" style="padding:6px 12px; font-size:11px;" onclick="unloadModelVram()">Liberar VRAM</button>
      </div>
    </div>
  </section>

  <!-- LINHA 2: Grid 2 Colunas Assimétrico -->
  <form id="configForm" onsubmit="submitConfigForm(event)">
    <input type="hidden" name="csrf_token" id="csrfToken" value="{csrf_token}">

    <div class="grid-2col">
      <!-- Painel Esquerdo: Cofre de Chaves & Roteamento -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">🔑 Cofre de Chaves de IA & Roteamento Inteligente</div>
          <span style="font-size:12px; color:var(--gray);">Rotação Automática</span>
        </div>

        <div class="field-group">
          <label>Chaves Groq API (1 por linha) <span class="key-count" id="groqKeyCount">0 válidas</span></label>
          <textarea name="groq_keys" id="groqKeys" placeholder="gsk_..." oninput="updateKeyCounters()">{groq_keys}</textarea>
        </div>

        <div class="field-group">
          <label>Chaves Google Gemini API (1 por linha) <span class="key-count" id="geminiKeyCount">0 válidas</span></label>
          <textarea name="gemini_keys" id="geminiKeys" placeholder="AIzaSy..." oninput="updateKeyCounters()">{gemini_keys}</textarea>
        </div>

        <div class="field-group">
          <label>Chaves OpenAI API (Opcional) <span class="key-count" id="openaiKeyCount">0 válidas</span></label>
          <textarea name="openai_keys" id="openaiKeys" placeholder="sk-..." oninput="updateKeyCounters()">{openai_keys}</textarea>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Modelo Groq Principal</label>
            <input name="groq_primary_model" value="{groq_primary}">
          </div>
          <div class="field-group">
            <label>Modelo Groq Rápido</label>
            <input name="groq_fast_model" value="{groq_fast}">
          </div>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Modelo Groq Multilíngue</label>
            <input name="groq_multilingual_model" value="{groq_multi}">
          </div>
          <div class="field-group">
            <label>Modelo Whisper Áudio</label>
            <input name="groq_whisper_model" value="{groq_whisper}">
          </div>
        </div>

        <!-- Tabela Compacta de Cotas dos 36 Modelos -->
        <div class="field-group" style="margin-top:10px;">
          <label>Status das Cotas Diárias (36 Modelos / Roteamento)</label>
          <div class="quotas-table-container">
            <table id="quotasTable">
              <thead>
                <tr>
                  <th>Provedor</th>
                  <th>Modelo</th>
                  <th>RPM</th>
                  <th>TPM</th>
                  <th>RPD</th>
                </tr>
              </thead>
              <tbody id="quotasTbody">
                <!-- Renderizado via JS -->
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Painel Direito: Modelos Locais GGUF & Rede -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">🧠 Modelos Locais GGUF & Rede Soberana</div>
          <span style="font-size:12px; color:var(--gray);">In-Process (llama-cpp-2)</span>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Diretório de Modelos</label>
            <input name="models_dir" value="{models_dir}">
          </div>
          <div class="field-group">
            <label>URL do Runtime Local</label>
            <input name="local_runtime_url" value="{local_runtime_url}">
          </div>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Modelo Rápido (Fast GGUF)</label>
            <input name="fast_model" value="{fast_model}">
          </div>
          <div class="field-group">
            <label>Modelo Profundo (Deep GGUF)</label>
            <input name="deep_model" value="{deep_model}">
          </div>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Camadas na GPU (GPU Layers)</label>
            <input type="number" name="gpu_layers" value="{gpu_layers}">
          </div>
          <div class="field-group">
            <label>Contexto (Tokens)</label>
            <input type="number" name="context_size" value="{ctx_size}">
          </div>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Máximo Tokens Saída</label>
            <input type="number" name="max_tokens" value="{max_tokens}">
          </div>
          <div class="field-group">
            <label>TTL em VRAM (Segundos)</label>
            <input type="number" name="ttl_seconds" value="{ttl_sec}">
          </div>
        </div>

        <div class="panel-header" style="margin-top:10px;">
          <div class="panel-title" style="font-size:14px;">🌐 Rede Tailscale & Cluster VPS</div>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Host de Escuta</label>
            <input name="host" value="{host}">
          </div>
          <div class="field-group">
            <label>Porta do Servidor</label>
            <input type="number" name="port" value="{port}">
          </div>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>IP Tailscale do Nó</label>
            <input name="tailscale_ip" value="{ts_ip}">
          </div>
          <div class="field-group">
            <label>Nome do Nó</label>
            <input name="node_name" value="{node_name}">
          </div>
        </div>

        <div class="field-group">
          <label>Endpoint da VPS (Cluster)</label>
          <input name="vps_endpoint" value="{vps_endpoint}">
        </div>

        <div class="field-group">
          <label>Módulos ArtCraft Habilitados (separados por vírgula)</label>
          <input name="craft_enabled" value="{craft_enabled}">
        </div>
      </div>
    </div>

    <!-- Barra Inferior de Ação -->
    <div style="display:flex; justify-content:flex-end; gap:16px; margin-top:24px; padding-bottom:32px;">
      <button type="button" class="btn btn-secondary" onclick="testSvitloAi()">🧪 Testar Geração Svitlo + OpenAlex Agora</button>
      <button type="submit" class="btn btn-primary" id="btnSaveBottom" style="padding: 12px 28px; font-size:14px;">💾 Salvar Todas as Configurações no zorya.toml</button>
    </div>
  </form>

  <!-- Modal / Drawer de Teste -->
  <div id="testResultBox" class="panel" style="display:none; border-color:var(--amber);">
    <div class="panel-header">
      <div class="panel-title">🔬 Diagnóstico de Inferência Svitlo + OpenAlex</div>
      <button type="button" class="btn btn-secondary" style="padding:4px 8px; font-size:11px;" onclick="document.getElementById('testResultBox').style.display='none'">Fechar</button>
    </div>
    <pre id="testResultPre" style="font-family:var(--font-mono); font-size:12px; color:var(--white); white-space:pre-wrap; max-height:360px; overflow-y:auto; margin:0;"></pre>
  </div>

</main>

<div id="toast">
  <span class="toast-icon">✓</span>
  <span id="toastMsg">Configurações gravadas atomicamente em zorya.toml!</span>
</div>

<script>
const rawQuotas = {quotas_json};

function updateKeyCounters() {{
  ['groq', 'gemini', 'openai'].forEach(p => {{
    const el = document.getElementById(p + 'Keys');
    const cntEl = document.getElementById(p + 'KeyCount');
    if (el && cntEl) {{
      const lines = el.value.split('\n')
        .map(s => s.trim())
        .filter(s => s.length > 5 && !s.includes('...') && !s.includes('***'));
      cntEl.textContent = lines.length + ' válidas';
    }}
  }});
}}

function renderQuotasTable() {{
  const tbody = document.getElementById('quotasTbody');
  if (!tbody || !Array.isArray(rawQuotas)) return;
  tbody.innerHTML = '';
  rawQuotas.forEach(q => {{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="color:var(--gray);">${{q.provider}}</td>
      <td style="color:var(--white); font-weight:600;">${{q.model_name}}</td>
      <td><span class="badge-quota badge-ok">${{q.rpm_current}} / ${{q.rpm_limit || '∞'}}</span></td>
      <td><span class="badge-quota badge-ok">${{q.tpm_current}} / ${{q.tpm_limit ? q.tpm_limit.toLocaleString() : '∞'}}</span></td>
      <td><span class="badge-quota ${{q.rpd_current > 0 ? 'badge-warn' : 'badge-ok'}}">${{q.rpd_current}} / ${{q.rpd_limit || '∞'}}</span></td>
    `;
    tbody.appendChild(tr);
  }});
}}

async function submitConfigForm(e) {{
  if (e) e.preventDefault();
  const btnTop = document.getElementById('btnSaveTop');
  const btnBottom = document.getElementById('btnSaveBottom');
  const form = document.getElementById('configForm');
  const csrfToken = document.getElementById('csrfToken').value;

  btnTop.disabled = true;
  btnBottom.disabled = true;
  btnTop.textContent = '⏳ Salvando...';
  btnBottom.textContent = '⏳ Gravando atomicamente...';

  try {{
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    const res = await fetch('/api/v1/settings', {{
      method: 'POST',
      headers: {{
        'Content-Type': 'application/json',
        'X-Zorya-CSRF': csrfToken,
      }},
      body: JSON.stringify(data)
    }});

    const out = await res.json();
    if (res.ok && out.status === 'ok') {{
      showToast('Configurações gravadas atomicamente em ' + (out.config_path || 'zorya.toml') + '!');
      setTimeout(() => location.reload(), 1200);
    }} else {{
      alert('❌ Falha ao salvar configurações: ' + (out.message || out.error || 'Erro desconhecido'));
    }}
  }} catch (err) {{
    alert('❌ Erro de conexão: ' + err.message);
  }} finally {{
    btnTop.disabled = false;
    btnBottom.disabled = false;
    btnTop.textContent = '💾 Salvar no zorya.toml';
    btnBottom.textContent = '💾 Salvar Todas as Configurações no zorya.toml';
  }}
}}

function showToast(msg) {{
  const toast = document.getElementById('toast');
  const toastMsg = document.getElementById('toastMsg');
  toastMsg.textContent = msg;
  toast.style.display = 'flex';
  setTimeout(() => {{ toast.style.display = 'none'; }}, 3500);
}}

async function testSvitloAi() {{
  const box = document.getElementById('testResultBox');
  const pre = document.getElementById('testResultPre');
  box.style.display = 'block';
  box.scrollIntoView({{ behavior: 'smooth' }});
  pre.textContent = '⏳ Consultando OpenAlex, avaliando cotas via QuotaRouter e gerando síntese pedagógica...';

  try {{
    const res = await fetch('/api/v1/test/svitlo-openalex', {{
      method: 'POST',
      headers: {{ 'Content-Type': 'application/json' }},
      body: JSON.stringify({{ topic: 'Afasia de Broca', mode: 'both' }})
    }});
    const data = await res.json();
    pre.textContent = JSON.stringify(data, null, 2);
  }} catch (err) {{
    pre.textContent = 'Erro ao executar teste de diagnóstico: ' + err.message;
  }}
}}

async function loadModelVram() {{
  showToast('Carregando modelo GGUF na VRAM...');
  await fetch('/v1/models/load', {{ method: 'POST', headers: {{ 'Content-Type': 'application/json' }}, body: JSON.stringify({{}}) }});
  setTimeout(() => location.reload(), 1000);
}}

async function unloadModelVram() {{
  showToast('Liberando VRAM do modelo...');
  await fetch('/v1/models/unload', {{ method: 'POST', headers: {{ 'Content-Type': 'application/json' }}, body: JSON.stringify({{}}) }});
  setTimeout(() => location.reload(), 1000);
}}

document.addEventListener('DOMContentLoaded', () => {{
  updateKeyCounters();
  renderQuotasTable();
}});
</script>

</body>
</html>"#,
        port = cfg.server.port,
        host = cfg.server.host,
        ts_ip = ts_ip_str,
        node_name = cfg.server.node_name,
        vps_endpoint = cfg.server.vps_endpoint,
        tray_tooltip = tray_tooltip,
        gpu_name = telem.gpu_model,
        vram_used = vram_used,
        vram_total = vram_total,
        vram_pct = vram_pct,
        cpu_brand = telem.cpu_brand,
        cpu_cores = telem.cpu_logical_cores,
        ram_used = ram_used,
        ram_total = ram_total,
        ram_pct = ram_pct,
        models_dir = cfg.models.models_dir,
        local_runtime_url = cfg.inference.local_runtime_url,
        fast_model = cfg.models.fast_model,
        deep_model = cfg.models.deep_model,
        gpu_layers = cfg.models.gpu_layers,
        ctx_size = cfg.models.context_size,
        max_tokens = cfg.models.max_tokens,
        ttl_sec = cfg.models.ttl_seconds,
        groq_keys = groq_keys_str,
        gemini_keys = gemini_keys_str,
        openai_keys = openai_keys_str,
        groq_primary = cfg.credentials.groq_primary_model.as_deref().unwrap_or("openai/gpt-oss-120b"),
        groq_fast = cfg.credentials.groq_fast_model.as_deref().unwrap_or("openai/gpt-oss-20b"),
        groq_multi = cfg.credentials.groq_multilingual_model.as_deref().unwrap_or("qwen/qwen3.8-27b"),
        groq_whisper = cfg.credentials.groq_whisper_model.as_deref().unwrap_or("whisper-large-v3"),
        craft_enabled = craft_enabled_str,
        csrf_token = csrf_token,
        quotas_json = quotas_json,
    )
}
