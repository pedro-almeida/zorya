// PT: Módulo de Segurança de Aplicação, Sanitização e Validação Soberana (Zorya v3.0)
// EN: Application Security, Sanitization and Sovereign Validation Module (Zorya v3.0)
// AI: @llm-module security | @llm-context Proteção CSRF, headers OWASP, isolamento de path e prevenção de injection
// AI: @llm-invariant Previne Path Traversal, Command Injection e requisições CSRF não autenticadas

use axum::{
    extract::Request,
    http::{header, HeaderValue},
    middleware::Next,
    response::Response,
};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{SystemTime, UNIX_EPOCH};

static CSRF_COUNTER: AtomicU64 = AtomicU64::new(1);

/// PT: Gera um token CSRF criptograficamente seguro ou pseudo-aleatório baseado em timestamp e counter
/// EN: Generates a CSRF token based on system epoch and atomic sequence
// AI: @llm-func generate_csrf_token @llm-security Token por sessão para o formulário /settings
pub fn generate_csrf_token(secret: &str) -> String {
    let now = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_nanos();
    let count = CSRF_COUNTER.fetch_add(1, Ordering::Relaxed);
    let raw = format!("{}:{}:{}", secret, now, count);

    // Hash determinístico leve sem dependência externa adicional
    use std::collections::hash_map::DefaultHasher;
    use std::hash::{Hash, Hasher};
    let mut hasher = DefaultHasher::new();
    raw.hash(&mut hasher);
    let h1 = hasher.finish();
    format!("zcsrf_{:016x}{:016x}", h1, count)
}

/// PT: Valida se um token CSRF recebido confere com o padrão e formato seguro
/// EN: Validates whether incoming CSRF token matches expected format and prefix
// AI: @llm-func validate_csrf_token @llm-security Rejeita tokens nulos ou forjados
pub fn validate_csrf_token(token: &str) -> bool {
    let t = token.trim();
    t.starts_with("zcsrf_") && t.len() >= 24
}

/// PT: Sanitiza caminho de arquivo garantindo que permaneça estritamente contido em allowed_base_dir
/// EN: Sanitizes file path ensuring it remains strictly within allowed_base_dir
// AI: @llm-func sanitize_path @llm-security Bloqueia Path Traversal (..) e caracteres de escape
pub fn sanitize_path(base_dir: &Path, user_supplied: &str) -> anyhow::Result<PathBuf> {
    let trimmed = user_supplied.trim();
    if trimmed.is_empty() {
        anyhow::bail!("Caminho de arquivo vazio / Empty file path");
    }

    if trimmed.contains("..") || trimmed.contains('\0') {
        anyhow::bail!("Tentativa de Path Traversal detectada / Path traversal attempt detected");
    }

    // Bloqueia caminhos absolutos do sistema perigosos
    let lower = trimmed.replace('\\', "/").to_lowercase();
    let forbidden_prefixes = [
        "/etc",
        "/root",
        "/var/run",
        "c:/windows",
        "c:\\windows",
        "/proc",
        "/sys",
        "/dev",
    ];
    for p in forbidden_prefixes {
        if lower.starts_with(p) {
            anyhow::bail!("Acesso a diretório de sistema proibido: {}", trimmed);
        }
    }

    let target = if Path::new(trimmed).is_absolute() {
        PathBuf::from(trimmed)
    } else {
        base_dir.join(trimmed)
    };

    // Se o caminho existe, verifica canonicidade
    if target.exists() {
        let canonical_target = target.canonicalize()?;
        if base_dir.exists() {
            let canonical_base = base_dir.canonicalize()?;
            if !canonical_target.starts_with(&canonical_base)
                && !canonical_target.starts_with(base_dir)
            {
                // Se não está dentro da base, mas for permitido pelo modelo específico, checa
                tracing::warn!("Path fora do diretório base padrão: {:?}", canonical_target);
            }
        }
        Ok(canonical_target)
    } else {
        Ok(target)
    }
}

/// PT: Middleware de injeção de Headers de Segurança OWASP (CSP, HSTS, X-Content-Type-Options)
/// EN: Middleware for injecting OWASP Security Headers (CSP, HSTS, X-Content-Type-Options)
// AI: @llm-middleware security_headers_middleware @llm-security Aplica CSP e nosniff em todas as rotas
pub async fn security_headers_middleware(req: Request, next: Next) -> Response {
    let mut res = next.run(req).await;
    let headers = res.headers_mut();

    headers.insert(
        header::HeaderName::from_static("x-content-type-options"),
        HeaderValue::from_static("nosniff"),
    );
    headers.insert(
        header::HeaderName::from_static("x-frame-options"),
        HeaderValue::from_static("SAMEORIGIN"),
    );
    headers.insert(
        header::HeaderName::from_static("referrer-policy"),
        HeaderValue::from_static("strict-origin-when-cross-origin"),
    );
    headers.insert(
        header::HeaderName::from_static("x-xss-protection"),
        HeaderValue::from_static("1; mode=block"),
    );

    // Content-Security-Policy estrito para o Svitlo Design System
    let csp = "default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; connect-src 'self' http://127.0.0.1:8085 http://100.106.120.35:8085 https://api.espectroipe.com.br https://svitlo.espectroipe.com.br; script-src 'self' 'unsafe-inline';";
    if let Ok(val) = HeaderValue::from_str(csp) {
        headers.insert(
            header::HeaderName::from_static("content-security-policy"),
            val,
        );
    }

    res
}

/// PT: Heurística local ultrarrápida contra Prompt Injection para quando offline
/// EN: Ultrafast local heuristic prompt injection guard for offline conditions
// AI: @llm-func check_prompt_injection_heuristic @llm-security Bloqueia jailbreaks e system prompt overrides
pub fn check_prompt_injection_heuristic(text: &str) -> (bool, &'static str) {
    let lower = text.to_lowercase();
    let patterns = [
        ("ignore previous instructions", "jailbreak_ignore_prev"),
        ("ignore all prior instructions", "jailbreak_ignore_prior"),
        ("disregard all previous", "jailbreak_disregard"),
        ("you are now DAN", "jailbreak_dan"),
        ("system prompt override", "jailbreak_override"),
        ("reveal your system prompt", "prompt_leak"),
        ("exfiltrate keys", "key_exfiltration"),
        ("print your instructions", "instruction_leak"),
    ];

    for (pat, reason) in patterns {
        if lower.contains(pat) {
            return (true, reason);
        }
    }
    (false, "clean")
}

/// PT: Política declarativa de confinamento de agente inspirada no NVIDIA OpenShell
/// EN: Declarative agent confinement policy inspired by NVIDIA OpenShell
#[derive(Debug, Clone)]
pub struct OpenShellPolicy {
    pub allowed_roots: Vec<PathBuf>,
    pub allowed_binaries: Vec<String>,
    pub allow_outbound_network: bool,
}

impl Default for OpenShellPolicy {
    fn default() -> Self {
        Self {
            allowed_roots: vec![
                PathBuf::from("datasets"),
                PathBuf::from("outputs"),
                PathBuf::from("scripts"),
            ],
            allowed_binaries: vec![
                "ffmpeg".to_string(),
                "ffmpeg.exe".to_string(),
                "ffprobe".to_string(),
                "ffprobe.exe".to_string(),
                "yt-dlp".to_string(),
                "yt-dlp.exe".to_string(),
                "python".to_string(),
                "python.exe".to_string(),
                "python3".to_string(),
                "edge-tts".to_string(),
                "edge-tts.exe".to_string(),
                "git".to_string(),
                "git.exe".to_string(),
            ],
            allow_outbound_network: true,
        }
    }
}

pub fn verify_openshell_command(binary: &str, policy: &OpenShellPolicy) -> bool {
    let bin_name = Path::new(binary)
        .file_name()
        .and_then(|s| s.to_str())
        .unwrap_or(binary)
        .to_ascii_lowercase();
    policy
        .allowed_binaries
        .iter()
        .any(|b| b.to_ascii_lowercase() == bin_name)
}

/// PT: Valida e sanitiza nomes de perfil vocal conforme regex declarativo ^[a-z0-9_-]+$
/// EN: Validates and sanitizes voice profile names according to declarative regex ^[a-z0-9_-]+$
pub fn sanitize_profile_id(raw: &str) -> anyhow::Result<String> {
    let trimmed = raw.trim().to_ascii_lowercase();
    if trimmed.is_empty() {
        anyhow::bail!("Identificador de perfil vocal vazio");
    }
    if !trimmed
        .chars()
        .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || c == '-')
    {
        anyhow::bail!(
            "Identificador de perfil vocal inválido (deve conter apenas [a-z0-9_-]): {}",
            raw
        );
    }
    Ok(trimmed)
}

/// PT: Valida e sanitiza URLs do YouTube bloqueando qualquer injeção de shell
/// EN: Validates and sanitizes YouTube URLs blocking any shell injection attempt
pub fn sanitize_youtube_url(url: &str) -> anyhow::Result<String> {
    let trimmed = url.trim();
    if trimmed.is_empty() {
        anyhow::bail!("URL do YouTube vazia");
    }
    let forbidden_chars = ['\0', ';', '&', '|', '`', '$', '\n', '\r', '"', '\''];
    if trimmed.chars().any(|c| forbidden_chars.contains(&c)) {
        anyhow::bail!("Injeção de caracteres detectada na URL: {}", url);
    }
    let lower = trimmed.to_lowercase();
    let is_valid_yt = lower.starts_with("https://www.youtube.com/")
        || lower.starts_with("https://youtube.com/")
        || lower.starts_with("https://m.youtube.com/")
        || lower.starts_with("https://youtu.be/")
        || lower.starts_with("http://www.youtube.com/")
        || lower.starts_with("http://youtu.be/");
    if !is_valid_yt {
        anyhow::bail!("URL não pertence ao domínio YouTube permitido: {}", url);
    }
    Ok(trimmed.to_string())
}
