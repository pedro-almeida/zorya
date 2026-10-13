//go:build !windows

package server

type noopTray struct {
	stop chan struct{}
}

func newPlatformTray() TrayManager {
	return &noopTray{
		stop: make(chan struct{}),
	}
}

func (t *noopTray) Init(port int, onReload func(), onExit func()) error {
	return nil
}

func (t *noopTray) Run() error {
	<-t.stop
	return nil
}

func (t *noopTray) SetActive(active bool) {}

func (t *noopTray) Close() {
	select {
	case <-t.stop:
	default:
		close(t.stop)
	}
}
