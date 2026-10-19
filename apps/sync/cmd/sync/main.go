// Command sync runs a sync worker: it leases mail accounts from the API and keeps them in sync.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"tresso/sync/internal/api"
	"tresso/sync/internal/config"
	"tresso/sync/internal/lease"
)

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))

	if err := run(logger); err != nil {
		logger.Error("sync worker stopped", "error", err)
		os.Exit(1)
	}
}

func run(logger *slog.Logger) error {
	cfg, err := config.Load()
	if err != nil {
		return fmt.Errorf("configuration: %w", err)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	client := api.New(ctx, cfg.APIURL, cfg.TokenURL, cfg.ClientID, cfg.ClientSecret)
	manager := lease.NewManager(client, syncerFor, cfg.WorkerID, cfg.Capacity, cfg.HeartbeatInterval, logger.With("worker", cfg.WorkerID))

	server := &http.Server{Addr: cfg.HealthAddr, Handler: healthHandler(manager), ReadHeaderTimeout: 5 * time.Second}
	go func() {
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Error("health server failed", "error", err)
			stop()
		}
	}()

	logger.Info("sync worker started", "worker", cfg.WorkerID, "capacity", cfg.Capacity)
	manager.Run(ctx)

	shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	return server.Shutdown(shutdownCtx)
}

// syncerFor returns the provider implementation. IMAP, Gmail and Microsoft Graph syncers are built in phase 1;
// until then accounts are leased but not synced, and the manager logs why.
func syncerFor(provider string) (lease.Syncer, error) {
	return nil, fmt.Errorf("%w: %s", lease.ErrUnsupportedProvider, provider)
}

func healthHandler(manager *lease.Manager) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) {
		accounts, lastBeat, lastErr := manager.Status()
		status := http.StatusOK
		body := map[string]any{"accounts": accounts, "lastHeartbeat": lastBeat}
		if lastErr != nil {
			body["lastError"] = lastErr.Error()
		}
		if lastBeat.IsZero() {
			status = http.StatusServiceUnavailable
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(status)
		_ = json.NewEncoder(w).Encode(body)
	})
	return mux
}
