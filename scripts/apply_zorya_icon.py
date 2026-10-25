#!/usr/bin/env python3
# PT: Script para gerar assets oficiais de ícones e social preview a partir da imagem gerada do Zorya.
# EN: Script to generate official icon assets and social preview from the generated Zorya artwork.
# AI: @llm-script apply_zorya_icon

import os
import sys
from PIL import Image, ImageDraw, ImageFont

SRC_PATH = r"E:\OneDrive\Downloads\Gemini_Generated_Image_x9mw9bx9mw9bx9mw.jpeg"
ASSETS_DIR = r"assets"
GITHUB_DIR = r".github"

def main():
    if not os.path.exists(SRC_PATH):
        print(f"ERRO: Imagem de origem nao encontrada em {SRC_PATH}")
        sys.exit(1)

    os.makedirs(ASSETS_DIR, exist_ok=True)
    os.makedirs(GITHUB_DIR, exist_ok=True)

    print(f"[+] Carregando imagem de: {SRC_PATH}")
    img = Image.open(SRC_PATH).convert("RGBA")

    # 1. assets/icon.png (512x512, RGBA)
    icon_512 = img.resize((512, 512), Image.Resampling.LANCZOS)
    icon_png_path = os.path.join(ASSETS_DIR, "icon.png")
    icon_512.save(icon_png_path, format="PNG")
    print(f"[OK] Gerado {icon_png_path} (512x512 RGBA)")

    # 2. .github/repo-icon.png (512x512)
    repo_icon_path = os.path.join(GITHUB_DIR, "repo-icon.png")
    icon_512.save(repo_icon_path, format="PNG")
    print(f"[OK] Gerado {repo_icon_path} (512x512 RGBA)")

    # 3. assets/icon.ico estritamente com tamanhos [(256, 256), (128, 128), (64, 64), (48, 48), (32, 32), (16, 16)]
    # NUNCA 512x512 dentro do ICO para nao quebrar winres / rc.exe
    ico_sizes = [(256, 256), (128, 128), (64, 64), (48, 48), (32, 32), (16, 16)]
    icon_ico_path = os.path.join(ASSETS_DIR, "icon.ico")
    icon_512.save(icon_ico_path, format="ICO", sizes=ico_sizes)
    print(f"[OK] Gerado {icon_ico_path} com frames {ico_sizes}")

    # 4. .github/social-preview.png (1280x640, fundo #050811, ícone centralizado e título)
    bg_color = (5, 8, 17, 255) # #050811
    social_w, social_h = 1280, 640
    social_img = Image.new("RGBA", (social_w, social_h), bg_color)

    # Redimensiona ícone para ~300x300 e centraliza horizontalmente ligeiramente acima do meio
    icon_size = 300
    icon_preview = img.resize((icon_size, icon_size), Image.Resampling.LANCZOS)
    icon_x = (social_w - icon_size) // 2
    icon_y = 100
    social_img.paste(icon_preview, (icon_x, icon_y), icon_preview)

    draw = ImageDraw.Draw(social_img)
    title_text = "ZORYA v3.0"
    subtitle_text = "Sovereign AI Inference & Artcraft Engine"

    # Tentativa de carregar fonte TTF do sistema Windows ou fallback
    font_title = None
    font_sub = None
    for font_name in ["segoeuib.ttf", "arialbd.ttf", "segoeui.ttf", "arial.ttf"]:
        try:
            font_title = ImageFont.truetype(font_name, 42)
            font_sub = ImageFont.truetype(font_name.replace("bd", ""), 24)
            break
        except Exception:
            pass

    if font_title is None:
        font_title = ImageFont.load_default()
        font_sub = ImageFont.load_default()

    # Desenha Título
    gold_color = (235, 185, 90, 255) # tom ouro do ícone
    sub_color = (180, 190, 210, 255)  # tom suave prateado

    # Bounding box do título para centralização
    bbox_title = draw.textbbox((0, 0), title_text, font=font_title)
    w_title = bbox_title[2] - bbox_title[0]
    draw.text(((social_w - w_title) // 2, 440), title_text, fill=gold_color, font=font_title)

    # Bounding box do subtítulo
    bbox_sub = draw.textbbox((0, 0), subtitle_text, font=font_sub)
    w_sub = bbox_sub[2] - bbox_sub[0]
    draw.text(((social_w - w_sub) // 2, 510), subtitle_text, fill=sub_color, font=font_sub)

    social_preview_path = os.path.join(GITHUB_DIR, "social-preview.png")
    social_img.save(social_preview_path, format="PNG")
    print(f"[OK] Gerado {social_preview_path} ({social_w}x{social_h})")

if __name__ == "__main__":
    main()
