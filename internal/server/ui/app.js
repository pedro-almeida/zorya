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

    } catch (err) {
      const statusLabel = document.getElementById('status-label');
      if (statusLabel) statusLabel.textContent = 'Reconectando...';
    }
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

      const payload = {
        cpu_cores: parseInt(cpuSlider.value, 10),
        gpu_enabled: gpuToggle.checked,
        vram_limit_gb: parseFloat(vramSlider.value),
        ram_limit_gb: parseFloat(ramSlider.value),
        gemini_api_key: document.getElementById('gemini_api_key').value.trim(),
        groq_api_key: document.getElementById('groq_api_key').value.trim(),
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
      }
    });
  }
});
