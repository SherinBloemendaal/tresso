# Architecture

Product name: **Tresso**.

## 1. Product principle

> An email is something you need to **do**, **hand over** to someone else, or just **know**.

Every message converges on one of three verdicts:

| Verdict | Created artifact | Resolved when |
|---|---|---|
| `do` | Todo linked to the thread (due date extracted when present) | Todo completed, email auto-archives |
| `delegate` | Handed over with a note and due date: forwarded to someone, or assigned to a teammate in Tresso Cloud; appears in "Waiting on" | The other person completes it or replies |
| `know` | Entry in the daily brief, one-click "Noted" | Read or acknowledged, email auto-archives |

Categories (built-in: `spam`, `notification`, `advertise`, `todo`, `informational`; plus user-defined) feed the
verdicts through configurable mappings (e.g. `todo` -> `do`, `informational` -> `know`). The open edition classifies
with rules; Tresso Cloud adds AI classification.

## Open core

Tresso is open core.

| Edition | What it is | License |
|---|---|---|
| **Tresso** (this repository) | A complete, self-hostable email client for one person: accounts, mail, smart inboxes with rules, the three verdicts, todos, signatures, settings, web, desktop and mobile apps | AGPL-3.0; shared libraries in `packages/` are MIT |
| **Tresso Cloud** | The hosted service. Adds AI classification and summaries, teams and delegation, API keys, webhooks and MCP, developer tools, statistics and Year in mail | Proprietary, separate repository |

The open code never depends on Tresso Cloud. Cloud features attach through two generic, git-ignored hooks in the
API:

- `config/bundles.local.php`: extra Symfony bundles the Kernel registers when the file exists.
- `composer.local.json` (used with `COMPOSER=composer.local.json`, own lock file): extra Composer packages.

New features follow the same rule: the open code defines the interface and a simple implementation (for example
rule-based classification); an add-on may provide a richer one. Anyone can build their own add-on the same way.

## 2. System overview

```
 ┌──────────── Clients ─────────────┐
 │ Web (Vue 3)  Desktop (Tauri+Vue) │     Add-ons (optional)
 │ Mobile (Expo / React Native)     │   e.g. Tresso Cloud bundle
 └──────────────┬───────────────────┘              │
                │ OAuth2 (auth code + PKCE)        │ bundles.local.php
                ▼                                  ▼
 ┌─────────────────────────────────────────────────────────────┐
 │ API: Symfony 8 on FrankenPHP (worker mode)                  │
 │  - OAuth2 authorization server (league/oauth2-server)       │
 │  - Domain: accounts, mail metadata, categories, todos,      │
 │    signatures, settings                                     │
 │  - Delta sync endpoint for clients                          │
 │  - Mercure hub (built into FrankenPHP) for realtime         │
 └───────┬──────────────────────┬───────────────────┬──────────┘
         │ Messenger (Redis)    │ Postgres          │ S3 (RustFS locally)
         ▼                      ▼                   ▼
 ┌──────────────────┐   ┌──────────────┐   ┌──────────────────┐
 │ Messenger workers│   │ PostgreSQL   │   │ Raw MIME, bodies,│
 │ classify, scan,  │   │ metadata     │   │ attachments      │
 │ push, index,     │   └──────────────┘   └──────────────────┘
 │ daily brief      │   ┌──────────────┐   ┌──────────────────┐
 └──────────────────┘   │ Meilisearch  │   │ ClamAV (clamd)   │
                        └──────────────┘   └──────────────────┘
         ▲
         │ HTTPS, OAuth2 client_credentials (scope sync:*)
 ┌───────┴─────────────────────────────────────────────────────┐
 │ Sync workers: Go                                            │
 │  - Lease mail accounts from the API, hold IMAP IDLE /       │
 │    Gmail push / Microsoft Graph subscriptions               │
 │  - Upload raw MIME straight to S3, send parsed metadata     │
 │    to the API in batches (ingest endpoint)                  │
 │  - Execute outgoing actions (flags, moves, send via SMTP)   │
 └─────────────────────────────────────────────────────────────┘
```

## 3. Components

### 3.1 API (`apps/api`)

- Symfony 8.1, PHP 8.5, FrankenPHP worker mode, Doctrine ORM, PostgreSQL 18.
- Symfony Messenger with Redis transport for async jobs. Transports are split by priority (`ingest`, `scan`,
  `push`, `low`, and more from add-ons) so slow jobs never block ingest.
- Mercure (bundled in FrankenPHP) pushes realtime updates to web and desktop clients.
- Identifiers: UUIDv7 everywhere (time ordered, index friendly, safe to expose).

### 3.2 Authentication and authorization

Symfony is the single OAuth2 authorization server (`league/oauth2-server-bundle`).

| Client | Grant | Notes |
|---|---|---|
| Web, Desktop, Mobile | Authorization code + PKCE | Public clients, refresh token rotation |
| Go sync workers | Client credentials | Confidential client, scopes `sync:lease sync:ingest sync:credentials` |
Only first-party clients are approved automatically. Third-party apps, personal access tokens and MCP clients are
Tresso Cloud features.

Scopes: `mail:read`, `mail:write`, `mail:send`, `todos:read`, `todos:write`, `settings:read`, `settings:write`,
`signatures:read`, `signatures:write`. The scopes `teams:read`, `teams:write` and `webhooks:manage` are reserved for
add-ons.

Users log in with email + password (Argon2id) and, later, Google / Microsoft sign-in as identity providers.

### 3.3 Mail account credentials

- OAuth (Gmail, Microsoft 365) wherever possible; IMAP/SMTP passwords as fallback.
- Envelope encryption: each user has a data encryption key (DEK). The DEK is encrypted with a master key (KEK)
  held outside the database (env/KMS). Credentials are encrypted with the DEK using libsodium
  (XChaCha20-Poly1305). Rotating the KEK only re-encrypts DEKs.
- Sync workers fetch decrypted credentials over TLS through a dedicated endpoint, scope `sync:credentials`, only
  for accounts they currently lease. Credentials are held in memory only.

### 3.4 Sync workers (`apps/sync`)

Goal: fast synchronisation that scales horizontally to billions of accounts' worth of messages.

- **Leasing:** workers call `POST /internal/sync/leases` to claim a batch of accounts. Leases expire and are
  renewed by heartbeat, so a crashed worker's accounts move to another worker automatically.
- **Change detection:** IMAP `IDLE` + `CONDSTORE`/`QRESYNC` (MODSEQ based deltas), Gmail `history.list` with
  Pub/Sub push, Microsoft Graph delta queries with change notifications.
- **Ingest:** raw MIME goes directly to object storage (content-addressed by SHA-256, so duplicates across
  folders/accounts are stored once). Parsed metadata (headers, snippet, flags, folder, thread keys, attachment
  manifest) is sent to `POST /internal/sync/ingest` in batches.
- **Backfill:** newest first, so the inbox is usable within seconds while history fills in behind.
- **Outbound:** flag/move/delete/send commands are queued per account by the API and executed by the leasing
  worker, then confirmed back.

### 3.5 Client sync protocol

- Every mutable row carries a monotonically increasing `change_seq` per user.
- `GET /api/sync/changes?cursor=…` returns changes since the cursor (messages, threads, todos, categories,
  settings, and add-on data such as assignments). Clients store data in a local SQLite cache (desktop via Rust/Tauri, mobile via
  expo-sqlite, web via IndexedDB) and fetch message bodies lazily.
- Realtime: Mercure event tells the client "new changes up to seq N", client pulls the delta.

### 3.6 Processing pipeline (per new message)

```
ingest -> thread assignment -> virus scan (attachments) -> classification -> verdict actions
       -> search index -> push notification (only do / delegate)
```

Add-ons can hook into each step (Tresso Cloud adds AI classification, assignments and webhooks).

### 3.7 Classification

- A category = name + instructions + include list + exclude list (domains or addresses) + verdict mapping + flags
  (auto-create todo, batch delivery, notify).
- The open edition classifies with deterministic rules: include/exclude lists, `List-Unsubscribe`,
  `Precedence: bulk`, and authentication results (SPF/DKIM/DMARC) for spam. The `instructions` field is used by AI
  classifiers such as the one in Tresso Cloud.
- Classification sits behind an interface, so an add-on can replace or extend it.

### 3.8 Virus scanning

ClamAV daemon (`clamd`) scans every attachment via a Messenger job before it is downloadable. Infected
attachments are quarantined and the message is flagged; results are cached by attachment hash.

### 3.9 Tresso Cloud features

Built as add-ons and documented separately: AI classification and summaries, teams and delegation (assignments,
internal comments, delegation rules), API keys and third-party apps, webhooks, the MCP endpoint, developer tools,
statistics and Year in mail.

### 3.10 Clients

| Client | Stack | Local store | Push |
|---|---|---|---|
| Web (`apps/web`) | Vue 3, Vite, TypeScript, Pinia | IndexedDB | Web Push |
| Desktop (`apps/desktop`) | Tauri 2 (Rust) wrapping `apps/web` | SQLite via Rust | Native notifications |
| Mobile (`apps/mobile`) | Expo (React Native), TypeScript | expo-sqlite | APNs / FCM via Expo |

Shared TypeScript lives in `packages/` (`api-client`, `core` with domain types and verdict logic).

## 4. Scale notes

- Every mail metadata row (folders, threads, messages, attachments) carries `mail_account_id` and every query path
  is scoped by it. That keeps hash partitioning (or Citus distribution) by account possible. Introducing it is a
  planned migration that widens primary keys to `(mail_account_id, id)`; it is done once volume needs it.
- Bodies and attachments never live in Postgres.
- Search lives in Meilisearch (swappable for OpenSearch at very large scale), indexed asynchronously.
- API and sync workers are stateless and scale horizontally; Postgres scales with read replicas, then sharding by
  user.

## 5. Repository layout

```
apps/
  api/        Symfony 8 + FrankenPHP
  sync/       Go sync workers
  web/        Vue 3 web client
  desktop/    Tauri 2 shell around apps/web
  mobile/     Expo app
packages/
  core/       Shared TS domain types and logic
  api-client/ Generated TS API client
docker/       Dockerfiles and config for local services
docs/         Architecture, roadmap, decisions
compose.yaml  Local development stack
```
