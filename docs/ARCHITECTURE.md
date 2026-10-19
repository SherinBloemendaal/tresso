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
- Envelope encryption with XChaCha20-Poly1305 (libsodium):
  - Each user has a random data key (DEK), stored on the user wrapped by a master key (KEK):
    `v1.<key id>.<base64(nonce + ciphertext)>`, with the user id as additional data.
  - Credentials are sealed with the owner's DEK as `v1.<base64(nonce + ciphertext)>`, with the account id as
    additional data, so a ciphertext copied to another user or account does not decrypt.
  - Master keys come from the environment: `DATA_KEYS` (`id:base64`, comma separated) and `DATA_KEY_CURRENT`.
    Rotation: add a key, make it current, run `bin/console app:crypto:rotate-data-keys`, remove the old key. Only the
    DEKs are re-wrapped; credentials stay as they are.
- The API never returns credentials. Sync workers fetch them over TLS from
  `GET /internal/sync/accounts/{id}/credentials?workerId=...` (scope `sync:credentials`), only for accounts they hold
  an unexpired lease on (anything else is a 404), with `Cache-Control: no-store`. Workers keep them in memory only.
- Mail servers are user input, so they are checked against server-side request forgery: the host must resolve only
  to public addresses (no private, loopback, link-local, carrier-grade NAT or IPv4-mapped ranges) and the connection
  must use TLS or STARTTLS. `MAIL_ALLOW_UNSAFE_SERVERS=1` lifts this for local test servers only. The sync workers
  repeat the address check when they connect, which also covers DNS changes after validation.
- `GET /api/v1/mail-server-settings?emailAddress=` suggests IMAP and SMTP settings the way Thunderbird finds them:
  the provider's own autoconfig (`autoconfig.<domain>`, then `<domain>/.well-known/autoconfig`), Mozilla's ISPDB
  by domain, then ISPDB by the parent domains of the preferred mail exchanger (which finds company domains hosted
  by a large provider). The first three are requested together. These requests go to domains users type in, so
  they run through a client that refuses private networks, with short timeouts and a 64 KB limit. Only encrypted
  servers are suggested. Answers are cached per domain in Redis: a day when found, an hour when not.

### 3.4 Sync workers (`apps/sync`)

Goal: fast synchronisation that scales horizontally to billions of accounts' worth of messages.

- **Leasing:** workers call `POST /internal/sync/leases` to claim a batch of accounts. Leases expire and are
  renewed by heartbeat, so a crashed worker's accounts move to another worker automatically.
- **Change detection (IMAP):** `IDLE` on the inbox for instant new mail; other folders every 5 minutes. With
  `CONDSTORE`, flag changes since the last MODSEQ; without it, a full flag check, after an IDLE update at most once a
  minute (a throttled update is postponed, never dropped). Changes that happen between a sync and the next IDLE are
  caught by comparing the inbox's EXISTS, UIDNEXT and MODSEQ at re-selection.
- **Deletions without QRESYNC:** the worker reports presence ranges ("between UID a and b only these exist") in
  chunks of 5000; the API removes the rest. Every folder is checked at session start and every 30 minutes.
- **Gmail and Microsoft 365** (phase 1.6): Gmail `history.list` with Pub/Sub push, Microsoft Graph delta queries with
  change notifications.
- **Connections** go through a guarded dialer that resolves the host and refuses private, loopback, link-local,
  CGNAT, NAT64, 6to4 and documentation ranges at connect time (the API checks at save time, the worker again on every
  connection, so DNS changes cannot redirect it). TLS 1.2+ with certificate verification, or STARTTLS.
- **Failures:** sessions reconnect with exponential backoff (2 s to 5 min). A refused login reports `auth_failed` and
  stops until the user changes the credentials; three failed sessions in a row report `error` with a user-safe
  detail.
- **Ingest:** raw MIME goes directly to object storage (`raw/<sha256[0:2]>/<sha256>`, content-addressed, so
  duplicates are stored once); attachments too (`attachments/<sha256[0:2]>/<sha256>`). Parsed metadata goes to the
  ingest protocol (scope `sync:ingest`, only for the leasing worker, every call safe to repeat):

  | Call | Purpose |
  |---|---|
  | `POST /internal/sync/accounts/{id}/folders` | The complete folder list. Answers each folder's stored cursor; a changed UIDVALIDITY resets the folder. Folders missing from the list are deleted. |
  | `POST /internal/sync/accounts/{id}/messages` | Up to 200 new messages for one folder plus the folder's cursor after the batch. A known (folder, uid) only refreshes flags; a message already stored (same Message-ID, else same raw content) only gains a location. |
  | `POST /internal/sync/accounts/{id}/changes` | Flag changes and vanished uids for one folder. A message that is in no folder anymore is deleted. |
  | `POST /internal/sync/accounts/{id}/status` | `active`, `auth_failed` or `error` with a short detail the user sees. |

  The cursor is opaque to the API: the worker decides what it holds (UIDNEXT, HIGHESTMODSEQ, backfill position).
- **Threading** (API side, at ingest): first by headers in both directions (messages this one refers to through
  In-Reply-To/References, and stored messages that refer to this one), merging threads that turn out to be one
  conversation; without any references, a thread from the last 14 days with the same normalized subject that shares
  a participant; otherwise a new thread.
- **Backfill:** newest first, in budgeted steps per folder, so the inbox is usable within seconds while history fills
  in behind and new mail is never stuck behind it. Fetches are grouped by count (50) and size (20 MB).
- **Cursor:** per folder, opaque to the API: UIDVALIDITY, UIDNEXT, backfill position and HIGHESTMODSEQ. It only
  advances after the API accepted the work it covers, so a crash never skips mail.
- **Outbound:** flag/move/delete/send commands are queued per account by the API and executed by the leasing
  worker, then confirmed back.

### 3.5 Client sync protocol

- Synced entities (mail accounts, folders, threads, messages; todos and settings follow the same pattern) carry a
  `change_seq`. Every insert or client-visible update gets the owner's next number; updates that only touch internal
  fields (sync cursors, leases, encrypted credentials) do not. A message also changes when it gains or loses a
  folder. Deletes leave a tombstone.
- Numbers come from `UPDATE app_user SET change_seq = change_seq + 1 RETURNING`, which locks the user's row until
  the transaction commits. Writes for one user therefore become visible in number order, so a client that has seen
  N never later meets an uncommitted smaller number. Every write path runs in an explicit transaction; the
  allocator refuses to run outside one. Different users never wait on each other.
- `GET /api/v1/sync/changes?since=N&limit=500` (scope `mail:read`) returns mail accounts, folders (with role),
  threads, messages (metadata, flags and folder ids, never bodies) and `deleted` entries above N, plus the new
  `cursor` and `hasMore`. A page never splits one change number. A deleted mail account implies its folders,
  threads and messages are gone.
- Clients store data in a local cache (desktop via Rust/Tauri SQLite, mobile via expo-sqlite, web via IndexedDB),
  start with `since=0`, keep the cursor, and fetch message bodies lazily.
- Realtime: the Mercure hub built into FrankenPHP tells clients "your changes reached N"; the client then pulls the
  delta. The notification carries no data, so a missed one only delays the client until the next one or its next
  reconnect, when it pulls the delta anyway.
  - `GET /api/v1/realtime/token` (scope `mail:read`) returns `hubUrl`, the user's private `topic`, a subscriber
    `token` and its `expiresAt`. Clients subscribe with `GET {hubUrl}?match={topic}` and the token in the
    `Authorization: Bearer` header, and fetch a new token before it expires. Browsers read the event stream through
    `fetch()`, because `EventSource` cannot send headers; the hub accepts no token in the URL (RFC 9700), and its
    cookie alternative does not fit clients on another origin than the hub.
  - Every update is private, of type `changes`, with data `{"cursor": N}`.
  - The hub runs in issuer mode: it trusts tokens from issuer `tresso-api` with the hub's public URL as audience.
    Tokens are JWT access tokens (`typ: at+jwt`) whose grants are RFC 9396 `authorization_details`, limited to exact
    topics. Subscriber and publisher tokens use different keys, so a subscriber key can never publish.
  - Change numbers are published only after the transaction that allocated them committed (a DBAL driver
    middleware reports commits and rollbacks), and only after the response went out (`kernel.terminate`; console
    commands and Messenger messages publish when they finish). A rolled-back write publishes nothing. A failed
    publish is logged, never raised.
- Message content: the delta never carries bodies. `GET /api/v1/messages/{id}/body` (scope `mail:read`) reads the
  raw message from object storage, picks the HTML and text parts, and returns HTML that is safe to render:
  - An allowlist sanitizer (symfony/html-sanitizer) removes scripts, event handlers, forms, frames and embedded
    objects; links get `target="_blank"` and `rel="noopener noreferrer"`. Layout attributes, classes and inline
    styles stay, because mail layouts depend on them. Style elements are kept as sanitized CSS in front of the body.
  - Every URL that loads media (image sources, backgrounds, CSS `url()`) passes one policy: `cid:` references
    become signed attachment URLs, `data:` is allowed for plain image types only, and anything from the internet
    is removed unless the request says `remoteContent=allow`. Remote loads tell the sender that, when and where a
    message was read, so they are blocked by default; `hasRemoteContent` lets the client offer to load them. CSS
    escapes are resolved before checking, so escaped names cannot slip past.
  - Clients render the HTML in a sandboxed frame without scripts and with a Content-Security-Policy that allows
    images only from the API (and from the internet once allowed): a second, independent layer.
  - Attachments are listed with a signed URL (`/content/attachments/{id}`, valid for an hour, no access token,
    because images inside rendered mail cannot send one). Only plain image types are served inline; everything else
    downloads as `application/octet-stream`. Every response carries `nosniff` and `Content-Security-Policy:
    default-src 'none'; sandbox`. Quarantined (infected) attachments have no URL and are never served.
  - A part counts as inline when the HTML references its Content-ID; the worker decides this, because mail clients
    disagree on the disposition of embedded images. The Content-ID is kept for every part.

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
