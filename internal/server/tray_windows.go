//go:build windows

// PT: Gerenciador do Ícone de Bandeja do Windows (System Tray Nativo Win32)
// EN: Windows System Tray Manager (Native Win32, Zero-CGO)
// AI: @llm-context Módulo Zorya v1.0.0 / System Tray Win32 Dinâmico

package server

import (
	"bytes"
	"fmt"
	"image"
	"image/color"
	"image/png"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"syscall"
	"unsafe"

	"github.com/pedro-almeida/zorya/assets"
)

var (
	user32   = syscall.NewLazyDLL("user32.dll")
	shell32  = syscall.NewLazyDLL("shell32.dll")
	kernel32 = syscall.NewLazyDLL("kernel32.dll")

	procRegisterClassExW         = user32.NewProc("RegisterClassExW")
	procCreateWindowExW          = user32.NewProc("CreateWindowExW")
	procDefWindowProcW           = user32.NewProc("DefWindowProcW")
	procDestroyWindow            = user32.NewProc("DestroyWindow")
	procPostQuitMessage          = user32.NewProc("PostQuitMessage")
	procGetMessageW              = user32.NewProc("GetMessageW")
	procTranslateMessage         = user32.NewProc("TranslateMessage")
	procDispatchMessageW         = user32.NewProc("DispatchMessageW")
	procCreatePopupMenu          = user32.NewProc("CreatePopupMenu")
	procAppendMenuW              = user32.NewProc("AppendMenuW")
	procTrackPopupMenu           = user32.NewProc("TrackPopupMenu")
	procDestroyMenu              = user32.NewProc("DestroyMenu")
	procGetCursorPos             = user32.NewProc("GetCursorPos")
	procSetForegroundWindow      = user32.NewProc("SetForegroundWindow")
	procCreateIconFromResourceEx = user32.NewProc("CreateIconFromResourceEx")
	procDestroyIcon              = user32.NewProc("DestroyIcon")
	procPostMessageW             = user32.NewProc("PostMessageW")

	procShellNotifyIconW = shell32.NewProc("Shell_NotifyIconW")
	procGetModuleHandleW = kernel32.NewProc("GetModuleHandleW")
)

const (
	wmUser        = 0x0400
	wmTrayIcon    = wmUser + 1
	wmCommand     = 0x0111
	wmDestroy     = 0x0002
	wmLButtonUp   = 0x0202
	wmLButtonDbl  = 0x0203
	wmRButtonUp   = 0x0205

	nimAdd    = 0x00000000
	nimModify = 0x00000001
	nimDelete = 0x00000002

	nifMessage = 0x00000001
	nifIcon    = 0x00000002
	nifTip     = 0x00000004

	mfString    = 0x00000000
	mfSeparator = 0x00000800
	tpmRightBtn = 0x0002

	idmOpenWeb = 1001
	idmReload  = 1002
	idmExit    = 1003
)

type point struct {
	x int32
	y int32
}

type msg struct {
	hwnd    uintptr
	message uint32
	wParam  uintptr
	lParam  uintptr
	time    uint32
	pt      point
}

type wndClassExW struct {
	cbSize        uint32
	style         uint32
	lpfnWndProc   uintptr
	cbClsExtra    int32
	cbWndExtra    int32
	hInstance     uintptr
	hIcon         uintptr
	hCursor       uintptr
	hbrBackground uintptr
	lpszMenuName  *uint16
	lpszClassName *uint16
	hIconSm       uintptr
}

type notifyIconDataW struct {
	cbSize           uint32
	hwnd             uintptr
	uID              uint32
	uFlags           uint32
	uCallbackMessage uint32
	hIcon            uintptr
	szTip            [128]uint16
	dwState          uint32
	dwStateMask      uint32
	szInfo           [256]uint16
	uTimeoutOrVer    uint32
	szInfoTitle      [64]uint16
	dwInfoFlags      uint32
	guidItem         [16]byte
	hBalloonIcon     uintptr
}

type windowsTray struct {
	mu           sync.Mutex
	port         int
	onReload     func()
	onExit       func()
	hwnd         uintptr
	nid          notifyIconDataW
	hIconActive  uintptr
	hIconStandby uintptr
	isActive     bool
	closed       bool
}

var globalTray *windowsTray

func newPlatformTray() TrayManager {
	return &windowsTray{}
}

func (t *windowsTray) Init(port int, onReload func(), onExit func()) error {
	t.mu.Lock()
	t.port = port
	t.onReload = onReload
	t.onExit = onExit
	globalTray = t
	t.mu.Unlock()

	// 1. Gera ícones colorido e P&B em memória a partir dos bytes embutidos
	activeHIcon, standbyHIcon, err := loadTrayIcons()
	if err != nil {
		log.Printf("[Zorya Tray AVISO] Falha ao carregar ícone (%v). Utilizando fallback padrão.", err)
	}
	t.hIconActive = activeHIcon
	t.hIconStandby = standbyHIcon

	hInst, _, _ := procGetModuleHandleW.Call(0)
	className := syscall.StringToUTF16Ptr("ZoryaTrayWindowClass")

	var wc wndClassExW
	wc.cbSize = uint32(unsafe.Sizeof(wc))
	wc.lpfnWndProc = syscall.NewCallback(trayWndProc)
	wc.hInstance = hInst
	wc.lpszClassName = className

	r, _, err := procRegisterClassExW.Call(uintptr(unsafe.Pointer(&wc)))
	if r == 0 {
		return fmt.Errorf("RegisterClassExW falhou: %v", err)
	}

	hwnd, _, err := procCreateWindowExW.Call(
		0,
		uintptr(unsafe.Pointer(className)),
		uintptr(unsafe.Pointer(syscall.StringToUTF16Ptr("ZoryaTrayWindow"))),
		0,
		0, 0, 0, 0,
		0, 0, hInst, 0,
	)
	if hwnd == 0 {
		return fmt.Errorf("CreateWindowExW falhou: %v", err)
	}

	t.hwnd = hwnd

	// Configura o NotifyIconData (começa em Standby: P&B)
	initialIcon := t.hIconStandby
	if initialIcon == 0 {
		initialIcon = t.hIconActive
	}

	t.nid.cbSize = uint32(unsafe.Sizeof(t.nid))
	t.nid.hwnd = hwnd
	t.nid.uID = 1
	t.nid.uFlags = nifMessage | nifIcon | nifTip
	t.nid.uCallbackMessage = wmTrayIcon
	t.nid.hIcon = initialIcon
	copyStringToUTF16(t.nid.szTip[:], "Zorya RAW Engine — Standby (Ocioso)")

	log.Printf("[Zorya Tray] CreateWindowExW hwnd=%v, nid.hwnd=%v", hwnd, t.nid.hwnd)

	procShellNotifyIconW.Call(nimAdd, uintptr(unsafe.Pointer(&t.nid)))
	return nil
}

// Run executa a bomba de mensagens (message pump) Win32 na thread principal
func (t *windowsTray) Run() error {
	var m msg
	for {
		res, _, err := procGetMessageW.Call(uintptr(unsafe.Pointer(&m)), 0, 0, 0)
		if int32(res) == 0 || int32(res) == -1 {
			log.Printf("[Zorya Win32] GetMessageW terminou: res=%v err=%v msg=%x", res, err, m.message)
			break
		}
		procTranslateMessage.Call(uintptr(unsafe.Pointer(&m)))
		procDispatchMessageW.Call(uintptr(unsafe.Pointer(&m)))
	}
	return nil
}

func trayWndProc(hwnd uintptr, message uint32, wParam, lParam uintptr) uintptr {
	t := globalTray
	if t == nil {
		r, _, _ := procDefWindowProcW.Call(hwnd, uintptr(message), wParam, lParam)
		return r
	}

	switch message {
	case wmTrayIcon:
		switch lParam {
		case wmLButtonUp, wmLButtonDbl:
			t.openWebUI()
		case wmRButtonUp:
			t.showContextMenu(hwnd)
		}
		return 0

	case wmCommand:
		cmdID := int(wParam & 0xFFFF)
		switch cmdID {
		case idmOpenWeb:
			t.openWebUI()
		case idmReload:
			if t.onReload != nil {
				go t.onReload()
			}
		case idmExit:
			t.Close()
			if t.onExit != nil {
				go t.onExit()
			} else {
				os.Exit(0)
			}
		}
		return 0

	case wmDestroy:
		procPostQuitMessage.Call(0)
		return 0
	}

	r, _, _ := procDefWindowProcW.Call(hwnd, uintptr(message), wParam, lParam)
	return r
}

func (t *windowsTray) showContextMenu(hwnd uintptr) {
	var pt point
	procGetCursorPos.Call(uintptr(unsafe.Pointer(&pt)))

	hMenu, _, _ := procCreatePopupMenu.Call()
	if hMenu == 0 {
		return
	}
	defer procDestroyMenu.Call(hMenu)

	procAppendMenuW.Call(hMenu, mfString, idmOpenWeb, uintptr(unsafe.Pointer(syscall.StringToUTF16Ptr("⚡ Abrir Painel Web (Svitlo UI)"))))
	procAppendMenuW.Call(hMenu, mfString, idmReload, uintptr(unsafe.Pointer(syscall.StringToUTF16Ptr("🔄 Reiniciar Zorya"))))
	procAppendMenuW.Call(hMenu, mfSeparator, 0, 0)
	procAppendMenuW.Call(hMenu, mfString, idmExit, uintptr(unsafe.Pointer(syscall.StringToUTF16Ptr("❌ Sair do Zorya"))))

	procSetForegroundWindow.Call(hwnd)
	procTrackPopupMenu.Call(hMenu, tpmRightBtn, uintptr(pt.x), uintptr(pt.y), 0, hwnd, 0)
	procPostMessageW.Call(hwnd, 0, 0, 0)
}

func (t *windowsTray) openWebUI() {
	url := fmt.Sprintf("http://localhost:%d", t.port)
	cmd := exec.Command("rundll32", "url.dll,FileProtocolHandler", url)
	cmd.SysProcAttr = &syscall.SysProcAttr{
		HideWindow:    true,
		CreationFlags: 0x08000000, // CREATE_NO_WINDOW
	}
	_ = cmd.Start()
}

func (t *windowsTray) SetActive(active bool) {
	t.mu.Lock()
	defer t.mu.Unlock()

	if t.closed || t.hwnd == 0 {
		return
	}

	t.isActive = active
	if active && t.hIconActive != 0 {
		t.nid.hIcon = t.hIconActive
		copyStringToUTF16(t.nid.szTip[:], "Zorya RAW Engine — Processando Lote (Ativo)")
	} else if t.hIconStandby != 0 {
		t.nid.hIcon = t.hIconStandby
		copyStringToUTF16(t.nid.szTip[:], "Zorya RAW Engine — Standby (Ocioso)")
	}

	procShellNotifyIconW.Call(nimModify, uintptr(unsafe.Pointer(&t.nid)))
}

func (t *windowsTray) Close() {
	t.mu.Lock()
	defer t.mu.Unlock()

	if t.closed {
		return
	}
	t.closed = true

	if t.hwnd != 0 {
		procShellNotifyIconW.Call(nimDelete, uintptr(unsafe.Pointer(&t.nid)))
		procPostMessageW.Call(t.hwnd, wmDestroy, 0, 0)
	}

	if t.hIconActive != 0 {
		procDestroyIcon.Call(t.hIconActive)
	}
	if t.hIconStandby != 0 {
		procDestroyIcon.Call(t.hIconStandby)
	}
}

func copyStringToUTF16(dst []uint16, src string) {
	utf16Chars, _ := syscall.UTF16FromString(src)
	copy(dst, utf16Chars)
}

func loadTrayIcons() (uintptr, uintptr, error) {
	// Utiliza prioritariamente os bytes do ícone embutidos via go:embed
	rawBytes := defaultIconBytes
	if len(rawBytes) == 0 && len(assets.DefaultIconBytes) > 0 {
		rawBytes = assets.DefaultIconBytes
	}

	// Fallback em disco caso os bytes não estejam disponíveis em memória
	if len(rawBytes) == 0 {
		possiblePaths := []string{
			"assets/icon.png",
			filepath.Join("assets", "icon.png"),
			"C:\\dev\\zorya\\assets\\icon.png",
			"../assets/icon.png",
			"../../assets/icon.png",
		}

		var readErr error
		for _, p := range possiblePaths {
			if b, err := os.ReadFile(p); err == nil && len(b) > 0 {
				rawBytes = b
				readErr = nil
				break
			} else {
				readErr = err
			}
		}
		if readErr != nil || len(rawBytes) == 0 {
			return 0, 0, readErr
		}
	}

	img, err := png.Decode(bytes.NewReader(rawBytes))
	if err != nil {
		return 0, 0, err
	}

	// 1. Redimensiona para 64x64 preservando canal alfa
	scaledColor := scaleImage(img, 64, 64)

	// 2. Cria versão em Tons de Cinza / Preto & Branco para o Standby
	scaledGrayscale := makeGrayscaleImage(scaledColor)

	// 3. Encodifica para PNG em memória
	var activeBuf bytes.Buffer
	var standbyBuf bytes.Buffer
	if err := png.Encode(&activeBuf, scaledColor); err != nil {
		return 0, 0, err
	}
	if err := png.Encode(&standbyBuf, scaledGrayscale); err != nil {
		return 0, 0, err
	}

	activeBytes := activeBuf.Bytes()
	standbyBytes := standbyBuf.Bytes()

	// 4. Cria HICONs do Windows via CreateIconFromResourceEx
	hActive, _, _ := procCreateIconFromResourceEx.Call(
		uintptr(unsafe.Pointer(&activeBytes[0])),
		uintptr(len(activeBytes)),
		1,          // fIcon = TRUE
		0x00030000, // dwVersion
		32,         // cxDesired
		32,         // cyDesired
		0,          // flags
	)

	hStandby, _, _ := procCreateIconFromResourceEx.Call(
		uintptr(unsafe.Pointer(&standbyBytes[0])),
		uintptr(len(standbyBytes)),
		1,
		0x00030000,
		32,
		32,
		0,
	)

	return hActive, hStandby, nil
}

func scaleImage(src image.Image, targetW, targetH int) image.Image {
	bounds := src.Bounds()
	w := bounds.Dx()
	h := bounds.Dy()

	dst := image.NewRGBA(image.Rect(0, 0, targetW, targetH))
	scaleX := float64(w) / float64(targetW)
	scaleY := float64(h) / float64(targetH)

	for y := 0; y < targetH; y++ {
		for x := 0; x < targetW; x++ {
			srcX := int(float64(x) * scaleX)
			srcY := int(float64(y) * scaleY)
			dst.Set(x, y, src.At(srcX, srcY))
		}
	}
	return dst
}

func makeGrayscaleImage(src image.Image) image.Image {
	bounds := src.Bounds()
	dst := image.NewRGBA(bounds)

	for y := bounds.Min.Y; y < bounds.Max.Y; y++ {
		for x := bounds.Min.X; x < bounds.Max.X; x++ {
			r, g, b, a := src.At(x, y).RGBA()
			if a == 0 {
				dst.Set(x, y, color.Transparent)
				continue
			}
			// Fórmula de luminância clássica ITU-R BT.601
			lum := uint8((0.299*float64(r) + 0.587*float64(g) + 0.114*float64(b)) / 256.0)
			alpha := uint8(a / 256)
			dst.Set(x, y, color.RGBA{R: lum, G: lum, B: lum, A: alpha})
		}
	}
	return dst
}
