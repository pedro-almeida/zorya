package server

import (
	_ "embed"
	"os/exec"
	"syscall"

	"github.com/getlantern/systray"
	"github.com/pedro-almeida/zorya/assets"
)

//go:embed assets/icon.png
var defaultIconBytes []byte

func init() {
	if len(defaultIconBytes) == 0 && len(assets.DefaultIconBytes) > 0 {
		defaultIconBytes = assets.DefaultIconBytes
	}
}

// TrayManager defines cross-platform contract for System Tray interactions.
type TrayManager interface {
	Init(port int, onReload func(), onExit func()) error
	Run() error
	SetActive(active bool)
	Close()
}

func RunTray(onOpenWeb func(), onRestart func(), onExit func()) {
	systray.Run(func() {
		systray.SetIcon(defaultIconBytes)
		systray.SetTitle("Zorya RAW Engine")
		systray.SetTooltip("Zorya RAW Engine — Standby (Ocioso)")

		mOpen := systray.AddMenuItem("⚡ Abrir Painel Web", "Abre o Svitlo no navegador")
		mRestart := systray.AddMenuItem("🔄 Reiniciar Zorya", "Reinicia os serviços locais")
		systray.AddSeparator()
		mQuit := systray.AddMenuItem("❌ Sair do Zorya", "Encerra a usina")

		go func() {
			for {
				select {
				case <-mOpen.ClickedCh:
					cmd := exec.Command("cmd", "/c", "start", "http://localhost:9876")
					cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000}
					_ = cmd.Start()
				case <-mRestart.ClickedCh:
					if onRestart != nil {
						onRestart()
					}
				case <-mQuit.ClickedCh:
					systray.Quit()
					if onExit != nil {
						onExit()
					}
					return
				}
			}
		}()
	}, func() {})
}
