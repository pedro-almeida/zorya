//go:build !windows

package sysproc

import "os/exec"

// PrepareCmd is a no-op on non-Windows platforms.
func PrepareCmd(cmd *exec.Cmd) {}
