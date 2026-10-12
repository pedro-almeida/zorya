# Zorya (Зоря) — Coprocessador de Aceleração RAW v1.0.0

High-performance edge daemon for hardware-accelerated RAW image processing (GPU/CUDA/Vulkan) and distributed photography pipelines.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Go Report Card](https://goreportcard.com/badge/github.com/pedro-almeida/zorya)](https://goreportcard.com/report/github.com/pedro-almeida/zorya)
[![CI Build](https://github.com/pedro-almeida/zorya/actions/workflows/build.yml/badge.svg)](https://github.com/pedro-almeida/zorya/actions/workflows/build.yml)

---

## 🌟 Visão Geral (Overview)

**Zorya (Зоря)** é um daemon leve, autônomo e de alta performance escrito em Go. Ele transforma computadores pessoais, servidores dedicados e nós de borda equipados com GPUs NVIDIA (CUDA / Vulkan) ou CPUs multithread em usinas de processamento RAW fotográfico de alta vazão.

Operando como coprocessador local (via `localhost`) ou através de malhas de rede criptografadas ponto a ponto (**Tailscale**), o Zorya integra-se perfeitamente com o ecossistema criativo **Svitanok** e outros sistemas fotográficos.

Acesse o painel web embarcado no seu navegador: **`http://localhost:9876`**.

---

## 🎨 Painel Web Embarcado Svitlo

O Zorya v1.0.0 traz uma interface web completa e responsiva embutida diretamente no executável binário (`//go:embed`), desenhada com a estética calorosa e minimalista da paleta **Svitlo** (`#F7F4EF`, `#2C1F14`, `#8B5E3C`, `#5C3D1E`):

1. **Alocação de Recursos de Hardware**:
   - Slider de núcleos de CPU alocados (padrão: 50% dos núcleos lógicos do processador).
   - Chave de ativação de GPU (CUDA / Vulkan) com slider de teto máximo de VRAM (ex: 4 GB).
   - Limite de memória RAM física para retenção de buffers em lote.
2. **Auxílio de IA em Nuvem (Zero VRAM Local)**:
   - Campos seguros com botão de revelação para chave de API do **Google Gemini** (camada gratuita para metadados e legendagem) e **Groq**.
3. **Conectividade & Malha Tailscale**:
   - Detecção automática de IP Tailscale na faixa `100.64.0.0/10`.
   - Seletor de interface de escuta: *Apenas Localhost (127.0.0.1)* ou *Localhost + Malha Tailscale (0.0.0.0)*.
   - Token de autorização Bearer com botão de cópia com um clique para colar no Svitanok.
4. **Sistema Operacional**:
   - Opção para iniciar automaticamente com o Windows em segundo plano (`HKCU\Software\Microsoft\Windows\CurrentVersion\Run` com `--background`).
5. **Sobre & Filosofia**:
   - Narrativa da deusa eslava Zorya (guardiã da aurora que abre as portas para o *Svitanok*) combinada ao Imperativo Categórico de Immanuel Kant.
   - Licença MIT na íntegra.

---

## 📡 Endpoints REST da API v1

| Método | Rota | Descrição | Formato |
|---|---|---|---|
| `GET` | `/` | Interface Web Svitlo do nó | HTML5 / CSS / JS embutidos |
| `GET` | `/health` | Checagem de integridade, versão v1.0.0 e tempo de atividade (uptime) | JSON |
| `GET` | `/api/v1/system/info` | Telemetria detalhada de CPU real, RAM física, GPU e IP Tailscale | JSON |
| `POST` | `/api/v1/raw/decode` | Decodificação RAW de alta vazão via GPU/CPU | `multipart/form-data` ou JSON &rarr; Stream binário WebP/JPEG |
| `POST` | `/api/v1/config/save` | Atualização dinâmica de sliders, chaves e registro no `config.yaml` | JSON |
| `GET` | `/api/grid/status` | Topologia da malha P2P, nós ativos e taxa de transferência | JSON |
| `POST` | `/api/grid/batch/submit`| Enfileira lote fotográfico distribuído para a malha | JSON |

### Exemplo de Decodificação via cURL

```bash
# Decodificação direta de arquivo RAW via multipart/form-data retornando WebP binário
curl -X POST http://localhost:9876/api/v1/raw/decode \
  -H "Authorization: Bearer zorya-master-token-9876" \
  -F "file=@foto_camera.ARW" \
  -F "format=webp" \
  -F "quality=90" \
  --output preview.webp
```

---

## ⚙️ Execução e Linha de Comando

```bash
# Execução padrão interativa (abre painel em http://localhost:9876)
zorya.exe

# Execução silenciosa em segundo plano (autostart do Windows)
zorya.exe --background

# Execução com arquivo de configuração customizado
zorya.exe --config meu_servidor.yaml --port 9876
```

---

## 📜 Licença e Autoria

- **Autor**: Pedro Luís Tenório Rocha de Almeida
- **Organização**: [Espectro Ipê Soluções Tecnológicas](https://espectroipe.com.br)
- **Licença**: [MIT License](LICENSE)
