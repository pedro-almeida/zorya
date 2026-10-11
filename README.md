# Zorya (Зоря)

High-performance edge daemon for hardware-accelerated RAW image processing (GPU/CUDA/Vulkan) and distributed photography pipelines.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Go Report Card](https://goreportcard.com/badge/github.com/pedro-almeida/zorya)](https://goreportcard.com/report/github.com/pedro-almeida/zorya)
[![CI Build](https://github.com/pedro-almeida/zorya/actions/workflows/build.yml/badge.svg)](https://github.com/pedro-almeida/zorya/actions/workflows/build.yml)

---

## 🌟 Overview

**Zorya (Зоря)** is an autonomous, lightweight edge computing daemon written in Go. It turns workstations, dedicated servers, and edge devices equipped with NVIDIA CUDA, Vulkan-capable GPUs, or NPUs into high-throughput RAW photography processing nodes.

Operating over encrypted peer-to-peer overlay networks (such as **Tailscale**) or local networks, Zorya can run in three operational modes:
- **`STANDALONE`**: Default self-contained node that serves local HTTP API requests and processes RAW files directly on local accelerators.
- **`MASTER`**: Central coordinator that accepts photoshoot batches, fragments them into atomic tasks, monitors worker thermals, and orchestrates task dispatching.
- **`WORKER`**: Dedicated edge compute node that pulls processing tasks from a master, decodes and grades RAW files via hardware acceleration, and reports back telemetry and developed previews.

---

## 🚀 Features

- **Multi-Backend Acceleration**: Native hardware capability inspection supporting NVIDIA CUDA, Vulkan, Apple Silicon, and multi-core CPU fallbacks.
- **Zero-Config Mesh Networking**: Automatic detection of Tailscale CGNAT IPs (`100.64.0.0/10`) for secure peer-to-peer mesh clustering without port forwarding.
- **Thermal Guardian**: Real-time telemetry monitoring (GPU/CPU temperature and battery health) with automatic throttling and task requeueing to prevent hardware overheating.
- **Production REST API**: Clean endpoints for health checks, system inspection, RAW image decoding, and cluster orchestration.
- **Bearer Token Security**: Configurable Bearer authorization token protection.

---

## 📡 HTTP Endpoints

| Method | Endpoint | Description | Auth Required |
|---|---|---|---|
| `GET` | `/health` | Liveness check, uptime, and active accelerator status | No |
| `GET` | `/api/v1/system/info` | Detailed hardware telemetry (GPU, CUDA, Vulkan, CPU cores, thermal state) | Optional / Bearer |
| `POST` | `/api/v1/raw/decode` | Decodes a RAW image (DNG, CR3, ARW, NEF) from URL or Base64 into WebP/JPEG | Optional / Bearer |
| `GET` | `/api/grid/status` | Cluster topology, active workers, queue status, and throughput (PPM) | No |
| `POST` | `/api/grid/batch/submit` | Enqueues a batch of RAW photos for cluster distribution | No |
| `GET` | `/api/grid/task/pull` | Worker endpoint to pull the next pending task | No |
| `POST` | `/api/grid/task/complete` | Worker endpoint to submit processed results | No |

### RAW Decode Request Example

`POST /api/v1/raw/decode`
```json
{
  "raw_id": "shot-0042",
  "filename": "DSC04892.ARW",
  "raw_url": "https://storage.example.com/photos/DSC04892.ARW",
  "result_format": "webp",
  "preset_params": {
    "exposure": 0.35,
    "highlights": -20,
    "shadows": +15,
    "temperature": 5600
  }
}
```

Response:
```json
{
  "raw_id": "shot-0042",
  "filename": "DSC04892.ARW",
  "status": "success",
  "result_format": "webp",
  "result_data": "data:image/webp;base64,...",
  "width": 1920,
  "height": 1080,
  "elapsed_ms": 78,
  "accelerator": "NVIDIA GeForce RTX (CUDA Ativo)"
}
```

---

## 🛠️ Quick Start

### 1. Installation & Build

Ensure you have **Go 1.21+** installed:
```bash
git clone https://github.com/pedro-almeida/zorya.git
cd zorya

# Build local executable
go build -o bin/zorya.exe ./cmd/zorya
```

### 2. Configuration

Copy the example configuration:
```bash
cp config.example.yaml config.yaml
```

Adjust port, role, and auth token as required:
```yaml
server:
  host: "0.0.0.0"
  port: 9876
  auth_token: "your-secure-token"

node:
  role: "STANDALONE" # STANDALONE, MASTER, or WORKER
```

### 3. Execution Guides

#### Running on Localhost (Standalone)
```bash
./bin/zorya.exe
```
Access `http://127.0.0.1:9876/health` or `http://127.0.0.1:9876/api/v1/system/info`.

#### Running in a Tailscale Mesh Network

1. **Start Master Node on your main workstation or server:**
   ```bash
   ./bin/zorya.exe -role MASTER -port 9876 -name "Studio-Master-PC"
   ```
   Zorya automatically detects its Tailscale IPv4 address (e.g. `100.106.120.35`).

2. **Start Worker Node(s) on edge machines (e.g., secondary GPU rig, laptop):**
   ```bash
   ./bin/zorya.exe -role WORKER -master "100.106.120.35:9876" -name "Worker-Rig-RTX4090"
   ```
   Workers immediately register with the master, transmit 5-second heartbeats, and pull tasks automatically.

---

## 📄 License & Authorship

Distributed under the **MIT License**. See [LICENSE](LICENSE) for more information.

**Author**: Pedro Luís Tenório Rocha de Almeida  
**Repository**: [github.com/pedro-almeida/zorya](https://github.com/pedro-almeida/zorya)
