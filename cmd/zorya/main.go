// PT: Ponto de Entrada Executável do Daemon Zorya v1.0.0
// EN: Executable Entrypoint of Zorya Daemon v1.0.0
// AI: @llm-context Módulo Zorya v1.0.0 / CLI e Ciclo de Vida da Aplicação

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

const version = "1.0.0"

func main() {
	configFlag := flag.String("config", "config.yaml", "Caminho do arquivo de configuração YAML")
	backgroundFlag := flag.Bool("background", false, "Executa silenciosamente em segundo plano (modo serviço/autostart)")
	portFlag := flag.Int("port", 0, "Porta de escuta do daemon (padrão: 9876)")
	listenFlag := flag.String("listen", "", "Modo de escuta de rede: 'localhost' ou 'tailscale_all'")
	roleFlag := flag.String("role", "", "Papel do nó: MASTER, WORKER ou STANDALONE")
	nameFlag := flag.String("name", "", "Nome amigável do nó")
	tokenFlag := flag.String("token", "", "Token de autenticação Bearer")
	flag.Parse()

	// Load or initialize configuration
	cfg, err := config.Load(*configFlag)
	if err != nil {
		log.Printf("[Zorya AVISO] Arquivo de configuração não encontrado (%v). Inicializando padrões.", err)
		cfg = config.NewDefault()
		_ = config.Save(*configFlag, cfg)
	}

	// Apply CLI overrides if specified
	if *portFlag > 0 {
		cfg.Server.Port = *portFlag
	}
	if *listenFlag != "" {
		cfg.Server.ListenMode = config.ListenMode(*listenFlag)
	}
	if *roleFlag != "" {
		cfg.Node.Role = config.Role(*roleFlag)
	}
	if *nameFlag != "" {
		cfg.Node.Name = *nameFlag
	}
	if *tokenFlag != "" {
		cfg.Server.AuthToken = *tokenFlag
	}
	if *backgroundFlag {
		cfg.OS.BackgroundMode = true
	}

	// Apply Windows autostart if configured
	if cfg.OS.AutostartWindows {
		_ = config.ApplyWindowsAutostart(true)
	}

	// Print banner if running interactively
	if !*backgroundFlag {
		printBanner(cfg)
	}

	daemon := server.New(cfg, *configFlag)
	if err := daemon.Start(); err != nil {
		log.Fatalf("[Zorya FATAL] Falha ao iniciar servidor HTTP: %v\n", err)
	}

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, os.Interrupt, syscall.SIGTERM)
	<-sigChan

	if !*backgroundFlag {
		fmt.Println("\n[Zorya] Encerrando daemon...")
	}
	daemon.Stop()
}

func printBanner(cfg *config.Config) {
	fmt.Println("=============================================================================")
	fmt.Printf("   ZORYA (Зоря) RAW COMPUTE ENGINE — Versão %s\n", version)
	fmt.Println("   Coprocessador Autônomo e Malha P2P de Aceleração Tailscale")
	fmt.Println("=============================================================================")
	fmt.Printf("   Interface Web Svitlo : http://localhost:%d\n", cfg.Server.Port)
	if cfg.Node.TailscaleIP != "" {
		fmt.Printf("   URL Malha Tailscale  : http://%s:%d\n", cfg.Node.TailscaleIP, cfg.Server.Port)
	}
	fmt.Printf("   Modo de Escuta       : %s\n", cfg.Server.ListenMode)
	fmt.Printf("   Alocação de CPU      : %d núcleos lógicos\n", cfg.Hardware.CPUCores)
	fmt.Printf("   Aceleração por GPU   : %t (VRAM Teto: %.1f GB)\n", cfg.Hardware.GPUEnabled, cfg.Hardware.VRAMLimitGB)
	fmt.Printf("   Autenticação Bearer  : %s\n", cfg.Server.AuthToken)
	fmt.Println("=============================================================================")
	fmt.Println("   Pressione Ctrl+C para encerrar o serviço.")
	fmt.Println()
}
