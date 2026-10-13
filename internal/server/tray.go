// PT: Interface e Gerenciador da Bandeja do Sistema (System Tray)
// EN: Interface and Manager for System Tray
// AI: @llm-context Módulo Zorya v1.0.0 / System Tray e Ciclo de Vida Visual

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

// NewTray instantiates the platform-specific tray manager.
func NewTray() TrayManager {
	return newPlatformTray()
}
