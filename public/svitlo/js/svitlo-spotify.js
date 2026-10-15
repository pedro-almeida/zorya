/**
 * svitlo-spotify.js — Controle de Playlists de Estudo e Player Spotify
 * Mriya Ecosystem — Espectro Ipê Soluções Tecnológicas
 *
 * // PT: Controlador do Spotify Study Player — reprodução de playlists focadas (Lo-Fi, Deep Focus,
 * //     Ondas Binaurais, Synthwave, Clássico, Brown Noise) integradas ao timer Pomodoro e Estudo Ativo.
 * // EN: Spotify Study Player Controller — plays focused playlists integrated with Pomodoro timer & Active Study.
 * // AI: @llm-context Depende de svitlo-core.js (API_BASE, getToken, showToast).
 * //     Conecta com endpoints /svitlo/spotify/player/* e /svitlo/spotify/playlists/study.
 */

'use strict';

const SPOTIFY_STUDY_MODES = [
    {
        id: 'lofi',
        name: 'Lo-Fi Beats',
        icon: '🎧',
        description: 'Batidas calmas para estudo sem distração vocal',
        uri: 'spotify:playlist:37i9dQZF1DX8Uebhn9wzrS',
        url: 'https://open.spotify.com/playlist/37i9dQZF1DX8Uebhn9wzrS'
    },
    {
        id: 'deep_focus',
        name: 'Hiperfoco',
        icon: '🧠',
        description: 'Piano suave e texturas ambientais para foco profundo',
        uri: 'spotify:playlist:37i9dQZF1DWZeKCadgRdKQ',
        url: 'https://open.spotify.com/playlist/37i9dQZF1DWZeKCadgRdKQ'
    },
    {
        id: 'binaural',
        name: 'Ondas Binaurais',
        icon: '🌊',
        description: 'Frequências Alpha/Theta para induzir concentração',
        uri: 'spotify:playlist:37i9dQZF1DX4WYpdgoIcn6',
        url: 'https://open.spotify.com/playlist/37i9dQZF1DX4WYpdgoIcn6'
    },
    {
        id: 'synthwave',
        name: 'Synthwave',
        icon: '⚡',
        description: 'Ritmo contínuo para código e raciocínio lógico',
        uri: 'spotify:playlist:37i9dQZF1DXdLEN7aqioXM',
        url: 'https://open.spotify.com/playlist/37i9dQZF1DXdLEN7aqioXM'
    },
    {
        id: 'classical',
        name: 'Clássico / Barroco',
        icon: '🎻',
        description: 'Música erudita estruturada para leitura densa',
        uri: 'spotify:playlist:37i9dQZF1DWVfe3b7ghwOa',
        url: 'https://open.spotify.com/playlist/37i9dQZF1DWVfe3b7ghwOa'
    },
    {
        id: 'brown_noise',
        name: 'Ruído Marrom',
        icon: '📻',
        description: 'Bloqueio de ruído e alívio para TDAH / TEA',
        uri: 'spotify:playlist:37i9dQZF1DX8b3uXlsH35C',
        url: 'https://open.spotify.com/playlist/37i9dQZF1DX8b3uXlsH35C'
    }
];

const _spotifyState = {
    connected: false,
    isPlaying: false,
    activeMode: localStorage.getItem('svitlo_spotify_study_mode') || 'lofi',
    syncWithPomodoro: localStorage.getItem('svitlo_spotify_pomo_sync') !== 'false',
    track: null,
    device: null,
    pollTimer: null,
    lastStatusCheck: 0,
    isChecking: false
};

// PT: Inicializa o controlador do Spotify e escuta eventos de foco da janela
// EN: Initializes Spotify controller and listens to window focus events
// PT: Inicializa o módulo ou componente de spotify study player
// EN: Initializes the spotify study player module or component
// AI: @llm-context Sets up event listeners and initial DOM/state for spotify study player
function initSpotifyStudyPlayer() {
    renderPomodoroSpotifyWidget();
    checkSpotifyStatus();
    
    // Atualiza status ao retornar à aba
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && _spotifyState.connected) {
            checkSpotifyStatus();
        }
    });

    // Inicia polling periódico se conectado
    if (!_spotifyState.pollTimer) {
        _spotifyState.pollTimer = setInterval(() => {
            if (_spotifyState.connected && (_spotifyState.isPlaying || window.currentView === 'pomodoro' || window.currentView === 'estudo')) {
                checkSpotifyStatus(true);
            }
        }, 5000);
    }
}

// PT: Consulta o backend para obter o estado de conexão e reprodução do Spotify
// EN: Queries backend for Spotify connection and playback state
// AI: @llm-context GET /api/v1/svitlo/spotify/player/status
async function checkSpotifyStatus(silent = false) {
    if (typeof getToken !== 'function' || !getToken()) return;
    if (_spotifyState.isChecking) return;
    _spotifyState.isChecking = true;

    try {
        const res = await fetch(`${API_BASE}/svitlo/spotify/player/status`, {
            headers: { 'Authorization': 'Bearer ' + getToken() }
        });
        
        if (!res.ok) {
            if (res.status === 401) _spotifyState.connected = false;
            _spotifyState.isChecking = false;
            if (typeof updateConnectionUI === 'function') {
                updateConnectionUI('spotify', '', _spotifyState.connected);
            }
            renderSpotifyWidgets();
            return;
        }

        const data = await res.json();
        _spotifyState.connected = !!data.connected;
        _spotifyState.isPlaying = !!data.is_playing;
        _spotifyState.device = data.device || null;
        _spotifyState.track = data.track || null;
        _spotifyState.lastStatusCheck = Date.now();

        if (typeof updateConnectionUI === 'function') {
            updateConnectionUI('spotify', data.device?.name || (data.connected ? 'Conectado' : ''), _spotifyState.connected);
            const accInfo = document.getElementById('spotify-account-info');
            if (accInfo && _spotifyState.connected) {
                accInfo.innerHTML = `<span>✓</span> Conectado: <strong>${escapeHtml(data.device?.name || 'Spotify Web')}</strong>`;
            }
        }

        renderSpotifyWidgets();
    } catch (e) {
        if (!silent) console.warn('[Svitlo Spotify] Status check error:', e);
    } finally {
        _spotifyState.isChecking = false;
    }
}

// PT: Formata milissegundos para formato MM:SS
// EN: Formats milliseconds into MM:SS format
function _formatMs(ms) {
    if (!ms || isNaN(ms) || ms < 0) return '0:00';
    const totalSec = Math.floor(ms / 1000);
    const m = Math.floor(totalSec / 60);
    const s = totalSec % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
}

// PT: Ajusta o volume do player Spotify do usuário autenticado (0 a 100)
// EN: Sets user's Spotify player volume (0 to 100)
// AI: @llm-context POST /api/v1/svitlo/spotify/player/volume
async function setSpotifyVolume(percent) {
    const vol = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
    if (_spotifyState.device) _spotifyState.device.volume_percent = vol;
    try {
        await fetch(`${API_BASE}/svitlo/spotify/player/volume`, {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getToken(),
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ volume_percent: vol })
        });
    } catch (e) {
        console.warn('[Svitlo Spotify] Volume adjustment error:', e);
    }
}

// PT: Salta para uma posição específica (seek) na faixa do Spotify
// EN: Seeks to specific position in currently playing Spotify track
// AI: @llm-context POST /api/v1/svitlo/spotify/player/seek
async function seekSpotifyTrack(positionMs) {
    const pos = Math.max(0, parseInt(positionMs, 10) || 0);
    if (_spotifyState.track) _spotifyState.track.progress_ms = pos;
    try {
        await fetch(`${API_BASE}/svitlo/spotify/player/seek`, {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getToken(),
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ position_ms: pos })
        });
        setTimeout(() => checkSpotifyStatus(true), 400);
    } catch (e) {
        console.warn('[Svitlo Spotify] Seek error:', e);
    }
}

// PT: Inicia a reprodução de um modo de estudo no Spotify
// EN: Starts study mode playback on Spotify
// AI: @llm-context POST /api/v1/svitlo/spotify/player/play
async function playSpotifyStudy(modeId = null) {
    if (modeId) {
        _spotifyState.activeMode = modeId;
        localStorage.setItem('svitlo_spotify_study_mode', modeId);
    }
    const mode = _spotifyState.activeMode || 'lofi';
    const modeObj = SPOTIFY_STUDY_MODES.find(m => m.id === mode) || SPOTIFY_STUDY_MODES[0];

    try {
        const res = await fetch(`${API_BASE}/svitlo/spotify/player/play`, {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + getToken(),
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ mode: mode })
        });

        const data = await res.json();

        if (data.status === 'playing') {
            _spotifyState.isPlaying = true;
            _spotifyState.connected = true;
            showToast(`🎵 Tocando: ${modeObj.icon} ${modeObj.name}`, 'success');
            setTimeout(checkSpotifyStatus, 800);
        } else if (data.status === 'no_device') {
            _spotifyState.isPlaying = false;
            showSpotifyNoDeviceModal(modeObj);
        } else if (data.error) {
            showToast(data.error, 'error');
        }
        renderSpotifyWidgets();
    } catch (e) {
        showToast('Erro ao comunicar com Spotify', 'error');
    }
}

// PT: Pausa a reprodução no Spotify
// EN: Pauses Spotify playback
// AI: @llm-context POST /api/v1/svitlo/spotify/player/pause
async function pauseSpotifyStudy() {
    try {
        const res = await fetch(`${API_BASE}/svitlo/spotify/player/pause`, {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + getToken() }
        });
        const data = await res.json();
        if (data.status === 'paused') {
            _spotifyState.isPlaying = false;
            renderSpotifyWidgets();
            setTimeout(checkSpotifyStatus, 500);
        }
    } catch (e) {
        console.warn('[Svitlo Spotify] Pause error:', e);
    }
}

// PT: Alterna entre reproduzir e pausar a playlist de estudo
// EN: Toggles between playing and pausing study playlist
// PT: Alterna o estado de ativação de spotify study
// EN: Toggles active state of spotify study
// AI: @llm-context Switches boolean/UI state for spotify study
function toggleSpotifyStudy(modeId = null) {
    if (modeId && modeId !== _spotifyState.activeMode) {
        playSpotifyStudy(modeId);
        return;
    }
    if (_spotifyState.isPlaying) {
        pauseSpotifyStudy();
    } else {
        playSpotifyStudy(modeId || _spotifyState.activeMode);
    }
}

// PT: Pula para a próxima faixa no Spotify
// EN: Skips to next track on Spotify
// PT: Controla integração de estudo e música com o Spotify (next spotify track)
// EN: Controls Spotify study and music integration (next spotify track)
// AI: @llm-context Spotify Connect / Web API helper for nextSpotifyTrack
async function nextSpotifyTrack() {
    try {
        await fetch(`${API_BASE}/svitlo/spotify/player/next`, {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + getToken() }
        });
        setTimeout(checkSpotifyStatus, 600);
    } catch (e) {}
}

// PT: Volta para a faixa anterior no Spotify
// EN: Skips to previous track on Spotify
// PT: Controla integração de estudo e música com o Spotify (previous spotify track)
// EN: Controls Spotify study and music integration (previous spotify track)
// AI: @llm-context Spotify Connect / Web API helper for previousSpotifyTrack
async function previousSpotifyTrack() {
    try {
        await fetch(`${API_BASE}/svitlo/spotify/player/previous`, {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + getToken() }
        });
        setTimeout(checkSpotifyStatus, 600);
    } catch (e) {}
}

// PT: Define o modo cognitivo de estudo ativo
// EN: Sets active cognitive study mode
// PT: Controla integração de estudo e música com o Spotify (set spotify study mode)
// EN: Controls Spotify study and music integration (set spotify study mode)
// AI: @llm-context Spotify Connect / Web API helper for setSpotifyStudyMode
function setSpotifyStudyMode(modeId) {
    _spotifyState.activeMode = modeId;
    localStorage.setItem('svitlo_spotify_study_mode', modeId);
    if (_spotifyState.isPlaying) {
        playSpotifyStudy(modeId);
    } else {
        renderSpotifyWidgets();
    }
}

// PT: Alterna sincronização automática com o timer Pomodoro
// EN: Toggles automatic sync with Pomodoro timer
// PT: Alterna o estado de ativação de spotify pomodoro sync
// EN: Toggles active state of spotify pomodoro sync
// AI: @llm-context Switches boolean/UI state for spotify pomodoro sync
function toggleSpotifyPomodoroSync(checked) {
    _spotifyState.syncWithPomodoro = checked;
    localStorage.setItem('svitlo_spotify_pomo_sync', checked ? 'true' : 'false');
    showToast(checked ? '⚡ Sincronização com Pomodoro ativada' : '⏸️ Sincronização com Pomodoro desativada', 'info');
    renderSpotifyWidgets();
}

// PT: Hook chamado ao iniciar uma sessão de foco no Pomodoro
// EN: Hook called when a Pomodoro focus session starts
// PT: Trata evento de pomodoro start playback na interface
// EN: Handles pomodoro start playback UI event
// AI: @llm-context Event handler for user interaction on pomodoro start playback
function onPomodoroStartPlayback() {
    if (_spotifyState.connected && _spotifyState.syncWithPomodoro && !_spotifyState.isPlaying) {
        playSpotifyStudy(_spotifyState.activeMode);
    }
}

// PT: Hook chamado ao pausar o timer Pomodoro
// EN: Hook called when Pomodoro timer is paused
// PT: Trata evento de pomodoro pause playback na interface
// EN: Handles pomodoro pause playback UI event
// AI: @llm-context Event handler for user interaction on pomodoro pause playback
function onPomodoroPausePlayback() {
    if (_spotifyState.connected && _spotifyState.syncWithPomodoro && _spotifyState.isPlaying) {
        pauseSpotifyStudy();
    }
}

// PT: Hook chamado quando o Pomodoro entra em intervalo (pausa curta ou longa)
// EN: Hook called when Pomodoro enters break interval
// PT: Trata evento de pomodoro break playback na interface
// EN: Handles pomodoro break playback UI event
// AI: @llm-context Event handler for user interaction on pomodoro break playback
function onPomodoroBreakPlayback() {
    if (_spotifyState.connected && _spotifyState.syncWithPomodoro && _spotifyState.isPlaying) {
        pauseSpotifyStudy();
    }
}

// PT: Modal informativo caso nenhum player do Spotify esteja ativo
// EN: Helper modal when no active Spotify device is found
// PT: Abre ou exibe spotify no device modal na interface
// EN: Opens or displays spotify no device modal in the UI
// AI: @llm-context Toggles visibility/modal display for spotify no device modal
function showSpotifyNoDeviceModal(modeObj) {
    let modal = document.getElementById('modal-spotify-no-device');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modal-spotify-no-device';
        modal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(44,24,16,0.6);z-index:10000;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(4px);';
        document.body.appendChild(modal);
    }

    modal.innerHTML = `
      <div style="background:#FAF7F2;border-radius:14px;padding:28px;max-width:480px;width:100%;box-shadow:0 16px 40px rgba(0,0,0,0.25);border:1px solid var(--border-color);text-align:center;">
        <div style="font-size:38px;margin-bottom:12px;">📱🎧</div>
        <h3 style="font-family:'Cormorant Garamond',serif;font-size:24px;color:var(--brown-dark);margin:0 0 10px;">Spotify em Espera</h3>
        <p style="font-size:13.5px;color:var(--text-muted);line-height:1.6;margin-bottom:20px;">
          O Spotify exige que um aplicativo (Desktop, Celular ou Web) esteja aberto para receber comandos remotos.
        </p>
        <div style="background:var(--cream);padding:12px 16px;border-radius:8px;margin-bottom:24px;display:flex;align-items:center;gap:12px;text-align:left;">
          <span style="font-size:24px;">${modeObj.icon}</span>
          <div>
            <div style="font-weight:700;font-size:13px;color:var(--brown-dark);">${modeObj.name}</div>
            <div style="font-size:11px;color:var(--text-muted);">${modeObj.description}</div>
          </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
          <a href="${modeObj.url}" target="_blank" rel="noopener" class="btn-primary" style="padding:10px 18px;font-size:12px;text-decoration:none;" onclick="document.getElementById('modal-spotify-no-device').style.display='none'">
            🌐 Abrir no Spotify Web
          </a>
          <button onclick="document.getElementById('modal-spotify-no-device').style.display='none'" class="btn-ghost" style="padding:10px 18px;font-size:12px;">
            Fechar
          </button>
        </div>
      </div>
    `;
    modal.style.display = 'flex';
}

// PT: Renderiza os componentes de UI do Spotify em todas as views
// EN: Renders Spotify UI components across views
// PT: Renderiza visualmente spotify widgets no DOM
// EN: Renders spotify widgets in the DOM
// AI: @llm-context Generates and updates HTML markup for spotify widgets
function renderSpotifyWidgets() {
    renderPomodoroSpotifyWidget();
    renderStudyCompactSpotifyWidget();
    renderFloatingSpotifyPill();
    renderFloatingPomodoroSpotify();
}

// PT: Renderiza a seção de música de estudo dentro do Pop-up Flutuante do Pomodoro
// EN: Renders study music section inside the Floating Pomodoro Pop-up
// PT: Renderiza visualmente floating pomodoro spotify no DOM
// EN: Renders floating pomodoro spotify in the DOM
// AI: @llm-context Generates and updates HTML markup for floating pomodoro spotify
function renderFloatingPomodoroSpotify() {
    const sec = document.getElementById('floating-pomo-spotify-section');
    if (!sec) return;

    if (!_spotifyState.connected) {
        sec.innerHTML = `
          <div style="border-top:1px solid var(--border-color);padding-top:10px;text-align:center;">
            <button onclick="typeof connectSpotify==='function'?connectSpotify():showView('configuracoes')" style="padding:4px 10px;font-size:11px;background:#1DB954;color:#fff;border:none;border-radius:20px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
              <span>🟢</span> Conectar Spotify
            </button>
          </div>
        `;
        return;
    }

    const isPlaying = _spotifyState.isPlaying;
    const currentMode = SPOTIFY_STUDY_MODES.find(m => m.id === _spotifyState.activeMode) || SPOTIFY_STUDY_MODES[0];

    sec.innerHTML = `
      <div style="border-top:1px solid var(--border-color);padding-top:10px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <div style="display:flex;align-items:center;gap:5px;">
            <span style="font-size:13px;">${isPlaying ? '🎵' : '🎧'}</span>
            <span style="font-size:11px;font-weight:700;color:var(--brown-dark);">Música de Estudo:</span>
          </div>
          <button id="floating-spotify-toggle-btn" onclick="toggleSpotifyStudy()" style="padding:3px 10px;font-size:11px;background:var(--brown-dark);color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600;">
            ${isPlaying ? '⏸ Pausar' : '▶ Tocar'}
          </button>
        </div>
        <select id="floating-spotify-mode-select" onchange="playSpotifyStudy(this.value)" style="width:100%;padding:5px 8px;border:1px solid var(--border-color);border-radius:6px;font-size:11px;background:#fff;color:var(--brown-dark);cursor:pointer;">
          ${SPOTIFY_STUDY_MODES.map(m => `
            <option value="${m.id}" ${_spotifyState.activeMode === m.id ? 'selected' : ''}>${m.icon} ${m.name}</option>
          `).join('')}
        </select>
      </div>
    `;
}

// PT: Renderiza o card completo do player de estudo na view Pomodoro
// EN: Renders full study player card in Pomodoro view
// PT: Renderiza visualmente pomodoro spotify widget no DOM
// EN: Renders pomodoro spotify widget in the DOM
// AI: @llm-context Generates and updates HTML markup for pomodoro spotify widget
function renderPomodoroSpotifyWidget() {
    const container = document.getElementById('spotify-study-pomodoro-widget');
    if (!container) return;

    const currentMode = SPOTIFY_STUDY_MODES.find(m => m.id === _spotifyState.activeMode) || SPOTIFY_STUDY_MODES[0];

    if (!_spotifyState.connected) {
        container.innerHTML = `
          <div style="background:rgba(255,255,255,0.7);border:1px dashed var(--border-color);border-radius:12px;padding:20px;text-align:center;margin-top:20px;">
            <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:8px;">
              <span style="font-size:22px;">🎧</span>
              <strong style="font-family:'Cormorant Garamond',serif;font-size:20px;color:var(--brown-dark);">Músicas de Estudo Inteligentes</strong>
            </div>
            <p style="font-size:12.5px;color:var(--text-muted);max-width:420px;margin:0 auto 16px;line-height:1.5;">
              Conecte sua conta do Spotify para reproduzir automaticamente playlists de Lo-Fi, Hiperfoco, Ondas Binaurais e Ruído Marrom durante suas sessões Pomodoro.
            </p>
            <button onclick="typeof connectSpotify==='function' ? connectSpotify() : showView('configuracoes')" class="btn-primary" style="padding:8px 18px;font-size:12px;background:#1DB954;border-color:#1aa34a;">
              <span>🟢</span> Conectar Spotify
            </button>
          </div>
        `;
        return;
    }

    const trackName = _spotifyState.track ? _spotifyState.track.name : currentMode.name;
    const trackArtist = _spotifyState.track ? _spotifyState.track.artist : currentMode.description;
    const rawArt = _spotifyState.track?.art_url ? String(_spotifyState.track.art_url).trim() : '';
    // Evaluate cover URL before DOM/HTTP fetch / Avalia URL da capa antes do fetch HTTP
    const artUrl = rawArt
        ? ((rawArt.startsWith('http://') || rawArt.startsWith('https://') || rawArt.startsWith('/'))
            ? escapeHtml(rawArt)
            : `/js/${encodeURIComponent(rawArt)}`)
        : '';
    const isPlaying = _spotifyState.isPlaying;

    container.innerHTML = `
      <div style="background:var(--cream);border:1px solid var(--border-color);border-radius:12px;padding:20px;margin-top:24px;box-shadow:0 4px 16px rgba(92,61,30,0.06);transition:all 0.3s ease;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:20px;">🎵</span>
            <strong style="font-family:'Cormorant Garamond',serif;font-size:19px;color:var(--brown-dark);">Áudio de Estudo & Foco</strong>
            ${isPlaying ? '<span style="font-size:10px;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:12px;font-weight:700;letter-spacing:0.04em;">● EM REPRODUÇÃO</span>' : ''}
          </div>
          <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;color:var(--brown-dark);cursor:pointer;" title="Inicia/pausa música automaticamente com o timer Pomodoro">
            <input type="checkbox" id="spotify-pomo-sync-toggle" ${_spotifyState.syncWithPomodoro ? 'checked' : ''} onchange="toggleSpotifyPomodoroSync(this.checked)" style="accent-color:var(--brown-dark);cursor:pointer;">
            Sincronizar com Pomodoro
          </label>
        </div>

        <!-- Modos Cognitivos de Estudo -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin-bottom:16px;">
          ${SPOTIFY_STUDY_MODES.map(mode => `
            <button onclick="playSpotifyStudy('${mode.id}')" 
              style="padding:8px 10px;border-radius:8px;border:1px solid ${_spotifyState.activeMode === mode.id ? 'var(--brown-dark)' : 'var(--border-color)'};background:${_spotifyState.activeMode === mode.id ? 'var(--brown-dark)' : '#fff'};color:${_spotifyState.activeMode === mode.id ? '#fff' : 'var(--brown-dark)'};cursor:pointer;display:flex;flex-direction:column;align-items:flex-start;gap:2px;transition:all 0.2s;text-align:left;">
              <span style="font-size:15px;">${mode.icon}</span>
              <strong style="font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;">${mode.name}</strong>
            </button>
          `).join('')}
        </div>

        <!-- Player Bar -->
        <div style="background:#fff;border:1px solid var(--border-color);border-radius:10px;padding:14px 16px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:12px;min-width:0;flex:1;">
              ${artUrl ? `
                <img src="${artUrl}" alt="Album Art" style="width:48px;height:48px;border-radius:6px;object-fit:cover;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,0.15);">
              ` : `
                <div style="width:48px;height:48px;border-radius:6px;background:var(--brown-light);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">
                  ${currentMode.icon}
                </div>
              `}
              <div style="min-width:0;flex:1;">
                <div style="font-size:13.5px;font-weight:700;color:var(--brown-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${trackName}">
                  ${trackName}
                </div>
                <div style="font-size:11.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;">
                  ${trackArtist}
                </div>
              </div>
            </div>

            <!-- Controls -->
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
              <button onclick="previousSpotifyTrack()" title="Faixa Anterior" style="background:none;border:none;cursor:pointer;font-size:16px;color:var(--brown-dark);padding:6px 8px;border-radius:4px;transition:background 0.2s;">
                ⏮
              </button>
              <button onclick="toggleSpotifyStudy()" title="${isPlaying ? 'Pausar' : 'Reproduzir'}" class="btn-primary" style="padding:8px 14px;font-size:13px;border-radius:50px;min-width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:var(--brown-dark);">
                ${isPlaying ? '⏸' : '▶'}
              </button>
              <button onclick="nextSpotifyTrack()" title="Próxima Faixa" style="background:none;border:none;cursor:pointer;font-size:16px;color:var(--brown-dark);padding:6px 8px;border-radius:4px;transition:background 0.2s;">
                ⏭
              </button>
              <a href="${currentMode.url}" target="_blank" rel="noopener" title="Abrir no Spotify" style="background:none;border:1px solid var(--border-color);padding:6px 10px;border-radius:6px;font-size:11px;color:var(--text-muted);text-decoration:none;display:flex;align-items:center;gap:4px;margin-left:4px;">
                <span>↗</span> Spotify
              </a>
            </div>
          </div>

          <!-- Barra de Progresso com Tempo Decorrido / Total -->
          <div style="margin-top:12px;padding-top:10px;border-top:1px solid rgba(0,0,0,0.06);">
            <div style="display:flex;justify-content:space-between;font-size:10.5px;color:var(--text-muted);margin-bottom:4px;font-variant-numeric:tabular-nums;font-weight:600;">
              <span>${_formatMs(_spotifyState.track?.progress_ms || 0)}</span>
              <span>${_formatMs(_spotifyState.track?.duration_ms || 0)}</span>
            </div>
            <input type="range" min="0" max="${_spotifyState.track?.duration_ms || 100}" value="${_spotifyState.track?.progress_ms || 0}"
              onchange="seekSpotifyTrack(this.value)"
              style="width:100%;height:5px;accent-color:#1DB954;background:#eee;border-radius:3px;cursor:pointer;">
          </div>

          <!-- Controle de Volume e Dispositivo Ativo -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;gap:12px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:6px;flex:1;max-width:200px;">
              <span style="font-size:12px;color:var(--brown-dark);" title="Volume">🔉</span>
              <input type="range" min="0" max="100" value="${_spotifyState.device?.volume_percent ?? 100}"
                oninput="setSpotifyVolume(this.value)"
                style="flex:1;height:4px;accent-color:var(--brown-dark);background:#eee;border-radius:2px;cursor:pointer;">
              <span style="font-size:10px;color:var(--text-muted);min-width:26px;">${_spotifyState.device?.volume_percent ?? 100}%</span>
            </div>
            ${_spotifyState.device ? `
              <div style="font-size:10.5px;color:var(--text-muted);display:flex;align-items:center;gap:4px;">
                <span>Dispositivo:</span> <strong style="color:var(--brown-dark);">${escapeHtml(_spotifyState.device.name)}</strong>
              </div>
            ` : ''}
          </div>
        </div>
      </div>
    `;
}

// PT: Renderiza o widget compacto de estudo na view de Estudo Ativo
// EN: Renders compact study widget in Active Study view
// PT: Renderiza visualmente study compact spotify widget no DOM
// EN: Renders study compact spotify widget in the DOM
// AI: @llm-context Generates and updates HTML markup for study compact spotify widget
function renderStudyCompactSpotifyWidget() {
    const container = document.getElementById('spotify-study-compact-widget');
    if (!container) return;

    if (!_spotifyState.connected) {
        container.style.display = 'none';
        return;
    }

    container.style.display = 'block';
    const currentMode = SPOTIFY_STUDY_MODES.find(m => m.id === _spotifyState.activeMode) || SPOTIFY_STUDY_MODES[0];
    const isPlaying = _spotifyState.isPlaying;

    container.innerHTML = `
      <div style="background:var(--cream);border:1px solid var(--border-color);border-radius:8px;padding:8px 14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-size:16px;">${isPlaying ? '🎵' : '🎧'}</span>
          <span style="font-size:12px;font-weight:700;color:var(--brown-dark);">Música de Foco:</span>
          <select id="select-compact-spotify-mode" onchange="playSpotifyStudy(this.value)" style="padding:3px 8px;border:1px solid var(--border-color);border-radius:5px;font-size:11.5px;background:#fff;color:var(--brown-dark);cursor:pointer;">
            ${SPOTIFY_STUDY_MODES.map(m => `
              <option value="${m.id}" ${_spotifyState.activeMode === m.id ? 'selected' : ''}>${m.icon} ${m.name}</option>
            `).join('')}
          </select>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
          <button onclick="toggleSpotifyStudy()" style="padding:4px 10px;border-radius:5px;background:var(--brown-dark);color:#fff;border:none;cursor:pointer;font-size:11.5px;font-weight:600;display:flex;align-items:center;gap:4px;">
            <span>${isPlaying ? '⏸ Pausar' : '▶ Tocar'}</span>
          </button>
        </div>
      </div>
    `;
}

/* ============================================================
   MUSIC PROVIDERS REGISTRY (Extensível para múltiplos serviços)
   ============================================================ */
const MUSIC_SERVICES = {
    spotify: {
        id: 'spotify',
        name: 'Spotify',
        brandColor: '#1DB954',
        accentBg: 'rgba(29, 185, 84, 0.15)',
        iconSvg: `<svg width="15" height="15" viewBox="0 0 24 24" fill="#1DB954" style="flex-shrink:0;"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.48.66.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/></svg>`
    },
    apple_music: { id: 'apple_music', name: 'Apple Music', brandColor: '#FA243C' },
    youtube_music: { id: 'youtube_music', name: 'YouTube Music', brandColor: '#FF0000' },
    deezer: { id: 'deezer', name: 'Deezer', brandColor: '#A238FF' }
};

let _musicPlayerExpanded = localStorage.getItem('svitlo_music_player_expanded') === 'true';

// PT: Expande o player para o card flutuante no canto inferior direito
// EN: Expands player to bottom-right floating card
// PT: Controla integração de estudo e música com o Spotify (expand spotify player)
// EN: Controls Spotify study and music integration (expand spotify player)
// AI: @llm-context Spotify Connect / Web API helper for expandSpotifyPlayer
function expandSpotifyPlayer() {
    _musicPlayerExpanded = true;
    localStorage.setItem('svitlo_music_player_expanded', 'true');
    renderFloatingSpotifyPill();
}

// PT: Minimiza o player para a barra superior ao lado do pomodoro
// EN: Minimizes player into topbar next to pomodoro
// PT: Controla integração de estudo e música com o Spotify (minimize spotify player)
// EN: Controls Spotify study and music integration (minimize spotify player)
// AI: @llm-context Spotify Connect / Web API helper for minimizeSpotifyPlayer
function minimizeSpotifyPlayer() {
    _musicPlayerExpanded = false;
    localStorage.setItem('svitlo_music_player_expanded', 'false');
    renderFloatingSpotifyPill();
}

// PT: Alterna entre minimizado (topbar) e expandido (canto inferior direito)
// EN: Toggles between minimized (topbar) and expanded (bottom-right)
// PT: Alterna o estado de ativação de floating music player compact
// EN: Toggles active state of floating music player compact
// AI: @llm-context Switches boolean/UI state for floating music player compact
function toggleFloatingMusicPlayerCompact() {
    if (_musicPlayerExpanded) {
        minimizeSpotifyPlayer();
    } else {
        expandSpotifyPlayer();
    }
}

// PT: Ajusta dinamicamente a posição vertical do botão de acessibilidade para não colidir com o player expandido
// EN: Dynamically adjusts accessibility button vertical position so it elevates above expanded player
// PT: Executa update a11y button position no ecossistema Svitlo
// EN: Executes update a11y button position in Svitlo ecosystem
// AI: @llm-context Function updateA11yButtonPosition handles client-side state/UI for update a11y button position
function updateA11yButtonPosition(isExpanded) {
    const a11yBtn = document.getElementById('mriya-a11y-btn');
    if (a11yBtn) {
        a11yBtn.style.transition = 'bottom 0.35s cubic-bezier(0.16, 1, 0.3, 1), transform 0.2s ease, box-shadow 0.2s ease';
        if (isExpanded) {
            a11yBtn.style.bottom = '180px';
        } else {
            a11yBtn.style.bottom = '24px';
        }
    }
}

// PT: Renderiza o player do Spotify (Topbar quando minimizado, canto inferior direito quando expandido)
// EN: Renders Spotify player (Topbar when minimized, bottom-right when expanded)
// PT: Renderiza visualmente floating spotify pill no DOM
// EN: Renders floating spotify pill in the DOM
// AI: @llm-context Generates and updates HTML markup for floating spotify pill
function renderFloatingSpotifyPill() {
    // Remove widget legado se existir
    const oldPill = document.getElementById('spotify-floating-pill');
    if (oldPill) oldPill.remove();

    const topbarPlayer = document.getElementById('topbar-spotify-player');
    let widget = document.getElementById('svitlo-music-player-widget');

    if (!widget) {
        widget = document.createElement('div');
        widget.id = 'svitlo-music-player-widget';
        widget.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:999900;display:none;transition:all 0.3s cubic-bezier(0.16, 1, 0.3, 1);font-family:\'Inter\', -apple-system, BlinkMacSystemFont, sans-serif;';
        document.body.appendChild(widget);
    }

    const isConnectedAndPlaying = _spotifyState.connected && _spotifyState.isPlaying;

    if (isConnectedAndPlaying) {
        const trackTitle = _spotifyState.track?.name || 'Música de Estudo';
        const trackArtist = _spotifyState.track?.artist || 'Playlist Svitlo';
        const rawArt = _spotifyState.track?.art_url ? String(_spotifyState.track.art_url).trim() : '';
    // Evaluate cover URL before DOM/HTTP fetch / Avalia URL da capa antes do fetch HTTP
    const artUrl = rawArt
        ? ((rawArt.startsWith('http://') || rawArt.startsWith('https://') || rawArt.startsWith('/'))
            ? escapeHtml(rawArt)
            : `/js/${encodeURIComponent(rawArt)}`)
        : '';
        const currentMode = SPOTIFY_STUDY_MODES.find(m => m.id === _spotifyState.activeMode) || SPOTIFY_STUDY_MODES[0];
        const isPlaying = _spotifyState.isPlaying;
        const service = MUSIC_SERVICES.spotify;

        if (_musicPlayerExpanded) {
            // ==========================================
            // MODO EXPANDIDO: Card no canto inferior direito
            // ==========================================
            if (topbarPlayer) {
                topbarPlayer.style.display = 'none';
            }

            widget.innerHTML = `
              <div style="background:rgba(18, 18, 18, 0.96);backdrop-filter:blur(20px);color:#fff;border:1px solid rgba(29, 185, 84, 0.45);border-radius:14px;box-shadow:0 14px 40px rgba(0,0,0,0.5), 0 0 24px rgba(29, 185, 84, 0.18);width:320px;max-width:92vw;overflow:hidden;user-select:none;">
                
                <!-- Header com Brand Spotify e Ações -->
                <div style="padding:8px 12px;background:rgba(0,0,0,0.45);border-bottom:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;justify-content:space-between;">
                  <div style="display:flex;align-items:center;gap:6px;">
                    ${service.iconSvg}
                    <span style="font-size:11px;font-weight:700;color:#1DB954;letter-spacing:0.04em;">SPOTIFY STUDY</span>
                    <span style="font-size:9.5px;background:rgba(29, 185, 84, 0.2);color:#1DB954;padding:1px 6px;border-radius:10px;font-weight:600;">${currentMode.name}</span>
                  </div>
                  <div style="display:flex;align-items:center;gap:4px;">
                    <button onclick="event.stopPropagation();minimizeSpotifyPlayer()" title="Minimizar para Barra Superior" style="background:none;border:none;color:#b3b3b3;font-size:13px;font-weight:700;cursor:pointer;padding:2px 6px;border-radius:4px;line-height:1;display:flex;align-items:center;justify-content:center;" onmouseenter="this.style.color='#fff'" onmouseleave="this.style.color='#b3b3b3'">—</button>
                    <button onclick="event.stopPropagation();pauseSpotifyStudy()" title="Pausar e Fechar" style="background:none;border:none;color:#b3b3b3;font-size:12px;cursor:pointer;padding:2px 6px;border-radius:4px;line-height:1;display:flex;align-items:center;justify-content:center;" onmouseenter="this.style.color='#fff'" onmouseleave="this.style.color='#b3b3b3'">✕</button>
                  </div>
                </div>

                <!-- Detalhes da Faixa com Foto do Álbum -->
                <div style="padding:12px 14px;">
                  <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                    ${artUrl ? `
                      <img src="${artUrl}" alt="Album Art" style="width:52px;height:52px;border-radius:8px;object-fit:cover;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,0.5);border:1px solid rgba(255,255,255,0.12);">
                    ` : `
                      <div style="width:52px;height:52px;border-radius:8px;background:#282828;color:#1DB954;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;border:1px solid rgba(29, 185, 84, 0.25);">
                        ${currentMode.icon}
                      </div>
                    `}
                    <div style="min-width:0;flex:1;">
                      <div style="font-size:13px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.3;" title="${escapeHtml(trackTitle)}">
                        ${escapeHtml(trackTitle)}
                      </div>
                      <div style="font-size:11.5px;color:#b3b3b3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;" title="${escapeHtml(trackArtist)}">
                        ${escapeHtml(trackArtist)}
                      </div>
                      <div style="font-size:10px;color:#1DB954;margin-top:3px;display:flex;align-items:center;gap:4px;">
                        <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#1DB954;animation:pulse 1.5s infinite;"></span>
                        ${currentMode.name} ${_spotifyState.device ? '• ' + escapeHtml(_spotifyState.device.name) : ''}
                      </div>
                    </div>
                  </div>

                  <!-- Barra de Progresso com Tempo Decorrido / Total -->
                  <div style="margin: 10px 0 12px; background: rgba(0,0,0,0.25); padding: 8px 10px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.06);">
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:#a0a0a0;margin-bottom:4px;font-variant-numeric:tabular-nums;font-weight:600;">
                      <span id="floating-spotify-elapsed">${_formatMs(_spotifyState.track?.progress_ms || 0)}</span>
                      <span id="floating-spotify-duration">${_formatMs(_spotifyState.track?.duration_ms || 0)}</span>
                    </div>
                    <input type="range" min="0" max="${_spotifyState.track?.duration_ms || 100}" value="${_spotifyState.track?.progress_ms || 0}" 
                      onchange="seekSpotifyTrack(this.value)" 
                      style="width:100%;height:4px;accent-color:#1DB954;background:rgba(255,255,255,0.2);border-radius:2px;cursor:pointer;">
                  </div>

                  <!-- Linha de Controles e Seletor de Modo -->
                  <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid rgba(255,255,255,0.08);padding-top:10px;gap:8px;">
                    <!-- Seletor de Playlists Cognitivas -->
                    <select onchange="playSpotifyStudy(this.value)" style="padding:4px 8px;font-size:10.5px;background:#242424;color:#fff;border:1px solid rgba(255,255,255,0.15);border-radius:6px;cursor:pointer;max-width:115px;">
                      ${SPOTIFY_STUDY_MODES.map(m => `
                        <option value="${m.id}" ${_spotifyState.activeMode === m.id ? 'selected' : ''}>${m.icon} ${m.name}</option>
                      `).join('')}
                    </select>

                    <!-- Botões de Reprodução -->
                    <div style="display:flex;align-items:center;gap:8px;">
                      <button onclick="event.stopPropagation();previousSpotifyTrack()" title="Faixa Anterior" style="background:none;border:none;color:#b3b3b3;cursor:pointer;font-size:15px;padding:4px;display:flex;align-items:center;justify-content:center;line-height:1;" onmouseenter="this.style.color='#fff'" onmouseleave="this.style.color='#b3b3b3'">⏮</button>
                      <button onclick="event.stopPropagation();toggleSpotifyStudy()" title="${isPlaying ? 'Pausar' : 'Reproduzir'}" style="background:#1DB954;border:none;color:#000;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:transform 0.15s, background 0.2s;padding:0;margin:0;" onmouseenter="this.style.transform='scale(1.08)'" onmouseleave="this.style.transform='scale(1)'">
                        ${isPlaying ? 
                          `<svg width="12" height="12" viewBox="0 0 24 24" fill="#000" style="display:block;"><path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/></svg>` : 
                          `<svg width="12" height="12" viewBox="0 0 24 24" fill="#000" style="display:block;margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>`
                        }
                      </button>
                      <button onclick="event.stopPropagation();nextSpotifyTrack()" title="Próxima Faixa" style="background:none;border:none;color:#b3b3b3;cursor:pointer;font-size:15px;padding:4px;display:flex;align-items:center;justify-content:center;line-height:1;" onmouseenter="this.style.color='#fff'" onmouseleave="this.style.color='#b3b3b3'">⏭</button>
                      <a href="${currentMode.url}" target="_blank" rel="noopener" title="Abrir no Spotify" style="color:#b3b3b3;text-decoration:none;font-size:13px;padding:4px;display:flex;align-items:center;justify-content:center;line-height:1;" onmouseenter="this.style.color='#1DB954'" onmouseleave="this.style.color='#b3b3b3'">↗</a>
                    </div>
                  </div>

                  <!-- Controle de Volume -->
                  <div style="display:flex;align-items:center;gap:8px;margin-top:10px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.06);">
                    <span style="font-size:12px;color:#b3b3b3;" title="Volume">🔉</span>
                    <input type="range" min="0" max="100" value="${_spotifyState.device?.volume_percent ?? 100}" 
                      oninput="setSpotifyVolume(this.value)" 
                      style="flex:1;height:4px;accent-color:#1DB954;background:rgba(255,255,255,0.2);border-radius:2px;cursor:pointer;">
                    <span style="font-size:10px;color:#b3b3b3;min-width:24px;text-align:right;">${_spotifyState.device?.volume_percent ?? 100}%</span>
                  </div>
                </div>
              </div>
            `;
            widget.style.display = 'block';
            updateA11yButtonPosition(true);
        } else {
            // ==========================================
            // MODO MINIMIZADO: Integrado na barra superior (Topbar)
            // ==========================================
            widget.style.display = 'none';
            updateA11yButtonPosition(false);

            if (topbarPlayer) {
                topbarPlayer.innerHTML = `
                  <div onclick="expandSpotifyPlayer()" title="Clique para expandir player Spotify" style="background:rgba(18, 18, 18, 0.95);backdrop-filter:blur(10px);color:#fff;border:1px solid rgba(29, 185, 84, 0.45);border-radius:20px;box-shadow:0 2px 8px rgba(0,0,0,0.25);padding:3px 8px 3px 4px;display:inline-flex;align-items:center;gap:6px;cursor:pointer;user-select:none;height:30px;transition:all 0.2s ease;">
                    ${artUrl ? `
                      <img src="${artUrl}" alt="Album Art" style="width:22px;height:22px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid #1DB954;">
                    ` : `
                      <div style="width:22px;height:22px;border-radius:50%;background:#1DB954;color:#000;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">
                        ${currentMode.icon}
                      </div>
                    `}
                    <span style="max-width:85px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:11px;font-weight:600;color:#fff;line-height:1.2;">${escapeHtml(trackTitle)}</span>
                    
                    <!-- Botão Play/Pause perfeitamente centralizado -->
                    <button onclick="event.stopPropagation();toggleSpotifyStudy();" title="${isPlaying ? 'Pausar' : 'Reproduzir'}" style="background:#1DB954;border:none;color:#000;border-radius:50%;width:22px;height:22px;padding:0;margin:0;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform 0.15s;line-height:1;" onmouseenter="this.style.transform='scale(1.1)'" onmouseleave="this.style.transform='scale(1)'">
                      ${isPlaying ? 
                        `<svg width="9" height="9" viewBox="0 0 24 24" fill="#000" style="display:block;"><path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/></svg>` : 
                        `<svg width="9" height="9" viewBox="0 0 24 24" fill="#000" style="display:block;margin-left:1.5px;"><path d="M8 5v14l11-7z"/></svg>`
                      }
                    </button>
                    
                    <!-- Próxima Faixa -->
                    <button onclick="event.stopPropagation();nextSpotifyTrack();" title="Próxima Faixa" style="background:transparent;border:none;color:#b3b3b3;font-size:12px;cursor:pointer;padding:0 2px;margin:0;display:flex;align-items:center;justify-content:center;line-height:1;" onmouseenter="this.style.color='#fff'" onmouseleave="this.style.color='#b3b3b3'">
                      ⏭
                    </button>
                    
                    <!-- Expandir -->
                    <button onclick="event.stopPropagation();expandSpotifyPlayer();" title="Expandir Player" style="background:transparent;border:none;color:#b3b3b3;font-size:11px;cursor:pointer;padding:0 2px;margin:0;display:flex;align-items:center;justify-content:center;line-height:1;" onmouseenter="this.style.color='#fff'" onmouseleave="this.style.color='#b3b3b3'">
                      🗖
                    </button>
                  </div>
                `;
                topbarPlayer.style.display = 'inline-flex';
            }
        }
    } else {
        if (topbarPlayer) topbarPlayer.style.display = 'none';
        widget.style.display = 'none';
        updateA11yButtonPosition(false);
    }
}

// Expõe métodos globalmente
window.initSpotifyStudyPlayer = initSpotifyStudyPlayer;
window.checkSpotifyStatus = checkSpotifyStatus;
window.playSpotifyStudy = playSpotifyStudy;
window.pauseSpotifyStudy = pauseSpotifyStudy;
window.toggleSpotifyStudy = toggleSpotifyStudy;
window.nextSpotifyTrack = nextSpotifyTrack;
window.previousSpotifyTrack = previousSpotifyTrack;
window.setSpotifyVolume = setSpotifyVolume;
window.seekSpotifyTrack = seekSpotifyTrack;
window.setSpotifyStudyMode = setSpotifyStudyMode;
window.toggleSpotifyPomodoroSync = toggleSpotifyPomodoroSync;
window.onPomodoroStartPlayback = onPomodoroStartPlayback;
window.onPomodoroPausePlayback = onPomodoroPausePlayback;
window.onPomodoroBreakPlayback = onPomodoroBreakPlayback;
window.toggleFloatingMusicPlayerCompact = toggleFloatingMusicPlayerCompact;
window.expandSpotifyPlayer = expandSpotifyPlayer;
window.minimizeSpotifyPlayer = minimizeSpotifyPlayer;
window.renderPomodoroSpotifyWidget = renderPomodoroSpotifyWidget;
window.renderSpotifyWidgets = renderSpotifyWidgets;

// Inicializa no carregamento
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSpotifyStudyPlayer);
} else {
    initSpotifyStudyPlayer();
}
