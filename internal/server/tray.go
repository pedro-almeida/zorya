// PT: Interface e Gerenciador da Bandeja do Sistema (System Tray)
// EN: Interface and Manager for System Tray
// AI: @llm-context Módulo Zorya v1.0.0 / System Tray e Ciclo de Vida Visual

package server

// TrayManager defines cross-platform contract for System Tray interactions.
type TrayManager interface {
	Init(port int, onReload func(), onExit func()) error
	SetActive(active bool)
	Close()
}

// NewTray instantiates the platform-specific tray manager.
func NewTray() TrayManager {
	return newPlatformTray()
}
