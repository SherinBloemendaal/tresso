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

	S3Endpoint  string
	S3Bucket    string
	S3AccessKey string
	S3SecretKey string
	S3Region    string

	// AllowUnsafeServers permits mail servers on private addresses; only for local test servers.
	AllowUnsafeServers bool
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
		S3Endpoint:        os.Getenv("S3_ENDPOINT"),
		S3Bucket:          os.Getenv("S3_BUCKET"),
		S3AccessKey:       os.Getenv("S3_ACCESS_KEY"),
		S3SecretKey:       os.Getenv("S3_SECRET_KEY"),
		S3Region:          envOr("S3_REGION", "us-east-1"),
	}

	if v := os.Getenv("MAIL_ALLOW_UNSAFE_SERVERS"); v != "" {
		allow, err := strconv.ParseBool(v)
		if err != nil {
			return cfg, fmt.Errorf("MAIL_ALLOW_UNSAFE_SERVERS must be a boolean, got %q", v)
		}
		cfg.AllowUnsafeServers = allow
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
		"S3_ENDPOINT":         cfg.S3Endpoint,
		"S3_BUCKET":           cfg.S3Bucket,
		"S3_ACCESS_KEY":       cfg.S3AccessKey,
		"S3_SECRET_KEY":       cfg.S3SecretKey,
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
