// Package config loads the sync worker configuration from the environment.
package config

import (
	"errors"
	"fmt"
	"os"
	"strconv"
	"time"
)

type Config struct {
	APIURL            string
	TokenURL          string
	ClientID          string
	ClientSecret      string
	WorkerID          string
	Capacity          int
	HeartbeatInterval time.Duration
	HealthAddr        string
}

func Load() (Config, error) {
	cfg := Config{
		APIURL:            os.Getenv("API_URL"),
		TokenURL:          os.Getenv("OAUTH_TOKEN_URL"),
		ClientID:          os.Getenv("OAUTH_CLIENT_ID"),
		ClientSecret:      os.Getenv("OAUTH_CLIENT_SECRET"),
		WorkerID:          os.Getenv("WORKER_ID"),
		Capacity:          100,
		HeartbeatInterval: 30 * time.Second,
		HealthAddr:        envOr("HEALTH_ADDR", ":8081"),
	}

	if cfg.WorkerID == "" {
		host, err := os.Hostname()
		if err != nil {
			return cfg, fmt.Errorf("WORKER_ID is empty and hostname is unavailable: %w", err)
		}
		cfg.WorkerID = host
	}

	if v := os.Getenv("WORKER_CAPACITY"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 || n > 1000 {
			return cfg, fmt.Errorf("WORKER_CAPACITY must be an integer between 1 and 1000, got %q", v)
		}
		cfg.Capacity = n
	}

	if v := os.Getenv("HEARTBEAT_INTERVAL"); v != "" {
		d, err := time.ParseDuration(v)
		if err != nil || d <= 0 {
			return cfg, fmt.Errorf("HEARTBEAT_INTERVAL must be a positive duration, got %q", v)
		}
		cfg.HeartbeatInterval = d
	}

	var missing []error
	for name, value := range map[string]string{
		"API_URL":             cfg.APIURL,
		"OAUTH_TOKEN_URL":     cfg.TokenURL,
		"OAUTH_CLIENT_ID":     cfg.ClientID,
		"OAUTH_CLIENT_SECRET": cfg.ClientSecret,
	} {
		if value == "" {
			missing = append(missing, fmt.Errorf("%s is required", name))
		}
	}

	return cfg, errors.Join(missing...)
}

func envOr(name, fallback string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return fallback
}
