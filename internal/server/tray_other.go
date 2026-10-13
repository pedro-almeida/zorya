//go:build !windows

package server

type noopTray struct{}

func newPlatformTray() TrayManager {
	return &noopTray{}
}

func (t *noopTray) Init(port int, onReload func(), onExit func()) error {
	return nil
}

func (t *noopTray) SetActive(active bool) {}
func (t *noopTray) Close()                {}
