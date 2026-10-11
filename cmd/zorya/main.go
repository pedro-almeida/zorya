package main

import (
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"

	"github.com/pedro-almeida/zorya/internal/config"
	"github.com/pedro-almeida/zorya/internal/server"
)

func main() {
	configFlag := flag.String("config", "", "Caminho do arquivo de configuração YAML (ex: config.yaml)")
	roleFlag := flag.String("role", "", "Papel do nó: MASTER, WORKER ou STANDALONE")
	nameFlag := flag.String("name", "", "Nome amigável do nó")
	portFlag := flag.Int("port", 0, "Porta de escuta do daemon (padrão: 9876)")
	masterFlag := flag.String("master", "", "Endereço do Master coordenador (ex: '100.106.120.35:9876')")
	ipFlag := flag.String("tailscale-ip", "", "IP forçado na malha Tailscale")
	tokenFlag := flag.String("token", "", "Token de autenticação Bearer para endpoints da API")
	flag.Parse()

	cfg, err := config.Load(*configFlag)
	if err != nil {
		log.Printf("[Zorya WARN] Não foi possível carregar arquivo de configuração (%v). Usando padrões.", err)
	}

	if *roleFlag != "" {
		cfg.Node.Role = config.Role(*roleFlag)
	}
	if *nameFlag != "" {
		cfg.Node.Name = *nameFlag
	}
	if *portFlag > 0 {
		cfg.Server.Port = *portFlag
	}
	if *masterFlag != "" {
		cfg.Node.MasterAddr = *masterFlag
	}
	if *ipFlag != "" {
		cfg.Node.TailscaleIP = *ipFlag
	}
	if *tokenFlag != "" {
		cfg.Server.AuthToken = *tokenFlag
	}

	daemon := server.New(cfg)
	if err := daemon.Start(); err != nil {
		log.Fatalf("[Zorya ERRO] Falha ao iniciar daemon: %v\n", err)
	}

	fmt.Printf("[Zorya Daemon Ativo] Papel: %s | Porta: %d | Tailscale IP: %s\n",
		cfg.Node.Role, cfg.Server.Port, cfg.Node.TailscaleIP)
	fmt.Println("Pressione Ctrl+C para encerrar o daemon.")

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, os.Interrupt, syscall.SIGTERM)
	<-sigChan

	daemon.Stop()
}
