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
	"runtime/debug"
	"syscall"
	"time"

	"github.com/pedro-almeida/zorya/internal/config"
	"github.com/pedro-almeida/zorya/internal/server"
)

const version = "1.0.0"

type safeLogWriter struct {
	file *os.File
}

func (s *safeLogWriter) Write(p []byte) (n int, err error) {
	_, _ = os.Stderr.Write(p)
	if s.file != nil {
		return s.file.Write(p)
	}
	return len(p), nil
}

func main() {
	exePath, _ := os.Executable()
	exeDir := filepath.Dir(exePath)
	logPath := filepath.Join(exeDir, "zorya.log")

	// Recuperação de panics com gravação silenciosa em zorya.log para evitar crash-loop
	defer func() {
		if r := recover(); r != nil {
			f, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666)
			if err == nil {
				defer f.Close()
				fmt.Fprintf(f, "[%s] [Zorya Panic Recovered] %v\nStack trace:\n%s\n",
					time.Now().Format("2006-01-02 15:04:05"), r, debug.Stack())
			}
		}
	}()

	// Redireciona logs para zorya.log de forma segura e silenciosa
	if logFile, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666); err == nil {
		defer logFile.Close()
		log.SetOutput(&safeLogWriter{file: logFile})
	}

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

	log.Printf("[Zorya Main] Inicializando Daemon Zorya v%s...", version)
	daemon := server.New(cfg, *configFlag)

	// Inicialização da Bandeja do Sistema (System Tray com estados dinâmicos)
	tray := server.NewTray()
	daemon.SetTray(tray)

	// Iniciar o servidor HTTP em uma goroutine separada
	go func() {
		if err := daemon.Start(); err != nil {
			log.Printf("[Zorya Erro] %v", err)
		}
	}()

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, os.Interrupt, syscall.SIGTERM)

	onReload := func() {
		log.Println("[Zorya Tray] Recarregando configurações e reiniciando componentes...")
		if newCfg, err := config.Load(*configFlag); err == nil {
			log.Printf("[Zorya Tray] Configuração recarregada com sucesso (Porta=%d, Modo=%s)", newCfg.Server.Port, newCfg.Server.ListenMode)
		}
	}

	onExit := func() {
		log.Println("[Zorya Tray] onExit disparado pelo menu do Tray.")
		tray.Close()
	}

	go func() {
		s := <-sigChan
		log.Printf("[Zorya Main] Sinal OS recebido: %v. Encerrando Tray...", s)
		tray.Close()
	}()

	// Trava a goroutine atual na thread principal do SO para o message pump Win32
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()

	if err := tray.Init(cfg.Server.Port, onReload, onExit); err != nil {
		log.Printf("[Zorya AVISO] Não foi possível inicializar bandeja do sistema: %v", err)
		<-sigChan
	} else {
		log.Println("[Zorya Main] Entrando no loop de mensagens Win32 do Tray...")
		_ = tray.Run()
		log.Println("[Zorya Main] Loop de mensagens Win32 finalizado.")
	}

	if !*backgroundFlag {
		fmt.Println("\n[Zorya] Encerrando daemon...")
	}
	log.Println("[Zorya Main] Chamando daemon.Stop()...")
	daemon.Stop()
	log.Println("[Zorya Main] Daemon finalizado com sucesso.")
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
