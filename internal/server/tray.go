package server

import (
	_ "embed"

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

// NewTray returns the platform-specific native system tray instance.
func NewTray() TrayManager {
	return newPlatformTray()
}

// RunTray initializes and runs the platform native tray without crashing in headless/service sessions.
func RunTray(onOpenWeb func(), onRestart func(), onExit func()) {
	tray := newPlatformTray()
	if err := tray.Init(9876, onRestart, onExit); err == nil {
		_ = tray.Run()
	}
	select {}
}
