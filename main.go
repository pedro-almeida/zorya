// PT: Entrypoint Executável do Daemon Svitanok Grid Node (Tailscale Mesh)
// EN: Executable Entrypoint of Svitanok Grid Node Daemon (Tailscale Mesh)
// AI: @llm-context Módulo Svitanok / Daemon Go / CLI de Inicialização do Nó
// AI: @llm-invariant Suporta flags de linha de comando para chaveamento entre Master, Worker e Standalone

package main

import (
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"
)

func main() {
	roleFlag := flag.String("role", "STANDALONE", "Papel do nó na malha Tailscale: MASTER, WORKER ou STANDALONE")
	nameFlag := flag.String("name", "", "Nome amigável do nó (ex: 'PC Desktop Casa - RTX')")
	portFlag := flag.Int("port", 9876, "Porta de escuta do nó (padrão: 9876)")
	masterFlag := flag.String("master", "", "Endereço IP e porta do nó Master no Tailscale (ex: '100.106.120.35:9876')")
	ipFlag := flag.String("tailscale-ip", "", "IP forçado na malha Tailscale (deixe em branco para auto-detecção)")
	flag.Parse()

	cfg := NodeConfig{
		NodeName:    *nameFlag,
		Role:        Role(*roleFlag),
		Port:        *portFlag,
		MasterAddr:  *masterFlag,
		TailscaleIP: *ipFlag,
	}

	node := NewGridNode(cfg)
	if err := node.Start(); err != nil {
		fmt.Printf("[ERRO] Falha ao iniciar nó do Grid: %v\n", err)
		os.Exit(1)
	}

	fmt.Printf("[Svitanok Grid Node Ativo] Papel: %s | Porta: %d | Tailscale: %s\n",
		node.cfg.Role, node.cfg.Port, node.cfg.TailscaleIP)
	fmt.Println("Pressione Ctrl+C para encerrar o nó.")

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, os.Interrupt, syscall.SIGTERM)
	<-sigChan

	node.Stop()
}
