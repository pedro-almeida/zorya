"""
Zorya Usina Computacional Daemon
Escuta na interface 0.0.0.0:8085 (acessível via Tailscale 100.x.y.z e localhost 127.0.0.1).
Responde a checagens de saúde da VPS Mriya e gerencia a inferência local de IA compatível com OpenAI.
"""

import sys
import json
import socket
import logging
import ipaddress
import urllib.request
import urllib.error
from datetime import datetime, timezone
from http.server import HTTPServer, BaseHTTPRequestHandler
import psutil

# Configurações do Nó
HOST = "0.0.0.0"
PORT = 8085
NODE_NAME = "zorya-usina"
DEVICE_NAME = "notebook-pedro"
ENGINE_NAME = "llama-server"
LLAMA_SERVER_URL = "http://127.0.0.1:8080"
ALLOWED_CORS_ORIGIN = "https://svitlo.espectroipe.com.br"

# Faixa Tailscale segura: 100.64.0.0/10 (100.64.0.0 a 100.127.255.255)
TAILSCALE_NETWORK = ipaddress.ip_network("100.64.0.0/10")

logging.basicConfig(
    level=logging.INFO,
    format="[%(asctime)s] [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S"
)
logger = logging.getLogger("zorya-node")


def is_ip_allowed(client_ip_str: str) -> bool:
    """
    Permite apenas requisições vindas de:
    - Rede local (localhost): 127.0.0.1, ::1
    - Faixa segura do Tailscale: 100.64.0.0/10
    """
    try:
        ip = ipaddress.ip_address(client_ip_str)
        # Se for IPv6 mapeado para IPv4 (ex: ::ffff:127.0.0.1)
        if ip.version == 6 and ip.ipv4_mapped:
            ip = ip.ipv4_mapped

        if ip.is_loopback:
            return True
        if ip.version == 4 and ip in TAILSCALE_NETWORK:
            return True
        return False
    except Exception as e:
        logger.warning(f"Erro ao verificar IP do cliente {client_ip_str}: {e}")
        return False


def get_system_metrics() -> dict:
    """
    Coleta métricas do sistema: CPU, Memória RAM e Temperatura (se disponível).
    """
    cpu_percent = psutil.cpu_percent(interval=0.1)
    cpu_count_logical = psutil.cpu_count(logical=True)
    cpu_count_physical = psutil.cpu_count(logical=False)
    
    mem = psutil.virtual_memory()
    disk = psutil.disk_usage("/")

    metrics = {
        "cpu": {
            "usage_percent": cpu_percent,
            "cores_logical": cpu_count_logical,
            "cores_physical": cpu_count_physical,
        },
        "memory": {
            "total_gb": round(mem.total / (1024 ** 3), 2),
            "available_gb": round(mem.available / (1024 ** 3), 2),
            "used_gb": round(mem.used / (1024 ** 3), 2),
            "usage_percent": mem.percent,
        },
        "disk": {
            "total_gb": round(disk.total / (1024 ** 3), 2),
            "free_gb": round(disk.free / (1024 ** 3), 2),
            "usage_percent": disk.percent,
        },
        "temperatures": {}
    }

    # psutil.sensors_temperatures() pode não existir em Windows sem sensores WMI
    if hasattr(psutil, "sensors_temperatures"):
        try:
            temps = psutil.sensors_temperatures()
            if temps:
                temp_dict = {}
                for name, entries in temps.items():
                    temp_dict[name] = [
                        {"label": e.label or name, "current": e.current, "high": e.high, "critical": e.critical}
                        for e in entries
                    ]
                metrics["temperatures"] = temp_dict
        except Exception as e:
            logger.debug(f"Não foi possível ler sensores de temperatura: {e}")

    return metrics


class ZoryaHandler(BaseHTTPRequestHandler):
    def send_cors_headers(self):
        self.send_header("Access-Control-Allow-Origin", ALLOWED_CORS_ORIGIN)
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With")
        self.send_header("Access-Control-Allow-Credentials", "true")

    def do_OPTIONS(self):
        client_ip = self.client_address[0]
        if not is_ip_allowed(client_ip):
            self.send_response(403)
            self.end_headers()
            self.wfile.write(b'{"error": "Forbidden - IP not allowed"}')
            return

        self.send_response(204)
        self.send_cors_headers()
        self.end_headers()

    def do_GET(self):
        client_ip = self.client_address[0]
        if not is_ip_allowed(client_ip):
            logger.warning(f"Acesso negado para o IP: {client_ip}")
            self.send_response(403)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(json.dumps({"error": "Forbidden - IP not allowed"}).encode("utf-8"))
            return

        path = self.path.split("?")[0]

        if path == "/health":
            self.send_response(200)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_cors_headers()
            self.end_headers()

            payload = {
                "status": "ok",
                "node": NODE_NAME,
                "device": DEVICE_NAME,
                "timestamp": datetime.now(timezone.utc).isoformat(),
                "engine": ENGINE_NAME
            }
            self.wfile.write(json.dumps(payload, indent=2).encode("utf-8"))
            return

        elif path == "/status":
            self.send_response(200)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_cors_headers()
            self.end_headers()

            metrics = get_system_metrics()
            payload = {
                "status": "online",
                "node": NODE_NAME,
                "device": DEVICE_NAME,
                "timestamp": datetime.now(timezone.utc).isoformat(),
                "metrics": metrics
            }
            self.wfile.write(json.dumps(payload, indent=2).encode("utf-8"))
            return

        else:
            self.send_response(404)
            self.send_header("Content-Type", "application/json")
            self.send_cors_headers()
            self.end_headers()
            self.wfile.write(json.dumps({"error": "Not Found"}).encode("utf-8"))

    def do_POST(self):
        client_ip = self.client_address[0]
        if not is_ip_allowed(client_ip):
            logger.warning(f"Acesso negado para o IP: {client_ip}")
            self.send_response(403)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(json.dumps({"error": "Forbidden - IP not allowed"}).encode("utf-8"))
            return

        path = self.path.split("?")[0]

        if path == "/v1/chat/completions":
            content_length = int(self.headers.get("Content-Length", 0))
            post_data = self.rfile.read(content_length)

            target_url = f"{LLAMA_SERVER_URL}/v1/chat/completions"
            req = urllib.request.Request(
                target_url,
                data=post_data,
                headers={"Content-Type": "application/json"},
                method="POST"
            )

            try:
                with urllib.request.urlopen(req, timeout=120) as upstream_resp:
                    status_code = upstream_resp.status
                    self.send_response(status_code)

                    # Propaga headers relevantes do llama-server
                    for h_name, h_val in upstream_resp.getheaders():
                        if h_name.lower() in ["content-type", "transfer-encoding"]:
                            self.send_header(h_name, h_val)
                    self.send_cors_headers()
                    self.end_headers()

                    # Suporte a streaming / chunked response
                    while True:
                        chunk = upstream_resp.read(4096)
                        if not chunk:
                            break
                        self.wfile.write(chunk)
                        self.wfile.flush()

            except urllib.error.HTTPError as he:
                logger.error(f"Erro HTTP do llama-server ({he.code}): {he.reason}")
                error_body = he.read()
                self.send_response(he.code)
                self.send_header("Content-Type", "application/json")
                self.send_cors_headers()
                self.end_headers()
                self.wfile.write(error_body)

            except urllib.error.URLError as ue:
                logger.error(f"Falha ao conectar com o llama-server em {LLAMA_SERVER_URL}: {ue.reason}")
                self.send_response(503)
                self.send_header("Content-Type", "application/json")
                self.send_cors_headers()
                self.end_headers()
                err_resp = {
                    "error": {
                        "message": f"Engine llama-server offline em {LLAMA_SERVER_URL}: {ue.reason}",
                        "type": "engine_unavailable_error",
                        "node": NODE_NAME
                    }
                }
                self.wfile.write(json.dumps(err_resp).encode("utf-8"))

            except Exception as e:
                logger.error(f"Erro inesperado no proxy /v1/chat/completions: {e}")
                self.send_response(500)
                self.send_header("Content-Type", "application/json")
                self.send_cors_headers()
                self.end_headers()
                self.wfile.write(json.dumps({"error": str(e)}).encode("utf-8"))

        else:
            self.send_response(404)
            self.send_header("Content-Type", "application/json")
            self.send_cors_headers()
            self.end_headers()
            self.wfile.write(json.dumps({"error": "Not Found"}).encode("utf-8"))

    def log_message(self, format, *args):
        logger.info(f"{self.client_address[0]} - {format % args}")


def run():
    server_address = (HOST, PORT)
    httpd = HTTPServer(server_address, ZoryaHandler)
    logger.info(f"⚡ Zorya Usina Node rodando em {HOST}:{PORT}")
    logger.info(f"🔒 Rede permitida: 127.0.0.1 (Local) e 100.64.0.0/10 (Tailscale)")
    logger.info(f"🌐 CORS configurado para: {ALLOWED_CORS_ORIGIN}")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        logger.info("Encerrando Zorya Node...")
        httpd.server_close()


if __name__ == "__main__":
    run()
