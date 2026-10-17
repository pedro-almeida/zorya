// PT: [script.rs] Converte resumos em roteiros estruturados por cenas
// EN: [script.rs] Converts summaries into scene-structured scripts
// AI: @llm-module video::script

use crate::video::{VideoLessonScript, VideoScene};

pub fn generate_lesson_script(title: &str, raw_markdown: &str) -> VideoLessonScript {
    let paragraphs: Vec<&str> = raw_markdown
        .split("\n\n")
        .map(|s| s.trim())
        .filter(|s| !s.is_empty())
        .collect();

    let mut scenes = Vec::new();
    for (i, p) in paragraphs.iter().enumerate() {
        let word_count = p.split_whitespace().count().max(1);
        let duration_secs = ((word_count as f32) * 0.40).max(5.0);
        scenes.push(VideoScene {
            scene_index: i + 1,
            heading: format!("Tópico {}", i + 1),
            narration_text: (*p).to_string(),
            key_concepts: p
                .split_whitespace()
                .filter(|w| w.len() > 6)
                .take(4)
                .map(|w| w.trim_matches(|c: char| !c.is_alphanumeric()).to_string())
                .collect(),
            duration_secs,
        });
    }

    VideoLessonScript {
        title: title.to_string(),
        description: format!("Aula gerada pelo ecossistema Svitlo + Zorya v3.0: {}", title),
        tags: vec!["Psicologia".into(), "Svitlo".into(), "Educação".into()],
        scenes,
    }
}
