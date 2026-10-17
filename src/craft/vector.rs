// PT: Gerador de diagramas vetoriais SVG ricos do VectorCraft para o Svitlo e estúdio de aulas.
// EN: VectorCraft rich SVG diagram generator for Svitlo and lecture studio.
// AI: @llm-module craft::vector
// AI: @llm-output rich-styled-svg

use axum::{extract::Json, http::StatusCode};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Deserialize)]
pub struct DiagramNode {
    pub id: String,
    pub label: String,
    pub subtitle: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct DiagramEdge {
    pub from: String,
    pub to: String,
    pub relation: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct VectorDiagramRequest {
    pub title: String,
    pub layout: Option<String>, // "flowchart", "mindmap", "timeline"
    pub nodes: Vec<DiagramNode>,
    pub edges: Vec<DiagramEdge>,
}

#[derive(Debug, Clone, Serialize)]
pub struct VectorDiagramResponse {
    pub svg: String,
    pub width: u32,
    pub height: u32,
    pub node_count: usize,
}

// PT: Escapa caracteres especiais XML para evitar quebra de sintaxe no SVG.
// EN: Escapes XML special characters to prevent SVG syntax breakage.
// AI: @llm-func escape_xml
fn escape_xml(s: &str) -> String {
    s.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
}

pub fn render_rich_svg(req: &VectorDiagramRequest) -> (String, u32, u32) {
    let width = 1280_u32;
    let rows = ((req.nodes.len() as u32) + 2) / 3;
    let height = (220 + rows.max(1) * 180).max(520);

    let mut body = String::with_capacity(4096);
    let mut coords = std::collections::HashMap::new();

    for (idx, node) in req.nodes.iter().enumerate() {
        let col = (idx % 3) as u32;
        let row = (idx / 3) as u32;
        let x = 90 + col * 390;
        let y = 140 + row * 170;
        coords.insert(node.id.clone(), (x + 150, y + 45));
    }

    // Desenha conexões com curvas Bézier suaves
    for edge in &req.edges {
        if let (Some(&(x1, y1)), Some(&(x2, y2))) = (coords.get(&edge.from), coords.get(&edge.to)) {
            let cx1 = (x1 + x2) / 2;
            let cy1 = y1;
            let cx2 = (x1 + x2) / 2;
            let cy2 = y2;
            body.push_str(&format!(
                r##"<path d="M {x1} {y1} C {cx1} {cy1}, {cx2} {cy2}, {x2} {y2}" fill="none" stroke="#6366F1" stroke-width="3" marker-end="url(#arrow)" opacity="0.85"/>"##
            ));
            if let Some(rel) = &edge.relation {
                let mx = (x1 + x2) / 2;
                let my = (y1 + y2) / 2 - 8;
                body.push_str(&format!(
                    r##"<text x="{mx}" y="{my}" text-anchor="middle" fill="#CBD5E1" font-family="Inter, system-ui, sans-serif" font-size="12" font-weight="600">{}</text>"##,
                    escape_xml(rel)
                ));
            }
        }
    }

    // Desenha cards dos nós com gradiente e sombra
    for (idx, node) in req.nodes.iter().enumerate() {
        let col = (idx % 3) as u32;
        let row = (idx / 3) as u32;
        let x = 90 + col * 390;
        let y = 140 + row * 170;
        let sub = node.subtitle.as_deref().unwrap_or("");
        body.push_str(&format!(
            r##"<g transform="translate({x},{y})" filter="url(#shadow)">
                <rect width="300" height="92" rx="14" fill="url(#cardGrad)" stroke="#4F46E5" stroke-width="1.5"/>
                <text x="20" y="38" fill="#F8FAFC" font-family="Inter, system-ui, sans-serif" font-size="16" font-weight="700">{}</text>
                <text x="20" y="64" fill="#94A3B8" font-family="Inter, system-ui, sans-serif" font-size="13">{}</text>
            </g>"##,
            escape_xml(&node.label),
            escape_xml(sub)
        ));
    }

    let svg = format!(
        r##"<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" width="{width}" height="{height}">
  <defs>
    <linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0F172A"/>
      <stop offset="100%" stop-color="#1E1B4B"/>
    </linearGradient>
    <linearGradient id="cardGrad" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" stop-color="#1E293B"/>
      <stop offset="100%" stop-color="#0F172A"/>
    </linearGradient>
    <filter id="shadow" x="-10%" y="-10%" width="125%" height="125%">
      <feDropShadow dx="0" dy="8" stdDeviation="6" flood-color="#000000" flood-opacity="0.45"/>
    </filter>
    <marker id="arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
      <path d="M 0 1 L 10 5 L 0 9 z" fill="#6366F1"/>
    </marker>
  </defs>
  <rect width="100%" height="100%" fill="url(#bgGrad)"/>
  <text x="64" y="68" fill="#F8FAFC" font-family="Inter, system-ui, sans-serif" font-size="26" font-weight="800">{}</text>
  <line x1="64" y1="88" x2="{}" y2="88" stroke="#334155" stroke-width="2"/>
  {body}
</svg>"##,
        escape_xml(&req.title),
        width - 64
    );

    (svg, width, height)
}

pub async fn handle_vector_diagram(Json(req): Json<VectorDiagramRequest>) -> Result<Json<VectorDiagramResponse>, (StatusCode, String)> {
    let (svg, width, height) = render_rich_svg(&req);
    Ok(Json(VectorDiagramResponse {
        svg,
        width,
        height,
        node_count: req.nodes.len(),
    }))
}
