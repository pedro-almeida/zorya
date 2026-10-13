// PT: Controlador de Interface Web Zorya v1.0.0
// EN: Zorya v1.0.0 Web Interface Controller

document.addEventListener('DOMContentLoaded', () => {
  // 1. TABS NAVIGATION
  const tabBtns = document.querySelectorAll('.tab-btn');
  const tabContents = document.querySelectorAll('.tab-content');

  tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.tab;
      tabBtns.forEach(b => b.classList.remove('active'));
      tabContents.forEach(c => c.classList.remove('active'));
      btn.classList.add('active');
      const targetEl = document.getElementById(`tab-${target}`);
      if (targetEl) targetEl.classList.add('active');
    });
  });

  // 2. SLIDERS LIVE VALUE UPDATES
  const cpuSlider = document.getElementById('cpu_cores');
  const cpuVal = document.getElementById('cpu_cores_val');
  const vramSlider = document.getElementById('vram_limit_gb');
  const vramVal = document.getElementById('vram_limit_val');
  const ramSlider = document.getElementById('ram_limit_gb');
  const ramVal = document.getElementById('ram_limit_val');

  if (cpuSlider && cpuVal) {
    cpuSlider.addEventListener('input', () => {
      cpuVal.textContent = `${cpuSlider.value} núcleos`;
    });
  }

  if (vramSlider && vramVal) {
    vramSlider.addEventListener('input', () => {
      vramVal.textContent = `${parseFloat(vramSlider.value).toFixed(1)} GB`;
    });
  }

  if (ramSlider && ramVal) {
    ramSlider.addEventListener('input', () => {
      ramVal.textContent = `${parseFloat(ramSlider.value).toFixed(1)} GB`;
    });
  }

  // 3. GPU TOGGLE VISIBILITY
  const gpuToggle = document.getElementById('gpu_enabled');
  const vramGroup = document.getElementById('vram-group');
  if (gpuToggle && vramGroup) {
    gpuToggle.addEventListener('change', () => {
      vramGroup.style.opacity = gpuToggle.checked ? '1' : '0.4';
      vramGroup.style.pointerEvents = gpuToggle.checked ? 'auto' : 'none';
    });
  }

  // 4. PASSWORD REVEAL BUTTONS
  document.querySelectorAll('.reveal-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.dataset.target;
      const input = document.getElementById(targetId);
      if (input) {
        if (input.type === 'password') {
          input.type = 'text';
          btn.innerHTML = '&#128584;';
        } else {
          input.type = 'password';
          btn.innerHTML = '&#128065;';
        }
      }
    });
  });

  // 5. COPY TO CLIPBOARD BUTTONS
  document.querySelectorAll('.copy-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const targetId = btn.dataset.copy;
      const input = document.getElementById(targetId);
      if (input) {
        try {
          await navigator.clipboard.writeText(input.value);
          const orig = btn.textContent;
          btn.textContent = 'Copiado!';
          btn.style.backgroundColor = '#E8F5E9';
          btn.style.color = '#2E7D32';
          setTimeout(() => {
            btn.textContent = orig;
            btn.style.backgroundColor = '';
            btn.style.color = '';
          }, 2000);
        } catch (err) {
          input.select();
          document.execCommand('copy');
        }
      }
    });
  });

  // 6. LIVE TELEMETRY POLLING
  async function fetchTelemetry() {
    try {
      const res = await fetch('/api/v1/system/info');
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();

      // Status Pill
      const statusLabel = document.getElementById('status-label');
      if (statusLabel) statusLabel.textContent = 'Zorya Operacional';

      // Telemetry Bar
      if (data.cpu) {
        const teleCpuModel = document.getElementById('tele-cpu-model');
        const teleCpuLoad = document.getElementById('tele-cpu-load');
        if (teleCpuModel) teleCpuModel.textContent = data.cpu.model || 'CPU Multithread';
        if (teleCpuLoad) teleCpuLoad.textContent = `${data.cpu.usage_percent.toFixed(1)}% em uso (${data.cpu.total_cores} cores)`;

        // Adjust slider max if not set
        if (cpuSlider && data.cpu.total_cores > 0) {
          cpuSlider.max = data.cpu.total_cores;
        }
      }

      if (data.ram) {
        const teleRamStat = document.getElementById('tele-ram-stat');
        const teleRamSub = document.getElementById('tele-ram-sub');
        if (teleRamStat) teleRamStat.textContent = `${(data.ram.total_gb - data.ram.available_gb).toFixed(1)} GB / ${data.ram.total_gb.toFixed(1)} GB`;
        if (teleRamSub) teleRamSub.textContent = `Livre: ${data.ram.available_gb.toFixed(1)} GB (${data.ram.usage_percent.toFixed(1)}% ocupada)`;
      }

      if (data.gpu) {
        const teleGpuStat = document.getElementById('tele-gpu-stat');
        const teleGpuTemp = document.getElementById('tele-gpu-temp');
        if (teleGpuStat) teleGpuStat.textContent = data.gpu.type || 'CPU Fallback';
        if (teleGpuTemp) teleGpuTemp.textContent = `${data.gpu.temperature_c.toFixed(0)}°C | VRAM Teto: ${data.gpu.vram_limit_gb || 4.0} GB`;
      }

      if (data.tailscale) {
        const teleTsIp = document.getElementById('tele-ts-ip');
        const teleTsStatus = document.getElementById('tele-ts-status');
        if (teleTsIp) teleTsIp.textContent = data.tailscale.ip || 'Não detectado';
        if (teleTsStatus) {
          teleTsStatus.textContent = data.tailscale.connected ? 'Malha Tailscale Conectada' : 'Localhost apenas';
          teleTsStatus.style.color = data.tailscale.connected ? '#2E7D32' : '#9A8C80';
        }
      }

      // Update node URL display
      const urlDisplay = document.getElementById('node_url_display');
      if (urlDisplay && data.node_url) {
        urlDisplay.value = data.node_url;
      }

      // Update 2-Stage Queues Telemetry
      if (data.pipeline && data.pipeline.stats) {
        const stats = data.pipeline.stats;
        const q1Total = document.getElementById('q1-total');
        const q1App = document.getElementById('q1-approved');
        const q1Rev = document.getElementById('q1-review');
        const q2Jobs = document.getElementById('q2-jobs');
        const q2Mast = document.getElementById('q2-mastered');

        if (q1Total) q1Total.textContent = stats.ingest_photos_total || 0;
        if (q1App) q1App.textContent = stats.ingest_approved_total || 0;
        if (q1Rev) q1Rev.textContent = ((stats.ingest_review_total || 0) + (stats.ingest_rejected_total || 0)) || 0;
        if (q2Jobs) q2Jobs.textContent = stats.master_jobs_count || 0;
        if (q2Mast) q2Mast.textContent = stats.master_success_total || 0;
      }

    } catch (err) {
      const statusLabel = document.getElementById('status-label');
      if (statusLabel) statusLabel.textContent = 'Reconectando...';
    }
  }

  // State for Custom OpenAI-compatible providers
  let customProviders = [];
  let currentActiveProvider = 'gemini';

  function renderCustomProviders() {
    const listEl = document.getElementById('custom-providers-list');
    const activeSelect = document.getElementById('active_ai_provider');
    if (!listEl) return;

    listEl.innerHTML = '';

    // Rebuild active provider select options
    if (activeSelect) {
      activeSelect.innerHTML = `
        <option value="gemini" ${currentActiveProvider === 'gemini' ? 'selected' : ''}>Google Gemini (Visão Multimodal Nativa)</option>
        <option value="groq" ${currentActiveProvider === 'groq' ? 'selected' : ''}>Groq (LLaMA / Mixtral)</option>
      `;
      customProviders.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = `${p.name || 'Provedor OpenAI'} (${p.model || 'OpenAI Compat'})`;
        if (currentActiveProvider === p.id) {
          opt.selected = true;
        }
        activeSelect.appendChild(opt);
      });
    }

    if (customProviders.length === 0) {
      listEl.innerHTML = `
        <div style="font-size: 12px; color: var(--text-muted); font-style: italic; padding: 10px; background: var(--bg-card-subtle); border-radius: var(--radius-sm); border: 1px dashed var(--border-light); text-align: center;">
          Nenhum provedor customizado configurado. Clique acima para adicionar DeepSeek, OpenRouter ou outros compatíveis.
        </div>
      `;
      return;
    }

    customProviders.forEach((prov, idx) => {
      const isSelected = (currentActiveProvider === prov.id);
      const card = document.createElement('div');
      card.className = `provider-card ${isSelected ? 'is-active-provider' : ''}`;
      card.dataset.id = prov.id;

      card.innerHTML = `
        <div class="provider-card-header">
          <label class="provider-radio-label">
            <input type="radio" name="active_custom_radio" value="${prov.id}" ${isSelected ? 'checked' : ''}>
            <span>${prov.name || 'Provedor ' + (idx + 1)}</span>
            ${isSelected ? '<span class="ai-key-badge badge-active" style="font-size: 10px;">Ativo na Triagem</span>' : ''}
          </label>
          <button type="button" class="btn-danger-sm btn-remove-provider" data-id="${prov.id}" title="Excluir este provedor">Excluir</button>
        </div>
        <div class="provider-fields-grid">
          <div class="form-group" style="margin: 0;">
            <label style="font-size: 11px; margin-bottom: 2px;">Nome Amigável</label>
            <input type="text" class="prov-field-name" value="${escapeHtml(prov.name || '')}" placeholder="ex: DeepSeek V3">
          </div>
          <div class="form-group" style="margin: 0;">
            <label style="font-size: 11px; margin-bottom: 2px;">Base URL</label>
            <input type="text" class="prov-field-baseurl" value="${escapeHtml(prov.base_url || '')}" placeholder="https://api.deepseek.com/v1">
          </div>
          <div class="form-group" style="margin: 0;">
            <label style="font-size: 11px; margin-bottom: 2px;">Chave de API</label>
            <div class="input-with-action">
              <input type="password" class="prov-field-apikey" value="${escapeHtml(prov.api_key || '')}" placeholder="sk-...">
              <button type="button" class="btn-icon reveal-btn" title="Mostrar/Ocultar">&#128065;</button>
            </div>
          </div>
          <div class="form-group" style="margin: 0;">
            <label style="font-size: 11px; margin-bottom: 2px;">Modelo</label>
            <input type="text" class="prov-field-model" value="${escapeHtml(prov.model || '')}" placeholder="ex: deepseek-chat">
          </div>
        </div>
      `;

      listEl.appendChild(card);
    });

    // Attach listeners
    listEl.querySelectorAll('input[name="active_custom_radio"]').forEach(radio => {
      radio.addEventListener('change', (e) => {
        currentActiveProvider = e.target.value;
        if (activeSelect) activeSelect.value = currentActiveProvider;
        renderCustomProviders();
      });
    });

    listEl.querySelectorAll('.btn-remove-provider').forEach(btn => {
      btn.addEventListener('click', () => {
        const idToRemove = btn.dataset.id;
        customProviders = customProviders.filter(p => p.id !== idToRemove);
        if (currentActiveProvider === idToRemove) {
          currentActiveProvider = 'gemini';
        }
        renderCustomProviders();
      });
    });

    listEl.querySelectorAll('.reveal-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const inp = btn.parentElement.querySelector('input');
        if (inp) {
          inp.type = inp.type === 'password' ? 'text' : 'password';
          btn.innerHTML = inp.type === 'password' ? '&#128065;' : '&#128584;';
        }
      });
    });

    listEl.querySelectorAll('.prov-field-name').forEach(inp => {
      inp.addEventListener('input', (e) => {
        const pId = e.target.closest('.provider-card').dataset.id;
        const p = customProviders.find(x => x.id === pId);
        if (p) p.name = e.target.value;
      });
    });
    listEl.querySelectorAll('.prov-field-baseurl').forEach(inp => {
      inp.addEventListener('input', (e) => {
        const pId = e.target.closest('.provider-card').dataset.id;
        const p = customProviders.find(x => x.id === pId);
        if (p) p.base_url = e.target.value;
      });
    });
    listEl.querySelectorAll('.prov-field-apikey').forEach(inp => {
      inp.addEventListener('input', (e) => {
        const pId = e.target.closest('.provider-card').dataset.id;
        const p = customProviders.find(x => x.id === pId);
        if (p) p.api_key = e.target.value;
      });
    });
    listEl.querySelectorAll('.prov-field-model').forEach(inp => {
      inp.addEventListener('input', (e) => {
        const pId = e.target.closest('.provider-card').dataset.id;
        const p = customProviders.find(x => x.id === pId);
        if (p) p.model = e.target.value;
      });
    });
  }

  function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // Active provider select event listener
  const activeProvSelect = document.getElementById('active_ai_provider');
  if (activeProvSelect) {
    activeProvSelect.addEventListener('change', (e) => {
      currentActiveProvider = e.target.value;
      renderCustomProviders();
    });
  }

  // Add provider button
  const addProvBtn = document.getElementById('btn-add-provider');
  if (addProvBtn) {
    addProvBtn.addEventListener('click', () => {
      const newId = 'provider_' + Math.random().toString(36).substring(2, 9);
      customProviders.push({
        id: newId,
        name: 'DeepSeek V3',
        base_url: 'https://api.deepseek.com/v1',
        api_key: '',
        model: 'deepseek-chat',
        is_active: false
      });
      renderCustomProviders();
    });
  }

  // Initial load of saved config values
  async function loadInitialConfig() {
    try {
      const res = await fetch('/api/v1/system/info');
      if (!res.ok) return;
      const data = await res.json();

      if (data.cpu && data.cpu.allocated_cores) {
        cpuSlider.value = data.cpu.allocated_cores;
        cpuVal.textContent = `${data.cpu.allocated_cores} núcleos`;
      }

      if (data.gpu) {
        gpuToggle.checked = data.gpu.enabled !== false;
        vramSlider.value = data.gpu.vram_limit_gb || 4.0;
        vramVal.textContent = `${parseFloat(vramSlider.value).toFixed(1)} GB`;
      }

      if (data.ram && data.ram.allocated_limit_gb) {
        ramSlider.value = data.ram.allocated_limit_gb;
        ramVal.textContent = `${parseFloat(ramSlider.value).toFixed(1)} GB`;
      }

      if (data.tailscale && data.tailscale.listen_mode) {
        const listenSelect = document.getElementById('listen_mode');
        if (listenSelect) listenSelect.value = data.tailscale.listen_mode;
      }

      // Process AI Telemetry
      if (data.ai) {
        const geminiInput = document.getElementById('gemini_api_key');
        const geminiBadge = document.getElementById('gemini_status_badge');
        if (data.ai.gemini_configured) {
          if (geminiInput) geminiInput.value = data.ai.gemini_masked_key || '••••••••';
          if (geminiBadge) {
            geminiBadge.textContent = '✓ Chave Ativa e Gravada';
            geminiBadge.className = 'ai-key-badge badge-active';
            geminiBadge.style.display = 'inline-flex';
          }
        } else {
          if (geminiBadge) {
            geminiBadge.textContent = 'Não configurada';
            geminiBadge.className = 'ai-key-badge badge-missing';
            geminiBadge.style.display = 'inline-flex';
          }
        }

        const groqInput = document.getElementById('groq_api_key');
        const groqBadge = document.getElementById('groq_status_badge');
        if (data.ai.groq_configured) {
          if (groqInput) groqInput.value = data.ai.groq_masked_key || '••••••••';
          if (groqBadge) {
            groqBadge.textContent = '✓ Chave Ativa e Gravada';
            groqBadge.className = 'ai-key-badge badge-active';
            groqBadge.style.display = 'inline-flex';
          }
        } else {
          if (groqBadge) {
            groqBadge.textContent = 'Opcional';
            groqBadge.className = 'ai-key-badge badge-missing';
            groqBadge.style.display = 'inline-flex';
          }
        }

        if (data.ai.active_provider) {
          currentActiveProvider = data.ai.active_provider;
        }

        if (Array.isArray(data.ai.custom_providers)) {
          customProviders = data.ai.custom_providers;
        }

        renderCustomProviders();
      }

    } catch (e) {
      console.warn('Initial config fetch failed:', e);
    }
  }

  loadInitialConfig();
  fetchTelemetry();
  setInterval(fetchTelemetry, 2500);

  // 7. SAVE CONFIGURATION
  const saveBtn = document.getElementById('save-btn');
  const saveToast = document.getElementById('save-status');

  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      saveBtn.disabled = true;
      saveBtn.querySelector('.btn-text').textContent = 'Gravando...';
      saveToast.className = 'save-toast';
      saveToast.textContent = '';

      // Prepare custom providers with is_active flag
      const serializedCustomProviders = customProviders.map(p => ({
        id: p.id,
        name: p.name || 'Provedor OpenAI',
        base_url: p.base_url || '',
        api_key: p.api_key || '',
        model: p.model || '',
        is_active: (p.id === currentActiveProvider)
      }));

      const payload = {
        cpu_cores: parseInt(cpuSlider.value, 10),
        gpu_enabled: gpuToggle.checked,
        vram_limit_gb: parseFloat(vramSlider.value),
        ram_limit_gb: parseFloat(ramSlider.value),
        gemini_api_key: document.getElementById('gemini_api_key').value.trim(),
        groq_api_key: document.getElementById('groq_api_key').value.trim(),
        active_provider: currentActiveProvider,
        custom_providers: serializedCustomProviders,
        listen_mode: document.getElementById('listen_mode').value,
        auth_token: document.getElementById('auth_token').value.trim(),
        autostart_windows: document.getElementById('autostart_windows').checked
      };

      try {
        const res = await fetch('/api/v1/config/save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });

        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const result = await res.json();

        saveToast.className = 'save-toast success';
        saveToast.textContent = 'Configurações salvas e aplicadas com sucesso!';
        setTimeout(() => { saveToast.textContent = ''; }, 4000);
      } catch (err) {
        saveToast.className = 'save-toast error';
        saveToast.textContent = `Erro ao salvar: ${err.message}`;
      } finally {
        saveBtn.disabled = false;
        saveBtn.querySelector('.btn-text').textContent = 'Salvar e Aplicar Imediatamente';
        fetchTelemetry();
        loadInitialConfig();
      }
    });
  }
});
