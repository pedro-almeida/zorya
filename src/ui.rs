// PT: Interface Web do Painel de Controle Zorya v3.0 no Svitlo Design System
// EN: Zorya v3.0 Control Panel Web UI in Svitlo Design System
// AI: @llm-module ui | @llm-context Svitlo Design System (Escala Fibonacci 3, 5, 8, 13, 21, 34, 55px; Full-Bleed 100vw)
// AI: @llm-invariant Grid responsivo de alta densidade sem limites fixos de largura, cofre com visualização real/mascarada, 11 módulos ArtCraft e governança

use crate::config::ZoryaConfig;
use crate::telemetry::DetailedSystemInfo;

/// PT: Renderiza a página HTML completa de /settings no padrão estético Svitlo Design System
/// EN: Renders complete HTML page for /settings in Svitlo Design System standard
// AI: @llm-func render_svitlo_dashboard_html @llm-ux Svitlo Fibonacci, responsivo full-bleed, cofre de chaves interativo
pub fn render_svitlo_dashboard_html(
    cfg: &ZoryaConfig,
    telem: &DetailedSystemInfo,
    ts_ip_str: &str,
    tray_tooltip: &str,
    csrf_token: &str,
    quotas_json: &str,
    openrouter_status_json: &str,
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
    let openrouter_keys_str = mask_keys(&cfg.credentials.openrouter_keys);
    let craft_enabled_str = cfg.craft.enabled.join(",");

    let vram_total = telem.gpu_vram_total_mb.max(4096);
    let vram_used = telem
        .gpu_vram_total_mb
        .saturating_sub(telem.gpu_vram_free_mb);
    let vram_pct = ((vram_used as f64 / vram_total as f64) * 100.0).clamp(0.0, 100.0) as u32;

    let ram_total = telem.ram_total_mb.max(16384);
    let ram_used = telem.ram_used_mb;
    let ram_pct = ((ram_used as f64 / ram_total as f64) * 100.0).clamp(0.0, 100.0) as u32;

    // Renderiza os 11 cards dos módulos ArtCraft
    let craft_defs = [
        (
            "lightcraft",
            "💡 LightCraft",
            "RAW & Denoise de alta fidelidade",
        ),
        (
            "photocraft",
            "📷 PhotoCraft",
            "Curadoria & Culling de imagens",
        ),
        (
            "vectorcraft",
            "📐 VectorCraft",
            "Diagramas e ilustrações vetoriais SVG",
        ),
        (
            "soundcraft",
            "🎙️ SoundCraft",
            "EBU R128, Voice Cloning & Áudio",
        ),
        (
            "pdfcraft",
            "📑 PdfCraft",
            "Extração estruturada, OCR & Parsing",
        ),
        (
            "wordcraft",
            "✍️ WordCraft",
            "Redação acadêmica e estilo Svitlo",
        ),
        (
            "effectcraft",
            "🎨 EffectCraft",
            "Shaders, LUTs e pós-processamento",
        ),
        (
            "filmcraft",
            "🎬 FilmCraft",
            "Timeline, sequenciamento e montagem",
        ),
        (
            "deckcraft",
            "📊 DeckCraft",
            "Apresentações e slides pedagógicos",
        ),
        (
            "figcraft",
            "🖼️ FigCraft",
            "Diagramação de figuras científicas",
        ),
        (
            "designcraft",
            "🏛️ DesignCraft",
            "Design System e layouts editoriais",
        ),
    ];

    let mut craft_cards_html = String::new();
    for (id, name, desc) in craft_defs {
        let is_checked = cfg.is_craft_enabled(id);
        let checked_attr = if is_checked { "checked" } else { "" };
        let active_cls = if is_checked {
            "craft-card active"
        } else {
            "craft-card"
        };
        craft_cards_html.push_str(&format!(
            r#"<label class="{active_cls}" id="craft_card_{id}">
  <input type="checkbox" class="craft-checkbox" value="{id}" {checked_attr} onchange="toggleCraftModule(this)">
  <div class="craft-card-content">
    <div class="craft-card-title">{name}</div>
    <div class="craft-card-desc">{desc}</div>
  </div>
</label>"#,
            active_cls = active_cls,
            id = id,
            checked_attr = checked_attr,
            name = name,
            desc = desc,
        ));
    }

    format!(
        r#"<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
<meta charset="utf-8">
<title>⚡ USINA ZORYA v1.0 — PAINEL DE CONTROLE SOBERANO</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
  :root {{
    /* Escala de Fibonacci Svitlo */
    --fib-1: 3px;
    --fib-2: 5px;
    --fib-3: 8px;
    --fib-4: 13px;
    --fib-5: 21px;
    --fib-6: 34px;
    --fib-7: 55px;

    /* Tema Claro Acadêmico Padrão */
    --bg-warm: #F7F4EF;
    --bg-feature: #EDE8DF;
    --cream: #F0E8D8;
    --brown-dark: #5C3D1E;
    --brown-medium: #8B5E3C;
    --brown-light: #C4956A;
    --gold: #C9A84C;
    --text-dark: #2C1F14;
    --text-muted: #6E5A4B;
    --border-color: rgba(92, 61, 30, 0.18);
    --card-bg: #FFFFFF;
    --card-shadow: 0 4px 13px rgba(92, 61, 30, 0.06);

    --font-heading: 'Cormorant Garamond', Georgia, serif;
    --font-body: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --font-mono: 'JetBrains Mono', monospace;

    --green: #2E7D32;
    --danger: #C62828;
  }}

  [data-theme="dark"] {{
    --bg-warm: #18120E;
    --bg-feature: #231A14;
    --cream: #2C2018;
    --brown-dark: #EBD9C8;
    --brown-medium: #C4956A;
    --gold: #D4AF37;
    --text-dark: #F7F4EF;
    --text-muted: #B8A696;
    --card-bg: #211813;
    --border-color: rgba(201, 168, 76, 0.22);
    --card-shadow: 0 4px 13px rgba(0, 0, 0, 0.4);
    --green: #4CAF50;
    --danger: #EF5350;
  }}

  * {{ box-sizing: border-box; }}

  html, body {{
    width: 100%;
    max-width: 100vw;
    min-height: 100vh;
    margin: 0;
    padding: 0;
    background: var(--bg-warm);
    color: var(--text-dark);
    font-family: var(--font-body);
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
  }}

  /* Topbar Svitlo com Gradiente e Borda Dourada */
  .svitlo-topbar {{
    position: sticky;
    top: 0;
    z-index: 100;
    background: linear-gradient(135deg, #3D261C 0%, #5C3D1E 100%);
    color: #F7F4EF;
    border-bottom: 2px solid var(--gold);
    padding: var(--fib-4) var(--fib-5);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--fib-4);
    flex-wrap: wrap;
    box-shadow: 0 4px 21px rgba(0, 0, 0, 0.25);
  }}

  .topbar-brand {{
    display: flex;
    align-items: center;
    gap: var(--fib-4);
  }}

  .topbar-title {{
    font-family: var(--font-heading);
    font-size: 24px;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #F7F4EF;
    display: flex;
    align-items: center;
    gap: var(--fib-3);
  }}

  .topbar-badge {{
    display: inline-flex;
    align-items: center;
    gap: var(--fib-2);
    background: rgba(201, 168, 76, 0.2);
    border: 1px solid var(--gold);
    color: var(--gold);
    padding: var(--fib-1) var(--fib-3);
    border-radius: var(--fib-3);
    font-size: 12px;
    font-weight: 600;
    font-family: var(--font-mono);
  }}

  .pulse-dot {{
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #4CAF50;
    box-shadow: 0 0 8px #4CAF50;
    animation: svitloPulse 2s infinite;
  }}

  @keyframes svitloPulse {{
    0%, 100% {{ opacity: 1; transform: scale(1); }}
    50% {{ opacity: 0.4; transform: scale(0.85); }}
  }}

  .topbar-actions {{
    display: flex;
    align-items: center;
    gap: var(--fib-3);
    flex-wrap: wrap;
  }}

  /* Botões Svitlo */
  .btn {{
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--fib-2);
    padding: var(--fib-3) var(--fib-4);
    border-radius: var(--fib-2);
    font-family: var(--font-body);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid transparent;
    text-decoration: none;
  }}

  .btn-gold {{
    background: var(--gold);
    color: #2C1F14;
    border-color: #B5953F;
    font-weight: 700;
  }}
  .btn-gold:hover {{
    background: #D4AF37;
    transform: translateY(-1px);
    box-shadow: 0 4px 13px rgba(201, 168, 76, 0.4);
  }}

  .btn-secondary {{
    background: rgba(247, 244, 239, 0.15);
    color: #F7F4EF;
    border-color: rgba(247, 244, 239, 0.3);
  }}
  .btn-secondary:hover {{
    background: rgba(247, 244, 239, 0.25);
    border-color: #F7F4EF;
  }}

  .btn-theme-toggle {{
    background: transparent;
    color: #F7F4EF;
    border: 1px solid var(--gold);
    padding: var(--fib-2) var(--fib-3);
    font-size: 12px;
  }}
  .btn-theme-toggle:hover {{
    background: rgba(201, 168, 76, 0.2);
  }}

  /* Layout Full-Bleed sem limites fixos */
  .main-fullbleed {{
    width: 100%;
    max-width: 100vw;
    margin: 0;
    padding: var(--fib-4) var(--fib-5);
    display: flex;
    flex-direction: column;
    gap: var(--fib-5);
  }}

  /* Telemetria KPIs em Grid Fluido */
  .kpi-fluid-grid {{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
    gap: var(--fib-4);
  }}

  .kpi-card {{
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-top: 3px solid var(--gold);
    border-radius: var(--fib-3);
    padding: var(--fib-4);
    box-shadow: var(--card-shadow);
    display: flex;
    flex-direction: column;
    gap: var(--fib-3);
  }}

  .kpi-title {{
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }}

  .kpi-value {{
    font-family: var(--font-heading);
    font-size: 22px;
    font-weight: 700;
    color: var(--text-dark);
  }}

  .kpi-sub {{
    font-size: 12px;
    color: var(--text-muted);
  }}

  .progress-bg {{
    width: 100%;
    height: 6px;
    background: var(--bg-feature);
    border-radius: 3px;
    overflow: hidden;
  }}
  .progress-fill {{
    height: 100%;
    background: var(--gold);
    border-radius: 3px;
    transition: width 0.3s ease;
  }}

  /* Grid Fluido de 2 Painéis Principais */
  .dashboard-grid {{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 540px), 1fr));
    gap: var(--fib-5);
  }}

  .panel-svitlo {{
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: var(--fib-3);
    padding: var(--fib-5);
    box-shadow: var(--card-shadow);
    display: flex;
    flex-direction: column;
    gap: var(--fib-4);
  }}

  .panel-header {{
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border-color);
    padding-bottom: var(--fib-3);
    flex-wrap: wrap;
    gap: var(--fib-2);
  }}

  .panel-title {{
    font-family: var(--font-heading);
    font-size: 20px;
    font-weight: 700;
    color: var(--brown-dark);
    display: flex;
    align-items: center;
    gap: var(--fib-2);
  }}

  .field-group {{
    display: flex;
    flex-direction: column;
    gap: var(--fib-2);
  }}

  label {{
    font-size: 11.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--fib-1);
  }}

  .label-tools {{
    display: flex;
    align-items: center;
    gap: var(--fib-3);
  }}

  .key-counter {{
    color: var(--brown-medium);
    font-family: var(--font-mono);
    font-weight: 600;
  }}

  .clear-checkbox-label {{
    font-size: 11px;
    color: var(--danger);
    display: inline-flex;
    align-items: center;
    gap: var(--fib-1);
    cursor: pointer;
    text-transform: none;
    font-weight: 500;
  }}

  input, select, textarea {{
    width: 100%;
    background: var(--bg-warm);
    border: 1px solid var(--border-color);
    color: var(--text-dark);
    padding: var(--fib-3) var(--fib-4);
    border-radius: var(--fib-2);
    font-size: 13.5px;
    font-family: var(--font-mono);
    transition: all 0.2s ease;
  }}

  input:focus, select:focus, textarea:focus {{
    outline: none;
    border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(201, 168, 76, 0.2);
    background: var(--card-bg);
  }}

  textarea {{
    min-height: 78px;
    resize: vertical;
    line-height: 1.4;
  }}

  .input-grid-2 {{
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--fib-4);
  }}

  @media (max-width: 640px) {{
    .input-grid-2 {{ grid-template-columns: 1fr; }}
  }}

  /* Checkboxes Interativos ArtCraft (Grid 11 Módulos) */
  .craft-grid {{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
    gap: var(--fib-3);
  }}

  .craft-card {{
    background: var(--bg-warm);
    border: 1px solid var(--border-color);
    border-radius: var(--fib-2);
    padding: var(--fib-3);
    cursor: pointer;
    display: flex;
    align-items: flex-start;
    gap: var(--fib-3);
    transition: all 0.2s ease;
    user-select: none;
  }}
  .craft-card:hover {{
    border-color: var(--gold);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(92, 61, 30, 0.08);
  }}
  .craft-card.active {{
    background: var(--bg-feature);
    border-color: var(--gold);
    border-left: 3px solid var(--gold);
  }}

  .craft-checkbox {{
    width: 16px;
    height: 16px;
    margin-top: 2px;
    accent-color: var(--gold);
    cursor: pointer;
  }}

  .craft-card-content {{
    display: flex;
    flex-direction: column;
    gap: var(--fib-1);
  }}

  .craft-card-title {{
    font-weight: 700;
    font-size: 12.5px;
    color: var(--text-dark);
  }}

  .craft-card-desc {{
    font-size: 11px;
    color: var(--text-muted);
    line-height: 1.3;
  }}

  /* Checkboxes de Governança e Flags do Sistema */
  .gov-grid {{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 250px), 1fr));
    gap: var(--fib-3);
  }}

  .gov-card {{
    background: var(--bg-warm);
    border: 1px solid var(--border-color);
    border-radius: var(--fib-2);
    padding: var(--fib-3) var(--fib-4);
    display: flex;
    align-items: flex-start;
    gap: var(--fib-3);
    cursor: pointer;
    transition: all 0.2s ease;
  }}
  .gov-card:hover {{
    border-color: var(--gold);
  }}
  .gov-card input[type="checkbox"] {{
    width: 18px;
    height: 18px;
    margin-top: 2px;
    accent-color: var(--gold);
    cursor: pointer;
  }}
  .gov-text {{
    display: flex;
    flex-direction: column;
    gap: var(--fib-1);
  }}
  .gov-title {{
    font-weight: 600;
    font-size: 12.5px;
    color: var(--text-dark);
  }}
  .gov-sub {{
    font-size: 11px;
    color: var(--text-muted);
  }}

  /* Tabela de Cotas */
  .quotas-container {{
    max-height: 260px;
    overflow-y: auto;
    border: 1px solid var(--border-color);
    border-radius: var(--fib-2);
    background: var(--bg-warm);
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
    background: var(--bg-feature);
    color: var(--text-dark);
    padding: var(--fib-3) var(--fib-4);
    font-weight: 600;
    border-bottom: 1px solid var(--border-color);
  }}
  td {{
    padding: var(--fib-2) var(--fib-4);
    border-bottom: 1px solid var(--border-color);
    font-family: var(--font-mono);
  }}
  tr:hover td {{
    background: rgba(201, 168, 76, 0.08);
  }}

  .badge-quota {{
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
  }}
  .badge-ok {{ background: rgba(46, 125, 50, 0.15); color: var(--green); }}
  .badge-warn {{ background: rgba(201, 168, 76, 0.25); color: var(--gold); }}

  /* Toast de Notificação */
  #toast {{
    position: fixed;
    bottom: var(--fib-5);
    right: var(--fib-5);
    z-index: 1000;
    background: var(--card-bg);
    border: 2px solid var(--gold);
    color: var(--text-dark);
    padding: var(--fib-4) var(--fib-5);
    border-radius: var(--fib-3);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
    display: none;
    align-items: center;
    gap: var(--fib-4);
    font-size: 13.5px;
    font-weight: 600;
    animation: toastSlideUp 0.3s ease;
  }}
  @keyframes toastSlideUp {{
    from {{ opacity: 0; transform: translateY(20px); }}
    to {{ opacity: 1; transform: translateY(0); }}
  }}
</style>
</head>
<body>

<header class="svitlo-topbar">
  <div class="topbar-brand">
    <div class="topbar-title">⚡ USINA ZORYA v3.0</div>
    <div class="topbar-badge">
      <span class="pulse-dot"></span>
      ONLINE • {ts_ip}:{port}
    </div>
  </div>
  <div class="topbar-actions">
    <button type="button" class="btn btn-theme-toggle" onclick="toggleSvitloTheme()">🌓 Alternar Tema</button>
    <button type="button" class="btn btn-secondary" onclick="toggleRevealKeys()" id="btnRevealKeys">👁️ Mostrar Chaves Reais</button>
    <button type="button" class="btn btn-secondary" onclick="testSvitloAi()">🧪 Testar Svitlo + OpenAlex</button>
    <button type="button" class="btn btn-gold" id="btnSaveTop" onclick="submitConfigForm(event)">💾 Salvar no zorya.toml</button>
  </div>
</header>

<main class="main-fullbleed">

  <!-- LINHA 1: KPI Cards de Telemetria (Fluido e Responsivo) -->
  <section class="kpi-fluid-grid">
    <div class="kpi-card">
      <div class="kpi-title">GPU Dedicada (CUDA) <span>RTX/GTX</span></div>
      <div class="kpi-value">{gpu_name}</div>
      <div class="kpi-sub">VRAM: <strong>{vram_used} MB</strong> / {vram_total} MB ({vram_pct}%)</div>
      <div class="progress-bg">
        <div class="progress-fill" style="width: {vram_pct}%;"></div>
      </div>
      <div style="font-size: 11px; color: var(--text-muted);">Display isolado na iGPU AMD Radeon</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">CPU & RAM Sistema <span>AVX2/FMA</span></div>
      <div class="kpi-value">{cpu_brand}</div>
      <div class="kpi-sub">RAM: <strong>{ram_used} MB</strong> / {ram_total} MB ({ram_pct}%)</div>
      <div class="progress-bg">
        <div class="progress-fill" style="width: {ram_pct}%;"></div>
      </div>
      <div style="font-size: 11px; color: var(--text-muted);">{cpu_cores} núcleos físicos e lógicos</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">Rede Tailscale & Malha <span>HEADSCALE</span></div>
      <div class="kpi-value">Nó: {node_name}</div>
      <div style="font-size: 12px; display: flex; flex-direction: column; gap: 3px;">
        <div>Local: <a href="http://127.0.0.1:{port}/settings" style="color:var(--gold); text-decoration:none;">http://127.0.0.1:{port}</a></div>
        <div>Mobile: <a href="http://{ts_ip}:{port}/settings" style="color:var(--gold); text-decoration:none;">http://{ts_ip}:{port}</a></div>
        <div style="color: var(--text-muted);">VPS Peer: {vps_endpoint}</div>
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">Inferência In-Process <span>LLAMA-CPP</span></div>
      <div class="kpi-value" style="font-size:16px;">{fast_model}</div>
      <div class="kpi-sub">Bandeja: {tray_tooltip}</div>
      <div style="display:flex; gap:8px; margin-top:4px;">
        <button type="button" class="btn btn-secondary" style="color:var(--text-dark); border-color:var(--border-color); padding:4px 10px; font-size:11px;" onclick="loadModelVram()">Carregar VRAM</button>
        <button type="button" class="btn btn-secondary" style="color:var(--text-dark); border-color:var(--border-color); padding:4px 10px; font-size:11px;" onclick="unloadModelVram()">Liberar VRAM</button>
      </div>
    </div>
  </section>

  <!-- FORMULÁRIO PRINCIPAL UNIFICADO -->
  <form id="configForm" onsubmit="submitConfigForm(event)">
    <input type="hidden" name="csrf_token" id="csrfToken" value="{csrf_token}">
    <input type="hidden" name="craft_enabled" id="craftEnabledHidden" value="{craft_enabled}">

    <div class="dashboard-grid">

      <!-- PAINEL ESQUERDO: Cofre de Chaves, Rotação & Provedores -->
      <div class="panel-svitlo">
        <div class="panel-header">
          <div class="panel-title">🔑 Cofre Soberano de Chaves de IA</div>
          <span style="font-size:12px; color:var(--text-muted);">Rotação Atômica & Hot-Reload</span>
        </div>

        <!-- Groq -->
        <div class="field-group">
          <label>
            <span>Chaves Groq API (1 por linha)</span>
            <div class="label-tools">
              <span class="key-counter" id="groqKeyCount">0 válidas</span>
              <label class="clear-checkbox-label">
                <input type="checkbox" name="clear_groq_keys" id="clear_groq_keys"> Limpar ao salvar
              </label>
            </div>
          </label>
          <textarea name="groq_keys" id="groqKeys" placeholder="gsk_..." oninput="updateKeyCounters()">{groq_keys}</textarea>
        </div>

        <!-- Gemini -->
        <div class="field-group">
          <label>
            <span>Chaves Google Gemini API (1 por linha)</span>
            <div class="label-tools">
              <span class="key-counter" id="geminiKeyCount">0 válidas</span>
              <label class="clear-checkbox-label">
                <input type="checkbox" name="clear_gemini_keys" id="clear_gemini_keys"> Limpar ao salvar
              </label>
            </div>
          </label>
          <textarea name="gemini_keys" id="geminiKeys" placeholder="AIzaSy..." oninput="updateKeyCounters()">{gemini_keys}</textarea>
        </div>

        <!-- OpenAI -->
        <div class="field-group">
          <label>
            <span>Chaves OpenAI API (Opcional)</span>
            <div class="label-tools">
              <span class="key-counter" id="openaiKeyCount">0 válidas</span>
              <label class="clear-checkbox-label">
                <input type="checkbox" name="clear_openai_keys" id="clear_openai_keys"> Limpar ao salvar
              </label>
            </div>
          </label>
          <textarea name="openai_keys" id="openaiKeys" placeholder="sk-..." oninput="updateKeyCounters()">{openai_keys}</textarea>
        </div>

        <!-- OpenRouter -->
        <div class="field-group">
          <label>
            <span>Chaves OpenRouter API (Fallback Condicional)</span>
            <div class="label-tools">
              <span class="key-counter" id="openrouterBadge">○ STANDBY</span>
              <label class="clear-checkbox-label">
                <input type="checkbox" name="clear_openrouter_keys" id="clear_openrouter_keys"> Limpar ao salvar
              </label>
            </div>
          </label>
          <textarea name="openrouter_keys" id="openrouterKeys" placeholder="sk-or-v1-..." oninput="updateKeyCounters()">{openrouter_keys}</textarea>
        </div>

        <!-- Modelos da Nuvem -->
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
            <label>Modelo Groq Whisper</label>
            <input name="groq_whisper_model" value="{groq_whisper}">
          </div>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Modelo Gemini Padrão</label>
            <input name="gemini_model" value="{gemini_model}">
          </div>
          <div class="field-group">
            <label>Modelo Fallback OpenRouter</label>
            <input name="openrouter_default_model" value="{openrouter_model}">
          </div>
        </div>

        <!-- Tabela de Telemetria Real de Cotas -->
        <div class="field-group" style="margin-top:8px;">
          <label>Telemetria em Tempo Real de Cotas (Groq / Gemini / OpenRouter)</label>
          <div class="quotas-container">
            <table>
              <thead>
                <tr>
                  <th>Provedor</th>
                  <th>Modelo</th>
                  <th>RPM (Restante)</th>
                  <th>TPM (Restante)</th>
                  <th>RPD (Restante)</th>
                </tr>
              </thead>
              <tbody id="quotasTbody"></tbody>
            </table>
          </div>
        </div>

      </div>

      <!-- PAINEL DIREITO: ArtCraft Suite, Governança & GGUF Local -->
      <div class="panel-svitlo">
        <div class="panel-header">
          <div class="panel-title">🎨 Suíte ArtCraft & Governança da Usina</div>
          <div style="display:flex; gap:8px;">
            <button type="button" class="btn btn-secondary" style="color:var(--text-dark); border-color:var(--border-color); padding:3px 8px; font-size:11px;" onclick="toggleAllCraft(true)">Marcar Todos</button>
            <button type="button" class="btn btn-secondary" style="color:var(--text-dark); border-color:var(--border-color); padding:3px 8px; font-size:11px;" onclick="toggleAllCraft(false)">Desmarcar</button>
          </div>
        </div>

        <!-- 11 Módulos ArtCraft -->
        <div class="craft-grid">
          {craft_cards}
        </div>

        <!-- Flags de Sistema & Governança Soberana -->
        <div class="panel-header" style="margin-top:13px;">
          <div class="panel-title" style="font-size:16px;">🛡️ Flags de Governança & Isolamento de Hardware</div>
        </div>

        <div class="gov-grid">
          <label class="gov-card">
            <input type="checkbox" name="prefer_tailscale" id="flag_prefer_tailscale" {checked_prefer_tailscale}>
            <div class="gov-text">
              <span class="gov-title">Priorizar Tailscale</span>
              <span class="gov-sub">Priorizar malha segura Headscale/Tailscale para comunicação</span>
            </div>
          </label>

          <label class="gov-card">
            <input type="checkbox" name="reserve_display_on_igpu" id="flag_reserve_display_on_igpu" {checked_reserve_display}>
            <div class="gov-text">
              <span class="gov-title">Display na iGPU AMD</span>
              <span class="gov-sub">Preservar 100% da VRAM GTX 1650 para inferência GGUF</span>
            </div>
          </label>

          <label class="gov-card">
            <input type="checkbox" name="enable_mcp_sse" id="flag_enable_mcp_sse" {checked_mcp_sse}>
            <div class="gov-text">
              <span class="gov-title">Servidor MCP SSE</span>
              <span class="gov-sub">Habilitar protocolo Model Context Protocol sobre SSE (/mcp/sse)</span>
            </div>
          </label>

          <label class="gov-card">
            <input type="checkbox" name="cluster_enabled" id="flag_cluster_enabled" {checked_cluster_enabled}>
            <div class="gov-text">
              <span class="gov-title">Cluster Federado</span>
              <span class="gov-sub">Habilitar cluster Nitro 5 ↔ Mini Zorya VPS</span>
            </div>
          </label>

          <label class="gov-card">
            <input type="checkbox" name="offload_light_tasks_to_vps" id="flag_offload_light_tasks" {checked_offload_vps}>
            <div class="gov-text">
              <span class="gov-title">Offload de Tarefas Leves</span>
              <span class="gov-sub">Delegar tarefas leves para a VPS quando apropriado</span>
            </div>
          </label>

          <label class="gov-card">
            <input type="checkbox" name="enable_p2p" id="flag_enable_p2p" {checked_enable_p2p}>
            <div class="gov-text">
              <span class="gov-title">Descoberta P2P</span>
              <span class="gov-sub">Habilitar descoberta e sincronização P2P na rede local</span>
            </div>
          </label>

          <label class="gov-card">
            <input type="checkbox" name="openrouter_enabled" id="flag_openrouter_enabled" {checked_openrouter_enabled}>
            <div class="gov-text">
              <span class="gov-title">Ativar Rota OpenRouter</span>
              <span class="gov-sub">Permitir fallback automático de modelos via OpenRouter</span>
            </div>
          </label>
        </div>

        <!-- Parâmetros Adicionais de Mídia e Inferência GGUF Local -->
        <div class="panel-header" style="margin-top:13px;">
          <div class="panel-title" style="font-size:16px;">🧠 Motor de Inferência GGUF & Mídia</div>
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
            <label>GPU Layers (Camadas CUDA)</label>
            <input type="number" name="gpu_layers" value="{gpu_layers}">
          </div>
          <div class="field-group">
            <label>Contexto (Tokens)</label>
            <input type="number" name="context_size" value="{ctx_size}">
          </div>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Método Denoise LightCraft</label>
            <input name="denoise_default_method" value="{denoise_default_method}">
          </div>
          <div class="field-group">
            <label>Alvo LUFS SoundCraft</label>
            <input type="number" step="0.5" name="audio_target_lufs" value="{audio_target_lufs}">
          </div>
        </div>

        <div class="input-grid-2">
          <div class="field-group">
            <label>Perfil ICC Padrão</label>
            <input name="default_icc_profile" value="{default_icc_profile}">
          </div>
          <div class="field-group">
            <label>Porta HTTP Canônica</label>
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
          <label>Endpoint VPS Federado</label>
          <input name="vps_endpoint" value="{vps_endpoint}">
        </div>

      </div>

    </div>

    <!-- Barra Inferior de Ação Svitlo -->
    <div style="display:flex; justify-content:flex-end; gap:var(--fib-4); margin-top:var(--fib-5); padding-bottom:var(--fib-6); flex-wrap:wrap;">
      <button type="button" class="btn btn-secondary" style="color:var(--text-dark); border-color:var(--border-color);" onclick="testSvitloAi()">🧪 Testar Geração Svitlo + OpenAlex</button>
      <button type="button" class="btn btn-gold" id="btnSaveBottom" onclick="submitConfigForm(event)" style="padding: 13px 34px; font-size:15px;">💾 Salvar Todas as Configurações no zorya.toml</button>
    </div>
  </form>

  <!-- Modal / Drawer de Teste Svitlo -->
  <div id="testResultBox" class="panel-svitlo" style="display:none; border-color:var(--gold);">
    <div class="panel-header">
      <div class="panel-title">🔬 Diagnóstico de Inferência Svitlo + OpenAlex</div>
      <button type="button" class="btn btn-secondary" style="color:var(--text-dark); border-color:var(--border-color); padding:4px 8px; font-size:11px;" onclick="document.getElementById('testResultBox').style.display='none'">Fechar</button>
    </div>
    <pre id="testResultPre" style="font-family:var(--font-mono); font-size:12px; color:var(--text-dark); white-space:pre-wrap; max-height:360px; overflow-y:auto; margin:0;"></pre>
  </div>

</main>

<div id="toast">
  <span style="color:var(--gold); font-size:18px;">✓</span>
  <span id="toastMsg">Configurações gravadas atomicamente em zorya.toml!</span>
</div>

<script>
const rawQuotas = {quotas_json};
const openRouterStatus = {openrouter_status_json};
let keysRevealed = false;
let realKeysCache = null;
let maskedKeysCache = null;

function toggleSvitloTheme() {{
  const html = document.documentElement;
  const current = html.getAttribute('data-theme') || 'light';
  const next = current === 'light' ? 'dark' : 'light';
  html.setAttribute('data-theme', next);
  localStorage.setItem('svitlo_theme', next);
}}

function initTheme() {{
  const saved = localStorage.getItem('svitlo_theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
}}

function toggleCraftModule(cb) {{
  const card = document.getElementById('craft_card_' + cb.value);
  if (card) {{
    if (cb.checked) {{
      card.classList.add('active');
    }} else {{
      card.classList.remove('active');
    }}
  }}
  syncCraftHidden();
}}

function toggleAllCraft(check) {{
  document.querySelectorAll('.craft-checkbox').forEach(cb => {{
    cb.checked = check;
    const card = document.getElementById('craft_card_' + cb.value);
    if (card) {{
      if (check) card.classList.add('active');
      else card.classList.remove('active');
    }}
  }});
  syncCraftHidden();
}}

function syncCraftHidden() {{
  const selected = [];
  document.querySelectorAll('.craft-checkbox:checked').forEach(cb => {{
    selected.push(cb.value);
  }});
  document.getElementById('craftEnabledHidden').value = selected.join(',');
}}

async function toggleRevealKeys() {{
  const btn = document.getElementById('btnRevealKeys');
  if (!keysRevealed) {{
    btn.disabled = true;
    btn.textContent = '⏳ Carregando chaves...';
    try {{
      const res = await fetch('/api/v1/settings/keys');
      if (res.ok) {{
        const data = await res.json();
        realKeysCache = data.real_keys;
        maskedKeysCache = data.masked_keys;

        document.getElementById('groqKeys').value = (realKeysCache.groq_keys || []).join('\n');
        document.getElementById('geminiKeys').value = (realKeysCache.gemini_keys || []).join('\n');
        document.getElementById('openaiKeys').value = (realKeysCache.openai_keys || []).join('\n');
        document.getElementById('openrouterKeys').value = (realKeysCache.openrouter_keys || []).join('\n');

        keysRevealed = true;
        btn.textContent = '🙈 Ocultar Chaves Reais';
        showToast('Chaves reais exibidas no painel.');
      }} else {{
        alert('Não foi possível obter as chaves reais. Status: ' + res.status);
        btn.textContent = '👁️ Mostrar Chaves Reais';
      }}
    }} catch (e) {{
      alert('Erro ao carregar chaves: ' + e.message);
      btn.textContent = '👁️ Mostrar Chaves Reais';
    }} finally {{
      btn.disabled = false;
      updateKeyCounters();
    }}
  }} else {{
    if (maskedKeysCache) {{
      document.getElementById('groqKeys').value = (maskedKeysCache.groq_keys || []).join('\n');
      document.getElementById('geminiKeys').value = (maskedKeysCache.gemini_keys || []).join('\n');
      document.getElementById('openaiKeys').value = (maskedKeysCache.openai_keys || []).join('\n');
      document.getElementById('openrouterKeys').value = (maskedKeysCache.openrouter_keys || []).join('\n');
    }}
    keysRevealed = false;
    btn.textContent = '👁️ Mostrar Chaves Reais';
    showToast('Chaves mascaradas.');
    updateKeyCounters();
  }}
}}

function updateKeyCounters() {{
  ['groq', 'gemini', 'openai', 'openrouter'].forEach(p => {{
    const el = document.getElementById(p + 'Keys');
    const cntEl = document.getElementById(p + 'KeyCount');
    if (el) {{
      const lines = el.value.split('\n')
        .map(s => s.trim())
        .filter(s => s.length > 5 && !s.includes('...') && !s.includes('***'));
      if (cntEl) cntEl.textContent = lines.length + ' válidas';

      if (p === 'openrouter') {{
        const badge = document.getElementById('openrouterBadge');
        if (badge) {{
          if (lines.length > 0 || openRouterStatus.enabled) {{
            badge.textContent = `● ATIVO (${{lines.length || openRouterStatus.active_keys_count}} chaves)`;
            badge.style.color = 'var(--green)';
          }} else {{
            badge.textContent = '○ STANDBY';
            badge.style.color = 'var(--text-muted)';
          }}
        }}
      }}
    }}
  }});
}}

function renderQuotasTable() {{
  const tbody = document.getElementById('quotasTbody');
  if (!tbody || !Array.isArray(rawQuotas)) return;
  tbody.innerHTML = '';
  rawQuotas.forEach(q => {{
    const tr = document.createElement('tr');
    const rpdRem = q.rpd_remaining !== null && q.rpd_remaining !== undefined ? q.rpd_remaining : (q.rpd_limit ? (q.rpd_limit - q.rpd_current) : '∞');
    const tpmRem = q.tpm_remaining !== null && q.tpm_remaining !== undefined ? q.tpm_remaining.toLocaleString() : (q.tpm_limit ? (q.tpm_limit - q.tpm_current).toLocaleString() : '∞');

    tr.innerHTML = `
      <td style="color:var(--text-muted);">${{q.provider}}</td>
      <td style="color:var(--text-dark); font-weight:600;">${{q.model_name}}</td>
      <td><span class="badge-quota badge-ok">${{q.rpm_current}} / ${{q.rpm_limit || '∞'}}</span></td>
      <td><span class="badge-quota badge-ok">${{tpmRem}} rest.</span></td>
      <td><span class="badge-quota ${{rpdRem === 0 ? 'badge-warn' : 'badge-ok'}}">${{rpdRem}} rest.</span></td>
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

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 8000);

  try {{
    syncCraftHidden();
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    // Converte checkboxes booleanas explicitamente
    [
      'clear_groq_keys', 'clear_gemini_keys', 'clear_openai_keys', 'clear_openrouter_keys',
      'prefer_tailscale', 'reserve_display_on_igpu', 'enable_mcp_sse',
      'cluster_enabled', 'offload_light_tasks_to_vps', 'enable_p2p', 'openrouter_enabled'
    ].forEach(k => {{
      data[k] = form.elements[k] ? form.elements[k].checked : false;
    }});

    const res = await fetch('/api/v1/settings', {{
      method: 'POST',
      headers: {{
        'Content-Type': 'application/json',
        'X-Zorya-CSRF': csrfToken,
      }},
      body: JSON.stringify(data),
      signal: controller.signal
    }});
    clearTimeout(timeoutId);

    const out = await res.json();
    if (res.ok && out.status === 'ok') {{
      showToast('Configurações gravadas atomicamente em ' + (out.config_path || 'zorya.toml') + '!');
      updateKeyCounters();
    }} else {{
      alert('❌ Falha ao salvar configurações: ' + (out.message || out.error || 'Erro desconhecido'));
    }}
  }} catch (err) {{
    clearTimeout(timeoutId);
    if (err.name === 'AbortError') {{
      alert('❌ Timeout ao salvar configurações (limite de 8s excedido)');
    }} else {{
      alert('❌ Erro de conexão: ' + err.message);
    }}
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
  initTheme();
  updateKeyCounters();
  renderQuotasTable();
}});
</script>

</body>
</html>"#,
        port = cfg.server.port,
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
        groq_keys = groq_keys_str,
        gemini_keys = gemini_keys_str,
        openai_keys = openai_keys_str,
        openrouter_keys = openrouter_keys_str,
        groq_primary = cfg
            .credentials
            .groq_primary_model
            .as_deref()
            .unwrap_or("openai/gpt-oss-120b"),
        groq_fast = cfg
            .credentials
            .groq_fast_model
            .as_deref()
            .unwrap_or("openai/gpt-oss-20b"),
        groq_multi = cfg
            .credentials
            .groq_multilingual_model
            .as_deref()
            .unwrap_or("qwen/qwen3.8-27b"),
        groq_whisper = cfg
            .credentials
            .groq_whisper_model
            .as_deref()
            .unwrap_or("whisper-large-v3"),
        gemini_model = cfg
            .credentials
            .gemini_model
            .as_deref()
            .unwrap_or("gemini-2.5-flash"),
        openrouter_model = cfg.credentials.openrouter_default_model,
        craft_cards = craft_cards_html,
        craft_enabled = craft_enabled_str,
        checked_prefer_tailscale = if cfg.network.prefer_tailscale {
            "checked"
        } else {
            ""
        },
        checked_reserve_display = if cfg.hardware.reserve_display_on_igpu {
            "checked"
        } else {
            ""
        },
        checked_mcp_sse = if cfg.server.enable_mcp_sse {
            "checked"
        } else {
            ""
        },
        checked_cluster_enabled = if cfg.cluster.enabled { "checked" } else { "" },
        checked_offload_vps = if cfg.cluster.offload_light_tasks_to_vps {
            "checked"
        } else {
            ""
        },
        checked_enable_p2p = if cfg.cluster.enable_p2p {
            "checked"
        } else {
            ""
        },
        checked_openrouter_enabled = if cfg.credentials.openrouter_enabled {
            "checked"
        } else {
            ""
        },
        denoise_default_method = cfg
            .craft
            .denoise_default_method
            .as_deref()
            .unwrap_or("bilateral"),
        audio_target_lufs = cfg.craft.audio_target_lufs.unwrap_or(-14.0),
        default_icc_profile = cfg.craft.default_icc_profile.as_deref().unwrap_or("sRGB"),
        csrf_token = csrf_token,
        quotas_json = quotas_json,
        openrouter_status_json = openrouter_status_json,
    )
}
