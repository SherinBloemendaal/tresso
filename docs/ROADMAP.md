# Roadmap

Each phase ends in something usable. Phases are started one at a time after review. This roadmap covers the open
edition; where Tresso Cloud adds something in the same phase, it is noted at the end of the phase.

## Phase 0: Foundation (done)

- [x] Architecture document
- [x] Monorepo layout, npm workspaces, Docker Compose dev stack (Postgres, Redis, RustFS (S3), Meilisearch, ClamAV)
- [x] Symfony 8 API on FrankenPHP with health endpoint
- [x] Initial domain schema + migration
- [x] OAuth2 authorization server skeleton (auth code + PKCE, client credentials)
- [x] Go sync worker skeleton (OAuth client credentials, lease heartbeat loop)
- [x] Vue 3 web, Tauri desktop and Expo mobile skeletons
- [x] Open core split: add-on hooks (`bundles.local.php`, `composer.local.json`), AGPL-3.0 and MIT licenses

## Phase 1: Read and send mail

Delivered in steps, each its own pull request:

- [x] 1.1 Accounts and credentials: envelope encryption with key rotation, mail account API (create, list, update,
  pause, delete), worker credentials endpoint, server-side request forgery checks, GreenMail test server
- [x] 1.2a Ingest protocol and threading: folders, message batches, changes, account status, thread merging
- [x] 1.2b Go IMAP sync: folder discovery, newest-first backfill, raw MIME and attachments to S3, MIME parsing,
  IDLE, CONDSTORE deltas, presence-based deletions, SSRF-guarded connections
- [x] 1.3a Client delta sync: per-user change numbers committed in order, tombstones, paged delta endpoint
- [x] 1.3b Mercure realtime: issuer-based hub, RFC 9396 subscriber tokens per user, "changes up to N" published
  after commit
- [x] 1.4a Message content API: sanitized HTML body with remote content blocked by default, signed attachment URLs
- [x] 1.4b Web client: sign-in, Unified Inbox and per-account inboxes from a local IndexedDB copy kept current by
  delta sync and realtime, reading pane rendering the body in a sandboxed frame
- [x] 1.4c Account management in the web client: server settings found from the address, add, fix, pause and remove
  mail accounts, with the connection result shown live
- [x] 1.5a Sending through the worker: outbox, send later and undo, retries, Sent copies, no double sends
- [x] 1.5b HTML signatures per mail account: sanitized on save, a default per account, synced to clients, edited
  in Settings with a live preview
- [x] 1.5c Compose, reply and forward in the web client: recipient suggestions, undo send, send later, outbox, drafts
  kept on the device until a message is confirmed sent
- [x] 1.5d Search: Meilisearch index kept in step with sync changes, from:, to:, in:, has:attachment and date filters,
  results with highlights in the web client
- [x] 1.5e Drafts synced across devices and filed in the account's Drafts folder, with conflicts between devices
  settled by the user
- [ ] 1.6 Gmail and Microsoft 365: OAuth connection, Gmail API and Microsoft Graph sync

## Phase 2: Do, hand over, know

- Built-in smart inboxes (spam, notification, advertise, todo, informational) and custom ones
  (name, instructions, include/exclude lists, verdict mapping)
- Rule-based classification behind a classifier interface
- Todos (synced), "make todo" in the single-email view, automatic todo creation per smart inbox
- Hand over by forwarding with a note and due date, tracked in "Waiting on" with nudges
- Triage mode (one email at a time, D / H / K)
- Daily brief for `know` mail, one-click "Noted"
- Batched delivery for advertise and notification mail, one-click unsubscribe

Tresso Cloud: AI classification with feedback learning, automatic todo extraction, AI summaries in the daily brief.

## Phase 3: Native apps

- Tauri desktop with local SQLite cache and native notifications
- Expo mobile app with push (only `do` and `delegate` by default)
- Sign-in through `tresso://auth/callback`, tokens in the OS keychain

Tresso Cloud: teams, shared inboxes, assignments, internal comments, delegation rules, workload.

## Phase 4: Safety and housekeeping

- ClamAV attachment scanning and quarantine
- Bulk management (by sender, smart inbox, age), cleanup suggestions

Tresso Cloud: API keys and third-party apps, webhooks, MCP endpoint, developer tools, statistics, Year in mail.
