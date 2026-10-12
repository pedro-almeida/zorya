//go:build windows

package hardware

import (
	"syscall"
	"unsafe"
)

type memoryStatusEx struct {
	cbSize                  uint32
	dwMemoryLoad            uint32
	ullTotalPhys            uint64
	ullAvailPhys            uint64
	ullTotalPageFile        uint64
	ullAvailPageFile        uint64
	ullTotalVirtual         uint64
	ullAvailVirtual         uint64
	ullAvailExtendedVirtual uint64
}

func detectPlatformRAM() (float64, float64) {
	kernel32 := syscall.NewLazyDLL("kernel32.dll")
	procGlobalMemoryStatusEx := kernel32.NewProc("GlobalMemoryStatusEx")
	var mem memoryStatusEx
	mem.cbSize = uint32(unsafe.Sizeof(mem))
	r1, _, _ := procGlobalMemoryStatusEx.Call(uintptr(unsafe.Pointer(&mem)))
	if r1 != 0 {
		total := float64(mem.ullTotalPhys) / (1024 * 1024 * 1024)
		avail := float64(mem.ullAvailPhys) / (1024 * 1024 * 1024)
		return round1(total), round1(avail)
	}
	return 16.0, 8.0
}
