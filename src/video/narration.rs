// PT: [narration.rs] Geração de legendas SRT sincronizadas e síntese por cena
// EN: [narration.rs] Synchronized SRT subtitle generation and scene narration
// AI: @llm-module video::narration

use crate::video::VideoLessonScript;

pub fn build_srt_subtitles(script: &VideoLessonScript) -> String {
    let mut srt = String::new();
    let mut current_sec = 0.0_f32;
    for scene in &script.scenes {
        let start = current_sec;
        let end = current_sec + scene.duration_secs;
        srt.push_str(&format!(
            "{}\n{} --> {}\n{}\n\n",
            scene.scene_index,
            format_srt_time(start),
            format_srt_time(end),
            scene.narration_text
        ));
        current_sec = end;
    }
    srt
}

fn format_srt_time(total_secs: f32) -> String {
    let secs = total_secs as u32;
    let millis = ((total_secs - (secs as f32)) * 1000.0) as u32;
    let h = secs / 3600;
    let m = (secs % 3600) / 60;
    let s = secs % 60;
    format!("{:02}:{:02}:{:02},{:03}", h, m, s, millis)
}
