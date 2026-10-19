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
	"tresso/sync/internal/imapsync"
	"tresso/sync/internal/lease"
	"tresso/sync/internal/netguard"
	"tresso/sync/internal/outbox"
	"tresso/sync/internal/realtime"
	"tresso/sync/internal/smtpsend"
	"tresso/sync/internal/storage"
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

	store, err := storage.NewS3(cfg.S3Endpoint, cfg.S3AccessKey, cfg.S3SecretKey, cfg.S3Bucket, cfg.S3Region)
	if err != nil {
		return fmt.Errorf("object storage: %w", err)
	}
	if err := store.EnsureBucket(ctx); err != nil {
		return fmt.Errorf("object storage: %w", err)
	}
	if cfg.AllowUnsafeServers {
		logger.Warn("MAIL_ALLOW_UNSAFE_SERVERS is on: private mail server addresses are allowed (local testing only)")
	}

	dialer := netguard.New(cfg.AllowUnsafeServers)
	imapSyncer := imapsync.New(client, store, dialer, cfg.WorkerID, imapsync.DefaultOptions(), logger.With("worker", cfg.WorkerID))
	syncerFor := func(provider string) (lease.Syncer, error) {
		switch provider {
		case "imap":
			return imapSyncer, nil
		default:
			// Gmail and Microsoft Graph follow in phase 1.6.
			return nil, fmt.Errorf("%w: %s", lease.ErrUnsupportedProvider, provider)
		}
	}

	manager := lease.NewManager(client, syncerFor, cfg.WorkerID, cfg.Capacity, cfg.HeartbeatInterval, logger.With("worker", cfg.WorkerID))

	// Outgoing mail and drafts: the hub says which account has work right away; every heartbeat repeats it.
	send := func(ctx context.Context, server api.ServerSettings, username, password string, envelope smtpsend.Envelope, raw []byte) error {
		return smtpsend.Send(ctx, dialer, server, username, password, envelope, raw)
	}
	openSent := func(ctx context.Context, credentials api.Credentials) (outbox.SentStore, error) {
		return imapsync.OpenSent(ctx, dialer, credentials)
	}
	openDrafts := func(ctx context.Context, credentials api.Credentials) (outbox.DraftsStore, error) {
		return imapsync.OpenDrafts(ctx, dialer, credentials)
	}
	dispatcher := outbox.NewDispatcher(client, store, send, openSent, openDrafts, cfg.WorkerID, logger.With("worker", cfg.WorkerID))
	manager.OnOutbox(func(ctx context.Context, accountIDs []string) {
		for _, id := range accountIDs {
			dispatcher.Trigger(ctx, id)
		}
	})
	subscriber := realtime.NewSubscriber(client, &http.Client{}, func(accountID string) {
		if manager.Holds(accountID) {
			dispatcher.Trigger(ctx, accountID)
		}
	}, logger.With("worker", cfg.WorkerID))
	go subscriber.Run(ctx)

	server := &http.Server{Addr: cfg.HealthAddr, Handler: healthHandler(manager), ReadHeaderTimeout: 5 * time.Second}
	go func() {
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Error("health server failed", "error", err)
			stop()
		}
	}()

	logger.Info("sync worker started", "worker", cfg.WorkerID, "capacity", cfg.Capacity)
	manager.Run(ctx)
	dispatcher.Wait()

	shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	return server.Shutdown(shutdownCtx)
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
