// PT: Script de build para embutir metadados, ZORYA_GIT_SHA e o ícone oficial do Zorya no executável Windows.
// EN: Build script to embed metadata, ZORYA_GIT_SHA, and official Zorya icon into Windows executable.
// AI: @llm-build winres-metadata

fn main() {
    println!("cargo:rerun-if-changed=.git/HEAD");

    let git_sha = std::process::Command::new("git")
        .args(["rev-parse", "--short", "HEAD"])
        .output()
        .ok()
        .and_then(|out| {
            if out.status.success() {
                String::from_utf8(out.stdout)
                    .ok()
                    .map(|s| s.trim().to_string())
            } else {
                None
            }
        })
        .filter(|s| !s.is_empty())
        .unwrap_or_else(|| env!("CARGO_PKG_VERSION").to_string());

    println!("cargo:rustc-env=ZORYA_GIT_SHA={}", git_sha);

    #[cfg(target_os = "windows")]
    {
        let mut res = winres::WindowsResource::new();
        res.set_icon("assets/icon.ico");
        res.set("ProductName", "Zorya");
        res.set(
            "FileDescription",
            "Zorya Sovereign Rust AI & Multimedia Coprocessor Daemon",
        );
        res.set("CompanyName", "Pedro Almeida");
        res.set("LegalCopyright", "Copyright (c) 2026 Pedro Almeida");
        if let Err(e) = res.compile() {
            eprintln!("Aviso: Falha ao compilar recursos winres: {}", e);
        }
    }
}
