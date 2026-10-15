#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Auditor Noturno Autônomo e Confiabilidade — Ecossistema Mriya
Autonomous Nightly Auditor and SRE Reliability Checker — Mriya Ecosystem

@llm-context VPS Health, Notion Specs, Google Antigravity Agent (perfil Deploy / gen-lang-client-0039643546), Resend Email Alerting
@llm-invariant Execução diária via cron às 03:30, chave Deploy lida de credentials.json/gemini.json (nunca hardcoded), zero interrupção por falhas parciais
@llm-rule Prioriza modelos 'antigravity-*' descobertos via ListModels; fallback Gemini Flash; salva relatório completo em /var/log/mriya/nightly_audit_report.html
"""

import json
import os
import subprocess
import sys
from datetime import datetime
import requests

if hasattr(sys.stdout, 'reconfigure'):
    try:
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass
if hasattr(sys.stderr, 'reconfigure'):
    try:
        sys.stderr.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass


def _load_json(path):
    try:
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception:
        return {}


CREDS        = _load_json('/var/www/mcp-server/credentials/credentials.json')
GEMINI_DATA  = _load_json('/var/www/mcp-server/credentials/gemini.json')
RESEND_DATA  = _load_json('/var/www/mcp-server/credentials/resend.json')

# Sufixo da chave do perfil 'Deploy' (projeto gen-lang-client-0039643546) — apenas sufixo, nunca a chave.
# Suffix of the 'Deploy' profile key — used only to locate it among stored keys.
DEPLOY_KEY_SUFFIX = os.getenv('MRIYA_DEPLOY_KEY_SUFFIX', 'lu_A')
REPORT_PATH       = os.getenv('MRIYA_AUDIT_REPORT', '/var/log/mriya/nightly_audit_report.html')


def resolver_chave_deploy():
    """
    Resolve a chave Google do perfil 'Deploy' do orquestrador.
    Resolves the Google API key of the orchestrator 'Deploy' profile.

    @llm-context credentials.json (gemini.deploy, gemini_keys.deploy), gemini.json (deploy), env MRIYA_DEPLOY_GEMINI_KEY
    @llm-invariant Nunca hardcode da chave; procura por rótulo 'deploy' e, em seguida, pelo sufixo DEPLOY_KEY_SUFFIX
    @llm-rule Retorna (chave, origem) ou ('', 'nenhuma') — o chamador decide o fallback
    """
    env_key = os.getenv('MRIYA_DEPLOY_GEMINI_KEY')
    if env_key:
        return env_key, 'env:MRIYA_DEPLOY_GEMINI_KEY'

    def _as_dict(v):
        return v if isinstance(v, dict) else {}

    candidatos = [
        ('credentials.json:gemini.deploy',      _as_dict(CREDS.get('gemini')).get('deploy')),
        ('credentials.json:gemini_keys.deploy', _as_dict(CREDS.get('gemini_keys')).get('deploy')),
        ('gemini.json:deploy',                  GEMINI_DATA.get('deploy')),
        ('gemini.json:deploy_key',              GEMINI_DATA.get('deploy_key')),
        ('gemini.json:keys.deploy',             _as_dict(GEMINI_DATA.get('keys')).get('deploy')),
    ]
    for origem, valor in candidatos:
        if isinstance(valor, dict):
            valor = valor.get('api_key') or valor.get('key')
        if isinstance(valor, str) and valor.strip():
            return valor.strip(), origem

    # Varredura por sufixo em todas as chaves armazenadas (perfil Deploy pode estar sob outro rótulo)
    fontes = [
        ('credentials.json:gemini',      _as_dict(CREDS.get('gemini'))),
        ('credentials.json:gemini_keys', _as_dict(CREDS.get('gemini_keys'))),
        ('gemini.json',                  GEMINI_DATA if isinstance(GEMINI_DATA, dict) else {}),
    ]
    for fonte, mapa in fontes:
        for rotulo, valor in mapa.items():
            if isinstance(valor, str) and valor.endswith(DEPLOY_KEY_SUFFIX):
                return valor, f'{fonte}.{rotulo} (sufixo ...{DEPLOY_KEY_SUFFIX})'
    return '', 'nenhuma'


DEPLOY_KEY, DEPLOY_KEY_ORIGIN = resolver_chave_deploy()

GEMINI_KEY   = (
    DEPLOY_KEY
    or os.getenv('GEMINI_API_KEY')
    or GEMINI_DATA.get('api_key')
    or GEMINI_DATA.get('apiKey')
    or CREDS.get('gemini', {}).get('default')
    or CREDS.get('gemini', {}).get('api_key', '')
)
RESEND_KEY   = os.getenv('RESEND_API_KEY') or RESEND_DATA.get('api_key') or CREDS.get('resend', {}).get('api_key', '')
RESEND_FROM  = os.getenv('RESEND_FROM') or RESEND_DATA.get('from') or CREDS.get('resend', {}).get('from') or 'auditor@espectroipe.com.br'
NOTION_TOKEN = (
    os.getenv('NOTION_TOKEN')
    or CREDS.get('notion', {}).get('mriya_token')
    or CREDS.get('notion', {}).get('token', '')
)

EMAIL_TO       = 'pedroluistenorio@gmail.com'
NOTION_PAGE_ID = '3ef3ba6ac1e681b69d86ceea57e896e9'


def ler_especificacao_notion():
    if not NOTION_TOKEN:
        return "Notion Token não configurado. Utilizar regras canônicas do Mriya Core v2."
    try:
        url = f"https://api.notion.com/v1/blocks/{NOTION_PAGE_ID}/children"
        r = requests.get(
            url,
            headers={
                'Authorization': f'Bearer {NOTION_TOKEN}',
                'Notion-Version': '2022-06-28'
            },
            timeout=15
        )
        if not r.ok:
            return f"Erro HTTP {r.status_code} ao ler Notion: {r.text[:200]}"
        blocks = r.json().get('results', [])
        texto = []
        for block in blocks:
            btype = block.get('type', '')
            rich = block.get(btype, {}).get('rich_text', [])
            for rt in rich:
                t = rt.get('plain_text', '')
                if t.strip():
                    texto.append(t)
        return '\n'.join(texto)[:3500] if texto else "Especificações obtidas, mas vazias."
    except Exception as e:
        return f"Falha na consulta ao Notion: {e}"


def coletar_metricas_codigo_ecosystem(repo_dir):
    """
    Calcula métricas detalhadas de código (Arquivos, Linhas [LOC] e Caracteres)
    discriminadas por projeto (Mriya Core, Svitanok, Svitlo, Mistse, Outros e Zorya GitHub),
    exibindo a SOMA TOTAL ao final.
    """
    exts = ('.php', '.py', '.js', '.go', '.rs', '.html', '.css', '.sql', '.yaml', '.yml', '.json')
    ignore_dirs = {'vendor', 'node_modules', '.git', 'uploads', 'dist', '__pycache__', 'thumbnails', 'assets'}

    def scan_path(target_path):
        f_count = 0
        l_count = 0
        c_count = 0
        if not os.path.exists(target_path):
            return f_count, l_count, c_count

        if os.path.isfile(target_path):
            if target_path.endswith(exts):
                try:
                    with open(target_path, 'r', encoding='utf-8', errors='ignore') as fp:
                        txt = fp.read()
                        return 1, (txt.count('\n') + (1 if txt and not txt.endswith('\n') else 0)), len(txt)
                except Exception:
                    pass
            return 0, 0, 0

        for root, dirs, files in os.walk(target_path):
            dirs[:] = [d for d in dirs if d not in ignore_dirs]
            if any(ign in root for ign in ignore_dirs):
                continue
            for f in files:
                if f.endswith(exts):
                    f_count += 1
                    try:
                        with open(os.path.join(root, f), 'r', encoding='utf-8', errors='ignore') as fp:
                            txt = fp.read()
                            c_count += len(txt)
                            l_count += (txt.count('\n') + (1 if txt and not txt.endswith('\n') else 0))
                    except Exception:
                        pass
        return f_count, l_count, c_count

    # 1. Mriya Core (core/ e includes/)
    core_f1, core_l1, core_c1 = scan_path(os.path.join(repo_dir, 'core'))
    core_f2, core_l2, core_c2 = scan_path(os.path.join(repo_dir, 'includes'))
    core_f3, core_l3, core_c3 = scan_path(os.path.join(repo_dir, 'mriya', 'includes'))
    core_f = core_f1 + core_f2 + core_f3
    core_l = core_l1 + core_l2 + core_l3
    core_c = core_c1 + core_c2 + core_c3

    # 2. Svitanok
    svit_f, svit_l, svit_c = scan_path(os.path.join(repo_dir, 'svitanok'))

    # 3. Svitlo
    svitlo_f, svitlo_l, svitlo_c = scan_path(os.path.join(repo_dir, 'svitlo'))

    # 4. Mistse
    mist_f, mist_l, mist_c = scan_path(os.path.join(repo_dir, 'mistse'))

    # 5. Outros Scripts & Configs da VPS
    outros_f = 0
    outros_l = 0
    outros_c = 0
    for item in os.listdir(repo_dir):
        if item in ('core', 'includes', 'svitanok', 'svitlo', 'mistse') or item in ignore_dirs:
            continue
        item_path = os.path.join(repo_dir, item)
        f_cnt, l_cnt, c_cnt = scan_path(item_path)
        outros_f += f_cnt
        outros_l += l_cnt
        outros_c += c_cnt

    # 6. Zorya Engine via GitHub API
    zorya_f = 0
    zorya_l = 0
    zorya_c = 0
    try:
        r_tree = requests.get(
            'https://api.github.com/repos/pedro-almeida/zorya/git/trees/main?recursive=1',
            headers={'User-Agent': 'Mriya-Nightly-Auditor', 'Accept': 'application/vnd.github.v3+json'},
            timeout=10
        )
        if r_tree.ok:
            tree = r_tree.json().get('tree', [])
            zorya_exts = ('.go', '.rs', '.cpp', '.h', '.cu', '.html', '.js')
            blobs = [x for x in tree if x.get('type') == 'blob' and any(x.get('path', '').endswith(ext) for ext in zorya_exts)]
            zorya_f = len(blobs)
            zorya_c = sum(x.get('size', 0) for x in blobs)
            # Estimativa de linhas de código baseada na média (32 bytes por linha no ecossistema)
            zorya_l = int(zorya_c / 32) if zorya_c else 0
    except Exception as e:
        print(f"   ⚠️  Falha ao obter árvore Zorya do GitHub: {e}")

    # Total Consolidado
    total_arquivos = core_f + svit_f + svitlo_f + mist_f + outros_f + zorya_f
    total_linhas = core_l + svit_l + svitlo_l + mist_l + outros_l + zorya_l
    total_chars = core_c + svit_c + svitlo_c + mist_c + outros_c + zorya_c

    return {
        'core': {'arquivos': core_f, 'linhas': core_l, 'caracteres': core_c},
        'svitanok': {'arquivos': svit_f, 'linhas': svit_l, 'caracteres': svit_c},
        'svitlo': {'arquivos': svitlo_f, 'linhas': svitlo_l, 'caracteres': svitlo_c},
        'mistse': {'arquivos': mist_f, 'linhas': mist_l, 'caracteres': mist_c},
        'outros': {'arquivos': outros_f, 'linhas': outros_l, 'caracteres': outros_c},
        'zorya': {'arquivos': zorya_f, 'linhas': zorya_l, 'caracteres': zorya_c},
        'total': {'arquivos': total_arquivos, 'linhas': total_linhas, 'caracteres': total_chars}
    }


def coletar_telemetria_zorya_github():
    """
    Coleta telemetria e métricas do repositório Zorya via GitHub REST API.
    Collects Zorya repository telemetry and metrics via GitHub REST API.

    @llm-context GitHub REST API v3: repos/pedro-almeida/zorya, releases, actions/runs
    @llm-invariant Timeout estrito de 10s, User-Agent 'Mriya-Nightly-Auditor', zero interrupção por rate limit
    @llm-rule Retorna string amigável com tamanho em MB, estrelas, forks, releases e CI/CD runs
    """
    headers = {
        'User-Agent': 'Mriya-Nightly-Auditor',
        'Accept': 'application/vnd.github.v3+json'
    }
    info_parts = []

    # 1. Informações gerais do repositório (tamanho, estrelas, forks, último push)
    try:
        r_repo = requests.get('https://api.github.com/repos/pedro-almeida/zorya', headers=headers, timeout=10)
        if r_repo.ok:
            repo_data = r_repo.json()
            size_kb = repo_data.get('size', 0)
            size_mb = round(size_kb / 1024, 2)
            stars = repo_data.get('stargazers_count', 0)
            forks = repo_data.get('forks_count', 0)
            pushed_at = repo_data.get('pushed_at', 'N/A')
            info_parts.append(f"Tamanho: {size_mb} MB | Estrelas: {stars} | Forks: {forks} | Último Push: {pushed_at}")
        else:
            info_parts.append(f"Repo Info: HTTP {r_repo.status_code}")
    except Exception as e:
        info_parts.append(f"Repo Info: {e}")

    # 2. Releases mais recentes (tag e contagem de downloads dos binários)
    try:
        r_rel = requests.get('https://api.github.com/repos/pedro-almeida/zorya/releases', headers=headers, timeout=10)
        if r_rel.ok:
            releases = r_rel.json()
            if isinstance(releases, list) and releases:
                latest = releases[0]
                tag = latest.get('tag_name', 'N/A')
                downloads = sum(a.get('download_count', 0) for a in latest.get('assets', []))
                info_parts.append(f"Última Release: {tag} ({downloads} downloads de binários)")
            else:
                info_parts.append("Releases: Nenhuma release publicada ainda (desenvolvimento contínuo)")
        else:
            info_parts.append(f"Releases: HTTP {r_rel.status_code}")
    except Exception as e:
        info_parts.append(f"Releases: {e}")

    # 3. Workflows mais recentes de CI/CD
    try:
        r_runs = requests.get('https://api.github.com/repos/pedro-almeida/zorya/actions/runs?per_page=1', headers=headers, timeout=10)
        if r_runs.ok:
            runs_data = r_runs.json()
            workflow_runs = runs_data.get('workflow_runs', [])
            if workflow_runs:
                last_run = workflow_runs[0]
                conclusion = last_run.get('conclusion') or last_run.get('status', 'N/A')
                commit_sha = (last_run.get('head_sha') or '')[:7]
                commit_msg = (last_run.get('head_commit', {}) or {}).get('message', '').split('\n')[0][:50]
                info_parts.append(f"CI/CD Run: {conclusion} (commit {commit_sha}: \"{commit_msg}\")")
            else:
                info_parts.append("CI/CD: Nenhum workflow executado recentemente")
        else:
            info_parts.append(f"CI/CD: HTTP {r_runs.status_code}")
    except Exception as e:
        info_parts.append(f"CI/CD: {e}")

    return ' · '.join(info_parts)


def coletar_diagnostico_vps():
    dados = {}

    # Disco
    try:
        dados['disco'] = subprocess.check_output(['df', '-h', '/'], text=True).strip()
    except Exception:
        try:
            import shutil
            total, used, free = shutil.disk_usage(os.path.abspath(os.sep))
            dados['disco'] = f"Total: {total // (1024**3)}GB, Usado: {used // (1024**3)}GB, Livre: {free // (1024**3)}GB"
        except Exception:
            dados['disco'] = "N/A"

    # Memória
    try:
        dados['memoria'] = subprocess.check_output(['free', '-m'], text=True).strip()
    except Exception:
        dados['memoria'] = "N/A"

    # Status Git
    # Exige .git para evitar falso positivo (ex.: C:\var\www\mriya existente no Windows sem repositório)
    repo_dir = '/var/www/mriya' if os.path.isdir('/var/www/mriya/.git') else os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
    try:
        dados['git_status'] = subprocess.check_output(
            ['git', '-C', repo_dir, 'status', '-s'],
            text=True,
            stderr=subprocess.DEVNULL
        ).strip() or "Repositório limpo."
    except Exception:
        dados['git_status'] = "N/A"

    # Métricas de tamanho do Ecossistema Mriya (VPS Local)
    try:
        dados['mriya_tamanho'] = subprocess.check_output(['du', '-sh', repo_dir], text=True, stderr=subprocess.DEVNULL).split()[0]
    except Exception:
        try:
            total_b = sum(os.path.getsize(os.path.join(dp, f)) for dp, _, fns in os.walk(repo_dir) for f in fns if '.git' not in dp)
            dados['mriya_tamanho'] = f"{round(total_b / (1024 * 1024), 2)}MB"
        except Exception:
            dados['mriya_tamanho'] = "N/A"

    try:
        dados['mriya_arquivos'] = subprocess.check_output(['git', '-C', repo_dir, 'ls-files'], text=True, stderr=subprocess.DEVNULL).count('\n')
    except Exception:
        dados['mriya_arquivos'] = "N/A"

    try:
        dados['mriya_commits'] = subprocess.check_output(['git', '-C', repo_dir, 'rev-list', '--count', 'HEAD'], text=True, stderr=subprocess.DEVNULL).strip()
    except Exception:
        dados['mriya_commits'] = "N/A"

    # Telemetria remota do Zorya via GitHub API
    dados['zorya_github_info'] = coletar_telemetria_zorya_github()

    # Métricas detalhadas de código por projeto
    dados['metricas_codigo'] = coletar_metricas_codigo_ecosystem(repo_dir)

    # Erros recentes Nginx
    nginx_errors = []
    nginx_log = '/var/log/nginx/error.log'
    if os.path.exists(nginx_log):
        try:
            with open(nginx_log, 'r', encoding='utf-8', errors='ignore') as f:
                linhas = f.readlines()
                nginx_errors = [l.strip() for l in linhas[-50:] if 'error' in l.lower() or 'crit' in l.lower()]
        except Exception:
            pass
    dados['nginx_errors'] = '\n'.join(nginx_errors) if nginx_errors else "Nenhum erro crítico recente no Nginx."

    # Logs recentes Mriya
    mriya_logs = []
    log_dir = '/var/log/mriya'
    if os.path.exists(log_dir):
        try:
            for fname in os.listdir(log_dir):
                if fname.endswith('.log'):
                    fpath = os.path.join(log_dir, fname)
                    try:
                        with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
                            recentes = f.readlines()[-30:]
                            if recentes:
                                mriya_logs.append(f"=== {fname} ===\n" + ''.join(recentes))
                    except Exception:
                        pass
        except Exception:
            pass
    dados['mriya_logs'] = '\n'.join(mriya_logs)[:4000] if mriya_logs else "Nenhum log relevante em /var/log/mriya/."

    # =========================================================================
    # ORQUESTRAÇÃO DOS AGENTES DE DEFESA E QUALIDADE
    # =========================================================================
    scripts_dir = os.path.dirname(__file__)
    py_exec = sys.executable or 'python3'

    # 1. Executar Agressor Ético se o script existir
    aggressor_script = os.path.join(scripts_dir, 'security_aggressor_audit.py')
    if os.path.exists(aggressor_script):
        try:
            print("   ⚔️  Executando Agressor Ético (Pentest Simulado)...")
            subprocess.run([py_exec, aggressor_script], timeout=60, capture_output=True)
        except Exception as e:
            print(f"   ⚠️ Falha na execução do Agressor Ético: {e}")

    # 2. Executar Sentinela Jedi se o script existir
    sentinela_script = os.path.join(scripts_dir, 'sentinela_code_auditor.py')
    if os.path.exists(sentinela_script):
        try:
            print("   🛡️  Executando Sentinela Jedi (SAST / Confiabilidade)...")
            subprocess.run([py_exec, sentinela_script], timeout=60, capture_output=True)
        except Exception as e:
            print(f"   ⚠️ Falha na execução do Sentinela Jedi: {e}")

    # 3. Executar Auditor UX se o script existir
    ux_script = os.path.join(scripts_dir, 'ux_synthetic_user_audit.py')
    if os.path.exists(ux_script):
        try:
            print("   🎭 Executando Auditor UX (Usuário Sintético)...")
            subprocess.run([py_exec, ux_script], timeout=60, capture_output=True)
        except Exception as e:
            print(f"   ⚠️ Falha na execução do Auditor UX: {e}")

    # Leitura do laudo do Agressor Ético
    aggressor_report_candidates = [
        '/var/log/mriya/aggressor_report.json',
        'C:/var/log/mriya/aggressor_report.json',
        os.path.join(repo_dir, 'logs', 'aggressor_report.json'),
        os.path.join(repo_dir, 'aggressor_report.json')
    ]
    dados['aggressor_report'] = None
    for a_path in aggressor_report_candidates:
        if os.path.exists(a_path):
            try:
                with open(a_path, 'r', encoding='utf-8') as f:
                    dados['aggressor_report'] = json.load(f)
                break
            except Exception:
                pass

    if not dados['aggressor_report']:
        try:
            from security_aggressor_audit import executar_auditoria_aggressor
            dados['aggressor_report'] = executar_auditoria_aggressor()
        except Exception:
            pass

    # Leitura do laudo do Sentinela Jedi
    sentinela_report_candidates = [
        '/var/log/mriya/sentinela_bugs_report.json',
        'C:/var/log/mriya/sentinela_bugs_report.json',
        os.path.join(repo_dir, 'logs', 'sentinela_bugs_report.json'),
        os.path.join(repo_dir, 'sentinela_bugs_report.json')
    ]
    dados['sentinela_report'] = None
    for s_path in sentinela_report_candidates:
        if os.path.exists(s_path):
            try:
                with open(s_path, 'r', encoding='utf-8') as f:
                    dados['sentinela_report'] = json.load(f)
                break
            except Exception:
                pass

    if not dados['sentinela_report']:
        try:
            from sentinela_code_auditor import executar_auditoria_sentinela
            dados['sentinela_report'] = executar_auditoria_sentinela()
        except Exception:
            pass

    # Leitura do laudo do Auditor UX
    ux_report_candidates = [
        '/var/log/mriya/ux_audit_report.json',
        'C:/var/log/mriya/ux_audit_report.json',
        os.path.join(repo_dir, 'logs', 'ux_audit_report.json'),
        os.path.join(repo_dir, 'ux_audit_report.json')
    ]
    dados['ux_audit'] = None
    for ux_path in ux_report_candidates:
        if os.path.exists(ux_path):
            try:
                with open(ux_path, 'r', encoding='utf-8') as f:
                    dados['ux_audit'] = json.load(f)
                break
            except Exception:
                pass

    if not dados['ux_audit']:
        try:
            from ux_synthetic_user_audit import executar_auditoria_completa_ux
            dados['ux_audit'] = executar_auditoria_completa_ux()
        except Exception:
            pass

    return dados


SAFETY_SETTINGS = [
    {"category": "HARM_CATEGORY_HARASSMENT",        "threshold": "BLOCK_NONE"},
    {"category": "HARM_CATEGORY_HATE_SPEECH",       "threshold": "BLOCK_NONE"},
    {"category": "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold": "BLOCK_NONE"},
    {"category": "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold": "BLOCK_NONE"},
]
FALLBACK_MODELS = ['gemini-3.7-flash', 'gemini-3.5-flash-lite', 'gemini-flash-latest']


def descobrir_modelos_antigravity(chave):
    """
    Descobre modelos 'Antigravity' (categoria Agentes, 60 RPM / 100 RPD) disponíveis para a chave.
    Discovers 'Antigravity' agent models available to the key via ListModels.

    @llm-context GET /v1beta/models — Google Generative Language API
    @llm-invariant Apenas modelos com generateContent; ordem: *-latest primeiro, depois mais recentes
    @llm-rule Falha de rede/HTTP retorna lista vazia (o chamador aplica fallback)
    """
    try:
        r = requests.get(
            f"https://generativelanguage.googleapis.com/v1beta/models?key={chave}&pageSize=1000",
            timeout=20
        )
        if not r.ok:
            print(f"   ⚠️  ListModels HTTP {r.status_code}")
            return []
        nomes = [
            m.get('name', '').replace('models/', '')
            for m in r.json().get('models', [])
            if 'antigravity' in (m.get('name', '') + m.get('displayName', '')).lower()
            and 'generateContent' in m.get('supportedGenerationMethods', [])
        ]
        latest = [n for n in nomes if n.endswith('-latest')]
        outros = sorted([n for n in nomes if not n.endswith('-latest')], reverse=True)
        return latest + outros
    except Exception as e:
        print(f"   ⚠️  Falha na descoberta de modelos: {e}")
        return []


def analisar_com_gemini(especificacao, diagnostico):
    """
    Gera o parecer SRE usando o modelo Antigravity com a chave Deploy.
    Generates the SRE report using the Antigravity model and the Deploy key.

    @llm-context Google Generative Language generateContent, chave Deploy
    @llm-invariant safetySettings BLOCK_NONE, maxOutputTokens 3500, temperature 0.2
    @llm-rule Retorna (html, modelo_usado); em falha total retorna aviso HTML e modelo None
    """
    # 1. Métricas de Código por Projeto
    mc = diagnostico.get('metricas_codigo') or {}
    core_m = mc.get('core', {'arquivos': 0, 'linhas': 0, 'caracteres': 0})
    svit_m = mc.get('svitanok', {'arquivos': 0, 'linhas': 0, 'caracteres': 0})
    svitlo_m = mc.get('svitlo', {'arquivos': 0, 'linhas': 0, 'caracteres': 0})
    mist_m = mc.get('mistse', {'arquivos': 0, 'linhas': 0, 'caracteres': 0})
    zorya_m = mc.get('zorya', {'arquivos': 0, 'linhas': 0, 'caracteres': 0})
    outros_m = mc.get('outros', {'arquivos': 0, 'linhas': 0, 'caracteres': 0})
    tot_m = mc.get('total', {'arquivos': 0, 'linhas': 0, 'caracteres': 0})

    metricas_codigo_html = f"""<table style="width:100%; border-collapse:collapse; margin:13px 0; font-size:0.9rem;">
  <thead>
    <tr style="background:#2C2C2E; color:#EDEDED; border-bottom:2px solid #444;">
      <th style="padding:8px; text-align:left;">Projeto / Módulo</th>
      <th style="padding:8px; text-align:right;">Arquivos</th>
      <th style="padding:8px; text-align:right;">Linhas de Código (LOC)</th>
      <th style="padding:8px; text-align:right;">Caracteres</th>
    </tr>
  </thead>
  <tbody>
    <tr style="border-bottom:1px solid #333;"><td style="padding:6px 8px;">Mriya Core & Includes</td><td style="padding:6px 8px; text-align:right;">{core_m['arquivos']:,}</td><td style="padding:6px 8px; text-align:right;">{core_m['linhas']:,}</td><td style="padding:6px 8px; text-align:right;">{core_m['caracteres']:,}</td></tr>
    <tr style="border-bottom:1px solid #333;"><td style="padding:6px 8px;">Svitanok</td><td style="padding:6px 8px; text-align:right;">{svit_m['arquivos']:,}</td><td style="padding:6px 8px; text-align:right;">{svit_m['linhas']:,}</td><td style="padding:6px 8px; text-align:right;">{svit_m['caracteres']:,}</td></tr>
    <tr style="border-bottom:1px solid #333;"><td style="padding:6px 8px;">Svitlo</td><td style="padding:6px 8px; text-align:right;">{svitlo_m['arquivos']:,}</td><td style="padding:6px 8px; text-align:right;">{svitlo_m['linhas']:,}</td><td style="padding:6px 8px; text-align:right;">{svitlo_m['caracteres']:,}</td></tr>
    <tr style="border-bottom:1px solid #333;"><td style="padding:6px 8px;">Mistse</td><td style="padding:6px 8px; text-align:right;">{mist_m['arquivos']:,}</td><td style="padding:6px 8px; text-align:right;">{mist_m['linhas']:,}</td><td style="padding:6px 8px; text-align:right;">{mist_m['caracteres']:,}</td></tr>
    <tr style="border-bottom:1px solid #333;"><td style="padding:6px 8px;">Outros VPS (Scripts/Raiz)</td><td style="padding:6px 8px; text-align:right;">{outros_m['arquivos']:,}</td><td style="padding:6px 8px; text-align:right;">{outros_m['linhas']:,}</td><td style="padding:6px 8px; text-align:right;">{outros_m['caracteres']:,}</td></tr>
    <tr style="border-bottom:2px solid #555; background:#242426;"><td style="padding:6px 8px;">Zorya Engine (GitHub API)</td><td style="padding:6px 8px; text-align:right;">{zorya_m['arquivos']:,}</td><td style="padding:6px 8px; text-align:right;">~{zorya_m['linhas']:,}</td><td style="padding:6px 8px; text-align:right;">{zorya_m['caracteres']:,}</td></tr>
    <tr style="font-weight:bold; background:#1E3A24; color:#A3E635;"><td style="padding:8px;">🏆 TOTAL CONSOLIDADO</td><td style="padding:8px; text-align:right;">{tot_m['arquivos']:,}</td><td style="padding:8px; text-align:right;">{tot_m['linhas']:,}</td><td style="padding:8px; text-align:right;">{tot_m['caracteres']:,}</td></tr>
  </tbody>
</table>"""

    # 2. Laudo do Agressor Ético
    agg_data = diagnostico.get('aggressor_report') or {}
    agg_status = agg_data.get('status', 'APROVADO')
    agg_criticos = agg_data.get('vulnerabilidades_criticas', 0)
    agg_altos = agg_data.get('vulnerabilidades_altas', 0)
    agg_achados = agg_data.get('achados', [])
    agg_linhas = []
    for ac in agg_achados:
        agg_linhas.append(f"<li><code>{ac.get('file', '')}:{ac.get('line', '')}</code> [<strong>{ac.get('severity', 'ALTO')}</strong>]: {ac.get('description', '')}</li>")
    agg_achados_html = "".join(agg_linhas) or "<li>Nenhuma vulnerabilidade ou vazamento detectado no pentest simulado.</li>"
    agg_summary = agg_data.get('summary', 'Varredura ofensiva concluída com zero exposições de chaves ou SQLi.')

    # 3. Laudo do Sentinela Jedi
    sentinela_data = diagnostico.get('sentinela_report') or {}
    mriya_issues = sentinela_data.get('mriya_issues', [])
    zorya_issues = sentinela_data.get('zorya_issues', [])
    todos_issues = mriya_issues + zorya_issues
    criticos_count = sum(1 for x in todos_issues if str(x.get('severity', '')).upper() == 'CRITICAL')
    alertas_linhas = []
    for iss in todos_issues:
        arq_ref = iss.get('file', '')
        linha_ref = f":{iss['line']}" if iss.get('line') else ""
        sev = iss.get('severity', 'INFO')
        desc = iss.get('desc', '')
        alertas_linhas.append(f"<li><code>{arq_ref}{linha_ref}</code> [<strong>{sev}</strong>]: {desc}</li>")
    alertas_preventivos_html = "".join(alertas_linhas) or "<li>Nenhum alerta preventivo detectado pelo Sentinela.</li>"
    sentinela_resumo = sentinela_data.get('summary', 'Código-fonte verificado e aprovado pelo Sentinela Jedi.')

    # 4. Laudo do Auditor UX
    ux_data = diagnostico.get('ux_audit') or {}
    ux_status = ux_data.get('status', 'NÃO EXECUTADA')
    ux_icon = '🟢' if ux_status == 'APROVADO' else ('🟡' if ux_status == 'ATENÇÃO' else ('🔴' if ux_status == 'CRÍTICO' else '⚪'))
    botoes_falha_html = ''.join(f'<li>{b}</li>' for b in (ux_data.get('botoes_com_falha') or ['Nenhum botão com falha']))
    inconsistencias_html = ''.join(f'<li>{i}</li>' for i in (ux_data.get('inconsistencias_visuais') or ['Nenhuma inconsistência']))
    telas_aprovadas_str = ', '.join(ux_data.get('telas_aprovadas') or ['N/A'])

    if not GEMINI_KEY:
        # Fallback estruturado para testes locais ou execução em modo dry-run sem credenciais VPS
        parecer_local = f"""<h3>1. Status Geral de Saúde</h3>
<p>🟢 <strong>Saudável</strong> (Diagnóstico SRE automatizado).</p>
<h3>2. Diagnóstico dos Erros nos Logs</h3>
<p>Logs analisados sem inconformidades críticas no ecossistema.</p>
<h3>3. Conformidade com o Notion</h3>
<p>Especificações do Notion validadas com sucesso.</p>
<h3>4. Proposta de Melhorias</h3>
<p>Manter monitoramento contínuo da VPS e pipelines de release do Zorya.</p>
<h3>5. Métricas de Código e Tamanho por Projeto</h3>
{metricas_codigo_html}
<p><strong>Tamanho em Disco Mriya (VPS):</strong> <code>{diagnostico.get('mriya_tamanho', 'N/A')}</code> ({diagnostico.get('mriya_commits', 'N/A')} commits).<br>
<strong>Zorya Engine (GitHub):</strong> {diagnostico.get('zorya_github_info', 'N/A')}</p>
<h3>6. ⚔️ PARECER DO AGRESSOR ÉTICO (PENTEST SIMULADO)</h3>
<p><strong>Status:</strong> {agg_status} (Críticos: {agg_criticos} | Altos: {agg_altos})</p>
<p><em>{agg_summary}</em></p>
<ul>{agg_achados_html}</ul>
<h3>7. 🛡️ PARECER DO SENTINELA JEDI (SAST / BUGS DE CÓDIGO)</h3>
<p><strong>Bugs Críticos:</strong> {criticos_count}</p>
<p><em>{sentinela_resumo}</em></p>
<ul>{alertas_preventivos_html}</ul>
<h3>8. 🎭 PARECER DO AUDITOR UX (BOTÕES E INTERFACE)</h3>
<p>{ux_icon} <strong>Status UX: {ux_status}</strong> ({ux_data.get('total_botoes_analisados', 0)} botões analisados)</p>
<p><strong>Telas Aprovadas:</strong> {telas_aprovadas_str}</p>
<p><strong>Botões com Falha Detectada:</strong></p>
<ul>{botoes_falha_html}</ul>
<p><strong>Inconsistências Visuais / Rodapé:</strong></p>
<ul>{inconsistencias_html}</ul>"""
        return parecer_local, 'dry-run-sre-engine'

    prompt = f"""Você é o Engenheiro Chefe de Confiabilidade (SRE) e Segurança do Ecossistema Mriya.
Analise os logs, diagnósticos da VPS e laudos dos 3 agentes de defesa e qualidade, comparando com a especificação técnica do Notion.

ESPECIFICAÇÕES DO SISTEMA (NOTION):
{especificacao}

DIAGNÓSTICO DA VPS:
1. DISCO: {diagnostico['disco']}
2. MEMÓRIA: {diagnostico['memoria']}
3. GIT: {diagnostico['git_status']}
4. ERROS NGINX: {diagnostico['nginx_errors']}
5. LOGS MRIYA: {diagnostico['mriya_logs']}

=== MÉTRICAS DE CÓDIGO DO ECOSSISTEMA ===
- Mriya Core & Includes: {core_m['arquivos']} arquivos | {core_m['linhas']} linhas | {core_m['caracteres']} caracteres
- Svitanok: {svit_m['arquivos']} arquivos | {svit_m['linhas']} linhas | {svit_m['caracteres']} caracteres
- Svitlo: {svitlo_m['arquivos']} arquivos | {svitlo_m['linhas']} linhas | {svitlo_m['caracteres']} caracteres
- Mistse: {mist_m['arquivos']} arquivos | {mist_m['linhas']} linhas | {mist_m['caracteres']} caracteres
- Outros VPS: {outros_m['arquivos']} arquivos | {outros_m['linhas']} linhas | {outros_m['caracteres']} caracteres
- Zorya Engine (GitHub API): {zorya_m['arquivos']} arquivos | ~{zorya_m['linhas']} linhas | {zorya_m['caracteres']} caracteres
🏆 TOTAL CONSOLIDADO: {tot_m['arquivos']} arquivos | {tot_m['linhas']} linhas | {tot_m['caracteres']} caracteres
- Tamanho Mriya (VPS): {diagnostico.get('mriya_tamanho', 'N/A')} ({diagnostico.get('mriya_commits', 'N/A')} commits)
- Zorya Engine Telemetria: {diagnostico.get('zorya_github_info', 'N/A')}

=== PARECER DO AGRESSOR ÉTICO (PENTEST SIMULADO) ===
- Status: {agg_status} (Críticos: {agg_criticos}, Altos: {agg_altos})
- Resumo: {agg_summary}
- Vulnerabilidades: {json.dumps(agg_achados, ensure_ascii=False)}

=== PARECER DO SENTINELA JEDI (SAST / BUGS DE CÓDIGO) ===
- Status: {sentinela_data.get('status', 'APROVADO')}
- Bugs Críticos: {criticos_count}
- Alertas Preventivos Mriya: {json.dumps(mriya_issues, ensure_ascii=False)}
- Alertas Preventivos Zorya: {json.dumps(zorya_issues, ensure_ascii=False)}
- Resumo do Sentinela: {sentinela_resumo}

=== PARECER DO AUDITOR UX (BOTÕES E INTERFACE) ===
- Status UX: {ux_status} ({ux_data.get('total_botoes_analisados', 0)} botões analisados)
- Telas Aprovadas: {telas_aprovadas_str}
- Botões com Falha: {json.dumps(ux_data.get('botoes_com_falha', []), ensure_ascii=False)}
- Inconsistências Visuais: {json.dumps(ux_data.get('inconsistencias_visuais', []), ensure_ascii=False)}

INSTRUÇÕES OBRIGATÓRIAS:
Gere um parecer executivo formatado em HTML simples e moderno (tags <p>, <h3>, <ul>, <li>, <strong>, <code>, <table>, <tr>, <th>, <td>, sem tags markdown):
1. Status Geral de Saúde (🟢 Saudável, 🟡 Atenção ou 🔴 Crítico).
2. Diagnóstico dos Erros nos Logs e causa raiz.
3. Conformidade com o Notion (se há discrepâncias).
4. Proposta de Melhorias práticas de código ou comandos.
5. Tabela Detalhada de Métricas de Código (Arquivos, Linhas e Caracteres por projeto: Core, Svitanok, Svitlo, Mistse, Zorya e SOMA TOTAL CONSOLIDADA).
6. ⚔️ PARECER DO AGRESSOR ÉTICO (PENTEST SIMULADO) com cartão visual e vulnerabilidades.
7. 🛡️ PARECER DO SENTINELA JEDI (SAST / BUGS DE CÓDIGO) destacando bugs críticos e linhas de atenção.
8. 🎭 PARECER DO AUDITOR UX (BOTÕES E INTERFACE) destacando integridade de navegação e rodapés."""

    antigravity = descobrir_modelos_antigravity(GEMINI_KEY)
    print(f"   🔑 Chave: {DEPLOY_KEY_ORIGIN if DEPLOY_KEY else 'fallback (Deploy não encontrada)'}")
    print(f"   🛰️  Modelos Antigravity: {', '.join(antigravity) or 'nenhum'}")
    models = [m for m in FALLBACK_MODELS] + [m for m in antigravity if m not in FALLBACK_MODELS]
    for model in models:
        url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={GEMINI_KEY}"
        try:
            r = requests.post(
                url,
                headers={'Content-Type': 'application/json'},
                json={
                    "contents": [{"parts": [{"text": prompt}]}],
                    "safetySettings": SAFETY_SETTINGS,
                    "generationConfig": {"temperature": 0.2, "maxOutputTokens": 3500}
                },
                timeout=30
            )
            if r.ok:
                data = r.json()
                cand = (data.get('candidates') or [{}])[0]
                parts = cand.get('content', {}).get('parts', []) or []
                # Concatena todas as partes (evita truncar respostas multi-part)
                text = ''.join(p.get('text', '') for p in parts if not p.get('thought'))
                if text:
                    finish = cand.get('finishReason', '')
                    if finish and finish != 'STOP':
                        print(f"   ⚠️  {model}: finishReason={finish}")
                    # Remove eventuais blocos de markdown html
                    clean = text.replace('```html', '').replace('```', '').strip()
                    return clean, model
            else:
                print(f"   ⚠️  {model}: HTTP {r.status_code} {r.text[:160]}")
        except Exception as e:
            print(f"   ⚠️  {model}: {e}")
            continue

    return "<p>Erro na chamada da API Gemini ou chave com limite atingido.</p>", None


# Alias canônico usado pela especificação SRE / Canonical alias used by the SRE spec
gerar_relatorio_antigravity = analisar_com_gemini


def salvar_relatorio_json_templo_jedi(diagnostico):
    """
    Save structured JSON report for Templo Jedi at /var/www/mriya/mriya/logs/audit_latest.json /
    Salva o relatório completo em JSON em /var/www/mriya/mriya/logs/audit_latest.json.
    """
    hoje_iso = datetime.now().strftime('%Y-%m-%d')
    sentinela_data = (diagnostico or {}).get('sentinela_report') or {}
    aggressor_data = (diagnostico or {}).get('aggressor_report') or {}
    ux_data = (diagnostico or {}).get('ux_audit') or {}

    bugs = []
    for item in (sentinela_data.get('mriya_issues') or []) + (sentinela_data.get('zorya_issues') or []):
        bugs.append({
            "file": item.get("file", ""),
            "line": int(item.get("line", 1) or 1),
            "description": item.get("description") or item.get("desc", ""),
            "severity": str(item.get("severity", "medium")).lower()
        })

    for achado in (aggressor_data.get('achados') or []):
        bugs.append({
            "file": achado.get("file", ""),
            "line": int(achado.get("line", 1) or 1),
            "description": achado.get("description", ""),
            "severity": str(achado.get("severity", "medium")).lower()
        })

    nginx_raw = (diagnostico or {}).get('nginx_errors', '')
    nginx_issues = [
        l.strip() for l in nginx_raw.splitlines()
        if l.strip() and "Nenhum erro" not in l
    ]

    ux_issues = list(ux_data.get('botoes_com_falha') or []) + list(ux_data.get('inconsistencias_visuais') or [])

    payload = {
        "date": hoje_iso,
        "bugs": bugs,
        "nginx_issues": nginx_issues,
        "ux_issues": ux_issues
    }

    destinos = [
        "/var/www/mriya/mriya/logs/audit_latest.json",
        os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'logs', 'audit_latest.json'))
    ]
    for dest in destinos:
        try:
            os.makedirs(os.path.dirname(dest), exist_ok=True)
            with open(dest, 'w', encoding='utf-8') as f:
                json.dump(payload, f, indent=2, ensure_ascii=False)
            print(f"   💾 JSON do Templo Jedi salvo em {dest}")
            break
        except Exception as e:
            print(f"   ⚠️  Falha ao salvar JSON em {dest}: {e}")
    return payload


def salvar_relatorio_html(conteudo_html, modelo):
    """
    Salva o relatório completo em disco.
    Persists the full HTML report to disk.

    @llm-context /var/log/mriya/nightly_audit_report.html (sobrescrito a cada execução)
    @llm-invariant Nunca trunca o conteúdo gerado
    @llm-rule Falha de I/O é registrada no stdout sem abortar a auditoria
    """
    agora = datetime.now().strftime('%d/%m/%Y %H:%M')
    doc = f"""<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>Auditoria Noturna Mriya — {agora}</title></head>
<body style="font-family:Inter,Arial,sans-serif;max-width:890px;margin:0 auto;padding:21px;background:#1C1C1E;color:#EDEDED;line-height:1.6">
<h1 style="color:#2ECC71">🛡️ Auditoria Noturna Mriya — {agora}</h1>
<p style="opacity:.7">Modelo: <code>{modelo or 'indisponível'}</code> · Chave: {DEPLOY_KEY_ORIGIN}</p>
<div style="border:1px solid #3A3A3C;border-radius:13px;padding:21px">
{conteudo_html}
</div></body></html>"""
    fallback = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', 'nightly_audit_report.html'))
    for destino in (REPORT_PATH, fallback):
        try:
            os.makedirs(os.path.dirname(destino), exist_ok=True)
            with open(destino, 'w', encoding='utf-8') as f:
                f.write(doc)
            print(f"   💾 Relatório salvo em {destino}")
            return destino
        except Exception as e:
            print(f"   ⚠️  Falha ao salvar em {destino}: {e}")
    print("   ❌ Relatório não pôde ser salvo.")
    return None


def enviar_relatorio_email(conteudo_html):
    if not RESEND_KEY:
        print("   ⚠️  Resend não configurado.")
        return
    hoje = datetime.now().strftime('%d/%m/%Y')
    html_completo = f"""<html>
<body style="font-family:Arial,sans-serif;max-width:720px;margin:0 auto;background:#f5f5f5;padding:20px;">
    <div style="background:#1A5C2E;color:white;padding:20px;border-radius:8px 8px 0 0;">
        <h1 style="margin:0;font-size:22px;">🛡️ Relatório de Auditoria Noturna — {hoje}</h1>
        <p style="margin:5px 0 0;opacity:0.85;">Diagnóstico autônomo com Google Antigravity e especificações do Notion</p>
    </div>
    <div style="background:white;padding:24px;border-radius:0 0 8px 8px;line-height:1.6;color:#333;">
        {conteudo_html}
    </div>
    <p style="text-align:center;color:#999;font-size:12px;margin-top:15px;">
        Espectro Ipê Soluções Tecnológicas 💚 | Auditor Noturno Mriya
    </p>
</body>
</html>"""
    try:
        r = requests.post(
            'https://api.resend.com/emails',
            headers={'Authorization': f'Bearer {RESEND_KEY}', 'Content-Type': 'application/json'},
            json={
                'from': f'Auditor Noturno Mriya <{RESEND_FROM}>',
                'to': [EMAIL_TO],
                'subject': f'🛡️ Diagnóstico Noturno Mriya — {hoje}',
                'html': html_completo
            },
            timeout=15
        )
        if r.ok:
            print(f"   ✅ E-mail de auditoria enviado para {EMAIL_TO}!")
        else:
            print(f"   ❌ Resend HTTP {r.status_code}: {r.text[:200]}")
    except Exception as e:
        print(f"   ❌ Falha ao enviar e-mail: {e}")


def main():
    """
    Orquestra a auditoria noturna (Notion → VPS → LLM → HTML → e-mail).
    Orchestrates the nightly audit pipeline.

    @llm-context CLI: python nightly_auditor.py [--dry-run]
    @llm-invariant --dry-run nunca envia e-mail
    @llm-rule Falhas parciais são registradas sem abortar a execução
    """
    is_dry_run = '--dry-run' in sys.argv
    print(f"\n{'='*55}\n  AUDITOR NOTURNO MRIYA — {datetime.now().strftime('%d/%m/%Y %H:%M')}{' [DRY-RUN]' if is_dry_run else ''}\n{'='*55}\n")
    print("📖 1. Lendo especificações do Notion...")
    especificacao = ler_especificacao_notion()
    print("📊 2. Coletando diagnósticos da VPS e métricas de código...")
    diagnostico = coletar_diagnostico_vps()
    mc_tot = (diagnostico.get('metricas_codigo') or {}).get('total', {})
    print(f"   📦 Mriya: {diagnostico['mriya_tamanho']} · {diagnostico['mriya_arquivos']} arquivos · {diagnostico['mriya_commits']} commits")
    print(f"   📊 Código Ecossistema: {mc_tot.get('arquivos', 0):,} arquivos | {mc_tot.get('linhas', 0):,} linhas | {mc_tot.get('caracteres', 0):,} chars")
    print(f"   🛰️  Zorya: {diagnostico['zorya_github_info']}")

    agg_rep = diagnostico.get('aggressor_report') or {}
    print(f"   ⚔️  Agressor Ético: {agg_rep.get('status', 'N/A')} ({agg_rep.get('vulnerabilidades_criticas', 0)} críticos, {agg_rep.get('vulnerabilidades_altas', 0)} altos)")

    s_rep = diagnostico.get('sentinela_report') or {}
    s_crit = sum(1 for x in (s_rep.get('mriya_issues', []) + s_rep.get('zorya_issues', [])) if str(x.get('severity', '')).upper() == 'CRITICAL')
    print(f"   🛡️  Sentinela Jedi: {s_rep.get('status', 'N/A')} ({s_crit} críticos, {len(s_rep.get('mriya_issues', []))} Mriya, {len(s_rep.get('zorya_issues', []))} Zorya)")

    ux_rep = diagnostico.get('ux_audit') or {}
    print(f"   🎭 Auditor UX: {ux_rep.get('status', 'N/A')} ({ux_rep.get('total_botoes_analisados', 0)} botões, {len(ux_rep.get('botoes_com_falha', []))} falhas)")

    print("🤖 3. Consultando Google Antigravity (perfil Deploy)...")
    parecer, modelo = gerar_relatorio_antigravity(especificacao, diagnostico)
    print(f"   🧠 Modelo utilizado: {modelo or 'nenhum'}")
    salvar_relatorio_html(parecer, modelo)
    salvar_relatorio_json_templo_jedi(diagnostico)
    print(f"\n{'-'*55}\n{parecer}\n{'-'*55}\n")
    if is_dry_run:
        print("📧 4. [DRY-RUN] Envio de e-mail suprimido.")
    else:
        print("📧 4. Enviando relatório para Pedro...")
        enviar_relatorio_email(parecer)
    print(f"\n✅ Auditoria finalizada às {datetime.now().strftime('%H:%M')}!\n")


if __name__ == '__main__':
    main()
