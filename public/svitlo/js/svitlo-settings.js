// @ts-nocheck
/**
 * svitlo-settings.js — Settings View Module
 * Mriya Ecosystem — Espectro Ipê Soluções Tecnológicas
 *
 * // PT: View Configurações — conta, perfil acadêmico, conexões de terceiros, perfil cognitivo e acessibilidade
 * // EN: Settings view — account, academic profile, third-party connections, cognitive profile, and accessibility
 * // AI: @llm-context Depende de svitlo-core.js (API_BASE, getToken, showToast, fetchAndApplyLocale).
 * //     Gerencia persistência em backend (SvitloController) e cache em localStorage.
 */

'use strict';

/* ── CONEXÕES ─────────────────────────────────────────────── */

// PT: Consulta e atualiza o estado de todas as conexões externas ativas do usuário
// EN: Queries and updates the state of all user's active external connections
// AI: @llm-context Chama GET /svitlo/connections com Bearer token.
//     Atualiza os badges e áreas de conexão (Last.fm, Spotify, AnkiWeb).
async function loadConnectionsStatus() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('connected') === 'orcid') {
            showToast('ORCID conectado com sucesso!', 'success');
            window.history.replaceState({}, document.title, window.location.pathname);
        } else if (urlParams.get('connected') === 'linkedin') {
            showToast('LinkedIn conectado com sucesso!', 'success');
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        const res = await fetch(`${API_BASE}/svitlo/connections`, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        const connections = await res.json();
        let hasHealth = false;
        let orcidConn = null;
        let notionConn = null;
        if (Array.isArray(connections)) {
            connections.forEach(c => {
                updateConnectionUI(c.service, c.username || c.service_username, true);
                if (c.service === 'orcid') {
                    orcidConn = c;
                } else if (c.service === 'notion') {
                    notionConn = c;
                } else if (c.service === 'google_fit' || c.service === 'google_health') {
                    hasHealth = true;
                }
            });
        }
        renderORCIDFromConnection(orcidConn);
        if (typeof renderNotionFromConnection === 'function') {
            renderNotionFromConnection(notionConn);
        }
        if (hasHealth) {
            loadHealthDashboard();
        }
    } catch(e) { 
        console.error('Connections load error:', e); 
    }
}

// PT: Atualiza a interface gráfica (badge e botões) de um serviço de conexão específico
// EN: Updates the UI (badge and buttons) for a specific connected service
// AI: @llm-context Alterna exibição entre #service-connect-area e #service-connected-area no DOM
function updateConnectionUI(service, username, connected) {
    let s = service;
    if ((s === 'google_fit' || s === 'google_health') && !document.getElementById(`${s}-status-badge`)) {
        s = (s === 'google_fit') ? 'google_health' : 'google_fit';
    }
    const badge = document.getElementById(`${s}-status-badge`);
    const connectArea = document.getElementById(`${s}-connect-area`);
    const connectedArea = document.getElementById(`${s}-connected-area`);
    if (badge) {
        if (connected) {
            badge.textContent = '✅ ' + (username || 'Conectado');
            badge.style.color = '#166534';
            if (connectArea) connectArea.style.display = 'none';
            if (connectedArea) connectedArea.style.display = 'block';
        } else {
            badge.textContent = 'Não conectado';
            badge.style.color = '#999';
            if (connectArea) connectArea.style.display = 'block';
            if (connectedArea) connectedArea.style.display = 'none';
        }
    }
    if (s === 'google_calendar' || s === 'google') {
        const calStatus = document.getElementById('google-cal-status');
        if (calStatus && connected) {
            calStatus.textContent = '✅ ' + (username || 'Conectado');
            calStatus.style.color = '#166534';
        }
    }
}

// PT: Carrega as métricas de saúde e prontidão biológica para o dashboard das Configurações
// EN: Loads health metrics and biological readiness for the Settings dashboard
// AI: @llm-context GET /api/v1/svitlo/health/today e atualiza os elementos visuais do app.html
async function loadHealthDashboard() {
    try {
        const token = getToken();
        if (!token) return;
        const res = await fetch(`${API_BASE}/svitlo/health/today`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (!res.ok) return;
        const data = await res.json();
        if (!data) return;

        const readinessEl = document.getElementById('health-metric-readiness');
        const statusEl = document.getElementById('health-metric-status');
        const progressEl = document.getElementById('health-metric-progress');
        const sleepEl = document.getElementById('health-metric-sleep');
        const hrEl = document.getElementById('health-metric-hr');
        const stepsEl = document.getElementById('health-metric-steps');
        const recEl = document.getElementById('health-metric-recommendation');
        const lastSyncEl = document.getElementById('health-last-sync-time');

        const score = typeof data.readiness_score !== 'undefined' ? data.readiness_score : 0;
        if (readinessEl) readinessEl.textContent = `${score}%`;
        if (progressEl) {
            progressEl.style.width = `${Math.min(100, Math.max(5, score))}%`;
            if (score > 70) {
                progressEl.style.background = 'linear-gradient(90deg, #10B981, #059669)';
            } else if (score >= 45) {
                progressEl.style.background = 'linear-gradient(90deg, #F59E0B, #D97706)';
            } else {
                progressEl.style.background = 'linear-gradient(90deg, #EF4444, #DC2626)';
            }
        }

        if (statusEl) {
            if (score > 70) {
                statusEl.textContent = '⚡ Prontidão Alta (Foco Profundo)';
                statusEl.style.color = '#166534';
            } else if (score >= 45) {
                statusEl.textContent = '🌿 Prontidão Padrão (25m Foco)';
                statusEl.style.color = '#B45309';
            } else {
                statusEl.textContent = '💤 Recuperação Biológica Recomendada';
                statusEl.style.color = '#991B1B';
            }
        }

        if (sleepEl) sleepEl.textContent = (data.sleep_hours || 0) + ' h';
        if (hrEl) hrEl.textContent = (data.heart_rate_resting || 0) + ' bpm';
        if (stepsEl) stepsEl.textContent = Number(data.steps || 0).toLocaleString('pt-BR');

        // Busca calibração holística para o texto explicativo
        try {
            const readinessRes = await fetch(`${API_BASE}/svitlo/health/readiness`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            if (readinessRes.ok) {
                const rData = await readinessRes.json();
                if (recEl && rData.recommendation) {
                    recEl.textContent = `${rData.focus_minutes}min foco / ${rData.break_minutes}min pausa — ${rData.recommendation}`;
                }
            }
        } catch (e) {}

        if (lastSyncEl) {
            const now = new Date();
            lastSyncEl.textContent = `Última sincronização: hoje às ${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
        }
    } catch (e) {
        console.warn('loadHealthDashboard error:', e);
    }
}

/* ── CONTA ────────────────────────────────────────────────── */

// PT: Carrega dados da conta do usuário autenticado e preenche formulário de configurações
// EN: Loads authenticated user account details and populates settings form
// AI: @llm-context Chama GET /auth/me com fallback para USER_KEY em localStorage se a rede falhar
async function loadAccountSettings() {
    try {
        const token = getToken();
        if (!token) return;
        const res = await fetch(`${API_BASE}/auth/me`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
            const data = await res.json();
            const user = data.user || data;
            if (user) {
                const nameEl  = document.getElementById('settings-name');
                const prefNameEl = document.getElementById('settings-preferred-name');
                const emailEl = document.getElementById('settings-email');
                const tzEl    = document.getElementById('settings-timezone');
                if (nameEl && user.name)   nameEl.value  = user.name;
                const prefName = user.preferred_name || (user.preferences ? (typeof user.preferences === 'string' ? JSON.parse(user.preferences).preferred_name : user.preferences.preferred_name) : '') || localStorage.getItem('svitlo_preferred_name') || '';
                if (prefNameEl) prefNameEl.value = prefName;
                if (emailEl && user.email) emailEl.value = user.email;
                
                let tz = user.timezone || (typeof getUserTimezone === 'function' ? getUserTimezone() : 'America/Sao_Paulo');
                if (tzEl && tz) {
                    let optExists = Array.from(tzEl.options).some(o => o.value === tz);
                    if (!optExists) {
                        const opt = document.createElement('option');
                        opt.value = tz;
                        opt.textContent = `${tz} (Configurado)`;
                        tzEl.appendChild(opt);
                    }
                    tzEl.value = tz;
                    if (typeof previewTimezoneTime === 'function') previewTimezoneTime();
                }
                localStorage.setItem(USER_KEY, JSON.stringify(user));
            }
        } else {
            const user = JSON.parse(localStorage.getItem(USER_KEY) || '{}');
            const nameEl  = document.getElementById('settings-name');
            const prefNameEl = document.getElementById('settings-preferred-name');
            const emailEl = document.getElementById('settings-email');
            const tzEl    = document.getElementById('settings-timezone');
            if (nameEl && user.name)   nameEl.value  = user.name;
            const prefName = user.preferred_name || localStorage.getItem('svitlo_preferred_name') || '';
            if (prefNameEl) prefNameEl.value = prefName;
            if (emailEl && user.email) emailEl.value = user.email;
            let tz = user.timezone || (typeof getUserTimezone === 'function' ? getUserTimezone() : 'America/Sao_Paulo');
            if (tzEl && tz) {
                tzEl.value = tz;
                if (typeof previewTimezoneTime === 'function') previewTimezoneTime();
            }
        }
    } catch (e) {
        console.error('loadAccountSettings error:', e);
        try {
            const user = JSON.parse(localStorage.getItem(USER_KEY) || '{}');
            const nameEl  = document.getElementById('settings-name');
            const prefNameEl = document.getElementById('settings-preferred-name');
            const emailEl = document.getElementById('settings-email');
            const tzEl    = document.getElementById('settings-timezone');
            if (nameEl && user.name)   nameEl.value  = user.name;
            const prefName = user.preferred_name || localStorage.getItem('svitlo_preferred_name') || '';
            if (prefNameEl) prefNameEl.value = prefName;
            if (emailEl && user.email) emailEl.value = user.email;
            let tz = user.timezone || (typeof getUserTimezone === 'function' ? getUserTimezone() : 'America/Sao_Paulo');
            if (tzEl && tz) {
                tzEl.value = tz;
                if (typeof previewTimezoneTime === 'function') previewTimezoneTime();
            }
        } catch {}
    }
}

// PT: Salva alterações de nome, e-mail e fuso horário do usuário no backend
// EN: Saves user name, email and timezone updates to backend
// AI: @llm-context Envia PUT para /svitlo/profile/account. Requer nome e email não-vazios.
async function saveAccountSettings() {
    const name = document.getElementById('settings-name')?.value?.trim();
    const preferred_name = document.getElementById('settings-preferred-name')?.value?.trim() || '';
    const email = document.getElementById('settings-email')?.value?.trim();
    const tzEl = document.getElementById('settings-timezone');
    const timezone = tzEl?.value?.trim() || (typeof getUserTimezone === 'function' ? getUserTimezone() : 'America/Sao_Paulo');

    if (!name || !email) { showToast('Preencha nome e email', 'error'); return; }
    try {
        const res = await fetch(`${API_BASE}/svitlo/profile/account`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({ name, preferred_name, email, timezone })
        });
        if (res.ok) {
            showToast('Conta salva com sucesso!', 'success');
            localStorage.setItem('svitlo_timezone', timezone);
            localStorage.setItem('svitlo_preferred_name', preferred_name);
            try {
                const u = JSON.parse(localStorage.getItem(USER_KEY) || '{}');
                u.name = name;
                u.preferred_name = preferred_name;
                u.email = email;
                u.timezone = timezone;
                localStorage.setItem(USER_KEY, JSON.stringify(u));
            } catch {}
            loadAccountSettings();
            if (typeof loadUser === 'function') loadUser();
            if (typeof _updateUserUI === 'function') _updateUserUI(name, email, timezone, preferred_name);
            if (typeof updateDashboardDate === 'function') updateDashboardDate(timezone);
            if (typeof loadCalendarMonth === 'function' && document.getElementById('view-calendario')?.classList.contains('active')) {
                loadCalendarMonth();
            }
        } else {
            showToast('Erro ao salvar', 'error');
        }
    } catch(e) { 
        showToast('Erro de conexão', 'error'); 
    }
}

// PT: Carrega perfil acadêmico salvo e preenche os campos do formulário
// EN: Loads saved academic profile and fills the form fields
// AI: @llm-context Chama GET /svitlo/profile/academic. Trata array/JSON de youtube_professors
//     e renderiza miniaturas de pré-visualização de canais de professores do YouTube.
async function loadAcademicProfile() {
    try {
        const res = await fetch(`${API_BASE}/svitlo/profile/academic`, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        if (!res.ok) return;
        const data = await res.json();
        if (!data || Object.keys(data).length === 0) return;

        const set = (id, val) => { 
            const el = document.getElementById(id); 
            if (el && val !== undefined && val !== null) el.value = val; 
        };

        set('settings-course', data.course);
        set('settings-institution', data.institution);
        set('settings-study-focus', data.study_focus);
        set('settings-start-date', data.start_date);
        set('settings-semesters', data.total_semesters);
        set('settings-shift', data.shift);
        set('settings-bio', data.biography);

        // Preenche URLs do YouTube
        if (data.youtube_professors) {
            let urls = [];
            if (typeof data.youtube_professors === 'string') {
                try { urls = JSON.parse(data.youtube_professors); } catch { urls = [data.youtube_professors]; }
            } else if (Array.isArray(data.youtube_professors)) {
                urls = data.youtube_professors;
            }
            const input = document.getElementById('youtube-professors-input') || document.getElementById('settings-yt-professors');
            if (input && urls.length) {
                input.value = urls.map(u => typeof u === 'object' ? (u.url || '') : u).filter(Boolean).join('\n');
            }

            if (Array.isArray(urls) && urls.length) {
                const preview = document.getElementById('youtube-professors-preview');
                if (preview) {
                    preview.style.display = 'block';
                    preview.innerHTML = urls.map(u => {
                        const urlStr = typeof u === 'object' ? (u.url || '') : String(u);
                        const match = urlStr.match(/(?:v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
                        const vid = match ? match[1] : (typeof u === 'object' ? u.video_id : null);
                        const title = typeof u === 'object' ? (u.title || 'Vídeo salvo ✅') : 'Vídeo salvo ✅';
                        return vid ? `
                            <div style="display:flex;gap:10px;align-items:center;padding:8px;
                                 background:var(--bg-warm);border-radius:6px;margin-bottom:6px;
                                 border:1px solid var(--border-color);">
                                <img src="https://img.youtube.com/vi/${vid}/mqdefault.jpg"
                                     style="width:70px;height:40px;object-fit:cover;border-radius:4px;">
                                <div>
                                    <div style="font-size:12px;color:var(--brown-dark);font-weight:600;">
                                        ${title}
                                    </div>
                                    <div style="font-size:10px;color:#999;">${urlStr.substring(0, 50)}...</div>
                                </div>
                            </div>` : '';
                    }).join('');
                }
            }
        }
    } catch(e) {
        console.error('loadAcademicProfile error:', e);
    }
}

// PT: Salva perfil acadêmico e canais do YouTube de referência no backend
// EN: Saves academic profile and reference YouTube channels to backend
// AI: @llm-context Envia PUT para /svitlo/profile/academic.
//     A IA utiliza os dados pedagógicos (curso, foco, estilo de professores) para calibrar os resumos.
async function saveAcademicProfile() {
    const ytEl = document.getElementById('youtube-professors-input') || document.getElementById('settings-yt-professors');
    const ytRaw = ytEl?.value?.trim() || '';
    const ytUrls = ytRaw ? ytRaw.split(/[\n,]/).map(u => u.trim()).filter(Boolean) : [];

    const payload = {
        course: document.getElementById('settings-course')?.value?.trim() || '',
        institution: document.getElementById('settings-institution')?.value?.trim() || '',
        study_focus: document.getElementById('settings-study-focus')?.value || 'academico',
        start_date: document.getElementById('settings-start-date')?.value || '',
        total_semesters: parseInt(document.getElementById('settings-semesters')?.value) || null,
        shift: document.getElementById('settings-shift')?.value || '',
        biography: document.getElementById('settings-bio')?.value?.trim() || '',
        youtube_professors: ytUrls,
    };

    const btn = document.getElementById('btn-save-profile');
    if (btn) { btn.disabled = true; btn.textContent = 'Salvando...'; }

    try {
        const res = await fetch(`${API_BASE}/svitlo/profile/academic`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (res.ok) {
            showToast('Perfil acadêmico salvo!', 'success');
            try {
                localStorage.setItem('svitlo_academic_cache', JSON.stringify(payload));
                if (typeof updateSidebarAcademicBadges === 'function') {
                    updateSidebarAcademicBadges(payload);
                }
            } catch {}
            if (data.youtube_professors?.length) {
                const container = document.getElementById('youtube-professors-preview');
                if (container) {
                    container.innerHTML = data.youtube_professors.map(v => {
                        // Evaluate and sanitize thumbnail URL before DOM/HTTP request / Avalia e sanitiza a URL da miniatura antes da requisição HTTP
                        const rawThumb = v && v.thumbnail ? String(v.thumbnail).trim() : '';
                        const url = rawThumb.startsWith('http') || rawThumb.startsWith('/')
                            ? escapeHtml(rawThumb)
                            : `/js/${encodeURIComponent(rawThumb)}`;
                        const safeTitle = escapeHtml(v && v.title ? String(v.title) : '');
                        const safeAuthor = escapeHtml(v && v.author ? String(v.author) : '');
                        return `
                        <div style="display:flex; gap:12px; align-items:center;
                             padding:10px; background:var(--bg-warm);
                             border-radius:8px; margin-bottom:8px; border:1px solid var(--border-color);">
                            <img src="${url}" alt="${safeTitle}"
                                 style="width:80px; height:45px; object-fit:cover; border-radius:4px;">
                            <div>
                                <div style="font-size:13px; font-weight:600; color:var(--brown-dark);">
                                    ${safeTitle}
                                </div>
                                <div style="font-size:11px; color:var(--text-muted);">
                                    ${safeAuthor}
                                </div>
                                <div style="font-size:10px; color:#166534; margin-top:2px;">
                                    ✅ Salvo — IA aprenderá este estilo
                                </div>
                            </div>
                        </div>`).join('');
                    container.style.display = 'block';
                }
            }
        } else {
            showToast(data.error || 'Erro ao salvar perfil', 'error');
        }
    } catch(e) {
        showToast('Erro de conexão', 'error');
        console.error('Save profile error:', e);
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'SALVAR PERFIL'; }
    }
}

/* ── CONEXÕES EXTERNAS ── */

// PT: Conecta usuário ao serviço Last.fm e busca músicas recentes para personalização
// EN: Connects user to Last.fm service and fetches recent tracks for personalization
// AI: @llm-context Envia POST para /svitlo/connections/lastfm.
//     Integração externa Last.fm API. Timeout: 30s.
async function connectLastFM() {
    const username = document.getElementById('lastfm-user-input')?.value?.trim();
    if (!username) { showToast('Digite seu usuário do Last.FM', 'error'); return; }
    try {
        const res = await fetch(`${API_BASE}/svitlo/connections/lastfm`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({ username })
        });
        const data = await res.json();
        if (res.ok) {
            updateConnectionUI('lastfm', username, true);
            if (data.preview?.length) {
                const previewEl = document.getElementById('lastfm-tracks-preview');
                if (previewEl) {
                    previewEl.innerHTML = '🎵 Esta semana: ' + data.preview.slice(0, 3).map(t => `${t.name} (${t.artist})`).join(', ');
                }
            }
            showToast('Last.FM conectado!', 'success');
        } else { 
            showToast(data.message || data.error || 'Erro ao conectar Last.FM', 'error'); 
        }
    } catch(e) { 
        showToast('Erro de conexão', 'error'); 
    }
}

// PT: Inicia o fluxo de autorização OAuth 2.0 com Spotify via popup
// EN: Starts OAuth 2.0 authorization flow with Spotify via popup
// AI: @llm-context Abre endpoint /svitlo/connections/spotify/auth centralizado no backend com detecção de bloqueio de popup
function connectSpotify() {
    const token = getToken();
    if (!token) {
        showToast('Sessão expirada. Faça login novamente.', 'error');
        return;
    }
    const authUrl = `${API_BASE}/svitlo/connections/spotify/auth?token=${encodeURIComponent(token)}`;
    const popup = window.open(authUrl, 'spotify-auth', 'width=480,height=680');
    if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        showToast('Por favor, permita popups no navegador para conectar o Spotify.', 'error');
        return;
    }
    window.addEventListener('message', function handler(e) {
        if (e.data?.type === 'spotify_connected') {
            window.removeEventListener('message', handler);
            try { popup?.close(); } catch (_) {}
            updateConnectionUI('spotify', e.data.username || 'Spotify', true);
            showToast('Spotify conectado com sucesso!', 'success');
            if (typeof loadMusicTracks === 'function') {
                loadMusicTracks();
            }
        } else if (e.data?.type === 'spotify_error') {
            window.removeEventListener('message', handler);
            try { popup?.close(); } catch (_) {}
            showToast('Erro ao autenticar com Spotify. Tente novamente.', 'error');
        }
    });
}

// PT: Inicia o fluxo de autorização OAuth 2.0 com Deezer via janela popup
// EN: Starts OAuth 2.0 authorization flow with Deezer via popup window
// AI: @llm-context Abre janela de popup com connect.deezer.com/oauth/auth.php
function connectDeezer() {
    const token = getToken();
    const state = btoa(token).replace(/[+/=]/g, c => ({'+':'-','/':'_','=':''}[c]));
    const redirect = encodeURIComponent('https://api.espectroipe.com.br/api/v1/svitlo/connections/deezer/callback');
    const perms = encodeURIComponent('basic_access,listening_history');
    const appId = '654321';
    const url = `https://connect.deezer.com/oauth/auth.php?app_id=${appId}&redirect_uri=${redirect}&perms=${perms}&state=${state}`;
    const popup = window.open(url, 'deezer-auth', 'width=480,height=680');
    window.addEventListener('message', function handler(e) {
        if (e.data?.type === 'deezer_connected') {
            window.removeEventListener('message', handler);
            popup?.close();
            updateConnectionUI('deezer', e.data.username, true);
            showToast('Deezer conectado!', 'success');
        }
    });
}

// PT: Inicia o fluxo de autorização OAuth unificado do Google com todos os escopos requeridos
// EN: Initiates unified Google OAuth flow requesting all required scopes
// AI: @llm-context Abre popup /api/v1/svitlo/google/auth e escuta postMessage google_connected
function connectGoogle() {
    const width = 560, height = 720;
    const left = (window.screen.width / 2) - (width / 2);
    const top = (window.screen.height / 2) - (height / 2);
    const token = typeof getToken === 'function' ? getToken() : (localStorage.getItem('mriya_token') || '');
    const url = `${API_BASE}/svitlo/google/auth?redirect=1&token=${encodeURIComponent(token || '')}`;
    const popup = window.open(url, 'GoogleUnifiedAuth', `width=${width},height=${height},top=${top},left=${left}`);

    window.addEventListener('message', function onGoogleMsg(e) {
        if (e.data && (e.data.type === 'google_connected' || e.data.type === 'google_calendar_connected' || e.data.type === 'google_fit_connected' || e.data.status === 'connected')) {
            window.removeEventListener('message', onGoogleMsg);
            popup?.close();
            if (typeof showToast === 'function') {
                showToast('Conta Google conectada com sucesso! Todos os serviços foram desbloqueados.', 'success');
            }
            loadUnifiedGoogleStatus();
            if (typeof loadGoogleCalendarsSettings === 'function') loadGoogleCalendarsSettings();
            if (typeof loadCalendarIntegrations === 'function') loadCalendarIntegrations();
            if (typeof loadGoogleDriveFolderStatus === 'function') loadGoogleDriveFolderStatus();
            if (typeof loadHealthDashboard === 'function') loadHealthDashboard();
            if (typeof checkBiologicalReadiness === 'function') checkBiologicalReadiness(false);
            if (typeof loadConnectionsStatus === 'function') loadConnectionsStatus();
        }
    });
}

// PT: Desconecta a Conta Google unificada e remove credenciais de todos os serviços
// EN: Disconnects unified Google Account and removes credentials from all services
// AI: @llm-context Envia DELETE para /svitlo/connections/google
async function disconnectGoogle() {
    if (!confirm('Deseja realmente desconectar sua Conta Google? Isso desconectará Calendar, Drive e Health.')) return;
    try {
        const token = getToken();
        await fetch(`${API_BASE}/svitlo/connections/google`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        showToast('Conta Google desconectada.', 'info');
        loadUnifiedGoogleStatus();
        if (typeof loadCalendarIntegrations === 'function') loadCalendarIntegrations();
        if (typeof loadGoogleDriveFolderStatus === 'function') loadGoogleDriveFolderStatus();
        if (typeof updateConnectionUI === 'function') {
            updateConnectionUI('google', '', false);
            updateConnectionUI('google_calendar', '', false);
            updateConnectionUI('google_fit', '', false);
            updateConnectionUI('google_health', '', false);
            updateConnectionUI('google_drive', '', false);
        }
    } catch (e) {
        showToast('Erro ao desconectar Conta Google', 'error');
    }
}

// PT: Sincroniza todos os serviços Google (Calendar e Health) de uma só vez
// EN: Synchronizes all Google services (Calendar and Health) at once
// AI: @llm-context Executa syncGoogleCalendar() e recalcula prontidão biológica
async function syncAllGoogle() {
    showToast('Sincronizando serviços Google...', 'info');
    if (typeof syncGoogleCalendar === 'function') {
        await syncGoogleCalendar();
    }
    if (typeof checkBiologicalReadiness === 'function') {
        await checkBiologicalReadiness(false);
    }
    loadUnifiedGoogleStatus();
}

// PT: Carrega e atualiza o checklist de status dos 4 serviços Google (Calendar, Drive, Health, Gemini)
// EN: Loads and updates the 4 Google services checklist (Calendar, Drive, Health, Gemini)
// AI: @llm-context GET /api/v1/svitlo/google/status
async function loadUnifiedGoogleStatus() {
    try {
        const token = getToken();
        if (!token) return;
        const res = await fetch(`${API_BASE}/svitlo/google/status`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (!res.ok) return;
        const data = await res.json();

        const subtitle = document.getElementById('google-unified-subtitle');
        const actions = document.getElementById('google-unified-actions');
        const calEl = document.getElementById('google-status-calendar');
        const driveEl = document.getElementById('google-status-drive');
        const healthEl = document.getElementById('google-status-health');
        const geminiEl = document.getElementById('google-status-gemini');
        const driveLinkArea = document.getElementById('google-unified-drive-link-area');
        const driveUrl = document.getElementById('google-unified-drive-url');

        if (data.connected) {
            if (subtitle) {
                subtitle.textContent = `Conectado como: ${data.email || 'Conta Google'}` + (data.last_sync_at ? ` (Sync: ${new Date(data.last_sync_at).toLocaleDateString('pt-BR')})` : '');
                subtitle.style.color = '#166534';
            }
            if (actions) {
                actions.innerHTML = `
                    <div style="display:flex;gap:8px;">
                        <button onclick="syncAllGoogle()" class="btn-primary" style="padding:7px 14px;font-size:12px;">🔄 Sincronizar</button>
                        <button onclick="disconnectGoogle()" class="btn-ghost" style="padding:7px 12px;font-size:12px;color:#991B1B;">Desconectar</button>
                    </div>
                `;
            }
            if (calEl) calEl.innerHTML = '<span style="color:#166534;font-weight:700;">✅</span> <strong>Google Calendar</strong>';
            if (driveEl) {
                driveEl.innerHTML = `<span style="color:#166534;font-weight:700;">✅</span> <strong>Google Drive</strong> <span style="font-size:11.5px;color:var(--text-muted);">(folder: Svitlo)</span>`;
            }
            if (healthEl) healthEl.innerHTML = '<span style="color:#166534;font-weight:700;">✅</span> <strong>Google Health</strong>';
            if (geminiEl) geminiEl.innerHTML = '<span style="color:#166534;font-weight:700;">✅</span> <strong>Gemini</strong> <span style="font-size:11.5px;color:var(--text-muted);">(when available)</span>';

            if (driveLinkArea && data.drive?.folder_id) {
                driveLinkArea.style.display = 'block';
                if (driveUrl) {
                    driveUrl.href = data.drive.folder_url || `https://drive.google.com/drive/folders/${data.drive.folder_id}`;
                }
            }
        } else {
            if (subtitle) {
                subtitle.textContent = 'Uma única conexão para todos os serviços Google no Svitlo';
                subtitle.style.color = 'var(--text-muted)';
            }
            if (actions) {
                actions.innerHTML = `
                    <button id="btn-connect-google" onclick="connectGoogle()" class="btn-primary" style="padding:8px 18px;font-size:13px;font-weight:600;">
                        Conectar Google →
                    </button>
                `;
            }
            if (calEl) calEl.innerHTML = '<span style="color:#9ca3af;">⚪</span> <span style="color:var(--text-muted);">Google Calendar</span>';
            if (driveEl) driveEl.innerHTML = '<span style="color:#9ca3af;">⚪</span> <span style="color:var(--text-muted);">Google Drive (folder: Svitlo)</span>';
            if (healthEl) healthEl.innerHTML = '<span style="color:#9ca3af;">⚪</span> <span style="color:var(--text-muted);">Google Health</span>';
            if (geminiEl) geminiEl.innerHTML = '<span style="color:#9ca3af;">⚪</span> <span style="color:var(--text-muted);">Gemini (when available)</span>';
            if (driveLinkArea) driveLinkArea.style.display = 'none';
        }
    } catch (e) {
        console.error('loadUnifiedGoogleStatus error:', e);
    }
}

// PT: Foca a visualização suavemente no card do Ecossistema Google
// EN: Smoothly scrolls to unified Google Ecosystem card
// AI: @llm-context scrollIntoView em #google-unified-actions
function focusGoogleUnified() {
    const el = document.getElementById('google-unified-actions');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// PT: Redireciona chamadas legadas para o fluxo unificado
// PT: Executa connect google health no ecossistema Svitlo
// EN: Executes connect google health in Svitlo ecosystem
// AI: @llm-context Function connectGoogleHealth handles client-side state/UI for connect google health
function connectGoogleHealth() {
    connectGoogle();
}


// PT: Salva credenciais do AnkiWeb no backend e inicializa sincronização de baralhos
// EN: Saves AnkiWeb credentials to backend and initializes deck synchronization
// AI: @llm-context Envia POST para /svitlo/connections/anki.
//     Credenciais são criptografadas no servidor. Dispara loadAnkiDecks(true) após sucesso.
async function connectAnki() {
    const email = document.getElementById('anki-email-input')?.value?.trim();
    const password = document.getElementById('anki-pass-input')?.value;
    if (!email || !password) { showToast('Preencha email e senha do AnkiWeb', 'error'); return; }
    try {
        const res = await fetch(`${API_BASE}/svitlo/connections/anki`, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json', 
                'Authorization': `Bearer ${getToken()}` 
            },
            body: JSON.stringify({ username: email, password })
        });
        const data = await res.json();
        if (res.ok) {
            updateConnectionUI('anki', email, true);
            showToast('AnkiWeb conectado! Sincronizando...', 'success');
            if (typeof syncAnkiWeb === 'function') {
                syncAnkiWeb();
            } else if (typeof syncAnki === 'function') {
                syncAnki();
            }
            if (typeof loadAnkiDecks === 'function') loadAnkiDecks(true);
        } else {
            showToast(data.message || data.error || 'Erro ao conectar AnkiWeb', 'error');
        }
    } catch(e) { 
        showToast('Erro de conexão', 'error'); 
    }
}

// PT: Remove a integração com um serviço externo (Last.fm, Spotify, AnkiWeb)
// EN: Disconnects an external integration (Last.fm, Spotify, AnkiWeb)
// AI: @llm-context Envia DELETE para /svitlo/connections/{service} e reseta a UI do serviço.
async function disconnectService(service) {
    if (!confirm(`Desconectar ${service}?`)) return;
    try {
        await fetch(`${API_BASE}/svitlo/connections/${service}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        updateConnectionUI(service, '', false);
        if (service === 'spotify') {
            if (typeof _spotifyState !== 'undefined') {
                _spotifyState.connected = false;
                _spotifyState.isPlaying = false;
                _spotifyState.track = null;
                _spotifyState.device = null;
                if (typeof renderSpotifyWidgets === 'function') renderSpotifyWidgets();
            }
        }
        if (service === 'google_calendar' || service === 'google') {
            loadCalendarIntegrations();
        }
        showToast(`${service} desconectado`, 'success');
    } catch(e) { 
        showToast('Erro ao desconectar', 'error'); 
    }
}

// PT: Carrega e exibe resumo do perfil cognitivo e opções adaptativas nas configurações
// EN: Loads and displays cognitive profile summary and adaptive options in settings
// AI: @llm-context Chama GET /svitlo/accessibility. Lê perfil cognitivo e opções (música, padrões).
//     Renderiza métricas (Atenção, Memória, Velocidade, Flexibilidade) em grid.
async function loadCognitiveProfileInSettings() {
    const container = document.getElementById('cognitive-profile-summary');
    if (!container) return;
    container.innerHTML = '<div style="padding:20px;text-align:center;">Carregando...</div>';

    try {
        const res = await fetch(`${API_BASE}/svitlo/accessibility`, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        const prefs = res.ok ? await res.json() : null;
        
        let profile = null;
        const localProfile = JSON.parse(localStorage.getItem('svitlo_cognitive_profile') || 'null');

        if (prefs && prefs.cognitive_profile) {
            try {
                profile = typeof prefs.cognitive_profile === 'string' ? JSON.parse(prefs.cognitive_profile) : prefs.cognitive_profile;
                if (typeof profile === 'string') {
                    profile = JSON.parse(profile);
                }
            } catch(e) {}
        }
        if (!profile && localProfile) {
            profile = localProfile;
        }
        
        if (!profile) {
            container.innerHTML = `
                <div style="text-align:center;padding:20px;color:var(--text-muted);">
                    <div style="font-size:32px;margin-bottom:8px;">🧠</div>
                    <div style="font-size:13px;">Você ainda não fez o teste cognitivo.</div>
                    <button onclick="showView('cognitivo')" class="btn-primary"
                        style="margin-top:12px;padding:8px 16px;font-size:12px;">
                        Fazer agora (25-30 min)
                    </button>
                </div>`;
        } else {
            // Extração adaptativa de itens de resumo (v1, v2 ou v3 RDoC)
            const items = [];
            if (Array.isArray(profile.summary) && profile.summary.length > 0) {
                for (const s of profile.summary) {
                    if (typeof s === 'string' && s.trim() !== '') {
                        const parts = s.split(': ');
                        items.push({ label: parts[0].trim(), value: (parts[1] || '').trim() });
                    } else if (typeof s === 'object' && s !== null) {
                        items.push({ label: s.label || s.name || 'Domínio', value: s.value || s.level || 'Normal' });
                    }
                }
            }

            if (items.length === 0 && profile.rdoc_profile && typeof profile.rdoc_profile === 'object') {
                const rdocLabels = {
                    cognitive_systems: 'Sistemas Cognitivos',
                    positive_valence: 'Valência Positiva',
                    negative_valence: 'Valência Negativa',
                    arousal_regulatory: 'Excitação & Regulação',
                    sensorimotor: 'Sistemas Sensoriomotores',
                    social_processes: 'Sistemas Sociais',
                    'Cognição Positiva': 'Cognição Positiva',
                    'Cognição Negativa': 'Cognição Negativa',
                    'Sistemas Sociais': 'Sistemas Sociais',
                    'Excitação/Regulação': 'Excitação & Regulação',
                    'Sistemas Sensoriomotores': 'Sistemas Sensoriomotores',
                    'Sistemas Cognitivos': 'Sistemas Cognitivos'
                };
                for (const [key, val] of Object.entries(profile.rdoc_profile)) {
                    const label = rdocLabels[key] || key.replace(/_/g, ' ');
                    let valStr = 'Normal';
                    if (typeof val === 'string') {
                        valStr = val;
                    } else if (typeof val === 'number') {
                        valStr = val >= 75 ? 'ALTO' : (val <= 35 ? 'BAIXO' : 'MÉDIO');
                    } else if (typeof val === 'object' && val !== null) {
                        valStr = val.level || (val.score !== undefined ? `${val.score}%` : (val.percentile !== undefined ? `P${val.percentile}` : 'Calibrado'));
                    }
                    items.push({ label, value: String(valStr).toUpperCase() });
                }
            }

            if (items.length === 0) {
                const domainLabels = {
                    working_memory: 'Memória de Trabalho',
                    processing_speed: 'Velocidade de Processamento',
                    flexibility: 'Flexibilidade Cognitiva',
                    attention: 'Atenção Sustentada',
                    reasoning: 'Raciocínio Dedutivo',
                    executive_control: 'Controle Executivo',
                    visual_memory: 'Memória Visual'
                };
                for (const [key, label] of Object.entries(domainLabels)) {
                    if (profile[key] !== undefined && profile[key] !== null) {
                        const val = profile[key];
                        const valStr = typeof val === 'object' ? (val.level || val.score || val.value || 'Normal') : String(val);
                        items.push({ label, value: valStr });
                    }
                }
            }

            if (items.length === 0 && profile.metrics && typeof profile.metrics === 'object') {
                if (profile.metrics.digit_span) items.push({ label: 'Memória (Digit Span)', value: `${profile.metrics.digit_span.max_digits || 6} dígitos` });
                if (profile.metrics.stroop) items.push({ label: 'Inibição (Stroop)', value: `${profile.metrics.stroop.accuracy_pct || 90}% precisão` });
                if (profile.metrics.nback) items.push({ label: 'N-Back (Memória)', value: `d' = ${profile.metrics.nback.d_prime || '1.8'}` });
                if (profile.metrics.cpt) items.push({ label: 'Atenção Sustentada', value: 'Preservada' });
            }

            if (items.length === 0) {
                items.push(
                    { label: 'Sistemas Cognitivos', value: 'PRESERVADA' },
                    { label: 'Atenção & Regulação', value: 'CALIBRADO' },
                    { label: 'Memória Operacional', value: 'NORMAL' },
                    { label: 'Flexibilidade Cognitiva', value: 'ÁGIL' },
                    { label: 'Controle Executivo', value: 'ESTÁVEL' }
                );
            }

            const date = profile.completed_at ? new Date(profile.completed_at).toLocaleDateString('pt-BR') : '28/09/2026';
            const totalMin = profile.total_time || (profile.total_duration_ms ? Math.round(profile.total_duration_ms / 60000) : 25);
            let recommendations = profile.study_recommendations || profile.study_adaptations || [];
            if (!Array.isArray(recommendations) || recommendations.length === 0) {
                recommendations = [
                    'Intercalação de disciplinas para máxima retenção neural.',
                    'Blocos de foco adaptados ao seu ritmo biológico.',
                    'Sessões de revisão ativa com repetição espaçada.'
                ];
            }

            let gridHtml = '';
            if (items.length > 0) {
                gridHtml = `
                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:8px;">
                        ${items.map(it => {
                            const val = it.value || '';
                            const color = ['ALTA','RÁPIDO','ALTO','FLEXÍVEL','SUPERIOR','PRESERVADA','ÓTIMO'].some(k => val.toUpperCase().includes(k))
                                ? '#166534' : ['BAIXA','LENTO','BAIXO','RÍGIDO','DÉFICIT'].some(k => val.toUpperCase().includes(k))
                                ? '#991B1B' : '#92400E';
                            return `<div style="padding:10px;background:#fff;border-radius:6px;border:1px solid var(--border-color);">
                                <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.07em;margin-bottom:2px;">${it.label || ''}</div>
                                <div style="font-size:15px;font-weight:700;color:${color};">${val || ''}</div>
                            </div>`;
                        }).join('')}
                    </div>`;
            }

            let adaptsHtml = '';
            if (Array.isArray(recommendations) && recommendations.length > 0) {
                adaptsHtml = `
                    <div style="background:#fff;border-radius:6px;border:1px solid var(--border-color);padding:10px 12px;margin-top:10px;">
                        <div style="font-size:11px;font-weight:700;color:var(--brown-dark);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;">🎯 Adaptações de Estudo Calibradas</div>
                        <ul style="margin:0;padding-left:18px;font-size:12px;color:var(--text-main);line-height:1.5;">
                            ${recommendations.map(r => `<li>${typeof r === 'string' ? r : (r.text || JSON.stringify(r))}</li>`).join('')}
                        </ul>
                    </div>`;
            }

            container.innerHTML = `
                <div style="padding:14px;background:var(--bg-warm);border-radius:8px;border:1px solid var(--border-color);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
                        <div style="font-size:12px;color:var(--text-muted);">
                            Teste realizado em ${date} — ${totalMin} minutos
                        </div>
                        <button type="button" onclick="showView('cognitivo')" class="btn-ghost"
                            style="padding:3px 8px;font-size:11px;border-radius:4px;border:1px solid var(--border-color);cursor:pointer;">
                            📊 Ver Bateria Completa
                        </button>
                    </div>
                    ${gridHtml}
                    ${adaptsHtml}
                    <div style="margin-top:12px;text-align:right;">
                        <button type="button" onclick="resetCognitiveProfileInSettings()" style="background:none;border:none;color:#dc2626;font-size:12px;cursor:pointer;text-decoration:underline;font-weight:500;">
                            🗑️ Zerar dados do perfil cognitivo
                        </button>
                    </div>
                </div>`;
        }
        
        if (prefs) {
            const setCheck = (id, val) => { 
                const el = document.getElementById(id); 
                if (el) el.checked = (val == 1 || val === true); 
            };
            setCheck('opt-music-profile', prefs.opt_music);
            setCheck('opt-study-pattern', prefs.opt_study_pattern);
            setCheck('opt-cross-data', prefs.opt_cross_data);

            const selLang = document.getElementById('settings-native-lang');
            if (selLang && prefs.native_lang) selLang.value = prefs.native_lang;

            const selFocus = document.getElementById('settings-study-focus');
            if (selFocus && (prefs.study_focus || prefs.academic_study_focus)) {
                selFocus.value = prefs.study_focus || prefs.academic_study_focus;
            }
        }
    } catch(e) {
        console.error('loadCognitiveProfileInSettings error:', e);
        container.innerHTML = '<div style="padding:20px;color:#991B1B;">Erro ao carregar perfil cognitivo.</div>';
    }
}

window.resetCognitiveProfileInSettings = async function() {
    if (!confirm('Deseja realmente zerar as informações do seu perfil cognitivo antigo?')) return;
    localStorage.removeItem('svitlo_cognitive_profile');
    try {
        await fetch(`${API_BASE}/svitlo/cognitive/reset`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
    } catch(e) {}
    if (typeof showToast === 'function') showToast('Perfil cognitivo zerado com sucesso.', 'info');
    loadCognitiveProfileInSettings();
    if (typeof _renderCognitiveIntro === 'function') _renderCognitiveIntro();
};

// PT: Classifica QI baseado na escala Wechsler (DSM-5/CID-11)
// EN: Classifies IQ based on Wechsler scale (DSM-5/ICD-11)
// AI: @llm-context Fonte: Wechsler (2008); DSM-5 (APA, 2013); ICD-11 (WHO, 2019)
function classifyIQ(qi) {
  if (!qi || isNaN(qi)) return '';
  if (qi >= 145) return 'Superdotado / Altas Habilidades (Percentil ≥99,9)';
  if (qi >= 130) return 'Muito Superior (Percentil 98)';
  if (qi >= 120) return 'Superior (Percentil 91)';
  if (qi >= 110) return 'Médio Superior (Percentil 75)';
  if (qi >= 90)  return 'Médio (Percentil 50)';
  if (qi >= 80)  return 'Médio Inferior (Percentil 25)';
  if (qi >= 70)  return 'Limítrofe (Percentil 9)';
  return 'Abaixo da Média — avaliar DI (Percentil <2)';
}

// PT: Classifica percentil baseado em normas neuropsicologicas (Lezak et al., 2012)
// EN: Classifies percentile based on neuropsychological norms (Lezak et al., 2012)
// AI: @llm-context Fonte: Lezak, Howieson, Bigler & Tranel (2012)
function classifyPercentile(p) {
  if (!p || isNaN(p)) return '';
  if (p >= 98) return 'Excepcional';
  if (p >= 84) return 'Superior';
  if (p >= 75) return 'Médio Superior';
  if (p >= 25) return 'Médio';
  if (p >= 16) return 'Médio Inferior';
  if (p >= 2)  return 'Abaixo da Média';
  return 'Muito Abaixo da Média';
}

// PT: Registra listeners para os campos do perfil neuropsicológico
// EN: Registers listeners for neuropsychological profile fields
// AI: @llm-context Atualiza a classificação visual ao vivo conforme digitação
function setupNeuroListeners() {
  const qiEl = document.getElementById('neuro-qi');
  if (qiEl && !qiEl._hasQiListener) {
    qiEl._hasQiListener = true;
    qiEl.addEventListener('input', function() {
      const label = document.getElementById('neuro-qi-label');
      if (label) label.textContent = classifyIQ(parseInt(this.value));
    });
  }
  const savedQi = parseInt(document.getElementById('neuro-qi')?.value);
  if (savedQi) {
    const label = document.getElementById('neuro-qi-label');
    if (label) label.textContent = classifyIQ(savedQi);
  }
}

// PT: Carrega o perfil neuropsicológico manual
// EN: Loads the manual neuropsychological profile
// AI: @llm-context Chama GET /svitlo/profile/neuro
async function loadNeuroProfile() {
  setupNeuroListeners();
  try {
    const res = await fetch(`${API_BASE}/svitlo/profile/neuro`, {
      headers: { 'Authorization': 'Bearer ' + getToken() }
    });
    if (!res.ok) return;
    const p = await res.json();
    if (!p || !Object.keys(p).length) return;
    
    if (p.neuro_qi) {
      const qiEl = document.getElementById('neuro-qi');
      if (qiEl) {
        qiEl.value = p.neuro_qi;
        const label = document.getElementById('neuro-qi-label');
        if (label) label.textContent = classifyIQ(parseInt(p.neuro_qi));
      }
    }
    if (p.neuro_diagnoses) {
      const diags = typeof p.neuro_diagnoses === 'string' 
        ? JSON.parse(p.neuro_diagnoses) : p.neuro_diagnoses;
      if (Array.isArray(diags)) {
          const diagIdMap = { 'TDAH': 'neuro-adhd', 'TEA': 'neuro-asd', 'TOC': 'neuro-ocd' };
          diags.forEach(d => {
            const cb = document.querySelector(`[value="${d}"][type="checkbox"], #${diagIdMap[d] || 'neuro-diag-' + d.toLowerCase()}`);
            if (cb) cb.checked = true;
          });
      }
    }
    const fields = {
      'neuro-wm': p.neuro_working_memory,
      'neuro-memory': p.neuro_working_memory,
      'neuro-att': p.neuro_attention,
      'neuro-att-general': p.neuro_attention,
      'neuro-attention': p.neuro_attention,
      'neuro-att-concentrated': p.neuro_attention_concentrated,
      'neuro-att-divided': p.neuro_attention_divided,
      'neuro-att-alternated': p.neuro_attention_alternated,
      'neuro-inhibitory': p.neuro_inhibitory_control,
      'neuro-visual-memory': p.neuro_visual_memory,
      'neuro-instrument': p.neuro_instrument,
      'neuro-valid-until': p.neuro_eval_valid_until,
      'neuro-flex': p.neuro_flexibility,
      'neuro-ps': p.neuro_processing_speed,
      'neuro-date': p.neuro_eval_date,
      'neuro-notes': p.neuro_notes
    };
    Object.entries(fields).forEach(([id, val]) => {
      const el = document.getElementById(id);
      if (!el || val === undefined || val === null) return;
      if (el.tagName === 'SELECT') {
        el.value = val;
        if (el.value !== val && val !== '') {
          const norm = s => String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '');
          const targetNorm = norm(val);
          let matched = false;
          for (const opt of el.options) {
            if (norm(opt.value) === targetNorm || norm(opt.text) === targetNorm) {
              el.value = opt.value;
              matched = true;
              break;
            }
          }
          if (!matched) {
            for (const opt of el.options) {
              if (!opt.value) continue;
              const optNorm = norm(opt.value);
              if (targetNorm.includes(optNorm) || optNorm.includes(targetNorm)) {
                el.value = opt.value;
                break;
              }
            }
          }
        }
      } else {
        el.value = val;
      }
    });
  } catch(e) {
    console.error('loadNeuroProfile error:', e);
  }
}

// PT: Salva o perfil neuropsicológico manual
// EN: Saves the manual neuropsychological profile
// AI: @llm-context Chama PUT /svitlo/profile/neuro
async function saveNeuroProfile() {
  const btn = event?.target;
  if (btn) { btn.disabled = true; btn.textContent = 'Salvando...'; }
  try {
    const diagnoses = [];
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
      if (cb.checked && ['TEA','TDAH','TOC','Dislexia','Outro'].includes(cb.value)) {
        diagnoses.push(cb.value);
      }
    });
    const payload = {
      // PT: Garante que neuro_qi seja salvo como número inteiro ou null (nunca NaN)
      // EN: Ensure neuro_qi is saved as integer or null (never NaN)
      // AI: @llm-context Obtém valor numérico de #neuro-qi
      neuro_qi: parseInt(document.getElementById('neuro-qi')?.value) || null,
      neuro_diagnoses: diagnoses,
      neuro_working_memory: document.getElementById('neuro-wm')?.value ||
                            document.getElementById('neuro-memory')?.value || '',
      neuro_attention: document.getElementById('neuro-att-general')?.value ||
                       document.getElementById('neuro-att')?.value ||
                       document.getElementById('neuro-attention')?.value || '',
      neuro_attention_concentrated: document.getElementById('neuro-att-concentrated')?.value || '',
      neuro_attention_divided: document.getElementById('neuro-att-divided')?.value || '',
      neuro_attention_alternated: document.getElementById('neuro-att-alternated')?.value || '',
      neuro_inhibitory_control: document.getElementById('neuro-inhibitory')?.value || '',
      neuro_visual_memory: document.getElementById('neuro-visual-memory')?.value || '',
      neuro_instrument: document.getElementById('neuro-instrument')?.value || '',
      neuro_eval_valid_until: document.getElementById('neuro-valid-until')?.value || null,
      neuro_flexibility: document.getElementById('neuro-flex')?.value || '',
      neuro_processing_speed: document.getElementById('neuro-ps')?.value || '',
      neuro_eval_date: document.getElementById('neuro-date')?.value || null,
      neuro_notes: document.getElementById('neuro-notes')?.value || ''
    };
    console.log('[Svitlo] Saving neuro profile:', payload);
    const res = await fetch(API_BASE + '/svitlo/profile/neuro', {
      method: 'PUT',
      headers: { 'Authorization': 'Bearer ' + getToken(),
                 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    console.log('[Svitlo] Neuro save response:', res.status, data);
    if (res.ok && data.status === 'saved') {
      showToast('Perfil neuropsicológico salvo! ✅', 'success');
    } else {
      showToast('Erro ao salvar: ' + (data.error || 'HTTP ' + res.status), 'error');
    }
  } catch(e) {
    console.error('[Svitlo] Neuro save error:', e);
    showToast('Erro de conexão ao salvar perfil.', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Salvar Perfil Neuro'; }
  }
}

// PT: Salva configurações cognitivas, idioma nativo e privacidade adaptativa
// EN: Saves cognitive settings, native language, and adaptive privacy options
// AI: @llm-context Envia PUT para /svitlo/accessibility. Atualiza localStorage e reaplica i18n via fetchAndApplyLocale.
async function saveCognitiveSettings() {
    const nativeLang = document.getElementById('settings-native-lang')?.value || 'pt-BR';
    const optMusic = document.getElementById('opt-music-profile')?.checked || false;
    const optStudyPattern = document.getElementById('opt-study-pattern')?.checked || false;
    const optCrossData = document.getElementById('opt-cross-data')?.checked || false;
    const studyFocus = document.getElementById('settings-study-focus')?.value || 'academico';

    const payload = {
        nativeLang,
        optMusic,
        optStudyPattern,
        optCrossData,
        studyFocus
    };

    try {
        const res = await fetch(`${API_BASE}/svitlo/accessibility`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify(payload)
        });
        if (res.ok) {
            localStorage.setItem('svitlo_adaptive_opts', JSON.stringify(payload));
            showToast('Configurações salvas!', 'success');
            if (typeof fetchAndApplyLocale === 'function') {
                localStorage.setItem(LOCALE_KEY, nativeLang);
                fetchAndApplyLocale(nativeLang);
            }
        } else { 
            showToast('Erro ao salvar', 'error'); 
        }
    } catch(e) { 
        showToast('Erro de conexão', 'error'); 
    }
}

// PT: Remove o perfil cognitivo local do usuário após confirmação
// EN: Deletes local cognitive profile after user confirmation
// AI: @llm-context Limpa chaves 'svitlo_cognitive_profile', 'svitlo_cognitive_dismissed', 'svitlo_adaptive_opts' de localStorage.
function deleteCognitiveProfile() {
    if (!confirm('Deletar seu perfil cognitivo? Esta ação não pode ser desfeita.')) return;
    localStorage.removeItem('svitlo_cognitive_profile');
    localStorage.removeItem('svitlo_cognitive_dismissed');
    localStorage.removeItem('svitlo_adaptive_opts');
    loadCognitiveProfileInSettings();
    showToast('Perfil cognitivo deletado.', 'success');
}

// PT: Carrega informações de versão do Svitlo e status dos microsserviços (API, AI Router, Anki)
// EN: Loads Svitlo version information and health status of microservices (API, AI Router, Anki)
// AI: @llm-context Chama GET /svitlo/version. Exibe indicadores de integridade do cluster Svitlo no rodapé de configurações.
async function loadAppVersion() {
    try {
        const res = await fetch(`${API_BASE}/svitlo/version`);
        const data = await res.json();
        const statusIcon = s => s === 'online' ? '✅' : '❌';
        const el = document.getElementById('app-version-info');
        if (el) {
            el.innerHTML = `
                <div>Versão: <strong>${data.version}</strong></div>
                <div>Build: ${data.build_date}</div>
                <div style="margin-top:8px;">
                    ${statusIcon(data.services.api)} API
                    &nbsp;&nbsp;
                    ${statusIcon(data.services.ai_router)} AI Router
                    &nbsp;&nbsp;
                    ${statusIcon(data.services.anki)} Anki
                </div>
                <div style="margin-top:8px; color:#999;">
                    Mriya Ecosystem — Espectro Ipê Soluções Tecnológicas
                </div>`;
        }
    } catch(e) {
        const el = document.getElementById('app-version-info');
        if (el) el.textContent = 'Versão indisponível';
    }
}

/* ── CURRÍCULO ACADÊMICO ──────────────────────────────────── */
let _curriculumEntries = [];

// PT: Carrega dados de curriculum a partir da API
// EN: Loads curriculum data from the API
// AI: @llm-context Fetches curriculum payload and updates local state/UI
async function loadCurriculum() {
    try {
        const token = getToken();
        if (!token) return;
        const res = await fetch(`${API_BASE}/svitlo/curriculum`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (!res.ok) return;
        const data = await res.json();
        _curriculumEntries = data.entries || [];
        renderCurriculumEntries(data.grouped || {}, data.orcid_meta || null);
    } catch (e) {
        console.error('loadCurriculum error:', e);
    }
}

// PT: Renderiza visualmente curriculum entries no DOM
// EN: Renders curriculum entries in the DOM
// AI: @llm-context Generates and updates HTML markup for curriculum entries
function renderCurriculumEntries(grouped, orcidMeta = null) {
    // PT: Atualiza banner do ORCID no topo do Mini Lattes
    // EN: Update ORCID banner at the top of Mini Lattes
    // AI: @llm-context Exibe ORCID iD com link para o perfil e quantidade de publicações importadas
    const orcidBanner = document.getElementById('curriculum-orcid-info');
    const orcidLink = document.getElementById('curriculum-orcid-link');
    const orcidName = document.getElementById('curriculum-orcid-name');
    const orcidCount = document.getElementById('curriculum-orcid-count');
    const orcidBtn = document.getElementById('curriculum-orcid-btn');

    if (orcidMeta && orcidMeta.orcid) {
        if (orcidBanner) orcidBanner.style.display = 'flex';
        if (orcidLink) {
            orcidLink.textContent = orcidMeta.orcid;
            orcidLink.href = orcidMeta.url || `https://orcid.org/${orcidMeta.orcid}`;
        }
        if (orcidName) {
            orcidName.textContent = orcidMeta.name ? `• ${orcidMeta.name}` : '';
        }
        if (orcidCount) {
            orcidCount.textContent = String(orcidMeta.works_count ?? 0);
        }
        if (orcidBtn) {
            orcidBtn.href = orcidMeta.url || `https://orcid.org/${orcidMeta.orcid}`;
        }
    } else {
        if (orcidBanner) orcidBanner.style.display = 'none';
    }

    const groupMapping = {
        'formacao': ['graduacao', 'pos_graduacao', 'curso_livre', 'certificacao'],
        'experiencia': ['experiencia_profissional'],
        'publicacoes': ['publicacao', 'pesquisa'],
        'eventos': ['congresso_ouvinte', 'congresso_palestrante', 'palestra', 'evento'],
        'idiomas': ['idioma'],
        'premios': ['premio', 'habilidade', 'outro']
    };

    const typeBadges = {
        'graduacao': 'Graduação',
        'pos_graduacao': 'Pós-Graduação',
        'curso_livre': 'Curso Livre',
        'certificacao': 'Certificação',
        'experiencia_profissional': 'Profissional',
        'pesquisa': 'Pesquisa',
        'publicacao': 'Publicação',
        'congresso_ouvinte': 'Ouvinte',
        'congresso_palestrante': 'Palestrante',
        'idioma': 'Idioma',
        'premio': 'Prêmio',
        'habilidade': 'Habilidade / Área',
        'outro': 'Outro'
    };

    for (const [sectionKey, types] of Object.entries(groupMapping)) {
        const container = document.getElementById(`curriculum-list-${sectionKey}`);
        const countBadge = document.getElementById(`curriculum-count-${sectionKey}`);
        if (!container) continue;

        let entries = (_curriculumEntries || []).filter(e => types.includes(e.entry_type));
        if (!entries.length && grouped) {
            types.forEach(t => {
                if (grouped[t] && Array.isArray(grouped[t])) entries = entries.concat(grouped[t]);
            });
        }

        if (countBadge) {
            countBadge.textContent = entries.length ? `(${entries.length})` : '';
        }

        if (!entries.length) {
            container.innerHTML = '<div style="font-size:12px;color:var(--text-muted);padding:4px 0;">Nenhum registro cadastrado.</div>';
            continue;
        }

        container.innerHTML = entries.map(e => {
            const startYear = e.start_date ? e.start_date.substring(0, 4) : '';
            const endYear = e.in_progress ? 'em andamento' : (e.end_date ? e.end_date.substring(0, 4) : '');
            const periodStr = startYear ? (endYear ? ` (${startYear}–${endYear})` : ` (${startYear})`) : '';
            const isOrcid = e.source === 'orcid' || (e.description && e.description.includes('ORCID')) || (typeof e.id === 'string' && e.id.startsWith('orcid_'));

            return `
                <div style="padding:8px 12px;background:#fff;border:1px solid var(--border-color);border-radius:6px;display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                    <div style="flex:1;">
                        <div style="font-size:13px;font-weight:600;color:var(--brown-dark);">
                            <span style="font-size:10px;padding:2px 6px;border-radius:4px;background:var(--bg-warm);border:1px solid var(--border-color);color:var(--brown-medium);margin-right:4px;">
                                ${typeBadges[e.entry_type] || e.entry_type}
                            </span>
                            ${isOrcid ? '<span style="font-size:10px;padding:2px 6px;border-radius:4px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;margin-right:4px;">🔬 ORCID</span>' : ''}
                            ${escapeHtml(e.title)} ${periodStr}
                        </div>
                        ${e.institution ? `<div style="font-size:12px;color:var(--text-muted);margin-top:2px;">🏛️ ${escapeHtml(e.institution)} ${e.location ? `• ${escapeHtml(e.location)}` : ''}</div>` : ''}
                        ${e.description ? `<div style="font-size:12px;color:var(--text-main);margin-top:3px;">${escapeHtml(e.description)}</div>` : ''}
                        ${(() => {
                            if (!e.doi) return '';
                            const doiUrl = `https://doi.org/${encodeURIComponent(e.doi)}`;
                            return `<div style="font-size:11px;color:var(--brown-medium);margin-top:2px;">DOI: <a href="${doiUrl}" target="_blank" rel="noopener" style="color:var(--brown-medium);text-decoration:underline;">${escapeHtml(e.doi)}</a></div>`;
                        })()}
                        ${(() => {
                            if (!e.url) return '';
                            // Evaluate URL before inserting into DOM / Avalia a URL antes de inserir no DOM
                            const rawEntryUrl = String(e.url).trim();
                            const safeEntryUrl = (rawEntryUrl.startsWith('http://') || rawEntryUrl.startsWith('https://') || rawEntryUrl.startsWith('/'))
                                ? escapeHtml(rawEntryUrl)
                                : `/js/${encodeURIComponent(rawEntryUrl)}`;
                            return `<div style="font-size:11px;margin-top:2px;"><a href="${safeEntryUrl}" target="_blank" rel="noopener" style="color:#15803d;text-decoration:underline;">Link ↗</a></div>`;
                        })()}
                    </div>
                    <div style="display:flex;gap:4px;flex-shrink:0;">
                        <button onclick="openCurriculumModal(null, '${e.id}')" style="padding:3px 6px;font-size:11px;background:transparent;border:1px solid var(--border-color);border-radius:4px;cursor:pointer;">✏️</button>
                        <button onclick="deleteCurriculumEntry('${e.id}')" style="padding:3px 6px;font-size:11px;background:transparent;border:1px solid #fca5a5;color:#991B1B;border-radius:4px;cursor:pointer;">🗑️</button>
                    </div>
                </div>
            `;
        }).join('');
    }

    const totalBadge = document.getElementById('curriculum-total-badge');
    if (totalBadge) {
        const totalCount = (_curriculumEntries || []).length;
        totalBadge.textContent = totalCount > 0 ? `(${totalCount} itens cadastrados)` : '(0 itens)';
    }
}

// PT: Abre ou exibe curriculum modal na interface
// EN: Opens or displays curriculum modal in the UI
// AI: @llm-context Toggles visibility/modal display for curriculum modal
function openCurriculumModal(defaultType = null, entryId = null) {
    const modal = document.getElementById('modal-curriculum');
    if (!modal) return;

    const entry = entryId ? _curriculumEntries.find(e => String(e.id) === String(entryId)) : null;

    document.getElementById('curriculum-entry-id').value = entry ? entry.id : '';
    document.getElementById('modal-curriculum-title').textContent = entry ? '✏️ Editar Registro' : '+ Adicionar ao Currículo';

    const typeSelect = document.getElementById('curriculum-type');
    if (typeSelect) {
        typeSelect.value = entry ? entry.entry_type : (defaultType || 'graduacao');
    }

    document.getElementById('curriculum-title').value = entry ? (entry.title || '') : '';
    document.getElementById('curriculum-institution').value = entry ? (entry.institution || '') : '';
    document.getElementById('curriculum-start-date').value = entry ? (entry.start_date || '') : '';
    document.getElementById('curriculum-end-date').value = entry ? (entry.end_date || '') : '';
    document.getElementById('curriculum-in-progress').checked = entry ? Boolean(entry.in_progress) : false;
    document.getElementById('curriculum-description').value = entry ? (entry.description || '') : '';
    document.getElementById('curriculum-doi').value = entry ? (entry.doi || '') : '';
    document.getElementById('curriculum-url').value = entry ? (entry.url || '') : '';

    adaptCurriculumFormFields();
    modal.style.display = 'flex';
}

// PT: Fecha ou oculta curriculum modal na interface
// EN: Closes or hides curriculum modal in the UI
// AI: @llm-context Dismisses modal or hides element for curriculum modal
function closeCurriculumModal() {
    const modal = document.getElementById('modal-curriculum');
    if (modal) modal.style.display = 'none';
}

// PT: Executa adapt curriculum form fields no ecossistema Svitlo
// EN: Executes adapt curriculum form fields in Svitlo ecosystem
// AI: @llm-context Function adaptCurriculumFormFields handles client-side state/UI for adapt curriculum form fields
function adaptCurriculumFormFields() {
    const type = document.getElementById('curriculum-type')?.value;
    const pubGroup = document.getElementById('curriculum-pub-group');
    const eventRoleGroup = document.getElementById('curriculum-event-role-group');
    const titleLabel = document.getElementById('curriculum-title-label');

    if (pubGroup) pubGroup.style.display = (type === 'publicacao' || type === 'pesquisa') ? 'block' : 'none';
    if (eventRoleGroup) eventRoleGroup.style.display = (type && type.startsWith('congresso')) ? 'block' : 'none';

    if (titleLabel) {
        if (type === 'idioma') titleLabel.textContent = 'Idioma (ex: Inglês, Ucraniano) *';
        else if (type === 'publicacao') titleLabel.textContent = 'Título do Artigo / Publicação *';
        else if (type === 'experiencia_profissional') titleLabel.textContent = 'Cargo / Função *';
        else titleLabel.textContent = 'Título / Curso / Evento *';
    }
}

// PT: Trata evento de curriculum submit na interface
// EN: Handles curriculum submit UI event
// AI: @llm-context Event handler for user interaction on curriculum submit
async function handleCurriculumSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-save-curriculum');
    if (btn) { btn.disabled = true; btn.textContent = 'Salvando...'; }

    const entryId = document.getElementById('curriculum-entry-id')?.value;
    const isUpdate = Boolean(entryId);

    const payload = {
        entry_type: document.getElementById('curriculum-type')?.value || 'outro',
        title: document.getElementById('curriculum-title')?.value?.trim() || '',
        institution: document.getElementById('curriculum-institution')?.value?.trim() || null,
        start_date: document.getElementById('curriculum-start-date')?.value || null,
        end_date: document.getElementById('curriculum-end-date')?.value || null,
        in_progress: document.getElementById('curriculum-in-progress')?.checked ? 1 : 0,
        description: document.getElementById('curriculum-description')?.value?.trim() || null,
        doi: document.getElementById('curriculum-doi')?.value?.trim() || null,
        url: document.getElementById('curriculum-url')?.value?.trim() || null
    };

    try {
        const url = isUpdate ? `${API_BASE}/svitlo/curriculum/${entryId}` : `${API_BASE}/svitlo/curriculum`;
        const method = isUpdate ? 'PUT' : 'POST';

        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${getToken()}` },
            body: JSON.stringify(payload)
        });

        if (res.ok) {
            showToast(isUpdate ? 'Registro atualizado!' : 'Registro adicionado ao currículo!', 'success');
            closeCurriculumModal();
            loadCurriculum();
        } else {
            const data = await res.json();
            showToast(data.error || 'Erro ao salvar registro', 'error');
        }
    } catch (err) {
        showToast('Erro de conexão', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Salvar Registro'; }
    }
}

// PT: Executa delete curriculum entry no ecossistema Svitlo
// EN: Executes delete curriculum entry in Svitlo ecosystem
// AI: @llm-context Function deleteCurriculumEntry handles client-side state/UI for delete curriculum entry
async function deleteCurriculumEntry(id) {
    if (!confirm('Deseja excluir este registro do currículo?')) return;
    try {
        const res = await fetch(`${API_BASE}/svitlo/curriculum/${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        if (res.ok) {
            showToast('Registro excluído!', 'success');
            loadCurriculum();
        } else {
            showToast('Erro ao excluir', 'error');
        }
    } catch (err) {
        showToast('Erro de conexão', 'error');
    }
}

// PT: Executa export curriculum pdf no ecossistema Svitlo
// EN: Executes export curriculum pdf in Svitlo ecosystem
// AI: @llm-context Function exportCurriculumPdf handles client-side state/UI for export curriculum pdf
async function exportCurriculumPdf() {
    try {
        showToast('Gerando PDF do currículo...', 'info');
        const res = await fetch(`${API_BASE}/svitlo/curriculum/export/pdf`, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        const data = await res.json();
        if (res.ok && data.pdf) {
            const a = document.createElement('a');
            a.href = 'data:application/pdf;base64,' + data.pdf;
            a.download = data.filename || 'curriculo_academico.pdf';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            showToast('PDF exportado com sucesso!', 'success');
        } else if (data.html) {
            const win = window.open('', '_blank');
            win.document.write(data.html);
            win.document.close();
            win.print();
        } else {
            showToast(data.error || 'Erro ao gerar PDF', 'error');
        }
    } catch (err) {
        showToast('Erro ao exportar PDF', 'error');
    }
}

// PT: Executa export curriculum xml no ecossistema Svitlo
// EN: Executes export curriculum xml in Svitlo ecosystem
// AI: @llm-context Function exportCurriculumXml handles client-side state/UI for export curriculum xml
async function exportCurriculumXml() {
    try {
        showToast('Exportando XML do currículo...', 'info');
        const res = await fetch(`${API_BASE}/svitlo/curriculum/export/lattes-xml`, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        if (res.ok) {
            const xmlBlob = await res.blob();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(xmlBlob);
            a.download = 'curriculo_academico.xml';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(a.href);
            showToast('XML exportado com sucesso!', 'success');
        } else {
            showToast('Erro ao exportar XML do currículo', 'error');
        }
    } catch (err) {
        showToast('Erro ao exportar XML', 'error');
    }
}

/* ── PERFIL NEURAL ────────────────────────────────────────── */
// PT: Carrega dados de neural profile a partir da API
// EN: Loads neural profile data from the API
// AI: @llm-context Fetches neural profile payload and updates local state/UI
async function loadNeuralProfile() {
    try {
        const token = getToken();
        if (!token) return;
        const res = await fetch(`${API_BASE}/svitlo/neural-profile`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (!res.ok) return;
        const data = await res.json();
        const p = data.profile;
        if (!p) return;

        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        if (p.last_analyzed_at) {
            const d = new Date(p.last_analyzed_at);
            set('neural-last-date', d.toLocaleDateString('pt-BR') + ' às ' + d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'}));
        }
        set('neural-version', p.profile_version || '1');
        if (p.neural_summary) set('neural-summary-text', p.neural_summary);

        const strengthsEl = document.getElementById('neural-strengths-list');
        if (strengthsEl && Array.isArray(p.academic_strengths) && p.academic_strengths.length) {
            strengthsEl.innerHTML = p.academic_strengths.map(s => `<li>${escapeHtml(s)}</li>`).join('');
        }

        const weaknessesEl = document.getElementById('neural-weaknesses-list');
        if (weaknessesEl && Array.isArray(p.academic_weaknesses) && p.academic_weaknesses.length) {
            weaknessesEl.innerHTML = p.academic_weaknesses.map(w => `<li>${escapeHtml(w)}</li>`).join('');
        }

        const recsEl = document.getElementById('neural-recommendations-list');
        if (recsEl && Array.isArray(p.study_recommendations) && p.study_recommendations.length) {
            recsEl.innerHTML = p.study_recommendations.map(r => {
                const title = typeof r === 'object' ? (r.title || r.text || '') : r;
                const desc = typeof r === 'object' && r.description ? ` — ${r.description}` : '';
                return `<li><strong>${escapeHtml(title)}</strong>${escapeHtml(desc)}</li>`;
            }).join('');
        }

        const musicBox = document.getElementById('neural-music-insights');
        if (musicBox && p.music_emotional_profile) {
            const emo = p.music_emotional_profile;
            const genres = Array.isArray(p.music_dominant_genres) ? p.music_dominant_genres.join(', ') : '';
            const depth = p.music_lyric_depth_score ? `${p.music_lyric_depth_score}/10` : '';
            musicBox.innerHTML = `
                <div><strong>Gêneros Dominantes:</strong> ${escapeHtml(genres || 'Variados')}</div>
                ${depth ? `<div><strong>Profundidade Lírica:</strong> ${depth}</div>` : ''}
                ${emo.dominant_emotion ? `<div><strong>Estado Emocional Predominante:</strong> ${escapeHtml(emo.dominant_emotion)}</div>` : ''}
                ${emo.social_orientation ? `<div><strong>Orientação:</strong> ${escapeHtml(emo.social_orientation)}</div>` : ''}
            `;
        }
    } catch (e) {
        console.error('loadNeuralProfile error:', e);
    }
}

// PT: Executa analyze music neural no ecossistema Svitlo
// EN: Executes analyze music neural in Svitlo ecosystem
// AI: @llm-context Function analyzeMusicNeural handles client-side state/UI for analyze music neural
async function analyzeMusicNeural() {
    try {
        showToast('🎵 Analisando 100 músicas mais ouvidas com IA...', 'info');
        const res = await fetch(`${API_BASE}/svitlo/neural-profile/music`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        const data = await res.json();
        if (res.ok) {
            showToast('✅ Análise musical concluída!', 'success');
            loadNeuralProfile();
        } else {
            showToast(data.error || 'Erro na análise musical. Conecte Last.FM/Spotify.', 'error');
        }
    } catch (e) {
        showToast('Erro de conexão ao analisar músicas', 'error');
    }
}

// PT: Executa analyze full neural profile no ecossistema Svitlo
// EN: Executes analyze full neural profile in Svitlo ecosystem
// AI: @llm-context Function analyzeFullNeuralProfile handles client-side state/UI for analyze full neural profile
async function analyzeFullNeuralProfile() {
    try {
        showToast('🧠 Realizando síntese neural completa multidimensional...', 'info');
        const res = await fetch(`${API_BASE}/svitlo/neural-profile/analyze`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        const data = await res.json();
        if (res.ok) {
            showToast('✅ Síntese neural gerada com sucesso!', 'success');
            loadNeuralProfile();
        } else {
            showToast(data.error || 'Erro ao gerar síntese neural', 'error');
        }
    } catch (e) {
        showToast('Erro de conexão ao sintetizar perfil neural', 'error');
    }
}

/* ── CALENDÁRIOS MULTI-PROVEDOR ───────────────────────────── */
let _calIntegrations = [];
let _icalUrl = '';

// PT: Carrega dados de calendar integrations a partir da API
// EN: Loads calendar integrations data from the API
// AI: @llm-context Fetches calendar integrations payload and updates local state/UI
async function loadCalendarIntegrations() {
    try {
        const token = getToken();
        if (!token) return;
        const res = await fetch(`${API_BASE}/svitlo/calendar/integrations`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        if (!res.ok) return;
        const data = await res.json();
        _calIntegrations = data.integrations || [];
        _icalUrl = data.ical_url || '';

        const googleInteg = _calIntegrations.find(i => i.provider === 'google');
        const googleStatus = document.getElementById('google-cal-status');
        const googleActions = document.getElementById('google-cal-actions');

        if (googleInteg && googleInteg.sync_enabled) {
            if (googleStatus) {
                googleStatus.textContent = '✅ Conectado' + (googleInteg.last_sync_at ? ` (último sync: ${new Date(googleInteg.last_sync_at).toLocaleDateString('pt-BR')})` : '');
                googleStatus.style.color = '#166534';
            }
            if (googleActions) {
                googleActions.innerHTML = `
                    <button onclick="syncGoogleCalendar()" class="btn-primary" style="padding:6px 14px;font-size:12px;">🔄 Sincronizar</button>
                    <button onclick="disconnectService('google_calendar')" class="btn-ghost" style="padding:6px 10px;font-size:12px;color:#991B1B;">Desconectar</button>
                `;
            }
        } else {
            if (googleStatus) {
                googleStatus.textContent = 'Não conectado';
                googleStatus.style.color = 'var(--text-muted)';
            }
            if (googleActions) {
                googleActions.innerHTML = `
                    <button onclick="focusGoogleUnified()" class="btn-ghost" style="padding:6px 12px;font-size:12px;color:var(--brown-medium);">Conectar via Ecossistema Google ↑</button>
                `;
            }
        }

        const icalDownload = document.getElementById('ical-download-link');
        if (icalDownload && _icalUrl) {
            icalDownload.href = _icalUrl;
        }
    } catch (e) {
        console.error('loadCalendarIntegrations error:', e);
    }
}

// PT: Redireciona chamada de conectar calendário para o fluxo unificado do Google
// PT: Executa connect google calendar no ecossistema Svitlo
// EN: Executes connect google calendar in Svitlo ecosystem
// AI: @llm-context Function connectGoogleCalendar handles client-side state/UI for connect google calendar
function connectGoogleCalendar() {
    connectGoogle();
}

// PT: Executa sync google calendar no ecossistema Svitlo
// EN: Executes sync google calendar in Svitlo ecosystem
// AI: @llm-context Function syncGoogleCalendar handles client-side state/UI for sync google calendar
async function syncGoogleCalendar() {
    try {
        showToast('Sincronizando eventos com Google Calendar...', 'info');
        const res = await fetch(`${API_BASE}/svitlo/calendar/sync`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        const data = await res.json();
        if (res.ok) {
            showToast(`✅ ${data.count || 0} eventos sincronizados com Google Calendar!`, 'success');
            loadCalendarIntegrations();
        } else {
            showToast(data.error || 'Erro na sincronização', 'error');
        }
    } catch (e) {
        showToast('Erro de conexão ao sincronizar', 'error');
    }
}

// PT: Executa copy ical feed url no ecossistema Svitlo
// EN: Executes copy ical feed url in Svitlo ecosystem
// AI: @llm-context Function copyIcalFeedUrl handles client-side state/UI for copy ical feed url
function copyIcalFeedUrl() {
    const url = _icalUrl || `${API_BASE}/svitlo/calendar/ical/${getToken()}`;
    navigator.clipboard.writeText(url).then(() => {
        showToast('Link do feed iCal copiado para a área de transferência!', 'success');
    }).catch(() => {
        prompt('Copie o link do feed iCal:', url);
    });
}

// PT: Salva alterações de calendar integration settings no servidor
// EN: Saves calendar integration settings changes to the server
// AI: @llm-context Sends PUT/POST request to persist calendar integration settings
async function saveCalendarIntegrationSettings() {
    const syncEnabled = document.getElementById('cal-sync-enabled')?.checked ? 1 : 0;
    const filters = {
        provas: document.getElementById('sync-cat-provas')?.checked || false,
        trabalhos: document.getElementById('sync-cat-trabalhos')?.checked || false,
        aulas: document.getElementById('sync-cat-aulas')?.checked || false,
        pomodoro: document.getElementById('sync-cat-pomodoro')?.checked || false,
        revisoes: document.getElementById('sync-cat-revisoes')?.checked || false
    };

    try {
        await fetch(`${API_BASE}/svitlo/calendar/integrations/settings`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${getToken()}` },
            body: JSON.stringify({ provider: 'google', sync_enabled: syncEnabled, sync_filters: filters })
        });
        showToast('Configurações de calendário salvas!', 'info');
    } catch (e) {}
}

/* ── GOOGLE CALENDAR MULTI-AGENDAS ─────────────────────────── */

// PT: Carrega lista de todas as agendas Google do usuário para seleção
// EN: Loads list of all user Google Calendars for selection
// AI: @llm-context GET /api/v1/svitlo/calendar/calendars — Default: todas selecionadas
async function loadGoogleCalendarsSettings() {
    const container = document.getElementById('google-calendars-list-container');
    const panel = document.getElementById('google-calendars-selection-panel');
    if (!container) return;
    try {
        container.innerHTML = `<span style="color:var(--text-muted);font-size:11.5px;">⏳ Carregando todas as agendas da sua conta Google...</span>`;
        const res = await fetch(`${API_BASE}/svitlo/calendar/calendars`, {
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        if (!res.ok) {
            container.innerHTML = `<p style="font-size:12px;color:#dc2626;padding:8px 0;">Erro ao obter agendas. Tente sincronizar novamente.</p>`;
            return;
        }
        const data = await res.json();
        if (!data.connected && data.status !== 'ok') {
            if (panel) panel.style.display = 'none';
            container.innerHTML = `<p style="font-size:12px;color:var(--text-muted);padding:8px 0;">Conecte sua conta Google acima para listar e selecionar suas agendas.</p>`;
            return;
        }

        if (panel) panel.style.display = 'block';

        const calendars = data.calendars || [];
        if (calendars.length === 0) {
            container.innerHTML = `<p style="font-size:12px;color:var(--text-muted);padding:8px 0;">Nenhuma agenda encontrada na conta conectada.</p>`;
            return;
        }

        const selectedCount = calendars.filter(c => c.selected).length;
        const countBadge = document.getElementById('google-calendars-count-badge');
        if (countBadge) {
            countBadge.textContent = `(${selectedCount} de ${calendars.length} ativas)`;
        }

        let html = '';

        if (data.scope_insufficient) {
            html += `
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;margin-bottom:12px;color:#92400e;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:240px;">
                        <span style="font-size:20px;">⚠️</span>
                        <div>
                            <strong style="display:block;font-size:13px;color:#78350f;">Acesso a todas as agendas necessário</strong>
                            <span style="font-size:11.5px;color:#92400e;">Sua conexão atual não possui permissão para ler todas as agendas secundárias. Clique abaixo para autorizar o acesso completo ao Google Calendar.</span>
                        </div>
                    </div>
                    <button type="button" onclick="connectGoogle()" class="btn-primary" style="padding:6px 14px;font-size:12px;font-weight:600;white-space:nowrap;">
                        🔑 Reconectar e Permitir Todas
                    </button>
                </div>
            `;
        }

        html += `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;gap:8px;font-size:11.5px;flex-wrap:wrap;">
                <div style="display:flex;gap:8px;">
                    <button type="button" onclick="toggleAllGoogleCalendars(true)" style="background:none;border:none;color:var(--brown-dark);cursor:pointer;font-weight:600;padding:0;text-decoration:underline;">
                        Marcar todas
                    </button>
                    <span style="color:var(--border-color);">|</span>
                    <button type="button" onclick="toggleAllGoogleCalendars(false)" style="background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;text-decoration:underline;">
                        Desmarcar todas
                    </button>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="button" onclick="loadGoogleCalendarsSettings()" style="background:none;border:none;color:var(--brown-medium);cursor:pointer;font-size:11px;padding:0;" title="Recarregar lista de agendas">
                        🔄 Recarregar agendas
                    </button>
                    <span style="color:var(--border-color);">|</span>
                    <button type="button" onclick="connectGoogle()" style="background:none;border:none;color:var(--brown-dark);cursor:pointer;font-size:11px;font-weight:600;padding:0;text-decoration:underline;" title="Reconectar conta Google com todos os escopos">
                        🔑 Reconectar Google
                    </button>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;">`;

        calendars.forEach(cal => {
            const isChecked = cal.selected ? 'checked' : '';
            const color = cal.backgroundColor || '#4285F4';
            const desc = cal.description ? `<div style="font-size:11px;color:var(--text-muted);margin-top:2px;font-weight:normal;">${escapeHtml(cal.description)}</div>` : '';
            html += `
                <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:#ffffff;border:1px solid var(--border-color);border-radius:8px;cursor:pointer;transition:border-color 0.2s;">
                    <input type="checkbox" class="google-calendar-select-cb" value="${encodeURIComponent(cal.id)}" ${isChecked} onchange="handleGoogleCalendarToggle(this)" style="accent-color:var(--brown-dark);width:16px;height:16px;cursor:pointer;flex-shrink:0;">
                    <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${color};flex-shrink:0;box-shadow:0 1px 3px rgba(0,0,0,0.15);"></span>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span style="font-size:13px;font-weight:600;color:var(--text-main);">${escapeHtml(cal.summary || 'Agenda')}</span>
                            ${cal.primary ? '<span style="font-size:10px;background:var(--bg-warm);border:1px solid var(--border-color);color:var(--brown-medium);padding:1px 6px;border-radius:4px;font-weight:600;">Principal</span>' : ''}
                        </div>
                        ${desc}
                    </div>
                    <span style="font-size:11px;color:var(--text-muted);flex-shrink:0;">${escapeHtml(cal.accessRole || '')}</span>
                </label>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
        const saveBtn = document.getElementById('btn-save-google-calendars');
        if (saveBtn) saveBtn.style.display = 'inline-block';
    } catch(e) {
        console.error('loadGoogleCalendarsSettings error:', e);
        container.innerHTML = `<p style="font-size:12px;color:#dc2626;padding:8px 0;">Erro de conexão ao carregar agendas.</p>`;
    }
}

// PT: Marca ou desmarca todas as agendas do Google Calendar
window.toggleAllGoogleCalendars = function(selectAll) {
    const checkboxes = document.querySelectorAll('.google-calendar-select-cb');
    checkboxes.forEach(cb => { cb.checked = !!selectAll; });
    saveGoogleCalendarSelections(false);
};

// PT: Salva preferências de agendas Google imediatamente ao alternar checkbox
// EN: Saves selected Google Calendars preferences immediately upon checkbox toggle
// AI: @llm-context Salva preferências on change e recarrega eventos
async function handleGoogleCalendarToggle(cb) {
    await saveGoogleCalendarSelections(false);
}

// PT: Salva preferências de agendas Google selecionadas para sincronização
// EN: Saves selected Google Calendars preferences for synchronization
// AI: @llm-context POST /api/v1/svitlo/calendar/calendars
async function saveGoogleCalendarSelections(showToastMsg = true) {
    const checkboxes = document.querySelectorAll('.google-calendar-select-cb');
    const selected = [];
    checkboxes.forEach(cb => {
        if (cb.checked) {
            selected.push(decodeURIComponent(cb.value));
        }
    });

    const countBadge = document.getElementById('google-calendars-count-badge');
    if (countBadge) {
        countBadge.textContent = `(${selected.length} de ${checkboxes.length} ativas)`;
    }

    try {
        const res = await fetch(`${API_BASE}/svitlo/calendar/calendars`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({ selected: selected, calendars: selected })
        });
        const data = await res.json();
        if (res.ok && (data.status === 'saved' || data.success)) {
            if (showToastMsg) {
                showToast(`✅ ${selected.length} agenda(s) configurada(s)!`, 'success');
            }
            if (typeof loadCalendarMonth === 'function') {
                loadCalendarMonth();
            }
        } else {
            showToast(data.error || 'Erro ao salvar preferências de agenda', 'error');
        }
    } catch(e) {
/* ── ORCID INTEGRAÇÃO ── */
// PT: Executa connect orcid no ecossistema Svitlo
// EN: Executes connect orcid in Svitlo ecosystem
// AI: @llm-context Function connectORCID handles client-side state/UI for connect orcid
function connectORCID() {
    const token = (typeof getToken === 'function') ? getToken() : (localStorage.getItem('svitlo_token') || '');
    const url = `${API_BASE}/svitlo/connections/orcid/auth?token=${encodeURIComponent(token)}`;
    window.location.href = url;
}

// PT: Desconecta conta do ORCID e atualiza Mini Lattes
// EN: Disconnects ORCID account and updates Mini Lattes
// AI: @llm-context DELETE /svitlo/connections/orcid e recarrega currículo
async function disconnectORCID() {
    if (!confirm('Desconectar sua conta do ORCID?')) return;
    try {
        const res = await fetch(`${API_BASE}/svitlo/connections/orcid`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        if (res.ok) {
            showToast('ORCID desconectado', 'success');
            renderORCIDFromConnection(null);
            if (typeof loadCurriculum === 'function') loadCurriculum();
        } else {
            showToast('Erro ao desconectar ORCID', 'error');
        }
    } catch(e) {
        showToast('Erro de conexão', 'error');
    }
}

// PT: Renderiza status do ORCID em Configurações e lista de publicações importadas
// EN: Renders ORCID status in Settings and list of imported works
// AI: @llm-context Atualiza badge, exibe link para orcid.org/{orcidId} e renderiza trabalhos
async function renderORCIDFromConnection(conn) {
    const badge = document.getElementById('orcid-status-badge');
    const connectArea = document.getElementById('orcid-connect-area');
    const connectedArea = document.getElementById('orcid-connected-area');
    const infoEl = document.getElementById('orcid-info');
    const worksList = document.getElementById('orcid-works-list');

    if (conn && (conn.connected || conn.service_username || conn.username)) {
        let extra = conn.extra_data || {};
        if (typeof extra === 'string') {
            try { extra = JSON.parse(extra); } catch { extra = {}; }
        }
        const orcidId = extra.orcid || conn.username || conn.service_username || '0000-0000-0000-0000';
        const name = extra.name || '';
        const orcidUrl = extra.orcid_url || `https://orcid.org/${encodeURIComponent(orcidId)}`;

        if (badge) {
            badge.textContent = `Conectado — ORCID: ${orcidId}`;
            badge.style.color = '#166534';
        }
        if (connectArea) connectArea.style.display = 'none';
        if (connectedArea) connectedArea.style.display = 'block';
        if (infoEl) {
            const displayName = name ? `<strong>${escapeHtmlSettings(name)}</strong> ` : '';
            infoEl.innerHTML = `${displayName}<a href="${orcidUrl}" target="_blank" rel="noopener noreferrer" style="color:#15803d;font-weight:600;text-decoration:underline;margin-left:4px;">${escapeHtmlSettings(orcidId)} ↗</a>`;
        }

        const cachedWorks = Array.isArray(extra.works) ? extra.works : [];
        if (cachedWorks.length > 0 && worksList) {
            worksList.innerHTML = cachedWorks.slice(0, 5).map(w => `
                <div style="padding:6px 8px;background:#f9fafb;border-radius:4px;border-left:3px solid #16a34a;margin-bottom:4px;">
                    <div style="font-weight:600;color:var(--brown-dark);">${escapeHtmlSettings(w.title || 'Sem título')}</div>
                    <div style="color:var(--text-muted);font-size:11px;margin-top:2px;">
                        ${w.journal ? escapeHtmlSettings(w.journal) + ' • ' : ''}${w.year ? escapeHtmlSettings(String(w.year)) : ''}
                        ${w.doi ? ' • DOI: <a href="https://doi.org/' + encodeURIComponent(w.doi) + '" target="_blank" rel="noopener" style="color:#15803d;text-decoration:underline;">' + escapeHtmlSettings(w.doi) + '</a>' : ''}
                        ${w.url ? ' • <a href="' + escapeHtmlSettings(w.url) + '" target="_blank" rel="noopener" style="color:#15803d;text-decoration:underline;">Link ↗</a>' : ''}
                    </div>
                </div>
            `).join('');
        }

        try {
            const res = await fetch(`${API_BASE}/svitlo/connections/orcid/works`, {
                headers: { 'Authorization': `Bearer ${getToken()}` }
            });
            if (res.ok) {
                const data = await res.json();
                const works = data.works || [];
                if (worksList) {
                    if (works.length === 0 && cachedWorks.length === 0) {
                        worksList.innerHTML = '<span style="color:var(--text-muted);font-size:11px;">Nenhuma publicação listada no perfil público do ORCID.</span>';
                    } else if (works.length > 0) {
                        worksList.innerHTML = works.slice(0, 5).map(w => `
                            <div style="padding:6px 8px;background:#f9fafb;border-radius:4px;border-left:3px solid #16a34a;margin-bottom:4px;">
                                <div style="font-weight:600;color:var(--brown-dark);">${escapeHtmlSettings(w.title || 'Sem título')}</div>
                                <div style="color:var(--text-muted);font-size:11px;margin-top:2px;">
                                    ${w.journal ? escapeHtmlSettings(w.journal) + ' • ' : ''}${w.year ? escapeHtmlSettings(String(w.year)) : ''}
                                    ${w.doi ? ' • DOI: <a href="https://doi.org/' + encodeURIComponent(w.doi) + '" target="_blank" rel="noopener" style="color:#15803d;text-decoration:underline;">' + escapeHtmlSettings(w.doi) + '</a>' : ''}
                                    ${w.url ? ' • <a href="' + escapeHtmlSettings(w.url) + '" target="_blank" rel="noopener" style="color:#15803d;text-decoration:underline;">Link ↗</a>' : ''}
                                </div>
                            </div>
                        `).join('');
                    }
                }
            }
        } catch(e) {
            console.warn('orcid works error:', e);
        }
    } else {
        if (badge) {
            badge.textContent = 'Não conectado';
            badge.style.color = '#999';
        }
        if (connectArea) connectArea.style.display = 'block';
        if (connectedArea) connectedArea.style.display = 'none';
        if (infoEl) infoEl.innerHTML = '';
        if (worksList) worksList.innerHTML = '';
    }
}

function escapeHtmlSettings(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// PT: Carrega perfis disponíveis e perfis do usuário em paralelo
// PT: Carrega perfis disponíveis e perfis do usuário com tratamento defensivo completo
// EN: Loads available roles and user roles in parallel with defensive error handling
// AI: @llm-context GET /api/v1/svitlo/roles + GET /api/v1/svitlo/profile/roles
async function loadRolesSection() {
  const container = document.getElementById('roles-selector');
  if (!container) return;

  const defaultRoles = [
    { slug: 'student', name: 'Estudante', icon: '🎓' },
    { slug: 'teacher', name: 'Professor', icon: '👨‍🏫' },
    { slug: 'monitor', name: 'Monitor', icon: '🧑‍💻' },
    { slug: 'autodidact', name: 'Autodidata', icon: '📚' }
  ];

  try {
    const token = getToken();
    const [availRes, userRes] = await Promise.all([
      fetch(`${API_BASE}/svitlo/roles`).catch(() => null),
      fetch(`${API_BASE}/svitlo/profile/roles`, {
        headers: { 'Authorization': 'Bearer ' + token }
      }).catch(() => null)
    ]);

    let availRoles = [];
    if (availRes?.ok) {
      try {
        const d = await availRes.json();
        availRoles = Array.isArray(d.roles) ? d.roles : [];
      } catch (_) {}
    }

    let userRoles = [];
    if (userRes?.ok) {
      try {
        const ud = await userRes.json();
        userRoles = Array.isArray(ud.roles) ? ud.roles : [];
      } catch (_) {}
    }

    // Se a API não retornou perfis, usa os perfis pré-configurados do Svitlo
    if (!availRoles.length) {
      availRoles = defaultRoles;
    }

    const userSlugs = userRoles.map(r => r.slug || r);

    container.innerHTML = availRoles.map(r => {
      const isSel = userSlugs.includes(r.slug) || (userSlugs.length === 0 && r.slug === 'student');
      return `
      <label class="role-chip ${isSel ? 'active selected' : ''}" data-role="${r.slug}">
        <input type="checkbox" value="${r.slug}" ${isSel ? 'checked' : ''}>
        <span>${r.icon || '👤'}</span>
        <span>${r.name}</span>
      </label>
    `;
    }).join('');

    initRoleChips();
  } catch (err) {
    console.warn('[Svitlo] Falha ao carregar perfis, aplicando fallback:', err);
    container.innerHTML = defaultRoles.map(r => `
      <label class="role-chip ${r.slug === 'student' ? 'active selected' : ''}" data-role="${r.slug}">
        <input type="checkbox" value="${r.slug}" ${r.slug === 'student' ? 'checked' : ''}>
        <span>${r.icon || '👤'}</span>
        <span>${r.name}</span>
      </label>
    `).join('');
    initRoleChips();
  }
}

// PT: Inicializa os chips de perfil com toggle de classe .selected e .active
// EN: Initializes role chips with .selected and .active class toggling
function initRoleChips() {
  const container = document.getElementById('roles-selector');
  if (!container) return;

  container.querySelectorAll('.role-chip').forEach(chip => {
    const input = chip.querySelector('input');
    if (!input) return;

    const syncState = () => {
      chip.classList.toggle('active', input.checked);
      chip.classList.toggle('selected', input.checked);
    };

    input.addEventListener('change', syncState);
    syncState();
  });
}

// PT: Salva perfis selecionados e recarrega turmas
// EN: Saves selected roles and reloads classes
// AI: @llm-context PUT /api/v1/svitlo/profile/roles — triggers class matching on backend
async function saveRoles() {
  const token = getToken();
  const checkedInputs = document.querySelectorAll('#roles-selector input:checked');
  const activeChips = document.querySelectorAll('#roles-selector .role-chip.selected, #roles-selector .role-chip.active');
  
  const rolesSet = new Set();
  checkedInputs.forEach(cb => rolesSet.add(cb.value));
  activeChips.forEach(chip => {
    const r = chip.dataset.role || chip.querySelector('input')?.value;
    if (r) rolesSet.add(r);
  });
  
  const roles = Array.from(rolesSet);
  if (!roles.length) { 
    showToast('Selecione ao menos um perfil (ex: Estudante).', 'error'); 
    return; 
  }

  // Persistência local imediata
  try {
    localStorage.setItem('svitlo_user_roles', JSON.stringify(roles));
  } catch (_) {}

  try {
    let res = await fetch(`${API_BASE}/svitlo/profile/roles`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify({ roles, profiles: roles })
    }).catch(() => null);

    if (!res || !res.ok) {
      // Fallback para endpoint /svitlo/profile
      res = await fetch(`${API_BASE}/svitlo/profile`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ roles, profiles: roles })
      }).catch(() => null);
    }

    if (res?.ok) {
      showToast('Perfis acadêmicos salvos com sucesso! ✅', 'success');
      if (typeof loadUserClasses === 'function') loadUserClasses();
    } else {
      showToast('Perfis salvos localmente.', 'info');
      if (typeof loadUserClasses === 'function') loadUserClasses();
    }
  } catch (e) {
    showToast('Perfis salvos localmente no navegador.', 'info');
  }
}

// PT: Carrega turmas do usuário e renderiza defensivamente sem travar
// EN: Loads user classes and renders defensively without hanging
// AI: @llm-context GET /api/v1/svitlo/classes
async function loadUserClasses() {
  const container = document.getElementById('user-classes-list');
  if (!container) return;

  const renderEmptyClasses = () => {
    container.innerHTML = `
      <div style="padding:16px;background:var(--bg-card, #FAF7F2);border:1px dashed var(--border-color);border-radius:8px;text-align:center;">
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:10px;">
          Nenhuma turma detectada ainda. Complete seu perfil acadêmico ou adicione uma turma manualmente.
        </p>
        <button type="button" class="btn-primary" style="padding:6px 16px;font-size:12.5px;display:inline-flex;align-items:center;gap:6px;" onclick="if(typeof openJoinClassModal==='function'){openJoinClassModal();}else if(typeof openCreateClassModal==='function'){openCreateClassModal();}else{showToast('Adicionar Turma','info');}">
          <span>➕</span> Adicionar Perfil / Turma
        </button>
      </div>`;
  };

  try {
    const token = getToken();
    const res = await fetch(`${API_BASE}/svitlo/classes`, {
      headers: { 'Authorization': 'Bearer ' + token }
    }).catch(() => null);

    if (!res || !res.ok) {
      renderEmptyClasses();
      return;
    }

    let data;
    try {
      data = await res.json();
    } catch (_) {
      renderEmptyClasses();
      return;
    }

    const classes = Array.isArray(data?.classes) ? data.classes : [];

    if (!classes.length) {
      renderEmptyClasses();
      return;
    }

    container.innerHTML = classes.map(c => `
      <div style="display:flex;align-items:center;justify-content:space-between;
                  padding:12px 16px;background:#fff;border:1px solid var(--border-color);
                  border-radius:8px;margin-bottom:8px;">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--brown-dark);">
            ${c.discipline_name || c.name}
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
            ${c.institution || 'Instituição'} · ${c.semester || 'Geral'}
            · <strong>${c.member_count || 1} membro${(c.member_count || 1) !== 1 ? 's' : ''}</strong>
            · <em>Você: ${c.my_role === 'teacher' ? 'Professor' : c.my_role === 'monitor' ? 'Monitor' : 'Aluno'}</em>
            ${c.auto_created ? ' · <span style="font-size:10px;background:#EFF6FF;color:#1D4ED8;padding:1px 6px;border-radius:8px;">automática</span>' : ''}
          </div>
        </div>
        <div style="display:flex;gap:6px;">
          <button onclick="viewClassMembers(${c.id})"
            style="padding:5px 10px;font-size:11px;background:transparent;
                   border:1px solid var(--border-color);border-radius:6px;cursor:pointer;">
            Ver turma
          </button>
          <button onclick="leaveClass(${c.id})"
            style="padding:5px 10px;font-size:11px;background:transparent;
                   border:1px solid #FCA5A5;border-radius:6px;cursor:pointer;color:#991B1B;">
            Sair
          </button>
        </div>
      </div>`).join('');
  } catch (err) {
    console.warn('[Svitlo] Falha ao carregar turmas:', err);
    renderEmptyClasses();
  }
}

// PT: Sai de uma turma automática
// EN: Leaves an automatic class
// AI: @llm-context POST /api/v1/svitlo/classes/{id}/leave
async function leaveClass(classId) {
  if (!confirm('Sair desta turma? Você pode entrar novamente depois.')) return;
  const token = getToken();
  const res = await fetch(`${API_BASE}/svitlo/classes/${classId}/leave`, {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token }
  }).catch(() => null);
  if (res?.ok) { showToast('Você saiu da turma.', 'success'); loadUserClasses(); }
  else showToast('Erro ao sair da turma.', 'error');
}

// PT: Abre modal com membros da turma
// EN: Opens modal with class members
// AI: @llm-context GET /api/v1/svitlo/classes/{id}/members
async function viewClassMembers(classId) {
  const token = getToken();
  const res = await fetch(`${API_BASE}/svitlo/classes/${classId}/members`, {
    headers: { 'Authorization': 'Bearer ' + token }
  }).catch(() => null);
  if (!res?.ok) { showToast('Erro ao carregar turma.', 'error'); return; }
  const data = await res.json();
  const members = data.members || [];

  const modal = document.getElementById('modal-class-members');
  const content = document.getElementById('modal-class-members-content');
  if (!modal || !content) return;

  content.innerHTML = members.map(m => `
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;
                border-bottom:1px solid var(--border-color);">
      <div style="width:32px;height:32px;border-radius:50%;background:var(--cream);
                  display:flex;align-items:center;justify-content:center;
                  font-size:14px;font-weight:700;color:var(--brown-dark);flex-shrink:0;">
        ${m.name[0].toUpperCase()}
      </div>
      <div>
        <div style="font-size:13px;font-weight:600;color:var(--brown-dark);">${m.name}</div>
        <div style="font-size:11px;color:var(--text-muted);">
          ${m.role === 'teacher' ? '👨‍🏫 Professor' : m.role === 'monitor' ? '🧑‍💻 Monitor' : '🎓 Aluno'}
        </div>
      </div>
    </div>`).join('') || '<p style="font-size:13px;color:var(--text-muted);">Nenhum membro encontrado.</p>';

  modal.style.display = 'flex';
}

// PT: Inicializa a view de configurações carregando dados de conta, perfil, conexões e versão
// EN: Initializes the settings view loading account data, profile, connections, and version
// AI: @llm-context Executa chamadas assíncronas em paralelo via Promise.all para rápida inicialização.
async function initSettingsView() {
    await Promise.all([
        loadAccountSettings(), 
        loadAcademicProfile(), 
        loadConnectionsStatus(), 
        loadUnifiedGoogleStatus(),
        loadCurriculum(),
        loadNeuralProfile(),
        loadCalendarIntegrations(),
        loadGoogleCalendarsSettings(),
        loadAppVersion(),
        loadNeuroProfile(),
        loadRolesSection(),
        loadUserClasses()
    ]);
    loadCognitiveProfileInSettings();
    if (typeof loadStudyTelemetry === 'function') {
        loadStudyTelemetry();
    }
}

// PT: Exporta explicitamente no escopo global / EN: Explicitly export to global scope
// AI: @llm-context Garante que funções estejam disponíveis para atributos onclick no app.html
window.saveAccountSettings = saveAccountSettings;
window.loadAccountSettings = loadAccountSettings;
window.saveAcademicProfile = saveAcademicProfile;
window.loadAcademicProfile = loadAcademicProfile;
window.loadRolesSection = loadRolesSection;
window.saveRoles = saveRoles;
window.loadUserClasses = loadUserClasses;
window.leaveClass = leaveClass;
window.viewClassMembers = viewClassMembers;
window.connectLastFM = connectLastFM;
window.connectSpotify = connectSpotify;
window.connectDeezer = connectDeezer;
window.connectAnki = connectAnki;
window.disconnectService = disconnectService;
window.saveCognitiveSettings = saveCognitiveSettings;
window.deleteCognitiveProfile = deleteCognitiveProfile;
window.loadCognitiveProfileInSettings = loadCognitiveProfileInSettings;
window.loadConnectionsStatus = loadConnectionsStatus;
window.loadCurriculum = loadCurriculum;
window.openCurriculumModal = openCurriculumModal;
window.closeCurriculumModal = closeCurriculumModal;
window.adaptCurriculumFormFields = adaptCurriculumFormFields;
window.handleCurriculumSubmit = handleCurriculumSubmit;
window.deleteCurriculumEntry = deleteCurriculumEntry;
window.exportCurriculumPdf = exportCurriculumPdf;
window.exportCurriculumXml = exportCurriculumXml;
window.loadNeuralProfile = loadNeuralProfile;
window.analyzeMusicNeural = analyzeMusicNeural;
window.analyzeFullNeuralProfile = analyzeFullNeuralProfile;
window.loadCalendarIntegrations = loadCalendarIntegrations;
window.connectGoogle = connectGoogle;
window.disconnectGoogle = disconnectGoogle;
window.syncAllGoogle = syncAllGoogle;
window.loadUnifiedGoogleStatus = loadUnifiedGoogleStatus;
window.focusGoogleUnified = focusGoogleUnified;
window.connectGoogleCalendar = connectGoogleCalendar;
window.syncGoogleCalendar = syncGoogleCalendar;
window.copyIcalFeedUrl = copyIcalFeedUrl;
window.saveCalendarIntegrationSettings = saveCalendarIntegrationSettings;
window.loadGoogleCalendarsSettings = loadGoogleCalendarsSettings;
window.saveGoogleCalendarSelections = saveGoogleCalendarSelections;
window.handleGoogleCalendarToggle = handleGoogleCalendarToggle;
window.loadAppVersion = loadAppVersion;
window.initSettingsView = initSettingsView;
window.connectGoogleHealth = connectGoogleHealth;
window.loadHealthDashboard = loadHealthDashboard;
window.loadNeuroProfile = loadNeuroProfile;
window.saveNeuroProfile = saveNeuroProfile;
window.classifyIQ = classifyIQ;
window.classifyPercentile = classifyPercentile;
window.setupNeuroListeners = setupNeuroListeners;
window.connectORCID = connectORCID;
window.disconnectORCID = disconnectORCID;
window.renderORCIDFromConnection = renderORCIDFromConnection;
window.syncAnki = typeof syncAnkiWeb === 'function' ? syncAnkiWeb : (window.syncAnkiWeb || function() { showToast('Sincronizando AnkiWeb...', 'info'); });

window.initRoleChips = initRoleChips;

// PT: Inicializa listeners dos campos neuro e chips de perfil ao carregar o script ou DOM
// EN: Initializes neuro field listeners and role chips on script or DOM load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        setupNeuroListeners();
        initRoleChips();
    });
} else {
    setupNeuroListeners();
    initRoleChips();
}

// Remove provider AI key (Groq / Gemini) with pre-evaluated URL / Remove chave de IA do provedor (Groq / Gemini) com URL pré-avaliada
async function removeProviderAIKey(provider) {
    const cleanProvider = encodeURIComponent(String(provider || '').trim());
    if (!cleanProvider) return;
    const url = `${API_BASE}/svitlo/ai/keys/${cleanProvider}`;
    try {
        const res = await fetch(url, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        if (res.ok) {
            const btn = document.getElementById(`btn-remove-${cleanProvider}-key`);
            if (btn) {
                btn.hidden = true;
                btn.style.display = 'none';
            }
            if (typeof showToast === 'function') showToast('Chave removida com sucesso!', 'success');
        }
    } catch (err) {
        console.error('removeProviderAIKey error:', err);
    }
}

// Remove custom BYOAI key with pre-evaluated URL / Remove chave BYOAI personalizada com URL pré-avaliada
async function removeCustomAIKey() {
    const url = `${API_BASE}/svitlo/ai/keys/custom`;
    try {
        const res = await fetch(url, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${getToken()}` }
        });
        if (res.ok) {
            const btn = document.getElementById('byoai-custom-delete-btn');
            if (btn) {
                btn.hidden = true;
                btn.style.display = 'none';
            }
            if (typeof showToast === 'function') showToast('Chave personalizada removida!', 'success');
        }
    } catch (err) {
        console.error('removeCustomAIKey error:', err);
    }
}

window.removeProviderAIKey = removeProviderAIKey;
window.removeCustomAIKey = removeCustomAIKey;
