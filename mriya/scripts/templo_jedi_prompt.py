#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# Single-shot prompt builder for Templo Jedi autonomous code repair / Construtor de prompt tiro único para auto-correção do Templo Jedi


def build_prompt(file_content: str, bug_description: str, line_number: int, language: str) -> str:
    """
    Build single-shot prompt with ±50 lines around the target bug for Zorya / Constrói prompt tiro único com ±50 linhas ao redor do bug para o Zorya.
    """
    lines = file_content.splitlines()
    total_lines = len(lines)
    target_line = max(1, min(int(line_number or 1), max(1, total_lines)))

    # Extract ±50 lines around the reported bug / Extrai ±50 linhas ao redor do bug reportado
    start_idx = max(0, target_line - 51)
    end_idx = min(total_lines, target_line + 50)
    context_window = "\n".join(lines[start_idx:end_idx])

    prompt = f"""You are the Templo Jedi Autonomous Code Healer of the Mriya Ecosystem (Espectro Ipê Soluções Tecnológicas).
Você é o Curador Autônomo de Código do Templo Jedi do Ecossistema Mriya.

TARGET METADATA / METADADOS DO ALVO:
- Language / Linguagem: {language}
- Target Line / Linha Alvo: {target_line} (showing lines {start_idx + 1} to {end_idx} / exibindo linhas {start_idx + 1} a {end_idx})
- Bug Description / Descrição Exata do Bug: {bug_description}

MANDATORY ECOSYSTEM RULES / REGRAS INEGOCIÁVEIS DO ECOSSISTEMA:
1. Bilingual comments on modified lines: English first, Portuguese on the same line (e.g. // Validate token / Valida o token).
2. Zero heavy external frameworks: keep pure PHP 8.3 / Vanilla JS / Sovereign Rust.
3. Database security: strictly use PDO prepared statements (`prepare` + `execute`), never raw SQL interpolation.
4. Superglobal safety in PHP 8.3: always guard `$_GET`, `$_POST`, `$_COOKIE`, `$_REQUEST` with null coalescing `??`.
5. Preserve exact indentation and surrounding code structure so string replacement is deterministic.

FILE CONTEXT (±50 LINES AROUND LINE {target_line}) / CONTEXTO DO ARQUIVO:
```{language}
{context_window}
```

OUTPUT FORMAT / FORMATO DE SAÍDA:
Return ONLY a valid JSON object containing the exact original snippet to replace and the corrected snippet. Do NOT wrap in markdown code fences and do NOT add explanations.
Retorne APENAS um objeto JSON válido contendo o trecho original exato e o trecho corrigido:
{{"original": "...", "fixed": "..."}}
"""
    return prompt
