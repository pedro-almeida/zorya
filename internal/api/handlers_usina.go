// PT: Handlers de Integração da Usina Central Zorya (Templo Jedi, Svitlo e Anki Media)
// EN: Zorya Central AI Powerhouse Handlers (Templo Jedi, Svitlo Study Engine and Anki Media)
// AI: @llm-context Módulo Zorya v1.0.0 / Endpoints de IA, Orquestração e Geração Pedagógica

package api

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"time"

	"github.com/pedro-almeida/zorya/internal/sysproc"
)

// ResolveTemploJediScriptPath localiza o script templo_jedi.py
func ResolveTemploJediScriptPath() string {
	candidates := []string{
		"scripts/templo_jedi.py",
		"C:\\dev\\zorya\\scripts\\templo_jedi.py",
	}

	exePath, err := os.Executable()
	if err == nil {
		exeDir := filepath.Dir(exePath)
		candidates = append([]string{
			filepath.Join(exeDir, "scripts", "templo_jedi.py"),
			filepath.Join(exeDir, "..", "scripts", "templo_jedi.py"),
		}, candidates...)
	}

	for _, cand := range candidates {
		if _, err := os.Stat(cand); err == nil {
			return cand
		}
	}

	return "scripts/templo_jedi.py"
}

// ExecuteTemploJedi executa o script Python scripts/templo_jedi.py sem abrir janela de terminal (CREATE_NO_WINDOW)
func ExecuteTemploJedi(args []string, stdinBytes []byte, timeout time.Duration) ([]byte, error) {
	if timeout <= 0 {
		timeout = 120 * time.Second
	}

	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()

	scriptPath := ResolveTemploJediScriptPath()

	pyCmd := "python"
	cmdArgs := append([]string{scriptPath}, args...)
	cmd := exec.CommandContext(ctx, pyCmd, cmdArgs...)
	sysproc.PrepareCmd(cmd)

	if len(stdinBytes) > 0 {
		cmd.Stdin = bytes.NewReader(stdinBytes)
	}

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		errOutput := stderr.String()
		if errOutput == "" {
			errOutput = stdout.String()
		}
		return nil, fmt.Errorf("falha ao executar templo_jedi.py: %v (detalhe: %s)", err, errOutput)
	}

	return stdout.Bytes(), nil
}

// GetJediStatus executa scripts/templo_jedi.py --status e retorna o inventário de chaves e modelos ativos.
func GetJediStatus() ([]byte, error) {
	return ExecuteTemploJedi([]string{"--status"}, nil, 15*time.Second)
}

// DispatchJediCode executa o ciclo dos 3 Poderes (Coder, Kant, Gatekeeper) via scripts/templo_jedi.py --dispatch
func DispatchJediCode(payload []byte) ([]byte, error) {
	return ExecuteTemploJedi([]string{"--dispatch", "-"}, payload, 90*time.Second)
}

// GenerateSvitloStudy gera o pacote pedagógico Svitlo via scripts/templo_jedi.py --svitlo-generate
func GenerateSvitloStudy(payload []byte) ([]byte, error) {
	return ExecuteTemploJedi([]string{"--svitlo-generate", "-"}, payload, 90*time.Second)
}

// GenerateAnkiMedia gera diagramas SVG ou ilustrações mnemônicas para o Anki via scripts/templo_jedi.py --anki-media
func GenerateAnkiMedia(payload []byte) ([]byte, error) {
	return ExecuteTemploJedi([]string{"--anki-media", "-"}, payload, 45*time.Second)
}
