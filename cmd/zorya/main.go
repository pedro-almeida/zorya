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
	"path/filepath"
	"runtime"
	"syscall"
	"time"

	"github.com/pedro-almeida/zorya/internal/config"
	"github.com/pedro-almeida/zorya/internal/server"
)

const version = "1.0.0"

func main() {
	exePath, _ := os.Executable()
	exeDir := filepath.Dir(exePath)
	debugLog, errLog := os.OpenFile(filepath.Join(exeDir, "zorya_debug.log"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0666)
	if errLog == nil {
		defer debugLog.Close()
		log.SetOutput(debugLog)
	}

	log.Println("[Zorya Start] Iniciando main()...")

	defer func() {
		if r := recover(); r != nil {
			log.Printf("[Zorya PANIC] %v\n", r)
		}
	}()

	runtime.LockOSThread()

	defaultCfgPath := filepath.Join(exeDir, "config.yaml")
	if _, err := os.Stat(defaultCfgPath); os.IsNotExist(err) {
		if _, errCwd := os.Stat("config.yaml"); errCwd == nil {
			defaultCfgPath = "config.yaml"
		}
	}

	configFlag := flag.String("config", defaultCfgPath, "Caminho do arquivo de configuração YAML")
	backgroundFlag := flag.Bool("background", false, "Executa silenciosamente em segundo plano (modo serviço/autostart)")
	portFlag := flag.Int("port", 0, "Porta de escuta do daemon (padrão: 9876)")
	listenFlag := flag.String("listen", "", "Modo de escuta de rede: 'localhost' ou 'tailscale_all'")
	roleFlag := flag.String("role", "", "Papel do nó: MASTER, WORKER ou STANDALONE")
	nameFlag := flag.String("name", "", "Nome amigável do nó")
	tokenFlag := flag.String("token", "", "Token de autenticação Bearer")
	flag.Parse()

	log.Printf("[Zorya Config] Carregando config de: %s\n", *configFlag)
	cfg, err := config.Load(*configFlag)
	if err != nil {
		log.Printf("[Zorya AVISO] Arquivo de configuração não encontrado (%v). Inicializando padrões.", err)
		cfg = config.NewDefault()
		_ = config.Save(*configFlag, cfg)
	}

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

	if cfg.OS.AutostartWindows {
		_ = config.ApplyWindowsAutostart(true)
	}

	if !*backgroundFlag {
		printBanner(cfg)
	}

	log.Println("[Zorya Server] Criando daemon...")
	daemon := server.New(cfg, *configFlag)
	log.Println("[Zorya Server] Iniciando daemon.Start()...")
	if err := daemon.Start(); err != nil {
		log.Printf("[Zorya FATAL] Falha ao iniciar servidor HTTP: %v\n", err)
		return
	}
	log.Printf("[Zorya Server] daemon.Start() OK na porta %d!\n", cfg.Server.Port)

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, os.Interrupt, syscall.SIGTERM)
	go func() {
		sig := <-sigChan
		log.Printf("[Zorya Signal] OS Signal recebido: %v. Encerrando!\n", sig)
		daemon.Stop()
		os.Exit(0)
	}()

	log.Println("[Zorya Tray] Inicializando Tray...")
	tray := server.NewTray()
	daemon.SetTray(tray)

	onReload := func() {
		log.Println("[Zorya Tray] onReload")
		_ = daemon.Restart()
	}

	onExit := func() {
		log.Println("[Zorya Tray] onExit disparado pelo menu")
		daemon.Stop()
		os.Exit(0)
	}

	if err := tray.Init(cfg.Server.Port, onReload, onExit); err != nil {
		log.Printf("[Zorya Tray ERRO] tray.Init falhou: %v\n", err)
	} else {
		log.Println("[Zorya Tray] tray.Init OK, iniciando tray.Run()...")
		_ = tray.Run()
		log.Println("[Zorya Tray] tray.Run() terminou.")
	}

	log.Println("[Zorya Main] Entrando em loop infinito select {}...")
	for {
		time.Sleep(10 * time.Second)
	}
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
