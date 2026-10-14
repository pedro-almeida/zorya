package api

import (
	"encoding/json"
	"os"
	"testing"
)

func TestResolveTemploJediScriptPath(t *testing.T) {
	path := ResolveTemploJediScriptPath()
	if path == "" {
		t.Fatalf("expected non-empty script path")
	}
	if _, err := os.Stat(path); err != nil {
		t.Logf("script path resolved as %s (stat err: %v)", path, err)
	}
}

func TestGetJediStatus(t *testing.T) {
	out, err := GetJediStatus()
	if err != nil {
		t.Fatalf("GetJediStatus failed: %v", err)
	}
	var data map[string]interface{}
	if err := json.Unmarshal(out, &data); err != nil {
		t.Fatalf("failed to parse json from GetJediStatus: %v", err)
	}
	if data["status"] != "online" {
		t.Fatalf("expected status online, got: %v", data["status"])
	}
}
