// PT: Script de build para embutir metadados e o ícone oficial do Zorya no executável Windows.
// EN: Build script to embed metadata and the official Zorya icon into the Windows executable.
// AI: @llm-build winres-metadata

fn main() {
    #[cfg(target_os = "windows")]
    {
        let mut res = winres::WindowsResource::new();
        res.set_icon("assets/icon.ico");
        res.set("ProductName", "Zorya");
        res.set("FileDescription", "Zorya Sovereign Rust AI & Multimedia Coprocessor Daemon");
        res.set("CompanyName", "Pedro Almeida");
        res.set("LegalCopyright", "Copyright (c) 2026 Pedro Almeida");
        if let Err(e) = res.compile() {
            eprintln!("Aviso: Falha ao compilar recursos winres: {}", e);
        }
    }
}
