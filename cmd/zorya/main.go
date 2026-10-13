package main

import (
	"log"
	"os"
	"runtime"

	"github.com/pedro-almeida/zorya/internal/config"
	"github.com/pedro-almeida/zorya/internal/server"
)

func main() {
	runtime.LockOSThread()
	cfg := config.NewDefault()
	daemon := server.New(cfg, "config.yaml")

	go func() {
		if err := daemon.Start(); err != nil {
			log.Printf("[Zorya HTTP Erro] %v\n", err)
		}
	}()

	server.RunTray(
		func() {},
		func() { _ = daemon.Restart() },
		func() { daemon.Stop(); os.Exit(0) },
	)
}
