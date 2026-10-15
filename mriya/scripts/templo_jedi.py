#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# Templo Jedi Autonomous Self-Audit & Self-Healing Orchestrator / Orquestrador Autônomo de Auto-Auditoria e Auto-Correção Templo Jedi

import datetime
import json
import logging
import os
import py_compile
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Dict, List, Optional

from templo_jedi_prompt import build_prompt

# Canonical paths on Mriya VPS / Caminhos canônicos na VPS Mriya
REPO_ROOT = Path(os.getenv("MRIYA_REPO_ROOT", "/var/www/mriya"))
AUDIT_REPORT_PATH = Path(
    os.getenv("MRIYA_AUDIT_JSON", "/var/www/mriya/mriya/logs/audit_latest.json")
)
LOG_FILE_PATH = Path(
    os.getenv("TEMPLO_JEDI_LOG", "/var/www/mriya/mriya/logs/templo_jedi.log")
)
CREDENTIALS_PATH = Path(
    os.getenv("MRIYA_CREDENTIALS", "/var/www/mcp-server/credentials/credentials.json")
)
ZORYA_CHAT_URL = os.getenv(
    "ZORYA_CHAT_URL", "http://usina-zorya:8085/v1/chat/completions"
)
ZORYA_FALLBACK_URL = os.getenv(
    "ZORYA_FALLBACK_URL", "http://127.0.0.1:8085/v1/chat/completions"
)
MAX_OPEN_BRANCHES = 5

# Non-negotiable protected files and directories / Arquivos e diretórios protegidos inegociáveis
FORBIDDEN_PATTERNS = (
    "router.php",
    "little-hedla",
    ".sql",
    "schema",
    "migration",
)


class TemploJedi:
    """
    Autonomous code auditor and branch healer using Zorya as AI coprocessor /
    Sistema autônomo de auditoria e correção em branches usando o Zorya como coprocessador.
    """

    def __init__(self, repo_root: Path = REPO_ROOT) -> None:
        self.repo_root = repo_root
        LOG_FILE_PATH.parent.mkdir(parents=True, exist_ok=True)
        logging.basicConfig(
            level=logging.INFO,
            format="%(asctime)s [TEMPLO-JEDI] [%(levelname)s] %(message)s",
            handlers=[
                logging.FileHandler(LOG_FILE_PATH, encoding="utf-8"),
                logging.StreamHandler(sys.stdout),
            ],
        )
        self.logger = logging.getLogger("TemploJedi")
        self.credentials = self._load_credentials()

    # Load centralized credentials.json without hardcoding secrets / Carrega credentials.json centralizado sem hardcode de segredos
    def _load_credentials(self) -> Dict[str, Any]:
        if CREDENTIALS_PATH.exists():
            try:
                return json.loads(CREDENTIALS_PATH.read_text(encoding="utf-8"))
            except Exception as exc:
                self.logger.warning("Failed to parse credentials.json / Falha ao ler credentials.json: %s", exc)
        return {}

    # Check if a file touches any forbidden ecosystem rule / Verifica se o arquivo viola alguma regra inegociável do ecossistema
    def _is_protected_path(self, file_path: str) -> bool:
        norm = file_path.replace("\\", "/").lower()
        for pat in FORBIDDEN_PATTERNS:
            if pat in norm:
                return True
        return False

    # Count currently open fix/jedi-* branches / Conta quantas branches fix/jedi-* estão abertas simultaneamente
    def _count_open_jedi_branches(self) -> int:
        try:
            out = subprocess.check_output(
                ["git", "-C", str(self.repo_root), "branch", "--list", "fix/jedi-*"],
                text=True,
                stderr=subprocess.DEVNULL,
            )
            branches = [b.strip() for b in out.splitlines() if b.strip()]
            return len(branches)
        except Exception:
            return 0

    # 1. Read /var/www/mriya/mriya/logs/audit_latest.json / Lê o relatório JSON gerado pelo nightly_auditor.py
    def load_audit_report(self) -> Dict[str, Any]:
        if not AUDIT_REPORT_PATH.exists():
            self.logger.warning(
                "Audit report not found at %s / Relatório não encontrado em %s",
                AUDIT_REPORT_PATH,
                AUDIT_REPORT_PATH,
            )
            return {"date": datetime.date.today().isoformat(), "bugs": [], "nginx_issues": [], "ux_issues": []}

        raw = AUDIT_REPORT_PATH.read_text(encoding="utf-8")
        data = json.loads(raw)
        self.logger.info(
            "Loaded audit report (%s) with %d bugs / Relatório carregado (%s) com %d bugs",
            data.get("date"),
            len(data.get("bugs", [])),
            data.get("date"),
            len(data.get("bugs", [])),
        )
        return data

    # 2. Create atomic branch fix/jedi-YYYYMMDD-{slug} from main / Cria branch atômica fix/jedi-YYYYMMDD-{slug} a partir da main
    def create_branch(self, bug_slug: str) -> str:
        date_str = datetime.date.today().strftime("%Y%m%d")
        clean_slug = re.sub(r"[^a-z0-9\-]+", "-", bug_slug.lower()).strip("-")[:40] or "fix"
        branch_name = f"fix/jedi-{date_str}-{clean_slug}"

        # Always start cleanly from main, NEVER merging back into main / Sempre parte da main limpa, NUNCA fazendo merge na main
        subprocess.run(
            ["git", "-C", str(self.repo_root), "checkout", "main"],
            check=True,
            capture_output=True,
            text=True,
        )
        subprocess.run(
            ["git", "-C", str(self.repo_root), "checkout", "-B", branch_name],
            check=True,
            capture_output=True,
            text=True,
        )
        self.logger.info("Created branch %s / Branch %s criada com sucesso", branch_name, branch_name)
        return branch_name

    # 3. Request single-shot JSON patch from Zorya /v1/chat/completions / Solicita patch JSON tiro único ao Zorya via POST /v1/chat/completions
    def request_fix(
        self, file_path: str, bug_description: str, line_number: int
    ) -> Optional[Dict[str, str]]:
        abs_path = self.repo_root / file_path
        if not abs_path.exists():
            self.logger.error("Target file does not exist: %s / Arquivo alvo inexistente: %s", abs_path, abs_path)
            return None

        file_content = abs_path.read_text(encoding="utf-8", errors="replace")
        ext = abs_path.suffix.lstrip(".").lower() or "php"
        prompt = build_prompt(file_content, bug_description, line_number, ext)

        payload = {
            "model": os.getenv("ZORYA_MODEL", "qwen2.5-coder:7b-instruct-q4_K_M"),
            "stream": False,
            "temperature": 0.1,
            "messages": [
                {
                    "role": "system",
                    "content": 'Return strictly valid JSON: {"original": "...", "fixed": "..."}',
                },
                {"role": "user", "content": prompt},
            ],
        }

        body_bytes = json.dumps(payload).encode("utf-8")
        for endpoint in (ZORYA_CHAT_URL, ZORYA_FALLBACK_URL):
            try:
                req = urllib.request.Request(
                    endpoint,
                    data=body_bytes,
                    headers={"Content-Type": "application/json", "User-Agent": "TemploJedi/2.1.0"},
                    method="POST",
                )
                with urllib.request.urlopen(req, timeout=90) as resp:
                    resp_json = json.loads(resp.read().decode("utf-8", errors="replace"))
                    raw_content = (
                        resp_json.get("choices", [{}])[0]
                        .get("message", {})
                        .get("content", "")
                        .strip()
                    )
                    # Strip optional markdown fences if model included them / Remove cercas markdown opcionais
                    cleaned = re.sub(r"^```(?:json)?\s*|\s*```$", "", raw_content.strip(), flags=re.DOTALL)
                    parsed = json.loads(cleaned)
                    if isinstance(parsed, dict) and "original" in parsed and "fixed" in parsed:
                        return {
                            "original": str(parsed["original"]),
                            "fixed": str(parsed["fixed"]),
                        }
            except Exception as exc:
                self.logger.warning(
                    "Zorya endpoint %s failed (%s), trying next / Endpoint %s falhou (%s)",
                    endpoint,
                    exc,
                    endpoint,
                    exc,
                )

        return None

    # 4. Apply patch snippet to target file / Substitui o trecho original pelo corrigido no arquivo
    def apply_patch(self, file_path: str, original: str, fixed: str) -> bool:
        if self._is_protected_path(file_path):
            self.logger.error(
                "BLOCKED: %s is a protected path (router.php / SQL / little-hedla) / BLOQUEADO: %s é um arquivo protegido",
                file_path,
                file_path,
            )
            return False

        abs_path = self.repo_root / file_path
        if not abs_path.exists() or not original or original == fixed:
            return False

        content = abs_path.read_text(encoding="utf-8", errors="replace")
        if original not in content:
            self.logger.error(
                "Original snippet not found verbatim in %s / Trecho original não encontrado literalmente em %s",
                file_path,
                file_path,
            )
            return False

        updated = content.replace(original, fixed, 1)
        abs_path.write_text(updated, encoding="utf-8")
        self.logger.info("Applied patch to %s / Patch aplicado em %s", file_path, file_path)
        return True

    # 5. Validate syntax via php -l or cargo check / Valida sintaxe via php -l ou cargo check conforme extensão
    def validate_syntax(self, file_path: str) -> bool:
        abs_path = self.repo_root / file_path
        ext = abs_path.suffix.lower()

        try:
            if ext == ".php":
                res = subprocess.run(
                    ["php", "-l", str(abs_path)],
                    capture_output=True,
                    text=True,
                    timeout=20,
                )
                valid = res.returncode == 0
                if not valid:
                    self.logger.error("php -l failed / php -l falhou: %s", res.stderr or res.stdout)
                return valid

            if ext == ".rs":
                res = subprocess.run(
                    ["cargo", "check"],
                    cwd=str(abs_path.parent),
                    capture_output=True,
                    text=True,
                    timeout=120,
                )
                valid = res.returncode == 0
                if not valid:
                    self.logger.error("cargo check failed / cargo check falhou: %s", res.stderr)
                return valid

            if ext == ".py":
                py_compile.compile(str(abs_path), doraise=True)
                return True

            return True
        except Exception as exc:
            self.logger.error("Syntax validation exception / Exceção na validação sintática: %s", exc)
            return False

    # 6. Commit and push atomic branch (never main) / Faz git add, commit e push da branch atômica (nunca na main)
    def commit_and_push(self, branch: str, message: str) -> str:
        if branch in ("main", "master"):
            raise RuntimeError("Templo Jedi is strictly forbidden from pushing to main / Proibido push na main")

        subprocess.run(
            ["git", "-C", str(self.repo_root), "add", "-A"],
            check=True,
            capture_output=True,
            text=True,
        )
        diff_out = subprocess.check_output(
            ["git", "-C", str(self.repo_root), "diff", "--cached"],
            text=True,
        )
        subprocess.run(
            ["git", "-C", str(self.repo_root), "commit", "-m", message],
            check=True,
            capture_output=True,
            text=True,
        )
        subprocess.run(
            ["git", "-C", str(self.repo_root), "push", "-u", "origin", branch],
            check=False,
            capture_output=True,
            text=True,
        )
        self.logger.info("Committed and pushed branch %s / Commit e push concluídos na branch %s", branch, branch)
        return diff_out

    # 7. Open Linear issue with diff awaiting human approval / Abre issue no Linear com o diff aguardando aprovação humana
    def open_linear_issue(self, branch: str, diff: str, bug: Dict[str, Any]) -> Optional[str]:
        linear_cfg = self.credentials.get("linear", {})
        api_key = (
            os.getenv("LINEAR_API_KEY")
            or (linear_cfg.get("api_key") if isinstance(linear_cfg, dict) else None)
            or (linear_cfg.get("token") if isinstance(linear_cfg, dict) else None)
            or self.credentials.get("linear_api_key", "")
        )
        team_id = (
            os.getenv("LINEAR_TEAM_ID")
            or (linear_cfg.get("team_id") if isinstance(linear_cfg, dict) else None)
            or ""
        )

        title = f"[Templo Jedi] Fix {bug.get('file')}:{bug.get('line')} ({branch})"
        description = (
            f"### Correção Autônoma — Templo Jedi\n\n"
            f"- **Branch:** `{branch}` (aguardando revisão e merge humano)\n"
            f"- **Arquivo:** `{bug.get('file')}` (Linha {bug.get('line')})\n"
            f"- **Severidade:** `{bug.get('severity', 'medium')}`\n"
            f"- **Descrição:** {bug.get('description')}\n\n"
            f"#### Diff Validado (`php -l` / `cargo check`)\n"
            f"```diff\n{diff[:6000]}\n```"
        )

        if not api_key:
            self.logger.warning(
                "Linear API key not found; issue logged locally for branch %s / Chave Linear ausente; issue registrada no log para %s",
                branch,
                branch,
            )
            return None

        try:
            # Resolve default team ID dynamically if not configured / Resolve o team_id dinamicamente se não estiver configurado
            if not team_id:
                q_teams = json.dumps({"query": "{ teams(first: 1) { nodes { id name } } }"}).encode("utf-8")
                req_t = urllib.request.Request(
                    "https://api.linear.app/graphql",
                    data=q_teams,
                    headers={"Authorization": api_key, "Content-Type": "application/json"},
                    method="POST",
                )
                with urllib.request.urlopen(req_t, timeout=15) as r_t:
                    t_data = json.loads(r_t.read().decode("utf-8"))
                    nodes = t_data.get("data", {}).get("teams", {}).get("nodes", [])
                    if nodes:
                        team_id = nodes[0].get("id", "")

            mutation = {
                "query": """
                mutation IssueCreate($title: String!, $description: String!, $teamId: String!) {
                  issueCreate(input: { title: $title, description: $description, teamId: $teamId }) {
                    success
                    issue { id identifier url }
                  }
                }
                """,
                "variables": {
                    "title": title,
                    "description": description,
                    "teamId": team_id,
                },
            }
            req = urllib.request.Request(
                "https://api.linear.app/graphql",
                data=json.dumps(mutation).encode("utf-8"),
                headers={"Authorization": api_key, "Content-Type": "application/json"},
                method="POST",
            )
            with urllib.request.urlopen(req, timeout=20) as resp:
                res_data = json.loads(resp.read().decode("utf-8"))
                issue_info = (
                    res_data.get("data", {})
                    .get("issueCreate", {})
                    .get("issue", {})
                )
                issue_url = issue_info.get("url") or issue_info.get("identifier")
                self.logger.info("Opened Linear issue: %s / Issue aberta no Linear: %s", issue_url, issue_url)
                return issue_url
        except Exception as exc:
            self.logger.error("Failed to open Linear issue / Falha ao abrir issue no Linear: %s", exc)
            return None

    # 8. Orchestrate atomic self-healing workflow / Orquestra o fluxo completo de auto-auditoria e auto-correção
    def run(self) -> None:
        self.logger.info("Starting Templo Jedi execution / Iniciando execução do Templo Jedi...")
        report = self.load_audit_report()
        bugs: List[Dict[str, Any]] = report.get("bugs", [])

        if not bugs:
            self.logger.info("Zero bugs in audit_latest.json. Standing down / Zero bugs pendentes.")
            return

        for idx, bug in enumerate(bugs, 1):
            open_branches = self._count_open_jedi_branches()
            if open_branches >= MAX_OPEN_BRANCHES:
                self.logger.warning(
                    "Reached limit of %d open fix/jedi-* branches. Waiting for human approval / Limite de %d branches abertas atingido. Aguardando aprovação humana.",
                    MAX_OPEN_BRANCHES,
                    MAX_OPEN_BRANCHES,
                )
                break

            file_path = str(bug.get("file", "")).strip()
            line_number = int(bug.get("line", 1) or 1)
            description = str(bug.get("description") or bug.get("desc") or "SAST alert").strip()

            if not file_path or self._is_protected_path(file_path):
                self.logger.warning(
                    "Skipping protected or invalid file: %s / Ignorando arquivo protegido ou inválido: %s",
                    file_path,
                    file_path,
                )
                continue

            slug_base = f"{Path(file_path).stem}-L{line_number}-{idx}"
            try:
                branch = self.create_branch(slug_base)
                fix_data = self.request_fix(file_path, description, line_number)
                if not fix_data:
                    self.logger.warning("No valid fix returned for %s:%d / Nenhuma correção retornada", file_path, line_number)
                    subprocess.run(["git", "-C", str(self.repo_root), "checkout", "main"], check=False)
                    subprocess.run(["git", "-C", str(self.repo_root), "branch", "-D", branch], check=False)
                    continue

                if not self.apply_patch(file_path, fix_data["original"], fix_data["fixed"]):
                    subprocess.run(["git", "-C", str(self.repo_root), "checkout", "--", "."], check=False)
                    subprocess.run(["git", "-C", str(self.repo_root), "checkout", "main"], check=False)
                    subprocess.run(["git", "-C", str(self.repo_root), "branch", "-D", branch], check=False)
                    continue

                if not self.validate_syntax(file_path):
                    self.logger.error(
                        "Syntax validation failed for %s; reverting branch %s / Validação sintática falhou; revertendo %s",
                        file_path,
                        branch,
                        branch,
                    )
                    subprocess.run(["git", "-C", str(self.repo_root), "checkout", "--", "."], check=False)
                    subprocess.run(["git", "-C", str(self.repo_root), "checkout", "main"], check=False)
                    subprocess.run(["git", "-C", str(self.repo_root), "branch", "-D", branch], check=False)
                    continue

                commit_msg = f"fix(jedi): resolve SAST alert in {file_path}:{line_number}"
                diff_text = self.commit_and_push(branch, commit_msg)
                self.open_linear_issue(branch, diff_text, bug)

            except Exception as exc:
                self.logger.error("Error processing bug %s:%d -> %s", file_path, line_number, exc)
            finally:
                # Always return working copy to main without merging / Sempre retorna para a main sem fazer merge
                subprocess.run(
                    ["git", "-C", str(self.repo_root), "checkout", "main"],
                    check=False,
                    capture_output=True,
                )

        self.logger.info("Templo Jedi run completed / Execução do Templo Jedi finalizada.")


if __name__ == "__main__":
    jedi = TemploJedi()
    jedi.run()
