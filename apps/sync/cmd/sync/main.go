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
	"tresso/sync/internal/caldavsync"
	"tresso/sync/internal/config"
	"tresso/sync/internal/gmailsync"
	"tresso/sync/internal/graphsync"
	"tresso/sync/internal/imapsync"
	"tresso/sync/internal/lease"
	"tresso/sync/internal/netguard"
	"tresso/sync/internal/outbox"
	"tresso/sync/internal/realtime"
	"tresso/sync/internal/storage"
	"tresso/sync/internal/unsubscribe"
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
	workerLogger := logger.With("worker", cfg.WorkerID)
	imapSyncer := imapsync.New(client, store, dialer, cfg.WorkerID, imapsync.DefaultOptions(), workerLogger)
	gmailSyncer := gmailsync.New(client, store, cfg.WorkerID, gmailsync.DefaultOptions(), workerLogger)
	graphSyncer := graphsync.New(client, store, cfg.WorkerID, graphsync.DefaultOptions(), workerLogger)
	calendarOptions := caldavsync.DefaultOptions()
	calendarOptions.AllowHTTP = cfg.AllowUnsafeServers
	calendarSyncer := caldavsync.New(client, dialer, cfg.WorkerID, calendarOptions, workerLogger)
	syncerFor := func(provider string) (lease.Syncer, error) {
		switch provider {
		case "imap":
			return lease.WithCompanion(imapSyncer, calendarSyncer), nil
		case "gmail":
			return gmailSyncer, nil
		case "microsoft":
			return graphSyncer, nil
		default:
			return nil, fmt.Errorf("%w: %s", lease.ErrUnsupportedProvider, provider)
		}
	}

	manager := lease.NewManager(client, syncerFor, cfg.WorkerID, cfg.Capacity, cfg.HeartbeatInterval, workerLogger)

	// Outgoing mail, drafts, changes to messages, calendar changes and unsubscribe requests: the hub says which account
	// has work right away; every heartbeat repeats it.
	transports := map[string]outbox.Transport{
		"imap":      outbox.IMAPTransport(dialer),
		"gmail":     outbox.GmailTransport(client, cfg.WorkerID, gmailsync.DefaultClientOptions()),
		"microsoft": outbox.GraphTransport(client, cfg.WorkerID, graphOutboxOptions(workerLogger)),
	}
	// Unsubscribe addresses come from whoever sent a message, so their dialer never allows private addresses, whatever
	// MAIL_ALLOW_UNSAFE_SERVERS says for the user's own mail servers.
	unsubscriber := unsubscribe.New(netguard.New(false), unsubscribe.DefaultOptions())
	dispatcher := outbox.NewDispatcher(client, store, transports, unsubscriber, cfg.WorkerID, workerLogger)
	manager.OnOutbox(func(ctx context.Context, accountIDs []string) {
		for _, id := range accountIDs {
			dispatcher.Trigger(ctx, id)
			calendarSyncer.Trigger(id)
		}
	})
	subscriber := realtime.NewSubscriber(client, &http.Client{}, func(accountID string) {
		if manager.Holds(accountID) {
			dispatcher.Trigger(ctx, accountID)
			calendarSyncer.Trigger(accountID)
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

// graphOutboxOptions are the Graph client options of the outbox, logging through the worker's logger.
func graphOutboxOptions(logger *slog.Logger) graphsync.ClientOptions {
	opts := graphsync.DefaultClientOptions()
	opts.Logger = logger
	return opts
}
