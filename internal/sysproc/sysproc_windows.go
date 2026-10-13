//go:build windows

package sysproc

import (
	"os/exec"
	"syscall"
)

// PrepareCmd configures cmd to execute completely silently without flashing a console window.
func PrepareCmd(cmd *exec.Cmd) {
	if cmd == nil {
		return
	}
	cmd.SysProcAttr = &syscall.SysProcAttr{
		HideWindow:    true,
		CreationFlags: 0x08000000, // CREATE_NO_WINDOW
	}
}
