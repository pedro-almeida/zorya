// PT: Gerador de diagramas vetoriais SVG do VectorCraft com suporte aos modos Kindle (e-ink branco puro), Normal (acadêmico Svitlo) e Dark.
// EN: VectorCraft SVG diagram generator supporting Kindle (pure white e-ink), Normal (Svitlo academic), and Dark modes.
// AI: @llm-module craft::vector @llm-route POST /api/v1/vector/render & POST /api/v1/vector/diagram

use axum::{extract::Json, http::StatusCode};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Deserialize)]
pub struct DiagramNode {
    pub id: String,
    pub label: String,
    #[serde(default)]
    pub subtitle: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct DiagramEdge {
    pub from: String,
    pub to: String,
    #[serde(default)]
    pub relation: Option<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct VectorDiagramRequest {
    pub title: String,
    #[serde(default)]
    pub layout: Option<String>,
    #[serde(default)]
    pub mode: Option<String>, // "kindle", "normal", "dark"
    #[serde(default)]
    pub dark_mode: Option<bool>,
    #[serde(default)]
    pub nodes: Vec<DiagramNode>,
    #[serde(default)]
    pub edges: Vec<DiagramEdge>,
}

#[derive(Debug, Clone, Serialize)]
pub struct VectorDiagramResponse {
    pub svg: String,
    pub diagram: String,
    pub width: u32,
    pub height: u32,
    pub node_count: usize,
}

fn escape_xml(s: &str) -> String {
    s.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
}

pub fn render_rich_svg(req: &VectorDiagramRequest) -> (String, u32, u32) {
    let mode_str = req.mode.as_deref().unwrap_or("").to_lowercase();
    let is_kindle = mode_str == "kindle";
    let is_dark = !is_kindle && (mode_str == "dark" || req.dark_mode.unwrap_or(false));

    let width = 960_u32;
    let cols = 2_u32;
    let rows = ((req.nodes.len() as u32) + (cols - 1)) / cols;
    let height = (160 + rows.max(1) * 145).max(320);

    let mut body = String::with_capacity(4096);
    let mut coords = std::collections::HashMap::new();

    for (idx, node) in req.nodes.iter().enumerate() {
        let col = (idx as u32) % cols;
        let row = (idx as u32) / cols;
        let x = 60 + col * 450;
        let y = 110 + row * 140;
        coords.insert(node.id.clone(), (x + 190, y + 42));
    }

    // Se edges vier vazio, conecta os nós sequencialmente para garantir fluxo visual
    let effective_edges: Vec<DiagramEdge> = if !req.edges.is_empty() {
        req.edges.clone()
    } else {
        req.nodes
            .windows(2)
            .map(|w| DiagramEdge {
                from: w[0].id.clone(),
                to: w[1].id.clone(),
                relation: None,
            })
            .collect()
    };

    let stroke_edge = if is_kindle {
        "#000000"
    } else if is_dark {
        "#6366F1"
    } else {
        "#8B5E3C"
    };

    for edge in &effective_edges {
        if let (Some(&(x1, y1)), Some(&(x2, y2))) = (coords.get(&edge.from), coords.get(&edge.to)) {
            let cx1 = (x1 + x2) / 2;
            let cy1 = y1;
            let cx2 = (x1 + x2) / 2;
            let cy2 = y2;
            body.push_str(&format!(
                r##"<path d="M {x1} {y1} C {cx1} {cy1}, {cx2} {cy2}, {x2} {y2}" fill="none" stroke="{stroke_edge}" stroke-width="2.5" marker-end="url(#arrow)"/>"##
            ));
            if let Some(rel) = &edge.relation {
                let mx = (x1 + x2) / 2;
                let my = (y1 + y2) / 2 - 8;
                let rel_color = if is_kindle { "#000000" } else if is_dark { "#CBD5E1" } else { "#5C3D1E" };
                body.push_str(&format!(
                    r##"<text x="{mx}" y="{my}" text-anchor="middle" fill="{rel_color}" font-family="Inter, Georgia, sans-serif" font-size="12" font-weight="700">{}</text>"##,
                    escape_xml(rel)
                ));
            }
        }
    }

    for (idx, node) in req.nodes.iter().enumerate() {
        let col = (idx as u32) % cols;
        let row = (idx as u32) / cols;
        let x = 60 + col * 450;
        let y = 110 + row * 140;
        let sub = node.subtitle.as_deref().unwrap_or("");

        if is_kindle {
            body.push_str(&format!(
                r##"<g transform="translate({x},{y})">
                    <rect width="380" height="84" rx="6" fill="#FFFFFF" stroke="#000000" stroke-width="2"/>
                    <text x="16" y="34" fill="#000000" font-family="Georgia, serif" font-size="15" font-weight="700">{}</text>
                    <text x="16" y="60" fill="#000000" font-family="Georgia, serif" font-size="13">{}</text>
                </g>"##,
                escape_xml(&node.label),
                escape_xml(sub)
            ));
        } else if is_dark {
            body.push_str(&format!(
                r##"<g transform="translate({x},{y})">
                    <rect width="380" height="84" rx="12" fill="#1E293B" stroke="#4F46E5" stroke-width="1.5"/>
                    <text x="18" y="34" fill="#F8FAFC" font-family="Inter, system-ui, sans-serif" font-size="15" font-weight="700">{}</text>
                    <text x="18" y="58" fill="#94A3B8" font-family="Inter, system-ui, sans-serif" font-size="12.5">{}</text>
                </g>"##,
                escape_xml(&node.label),
                escape_xml(sub)
            ));
        } else {
            body.push_str(&format!(
                r##"<g transform="translate({x},{y})">
                    <rect width="380" height="84" rx="10" fill="#FFFFFF" stroke="#C9A84C" stroke-width="2"/>
                    <text x="18" y="34" fill="#5C3D1E" font-family="Inter, Georgia, sans-serif" font-size="15" font-weight="700">{}</text>
                    <text x="18" y="58" fill="#3D2B1F" font-family="Inter, Georgia, sans-serif" font-size="12.5">{}</text>
                </g>"##,
                escape_xml(&node.label),
                escape_xml(sub)
            ));
        }
    }

    let bg_fill = if is_kindle { "#FFFFFF" } else if is_dark { "#0F172A" } else { "#FAF7F2" };
    let border_col = if is_kindle { "#000000" } else if is_dark { "#334155" } else { "#C9A84C" };
    let title_col = if is_kindle { "#000000" } else if is_dark { "#F8FAFC" } else { "#5C3D1E" };

    let svg = format!(
        r##"<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" width="{width}" height="{height}">
  <defs>
    <marker id="arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
      <path d="M 0 1 L 10 5 L 0 9 z" fill="{stroke_edge}"/>
    </marker>
  </defs>
  <rect width="100%" height="100%" rx="10" fill="{bg_fill}" stroke="{border_col}" stroke-width="2"/>
  <text x="60" y="54" fill="{title_col}" font-family="Georgia, Inter, sans-serif" font-size="20" font-weight="800">{}</text>
  <line x1="60" y1="72" x2="{}" y2="72" stroke="{border_col}" stroke-width="1.5"/>
  {body}
</svg>"##,
        escape_xml(&req.title),
        width - 60
    );

    (svg, width, height)
}

pub async fn handle_vector_diagram(
    Json(req): Json<VectorDiagramRequest>,
) -> Result<Json<VectorDiagramResponse>, (StatusCode, String)> {
    let (svg, width, height) = render_rich_svg(&req);
    Ok(Json(VectorDiagramResponse {
        diagram: svg.clone(),
        svg,
        width,
        height,
        node_count: req.nodes.len(),
    }))
}
