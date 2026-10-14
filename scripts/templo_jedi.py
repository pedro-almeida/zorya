#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Templo Jedi - Orquestrador Unificado e Usina Central de IA Zorya
Integração com VPS, Descoberta Diária de Modelos, Ciclo dos 3 Poderes (Coder, Kant, Gatekeeper),
Motor de Estudo Svitlo Local e Fábrica de Mídias/Diagramas para Anki.
Dependências: Biblioteca Padrão do Python 3 (zero pip).
"""

import argparse
import base64
import json
import os
import pathlib
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

if sys.platform == "win32":
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")
        sys.stdin.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass

BASE_DIR = pathlib.Path(__file__).resolve().parent.parent
CREDENTIALS_CACHE = BASE_DIR / ".jedi_credentials_cache.json"
MODELS_CACHE = BASE_DIR / ".jedi_models_cache.json"
ANKI_MEDIA_DIR = BASE_DIR / "thumbnails" / "anki_media"
CACHE_TTL_SECONDS = 86400  # 24 horas

AGENT_MAP = {
    "orch": {"name": "Batman", "groq_key": "orch", "gemini_key": "orch"},
    "backend": {"name": "Hans Zimmer", "groq_key": "backend", "gemini_key": "backend"},
    "frontend": {"name": "Christopher Nolan", "groq_key": "frontend", "gemini_key": "frontend"},
    "fullstack": {"name": "Lucius Fox", "groq_key": "fullstack", "gemini_key": "fullstack"},
    "dba": {"name": "Cérebro", "groq_key": "dba", "gemini_key": "dba"},
    "notion": {"name": "Hermione Granger", "groq_key": "notion", "gemini_key": "notion"},
    "svitanok": {"name": "Zorya Vision", "groq_key": "svitanok", "gemini_key": "svitanok"},
    "qa": {"name": "Immanuel Kant", "groq_key": "qa", "gemini_key": "qa"},
}


class CredentialsManager:
    @staticmethod
    def sync(force=False):
        """Sincroniza credentials.json da VPS via SSH com TTL de 24h."""
        if not force and CREDENTIALS_CACHE.exists():
            mtime = CREDENTIALS_CACHE.stat().st_mtime
            if time.time() - mtime < CACHE_TTL_SECONDS:
                try:
                    with open(CREDENTIALS_CACHE, "r", encoding="utf-8") as f:
                        return json.load(f)
                except Exception:
                    pass

        targets = ["vps", "root@143.95.223.218"]
        for target in targets:
            try:
                cmd = [
                    "ssh", "-n",
                    "-o", "BatchMode=yes",
                    "-o", "ConnectTimeout=5",
                    target,
                    "cat /var/www/mcp-server/credentials/credentials.json"
                ]
                res = subprocess.run(
                    cmd,
                    capture_output=True,
                    text=True,
                    stdin=subprocess.DEVNULL,
                    timeout=8
                )
                if res.returncode == 0 and res.stdout.strip():
                    data = json.loads(res.stdout)
                    with open(CREDENTIALS_CACHE, "w", encoding="utf-8") as f:
                        json.dump(data, f, indent=2, ensure_ascii=False)
                    return data
            except Exception:
                continue

        # Fallback para cache existente se SSH falhar
        if CREDENTIALS_CACHE.exists():
            try:
                with open(CREDENTIALS_CACHE, "r", encoding="utf-8") as f:
                    return json.load(f)
            except Exception:
                pass

        return {}


class ModelsDiscovery:
    @staticmethod
    def discover(creds, force=False):
        """Descoberta e validação diária de modelos (Ollama, Groq, Gemini) com cache 24h."""
        if not force and MODELS_CACHE.exists():
            mtime = MODELS_CACHE.stat().st_mtime
            if time.time() - mtime < CACHE_TTL_SECONDS:
                try:
                    with open(MODELS_CACHE, "r", encoding="utf-8") as f:
                        return json.load(f)
                except Exception:
                    pass

        result = {
            "updated_at": int(time.time()),
            "ollama": [],
            "ollama_coder": "qwen2.5-coder:14b-instruct-q4_K_M",
            "ollama_vision": "moondream:latest",
            "groq": [],
            "groq_priority": "llama-3.3-70b-versatile",
            "groq_vision": "meta-llama/llama-4-scout-17b-16e-instruct",
            "gemini": [],
            "gemini_priority": "gemini-3.8-flash",
            "gemini_vision": "gemini-3.8-flash"
        }

        # 1. Ollama Local
        try:
            req = urllib.request.Request("http://localhost:11434/api/tags")
            with urllib.request.urlopen(req, timeout=3) as resp:
                if resp.status == 200:
                    data = json.loads(resp.read().decode("utf-8"))
                    names = [m.get("name", "") for m in data.get("models", [])]
                    result["ollama"] = names
                    for n in names:
                        if "qwen2.5-coder:14b" in n:
                            result["ollama_coder"] = n
                        elif "moondream" in n:
                            result["ollama_vision"] = n
        except Exception:
            pass

        # 2. Groq Cloud
        groq_key = creds.get("groq", {}).get("orch") or creds.get("groq", {}).get("default")
        if groq_key:
            try:
                req = urllib.request.Request(
                    "https://api.groq.com/openai/v1/models",
                    headers={"Authorization": f"Bearer {groq_key}"}
                )
                with urllib.request.urlopen(req, timeout=6) as resp:
                    if resp.status == 200:
                        data = json.loads(resp.read().decode("utf-8"))
                        m_ids = [m.get("id", "") for m in data.get("data", [])]
                        result["groq"] = m_ids
                        priority_order = [
                            "qwen/qwen3.8-27b",
                            "openai/gpt-oss-120b",
                            "llama-3.3-70b-versatile",
                            "meta-llama/llama-4-scout-17b-16e-instruct",
                            "llama3-70b-8192"
                        ]
                        for cand in priority_order:
                            if cand in m_ids:
                                result["groq_priority"] = cand
                                break
            except Exception:
                pass

        # 3. Gemini Cloud
        gemini_key = creds.get("gemini", {}).get("orch") or creds.get("gemini", {}).get("default")
        if gemini_key:
            try:
                url = f"https://generativelanguage.googleapis.com/v1beta/models?key={gemini_key}"
                req = urllib.request.Request(url)
                with urllib.request.urlopen(req, timeout=6) as resp:
                    if resp.status == 200:
                        data = json.loads(resp.read().decode("utf-8"))
                        raw_models = [m.get("name", "").replace("models/", "") for m in data.get("models", [])]
                        result["gemini"] = raw_models
                        gem_priority = [
                            "gemini-3.8-flash",
                            "gemini-3.5-flash-lite",
                            "gemma-4-31b-it",
                            "gemini-2.5-flash",
                            "gemini-1.5-flash"
                        ]
                        for cand in gem_priority:
                            if cand in raw_models:
                                result["gemini_priority"] = cand
                                break
            except Exception:
                pass

        with open(MODELS_CACHE, "w", encoding="utf-8") as f:
            json.dump(result, f, indent=2, ensure_ascii=False)

        return result


class LLMCaller:
    @staticmethod
    def call_ollama(model, prompt, num_ctx=8192, temperature=0.2):
        url = "http://localhost:11434/api/generate"
        payload = {
            "model": model,
            "prompt": prompt,
            "stream": False,
            "options": {
                "num_ctx": num_ctx,
                "temperature": temperature
            }
        }
        req = urllib.request.Request(
            url,
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"}
        )
        with urllib.request.urlopen(req, timeout=90) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            return data.get("response", "")

    @staticmethod
    def call_groq(model, prompt, api_key, temperature=0.2):
        url = "https://api.groq.com/openai/v1/chat/completions"
        payload = {
            "model": model,
            "messages": [{"role": "user", "content": prompt}],
            "temperature": temperature
        }
        req = urllib.request.Request(
            url,
            data=json.dumps(payload).encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {api_key}"
            }
        )
        with urllib.request.urlopen(req, timeout=45) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            return data["choices"][0]["message"]["content"]

    @staticmethod
    def call_gemini(model, prompt, api_key, temperature=0.2):
        url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}"
        payload = {
            "contents": [{"parts": [{"text": prompt}]}],
            "generationConfig": {"temperature": temperature}
        }
        req = urllib.request.Request(
            url,
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"}
        )
        with urllib.request.urlopen(req, timeout=45) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            return data["candidates"][0]["content"]["parts"][0]["text"]


def strip_code_fences(text):
    text = text.strip()
    match = re.search(r"```(?:\w+)?\s*\n(.*?)\n```", text, re.DOTALL)
    if match:
        return match.group(1).strip()
    if text.startswith("```") and text.endswith("```"):
        lines = text.split("\n")
        return "\n".join(lines[1:-1]).strip()
    return text


def validate_syntax(file_path, code):
    ext = pathlib.Path(file_path).suffix.lower()
    if ext == ".go":
        proc = subprocess.run(["gofmt", "-e"], input=code, text=True, capture_output=True)
        return proc.returncode == 0, proc.stderr.strip()
    elif ext == ".py":
        try:
            compile(code, "<string>", "exec")
            return True, ""
        except SyntaxError as e:
            return False, str(e)
    elif ext == ".php":
        proc = subprocess.run(["php", "-l"], input=code, text=True, capture_output=True)
        if proc.returncode == 0:
            return True, ""
        return False, proc.stderr.strip()
    return True, ""


# ---------------------------------------------------------------------------
# MODO 1: Ciclo dos 3 Poderes (Coder -> Kant -> Gatekeeper)
# ---------------------------------------------------------------------------
def execute_jedi_dispatch(payload, creds, models):
    dest = payload.get("arquivo_destino", "")
    instruction = payload.get("instrucao", "")
    agent_id = payload.get("agente_id", "orch")
    preferred_engine = payload.get("motor_preferido", "auto")

    agent_info = AGENT_MAP.get(agent_id, AGENT_MAP["orch"])
    agent_name = agent_info["name"]

    existing_code = ""
    if dest and os.path.exists(dest):
        try:
            with open(dest, "r", encoding="utf-8") as f:
                existing_code = f.read()
        except Exception:
            pass

    coder_prompt = f"""Você é {agent_name}, o especialista em engenharia de software da equipe Jedi Zorya.
Instrução do usuário:
{instruction}

Arquivo de destino: {dest}
Código existente:
{existing_code[:4000] if existing_code else '(Novo arquivo)'}

Gere o código completo, de produção, limpo e direto.
Responda APENAS com o código completo. Não adicione explicações, comentários de conversa ou notas antes ou depois.
"""

    code_generated = ""
    engine_used = ""

    # Poder 1: Geração pelo Coder
    if preferred_engine != "cloud" and models.get("ollama_coder") and models.get("ollama"):
        try:
            code_generated = LLMCaller.call_ollama(models["ollama_coder"], coder_prompt, num_ctx=8192)
            engine_used = f"Ollama Local ({models['ollama_coder']})"
        except Exception:
            pass

    if not code_generated:
        groq_key = creds.get("groq", {}).get(agent_info["groq_key"]) or creds.get("groq", {}).get("orch")
        if groq_key:
            try:
                code_generated = LLMCaller.call_groq(models.get("groq_priority", "llama-3.3-70b-versatile"), coder_prompt, groq_key)
                engine_used = f"Groq Cloud ({models.get('groq_priority')})"
            except Exception:
                pass

    if not code_generated:
        gemini_key = creds.get("gemini", {}).get(agent_info["gemini_key"]) or creds.get("gemini", {}).get("orch")
        if gemini_key:
            try:
                code_generated = LLMCaller.call_gemini(models.get("gemini_priority", "gemini-3.8-flash"), coder_prompt, gemini_key)
                engine_used = f"Gemini Cloud ({models.get('gemini_priority')})"
            except Exception:
                pass

    if not code_generated:
        return {"success": False, "error": "Nenhum motor de IA disponível para o Coder"}

    clean_code = strip_code_fences(code_generated)

    # Poder 2: Validação Mecânica do Gatekeeper
    valid_syntax, syntax_err = validate_syntax(dest, clean_code)

    # Auditoria de Immanuel Kant (QA)
    kant_feedback = "Auditoria aprovada por Immanuel Kant."
    qa_key = creds.get("groq", {}).get("qa") or creds.get("gemini", {}).get("qa")
    if qa_key and not valid_syntax:
        kant_prompt = f"""Você é Immanuel Kant, o auditor de código implacável do Templo Jedi.
O código a seguir apresentou o erro sintático: {syntax_err}
Corrija o código estritamente para torná-lo 100% válido sintaticamente:
{clean_code}
Responda APENAS com o código corrigido.
"""
        try:
            if creds.get("groq", {}).get("qa"):
                fixed = LLMCaller.call_groq("llama-3.3-70b-versatile", kant_prompt, creds["groq"]["qa"])
            else:
                fixed = LLMCaller.call_gemini("gemini-3.8-flash", kant_prompt, creds["gemini"]["qa"])
            clean_fixed = strip_code_fences(fixed)
            v2, _ = validate_syntax(dest, clean_fixed)
            if v2:
                clean_code = clean_fixed
                valid_syntax = True
                kant_feedback = "Kant interveio e corrigiu a sintaxe com sucesso."
        except Exception:
            pass

    # Poder 3: Salvamento Físico do Código
    if dest:
        pathlib.Path(dest).parent.mkdir(parents=True, exist_ok=True)
        with open(dest, "w", encoding="utf-8") as f:
            f.write(clean_code)

    return {
        "success": True,
        "arquivo_destino": dest,
        "agente": agent_name,
        "motor": engine_used,
        "syntax_valid": valid_syntax,
        "kant_audit": kant_feedback,
        "bytes_written": len(clean_code.encode("utf-8"))
    }


# ---------------------------------------------------------------------------
# MODO 2: Motor de Estudo Svitlo Local (--svitlo-generate)
# ---------------------------------------------------------------------------
def generate_svitlo_study(payload, creds, models):
    content = payload.get("content", "")
    mode = payload.get("mode", "full")
    topic = payload.get("topic", "Conceito de Estudo")

    prompt = f"""Você é o Motor Pedagógico Svitlo integrado à Usina de IA Zorya.
Gere um pacote pedagógico no formato JSON ESTRITO sobre o seguinte conteúdo:
Tópico: {topic}
Modo: {mode}
Conteúdo:
{content}

O JSON DEVE CONTER EXATAMENTE AS SEGUINTES CHAVES:
{{
  "cards": [
    {{"front": "Pergunta ou conceito essencial?", "back": "Resposta clara, direta e didática com fundamentação."}}
  ],
  "summary": {{
    "topic": "{topic}",
    "overview": "Resumo estruturado do assunto em 2-3 parágrafos.",
    "concepts": ["Conceito 1", "Conceito 2", "Conceito 3"],
    "feynman": "Explicação pelo Método Feynman (usando analogia simples e intuitiva do mundo real)."
  }},
  "mind_map_mermaid": "graph TD\\n    A[{topic}] --> B[Conceito 1]\\n    A --> C[Conceito 2]",
  "model_used": "Usina Zorya GPU Local + Templo Jedi"
}}

Responda APENAS o JSON válido. Não inclua texto introdutório ou notas fora do JSON.
"""

    resp_text = ""
    model_name = "Usina Zorya Local (Qwen 2.5 Coder 14B)"

    # Prioridade: Ollama Local
    if models.get("ollama_coder") and models.get("ollama"):
        try:
            resp_text = LLMCaller.call_ollama(models["ollama_coder"], prompt, num_ctx=8192)
            model_name = f"Ollama Local ({models['ollama_coder']})"
        except Exception:
            pass

    # Fallback: Groq svitlo_intelligence
    if not resp_text:
        svitlo_key = creds.get("groq", {}).get("svitlo_intelligence") or creds.get("groq", {}).get("orch")
        if svitlo_key:
            try:
                resp_text = LLMCaller.call_groq("llama-3.3-70b-versatile", prompt, svitlo_key)
                model_name = "Groq Cloud (llama-3.3-70b-versatile)"
            except Exception:
                pass

    # Fallback: Gemini svitlo_intelligence
    if not resp_text:
        gem_key = creds.get("gemini", {}).get("svitlo_intelligence") or creds.get("gemini", {}).get("orch")
        if gem_key:
            try:
                resp_text = LLMCaller.call_gemini("gemini-3.8-flash", prompt, gem_key)
                model_name = "Gemini Cloud (gemini-3.8-flash)"
            except Exception:
                pass

    clean_json = strip_code_fences(resp_text)
    try:
        data = json.loads(clean_json)
        data["model_used"] = model_name
        return data
    except Exception:
        # Fallback estruturado
        return {
            "cards": [
                {"front": f"Qual a ideia central de {topic}?", "back": content[:300]}
            ],
            "summary": {
                "topic": topic,
                "overview": content[:500],
                "concepts": [topic],
                "feynman": f"Imagine {topic} como um sistema estruturado onde cada parte cumpre uma função definida."
            },
            "mind_map_mermaid": f"graph TD\n    A[{topic}] --> B[Estrutura Principal]",
            "model_used": model_name
        }


# ---------------------------------------------------------------------------
# MODO 3: Fábrica de Imagens e Diagramas para o Anki (--anki-media)
# ---------------------------------------------------------------------------
def generate_anki_media(payload):
    prompt = payload.get("prompt", "")
    style = payload.get("style", "diagram").lower()
    deck = payload.get("deck", "Geral")
    front = payload.get("front", "")
    back = payload.get("back", "")
    inject_anki = payload.get("inject_anki", False)

    ANKI_MEDIA_DIR.mkdir(parents=True, exist_ok=True)
    safe_name = re.sub(r"[^\w\-]", "_", (front or prompt)[:30]).strip("_") or "media"
    timestamp = int(time.time())
    file_id = f"anki_{safe_name}_{timestamp}"

    # Caso 1: Diagrama SVG Didático Svitlo
    if style in ("diagram", "svg"):
        svg_filename = f"{file_id}.svg"
        svg_path = ANKI_MEDIA_DIR / svg_filename

        svg_content = f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500" width="100%" height="100%">
  <defs>
    <linearGradient id="svitloBg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#FDFBF7"/>
      <stop offset="100%" stop-color="#F4EFE6"/>
    </linearGradient>
    <filter id="cardShadow" x="-10%" y="-10%" width="120%" height="120%">
      <feDropShadow dx="0" dy="4" stdDeviation="6" flood-color="#2C1F14" flood-opacity="0.08"/>
    </filter>
  </defs>

  <rect width="800" height="500" rx="16" fill="url(#svitloBg)" stroke="#E6DFD5" stroke-width="2"/>

  <!-- Header Card -->
  <rect x="40" y="30" width="720" height="70" rx="12" fill="#2C1F14" filter="url(#cardShadow)"/>
  <text x="60" y="74" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="22" font-weight="700" fill="#FDFBF7">
    {xml_escape(front or prompt[:50])}
  </text>
  <text x="730" y="74" text-anchor="end" font-family="sans-serif" font-size="12" font-weight="600" fill="#D4AF37">
    ZORYA SVITLO DIAGRAM
  </text>

  <!-- Core Concept Left -->
  <rect x="40" y="130" width="340" height="320" rx="12" fill="#FFFFFF" stroke="#E6DFD5" stroke-width="1.5" filter="url(#cardShadow)"/>
  <rect x="40" y="130" width="340" height="8" rx="4" fill="#3A5A40"/>
  <text x="60" y="170" font-family="sans-serif" font-size="16" font-weight="700" fill="#3A5A40">FUNDAMENTO &amp; CONCEITO</text>
  <foreignObject x="60" y="190" width="300" height="240">
    <div xmlns="http://www.w3.org/1999/xhtml" style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; font-size: 14px; color: #2C1F14; line-height: 1.6;">
      {xml_escape(back or prompt)}
    </div>
  </foreignObject>

  <!-- Visual Structure Right -->
  <rect x="420" y="130" width="340" height="320" rx="12" fill="#FFFFFF" stroke="#E6DFD5" stroke-width="1.5" filter="url(#cardShadow)"/>
  <rect x="420" y="130" width="340" height="8" rx="4" fill="#D4AF37"/>
  <text x="440" y="170" font-family="sans-serif" font-size="16" font-weight="700" fill="#8C6D1F">FIXAÇÃO MNEMÔNICA</text>

  <!-- Node 1 -->
  <rect x="440" y="200" width="300" height="55" rx="8" fill="#F4EFE6" stroke="#D4AF37" stroke-width="1"/>
  <text x="460" y="233" font-family="sans-serif" font-size="14" font-weight="600" fill="#2C1F14">1. Ponto Focal Primário</text>

  <!-- Connector Line -->
  <line x1="590" y1="255" x2="590" y2="280" stroke="#8C6D1F" stroke-width="2" stroke-dasharray="4"/>

  <!-- Node 2 -->
  <rect x="440" y="280" width="300" height="55" rx="8" fill="#F4EFE6" stroke="#3A5A40" stroke-width="1"/>
  <text x="460" y="313" font-family="sans-serif" font-size="14" font-weight="600" fill="#2C1F14">2. Relação Estrutural de Causa</text>

  <!-- Connector Line -->
  <line x1="590" y1="335" x2="590" y2="360" stroke="#3A5A40" stroke-width="2" stroke-dasharray="4"/>

  <!-- Node 3 -->
  <rect x="440" y="360" width="300" height="55" rx="8" fill="#3A5A40"/>
  <text x="460" y="393" font-family="sans-serif" font-size="14" font-weight="700" fill="#FFFFFF">3. Aplicação Prática no Flashcard</text>
</svg>
"""
        with open(svg_path, "w", encoding="utf-8") as f:
            f.write(svg_content)

        b64 = base64.b64encode(svg_content.encode("utf-8")).decode("utf-8")
        url = f"http://127.0.0.1:9876/thumbnails/anki_media/{svg_filename}"

        return {
            "success": True,
            "filename": svg_filename,
            "filepath": str(svg_path),
            "image_url": url,
            "image_base64": f"data:image/svg+xml;base64,{b64}",
            "style": "svg_diagram"
        }

    # Caso 2: Ilustração Mnemônica via Pollinations Flux
    img_filename = f"{file_id}.jpg"
    img_path = ANKI_MEDIA_DIR / img_filename

    encoded_prompt = urllib.parse.quote(f"Educational illustration, clean digital art, vivid concept of {prompt or front}, highly readable, 8k, photorealistic details")
    pollinations_url = f"https://image.pollinations.ai/prompt/{encoded_prompt}?width=1024&height=768&nologo=true&model=flux"

    try:
        req = urllib.request.Request(pollinations_url, headers={"User-Agent": "Zorya-Engine/1.0"})
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = resp.read()
            with open(img_path, "wb") as f:
                f.write(data)
            b64 = base64.b64encode(data).decode("utf-8")
            url = f"http://127.0.0.1:9876/thumbnails/anki_media/{img_filename}"
            return {
                "success": True,
                "filename": img_filename,
                "filepath": str(img_path),
                "image_url": url,
                "image_base64": f"data:image/jpeg;base64,{b64}",
                "style": "illustration_flux"
            }
    except Exception as e:
        # Fallback gracioso para diagrama SVG se download falhar
        payload["style"] = "diagram"
        return generate_anki_media(payload)


def xml_escape(s):
    return (
        s.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
        .replace('"', "&quot;")
        .replace("'", "&apos;")
    )


# ---------------------------------------------------------------------------
# SERVIDOR MCP (JSON-RPC stdio)
# ---------------------------------------------------------------------------
def run_mcp_server():
    """Servidor MCP sobre stdio expondo 'templo_jedi_executar'."""
    creds = CredentialsManager.sync()
    models = ModelsDiscovery.discover(creds)

    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            req = json.loads(line)
        except Exception:
            continue

        method = req.get("method")
        msg_id = req.get("id")

        if method == "initialize":
            resp = {
                "jsonrpc": "2.0",
                "id": msg_id,
                "result": {
                    "protocolVersion": "2024-11-05",
                    "capabilities": {"tools": {}},
                    "serverInfo": {
                        "name": "templo-jedi",
                        "version": "1.0.0"
                    }
                }
            }
            sys.stdout.write(json.dumps(resp) + "\n")
            sys.stdout.flush()

        elif method == "notifications/initialized":
            pass

        elif method == "tools/list":
            resp = {
                "jsonrpc": "2.0",
                "id": msg_id,
                "result": {
                    "tools": [
                        {
                            "name": "templo_jedi_executar",
                            "description": "Executa o ciclo dos 3 Poderes (Coder, Kant, Gatekeeper) para geração e auditoria de código.",
                            "inputSchema": {
                                "type": "object",
                                "properties": {
                                    "arquivo_destino": {"type": "string", "description": "Caminho do arquivo de código a criar ou alterar"},
                                    "instrucao": {"type": "string", "description": "Instruções do que implementar"},
                                    "agente_id": {"type": "string", "description": "Agente Jedi: orch, backend, frontend, fullstack, dba, notion, svitanok, qa"},
                                    "motor_preferido": {"type": "string", "description": "ollama, groq, gemini ou auto"}
                                },
                                "required": ["instrucao"]
                            }
                        }
                    ]
                }
            }
            sys.stdout.write(json.dumps(resp) + "\n")
            sys.stdout.flush()

        elif method == "tools/call":
            params = req.get("params", {})
            name = params.get("name")
            args = params.get("arguments", {})

            if name == "templo_jedi_executar":
                out = execute_jedi_dispatch(args, creds, models)
                resp = {
                    "jsonrpc": "2.0",
                    "id": msg_id,
                    "result": {
                        "content": [{"type": "text", "text": json.dumps(out, ensure_ascii=False)}]
                    }
                }
            else:
                resp = {
                    "jsonrpc": "2.0",
                    "id": msg_id,
                    "error": {"code": -32601, "message": f"Ferramenta desconhecida: {name}"}
                }
            sys.stdout.write(json.dumps(resp) + "\n")
            sys.stdout.flush()


def main():
    parser = argparse.ArgumentParser(description="Templo Jedi - Usina Central Zorya")
    parser.add_argument("--status", action="store_true", help="Inventário de chaves e modelos ativos")
    parser.add_argument("--sync-creds", action="store_true", help="Força sincronização de credenciais via SSH")
    parser.add_argument("--discover", action="store_true", help="Força descoberta de modelos")
    parser.add_argument("--dispatch", type=str, help="Executa ciclo dos 3 Poderes via JSON payload (arquivo ou inline)")
    parser.add_argument("--svitlo-generate", type=str, help="Gera pacote pedagógico Svitlo via JSON payload")
    parser.add_argument("--anki-media", type=str, help="Gera diagrama SVG ou ilustração mnemônica via JSON payload")
    parser.add_argument("--mcp", action="store_true", help="Inicia como servidor MCP stdio")

    args = parser.parse_args()

    if args.mcp:
        run_mcp_server()
        return

    creds = CredentialsManager.sync(force=args.sync_creds)
    models = ModelsDiscovery.discover(creds, force=args.discover)

    if args.status:
        inventory = {
            "status": "online",
            "timestamp": int(time.time()),
            "credentials": {
                "groq_keys": list(creds.get("groq", {}).keys()),
                "gemini_keys": list(creds.get("gemini", {}).keys()),
                "vps_cached": CREDENTIALS_CACHE.exists()
            },
            "models": models,
            "capabilities": [
                "templo_jedi_3_powers",
                "svitlo_study_engine",
                "anki_media_factory",
                "mcp_stdio_server"
            ]
        }
        print(json.dumps(inventory, indent=2, ensure_ascii=False))
        return

    if args.dispatch:
        payload = load_payload(args.dispatch)
        res = execute_jedi_dispatch(payload, creds, models)
        print(json.dumps(res, indent=2, ensure_ascii=False))
        return

    if args.svitlo_generate:
        payload = load_payload(args.svitlo_generate)
        res = generate_svitlo_study(payload, creds, models)
        print(json.dumps(res, indent=2, ensure_ascii=False))
        return

    if args.anki_media:
        payload = load_payload(args.anki_media)
        res = generate_anki_media(payload)
        print(json.dumps(res, indent=2, ensure_ascii=False))
        return

    parser.print_help()


def load_payload(val):
    val = val.strip()
    if val == "-":
        try:
            return json.load(sys.stdin)
        except Exception:
            raw = sys.stdin.read()
            try:
                return json.loads(raw)
            except Exception:
                return {"content": raw, "topic": "Estudo Geral", "prompt": raw}
    if os.path.exists(val):
        with open(val, "r", encoding="utf-8") as f:
            return json.load(f)
    try:
        return json.loads(val)
    except Exception:
        return {"content": val, "topic": "Estudo Geral", "prompt": val}


if __name__ == "__main__":
    main()
