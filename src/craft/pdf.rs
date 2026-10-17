// PT: Extrator estruturado de documentos PDF do PdfCraft com detecção automática de páginas escaneadas.
// EN: PdfCraft structured PDF document extractor with automatic scanned-page OCR detection.
// AI: @llm-module craft::pdf
// AI: @llm-consumer svitlo-study-anki

use std::process::Command;
#[cfg(target_os = "windows")]
use std::os::windows::process::CommandExt;

use axum::{extract::Json, http::StatusCode};
use serde::{Deserialize, Serialize};

/// Cria std::process::Command suprimindo janelas de prompt no Windows (CREATE_NO_WINDOW)
#[inline]
fn new_command(program: &str) -> Command {
    let mut cmd = Command::new(program);
    #[cfg(target_os = "windows")]
    cmd.creation_flags(0x08000000);
    cmd
}

/// Executa pdftotext sem abrir console no Windows
pub fn run_pdftotext(pdf_path: &str) -> Option<String> {
    let mut cmd = new_command("pdftotext");
    let out = cmd.args(["-layout", pdf_path, "-"]).output().ok()?;
    if out.status.success() {
        let text = String::from_utf8_lossy(&out.stdout).trim().to_string();
        if !text.is_empty() {
            return Some(text);
        }
    }
    None
}

/// Executa tesseract OCR sem abrir console no Windows
pub fn run_tesseract(input_path: &str) -> Option<String> {
    let mut cmd = new_command("tesseract");
    let out = cmd.args([input_path, "stdout", "-l", "por+eng"]).output().ok()?;
    if out.status.success() {
        let text = String::from_utf8_lossy(&out.stdout).trim().to_string();
        if !text.is_empty() {
            return Some(text);
        }
    }
    None
}

#[derive(Debug, Clone, Deserialize)]
pub struct PdfExtractRequest {
    pub pdf_path: String,
    pub enable_ocr_fallback: Option<bool>,
    pub max_pages: Option<usize>,
}

#[derive(Debug, Clone, Serialize)]
pub struct PdfPageContent {
    pub page_number: usize,
    pub markdown_text: String,
    pub used_ocr: bool,
    pub char_count: usize,
}

#[derive(Debug, Clone, Serialize)]
pub struct PdfExtractResponse {
    pub pdf_path: String,
    pub total_pages: usize,
    pub combined_markdown: String,
    pub pages: Vec<PdfPageContent>,
}

// PT: Extrai streams de texto legíveis de um buffer PDF bruto e higieniza operadores PostScript.
// EN: Extracts readable text streams from a raw PDF buffer and sanitizes PostScript operators.
// AI: @llm-func extract_pdf_text_streams
pub fn extract_pdf_text_streams(raw: &[u8]) -> Vec<String> {
    let lossy = String::from_utf8_lossy(raw);
    let mut pages = Vec::new();
    let mut current = String::new();

    for token in lossy.split('(').skip(1) {
        if let Some((inside, _rest)) = token.split_once(')') {
            let cleaned: String = inside
                .chars()
                .filter(|c| !c.is_control() || *c == '\n' || *c == '\t')
                .collect();
            if !cleaned.trim().is_empty() {
                current.push_str(cleaned.trim());
                current.push(' ');
            }
            if current.len() > 1800 {
                pages.push(std::mem::take(&mut current));
            }
        }
    }
    if !current.trim().is_empty() || pages.is_empty() {
        pages.push(current);
    }
    pages
}

pub async fn handle_pdf_extract(Json(req): Json<PdfExtractRequest>) -> Result<Json<PdfExtractResponse>, (StatusCode, String)> {
    let ocr_enabled = req.enable_ocr_fallback.unwrap_or(true);
    let raw = std::fs::read(&req.pdf_path).unwrap_or_default();
    let mut raw_pages = extract_pdf_text_streams(&raw);

    // Se a extração interna retornou vazia ou muito curta, tenta pdftotext suprimindo janelas
    if (raw_pages.is_empty() || raw_pages.iter().all(|p| p.trim().is_empty())) && std::path::Path::new(&req.pdf_path).exists() {
        if let Some(txt) = run_pdftotext(&req.pdf_path) {
            raw_pages = vec![txt];
        }
    }

    let limit = req.max_pages.unwrap_or(raw_pages.len()).min(raw_pages.len());

    let mut pages = Vec::with_capacity(limit);
    let mut combined = String::new();

    for (idx, p_txt) in raw_pages.into_iter().take(limit).enumerate() {
        let page_num = idx + 1;
        let used_ocr = p_txt.trim().len() < 30 && ocr_enabled;
        let mut final_txt = p_txt.trim().to_string();
        if used_ocr {
            if let Some(ocr_text) = run_tesseract(&req.pdf_path) {
                if !ocr_text.trim().is_empty() {
                    final_txt = ocr_text;
                }
            }
        }
        let md = if used_ocr {
            format!("### Página {page_num} (OCR Local Ativado)\n\n{}", final_txt)
        } else {
            format!("### Página {page_num}\n\n{}", final_txt)
        };
        combined.push_str(&md);
        combined.push_str("\n\n");
        pages.push(PdfPageContent {
            page_number: page_num,
            char_count: md.len(),
            markdown_text: md,
            used_ocr,
        });
    }

    Ok(Json(PdfExtractResponse {
        pdf_path: req.pdf_path,
        total_pages: pages.len(),
        combined_markdown: combined,
        pages,
    }))
}
