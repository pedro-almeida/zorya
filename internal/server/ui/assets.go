// PT: Sistema de Arquivos Embutido da Interface Web Svitlo
// EN: Embedded Filesystem for Svitlo Web UI Assets
// AI: @llm-context Módulo Zorya v1.0.0 / Web Assets Embedding

package ui

import "embed"

// DistFS contains all web assets bundled into the final executable.
//
//go:embed index.html style.css app.js
var DistFS embed.FS
