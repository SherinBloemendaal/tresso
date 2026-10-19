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
| **Tresso** (this repository) | A complete, self-hostable email client for one person: accounts, mail, calendar, smart inboxes with rules, the three verdicts, todos, signatures, settings, web, desktop and mobile apps | AGPL-3.0; shared libraries in `packages/` are MIT |
| **Tresso Cloud** | The hosted service. Adds AI classification and summaries, AI calendar assistance, teams and delegation, API keys, webhooks and MCP, developer tools, statistics and Year in mail | Proprietary, separate repository |

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
 │    calendars, signatures, settings                          │
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

Scopes: `mail:read`, `mail:write`, `mail:send`, `todos:read`, `todos:write`, `calendar:read`, `calendar:write`,
`settings:read`, `settings:write`, `signatures:read`, `signatures:write`. The scopes `teams:read`, `teams:write` and `webhooks:manage` are reserved for
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
- **Sending:** `POST /api/v1/outgoing-messages` (scope `mail:send`) queues mail from one of the user's accounts, now or
  at a `sendAt` (send later, or a few seconds for undo; cancellable until a worker takes it). The API builds the
  MIME message (In-Reply-To and References for replies, Bcc only on the envelope) and stores it in object storage.
  Once the transaction commits it tells the workers on the Mercure topic `urn:tresso:sync:outbox`; scheduled mail is
  released by a delayed Messenger message, and every heartbeat both releases due mail and lists the leased accounts
  with mail to send, so a lost notification only delays sending. The leasing worker claims due messages (5 minute
  claim), sends over SMTP (whole message refused when one recipient is refused), files a copy in Sent (except at
  providers that do that themselves) and reports sent, retry (4xx, connection problems, a wrong password: up to 6
  attempts over about 4.5 hours) or failed (5xx). A message claimed again after an unreported attempt is first
  looked up in Sent by Message-ID, so it is never sent twice. Status reaches clients in the delta as
  `outgoingMessages`.
- **Drafts:** `/api/v1/drafts` (scopes `mail:read`, `mail:write`) stores the message being written with a `version`
  per save; an update names the version it builds on (`expectedVersion`) and is refused with 409 when another
  device saved in between, so nothing is overwritten silently. Clients choose the draft's id (`draftId` on create),
  so a draft has it before it reached the server. Drafts are in the delta. Once a draft rested 20 seconds after the
  last edit, the leasing worker appends a copy (`\Draft`, Bcc kept, `X-Tresso-Draft` header) to the account's
  Drafts folder, creating the folder when there is none, and only then removes the copy it replaces, so a failure
  leaves an extra copy rather than none. Copies of drafts that were sent (`draftId` on the outgoing message),
  deleted or moved to another account are queued for removal. The work comes with the outbox run: the same
  realtime notification and heartbeat hint, the same claim and report cycle.
- **Outbound changes:** flag/move/delete commands will follow the same pattern: queued per account by the API,
  executed by the leasing worker, confirmed back.

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

Shared TypeScript lives in `packages/core` (MIT): domain types, the OAuth flow, and the client side of the sync
protocol, so every client syncs the same way:

- `SyncEngine` pulls the delta into a `SyncStore` (each page and its cursor in one transaction). Pulls never overlap;
  a request during a pull makes it go round once more.
- `RealtimeClient` reads the hub through `fetch()` with the token as bearer, reconnects with backoff, renews the token
  before it expires, and asks for a pull on every (re)connect, because missed notifications are not replayed.
- `mailFrameDocument` wraps a sanitized body in the document for the sandboxed frame, with its Content-Security-Policy.

The web client keeps its copy in IndexedDB, one database per user, deleted on sign-out. Next to the synced records it
stores a summary per thread (senders, snippet, unread count, whether it is in an inbox), recomputed whenever one of
its messages changes, so the inbox lists read a few dozen rows through an index however much mail is stored.

Drafts in the web client are saved on the device first and uploaded right after (one upload at a time per
draft). Edits made here are never overwritten by the delta: when another device saved a newer version, the compose
window asks whether to keep this version or take the other one. Drafts written before they were synced are
uploaded after the update.

Writing mail in the web client: reply and reply all pick recipients from Reply-To, To and Cc without the user's own
addresses, and quote the original (helpers in `packages/core/src/compose.ts`, shared with mobile). Every message is
sent with a `sendAt` a few seconds ahead, so "Undo" can cancel it; "Send later" picks a time. The draft is saved in
IndexedDB while typing and kept until the message is confirmed sent, so a failed message can be edited and sent
again; the outcome of each sent message is announced once. Recipient suggestions come from the people in synced
mail, stored locally per user.

### 3.11 Signatures

- `/api/v1/signatures` (scopes `signatures:read` and `signatures:write`) lists, creates, updates (JSON merge patch)
  and deletes the user's HTML signatures. Each mail account points at at most one default signature
  (`mail_account.default_signature_id`), so one signature can be the default of several accounts. A signature's
  `defaultFor` is the complete list of accounts that use it; writing it moves those accounts' default and clears it on
  accounts left out. Accounts must belong to the same user. Deleting a signature clears the defaults in the same
  transaction, so the accounts reach clients as changed.
- The HTML is sanitized on save with symfony/html-sanitizer, so every client and the API hand out the same markup:
  inline styles and layout attributes stay; scripts, event handlers, forms, frames, style elements and linked
  stylesheets go. Images (sources, backgrounds, CSS `url()`) load only from `https:`, `data:` (plain image types)
  or `cid:` references to parts sent with the message.
- Signatures are part of the delta (`signatures`, tombstone type `signature`), and mail accounts carry
  `defaultSignatureId`. The web client previews unsaved HTML in a sandboxed frame without scripts
  (`signatureFrameDocument`), on a light and a dark background.

### 3.12 Search

- One Meilisearch index holds every user's messages (`MEILISEARCH_INDEX`), one document per message: subject,
  sender, recipients, snippet, body text and attachment names to search, plus owner, account, folder ids, address
  tokens, attachment flag and received time to filter on. The worker sends the body text with each message
  (`bodyText`, quoted replies dropped, at most 32768 characters); the API keeps it in `message_text`, so the index can
  be rebuilt without reading object storage.
- Indexing follows the client sync change numbers. `ChangeSequencer` queues an index run for the user after commit
  (`AfterCommitDispatcher`, Messenger transport `search`); the run sends the messages changed since the user's search
  cursor (`search_cursor`) and deletes tombstoned messages and removed accounts. Runs for one user take turns on the
  locked cursor row, so a late, repeated or lost run is caught up by the next one. `app:search:reindex --user=<email>`
  or `--all` rebuilds from the database and applies the index settings.
- `GET /api/v1/search` (scope `mail:read`) takes words, "phrases", -exclusions and the filters `from:`, `to:`,
  `has:attachment`, `in:` (folder role, account or folder name), `after:` and `before:` (days in `timeZone`), newest
  first and paged. Clients never talk to Meilisearch: the API always filters on the owner and the owner's current
  accounts, and resolves `in:` to the owner's own folder and account ids. Highlights come back as text segments, never
  as markup. The web client searches from the conversation list (shortcut `/`) and opens a result's thread.

### 3.13 Calendar

The calendar lives next to mail, because so much of it arrives by mail: invitations, reschedules, "can we meet
Thursday", deadlines. It follows the same rules as mail: the server holds the truth, clients keep a local copy
through the delta, providers are synced by the Go workers, and the open edition is complete on its own while AI
features attach through interfaces.

#### Data model

- **Calendar** (`calendar`, synced): belongs to a user (`owner_id`) and either to a mail account (a provider
  calendar) or to no account (the user's local **Tresso** calendar, created with the user, always writable, never
  synced to a provider). Fields: `source` (`local`, `caldav`, `google`, `microsoft`), `remote_id` (CalDAV collection
  URL, Google calendar id, Graph calendar id), name, color, default time zone, `writable` (from the provider's
  privileges), `is_default` (one per account: where new events and accepted invitations go), and the user's own
  `visible` and color override, which never go back to the provider. The provider sync cursor (sync-token, ctag,
  Google `nextSyncToken`, Graph delta link) is internal and not a client change.
- **Event** (`calendar_event`, synced) is one iCalendar `VEVENT`: a single event, the master of a recurring series,
  or an override of one occurrence. Rows are unique on `(calendar_id, uid, recurrence_id)`, so a series and its
  overrides share the `UID` exactly as RFC 5545 has it. Fields:
  - `uid`, `sequence`, `recurrence_id` (the original start of the overridden occurrence as a UTC instant, or the
    date for all-day series; null on masters and single events), `status` (`confirmed`, `tentative`, `cancelled`).
  - `summary`, `description`, `location`, `url`, `transparency` (`busy`/`free`), `visibility`
    (`public`/`private`/`confidential`).
  - Time: `start` and `end` as wall-clock values with their IANA time zone (`start_tz`, `end_tz`; null means floating),
    or dates when `all_day` is set (`DTEND` exclusive, as in RFC 5545). `DURATION` is converted to an end on ingest.
    Windows time zone names from Exchange and Outlook are mapped to IANA names on ingest; unknown `VTIMEZONE`
    definitions are matched by their rules, and the original definition stays in the stored iCalendar.
  - Recurrence: `rrule` (the `RRULE` value as text), `rdates` and `exdates` (lists of instants or dates).
  - Two UTC columns only for range queries: `starts_at` and `ends_at` of the first occurrence, and `series_ends_at`
    (end of the last occurrence; null when the series never ends). All-day and floating events are stored with
    UTC as their zone in these columns and are widened by 14 hours on each side when queried, then filtered in the
    reader's zone.
  - `organizer` and `attendees` (JSON: address, name, role, `partstat`, `rsvp`, kind), plus `self_partstat`, the
    response of the account owner, kept as a column so "needs action" can be found and indexed.
  - `reminders` (JSON list of `VALARM`s: trigger relative to start or end, or absolute, and action) and
    `conference` (JSON: provider `teams`, `meet` or `other`, join URL, conference id, dial-in), read from
    `X-MICROSOFT-*`/`X-GOOGLE-CONFERENCE`, Graph `onlineMeeting` and Google `conferenceData`.
  - `message_id`: the invitation this event came from, when it came by mail.
  - Provider state, internal: `remote_href` and `etag` (CalDAV resource; overrides share their master's resource)
    or the Google/Graph event id. For CalDAV the complete original resource is kept (`ical`), so properties Tresso
    does not model (`X-` properties, `VTIMEZONE`s, `ATTACH`) survive an edit: writes change the parsed object and
    serialize it again instead of building a new one.
- Parsing, serializing and recurrence expansion use `sabre/vobject` (iCalendar and RRULE per RFC 5545, Windows
  zone mapping). Clients expand recurrence for their local copy with `ical.js` in `packages/core`; the API's range
  endpoint is the reference both are tested against.

#### API

Scopes `calendar:read` and `calendar:write`, in the user scopes of the first-party clients.

| Call | Purpose |
|---|---|
| `GET /api/v1/calendars` | The user's calendars. `PATCH` changes name and color (written to the provider when it allows it), visibility and the default calendar. `POST`/`DELETE` only for local calendars. |
| `GET /api/v1/calendar-occurrences?from&to&calendarId[]` | Occurrences in a range (at most 400 days), recurrence expanded: per occurrence the event id, the `recurrenceId`, start, end and the event's fields, overrides applied and exceptions removed. |
| `GET /api/v1/calendar-events/{id}` | One event as stored (series or override). |
| `POST /api/v1/calendar-events` | Create in a writable calendar. |
| `PATCH /api/v1/calendar-events/{id}?scope=&recurrenceId=` | Edit; for a series `scope` is `this`, `following` or `all`. |
| `DELETE /api/v1/calendar-events/{id}?scope=&recurrenceId=` | Delete with the same scopes. |
| `POST /api/v1/calendar-events/{id}/response` | RSVP: `accepted`, `tentative` or `declined`, optional comment, for the series or one occurrence, optionally without telling the organizer. |

Edits of recurring events follow what Google Calendar, Outlook and Apple Calendar agree on:

- **This occurrence:** an override with the occurrence's `recurrence_id` is created or changed. Deleting one adds an
  `EXDATE` and removes its override.
- **This and following:** the series is split. The original gets an `UNTIL` just before the occurrence (a `COUNT`
  is converted to the equivalent `UNTIL`); a new series with a new `UID` starts at the occurrence with the edited
  fields and takes over the later overrides and exceptions, moved by the same time shift. Deleting cuts the
  series with `UNTIL`.
- **All:** the master changes. A start time shift moves every override's `recurrence_id` and every `EXDATE` by the
  same amount, so exceptions stay attached to their occurrences; overrides keep their own changed fields.
- Every change to an event with attendees raises `SEQUENCE` when it changes time, place or the recurrence, which is
  what tells attendees' clients that an update supersedes the previous one (RFC 5546).

Writes to provider calendars are optimistic: the row changes at once (clients see it in the delta), and an outbound
**calendar operation** (`PUT` or `DELETE` of one resource, with the `etag` it was based on) is queued for the
worker that leases the account, exactly like the outbox for mail. The worker reports the new `etag` and href. A
conflict (`412`) makes the worker fetch the current resource: the provider's version wins and the user's change is
reported back as failed, visible on the event. A provider refusal (read-only, quota) reverts the row.

#### Sync sources

The worker that leases an account runs the calendar syncer next to the mail syncer (`lease.Syncer` for the account
is a group of both); they share the account's credentials and lease but fail independently: a calendar server that
refuses the login does not stop mail, and reports a calendar status of its own.

- **CalDAV** for IMAP accounts (`apps/sync/internal/caldavsync`, on `emersion/go-webdav`, through the guarded
  dialer):
  - Discovery: the account's stored CalDAV URL, else RFC 6764 (`_caldavs._tcp` SRV and TXT records, then
    `https://<domain>/.well-known/caldav`, for the address's domain and the IMAP host's domain), else known
    provider hosts from the server settings detector (iCloud, Fastmail, Yahoo, mailbox.org and others). Then
    `current-user-principal`, `calendar-home-set` and the calendars with their name, color, supported components
    (only `VEVENT` calendars are synced), privileges, `getctag` and `sync-token`. The URL that worked is reported
    back and stored with the account; a server without CalDAV is reported once as `unsupported`, retried daily.
  - Changes: `sync-collection` (RFC 6578) with the stored token when the server supports it; otherwise the `getctag`
    is compared and, when it changed, the resource etags are listed and only changed resources fetched with
    `calendar-multiget`. Every 2 minutes, and right after a write. Missing tokens (`valid-sync-token` error) fall back
    to a full listing.
  - Ingest: `POST /internal/sync/accounts/{id}/calendars` (the complete calendar list, answers each calendar's
    cursor; calendars missing from the list are deleted) and `POST /internal/sync/accounts/{id}/calendars/{calendarId}/objects`
    (up to 100 changed resources as `href`, `etag` and raw iCalendar, removed hrefs, and the new cursor). The API
    parses every resource with sabre/vobject, so there is one iCalendar implementation on the server. The cursor only
    advances after the API accepted the work it covers.
  - Outbound: `POST /internal/sync/accounts/{id}/calendar-operations/claim` and `.../report`, the same claim and
    report cycle as the outbox, announced on the Mercure topic `urn:tresso:sync:outbox` together with mail.
  - Scheduling: servers that implement RFC 6638 (`calendar-auto-schedule` in the `DAV` header) send invitations and
    replies themselves; for others the API sends them as iTIP mail (below).
- **Google Calendar API** and **Microsoft Graph** for accounts connected through the provider OAuth layer (see
  1.6): Google `events.list` with `syncToken` and push channels (`events.watch`), Graph `calendarView/delta` per
  calendar with change notifications. Both report the same normalized events as CalDAV, converted to iCalendar by
  the worker so the API has one ingest path. Writes go through the provider's own API (`events.patch`,
  `PATCH /me/events/{id}`), and RSVPs through its response calls (`attendees[].responseStatus` with
  `sendUpdates`, Graph `accept`/`tentativelyAccept`/`decline`), because both providers then send the reply mail
  themselves.

#### Invitations in mail

- The worker's MIME parser reads `text/calendar` parts (inline or attached, any charset, at most 256 KB) and
  reports them with the message as `calendarParts`: the `METHOD` and the raw iCalendar. They are still stored as
  attachments, so the original can be downloaded.
- The API records an **invitation** per message (`message_id`, `method`, `uid`, `sequence`, `recurrence_id`, the
  iCalendar) and applies it after ingest, off the ingest transaction (Messenger, transport `low`):
  - `REQUEST` (new or updated invitation): if the user has the event with that `UID` in a provider calendar, the
    provider owns it (CalDAV servers with scheduling, Google and Microsoft put invitations into the calendar
    themselves) and nothing is written. Otherwise the event is created or updated in the account's default
    calendar (or the Tresso calendar when the account has none) with the user's `partstat` `NEEDS-ACTION`, so it
    shows in the calendar as unanswered. An older `SEQUENCE` than the stored one is ignored.
  - `CANCEL`: the event or occurrence is marked cancelled, under the same ownership rule.
  - `REPLY` (the user organizes): the attendee's `partstat` is updated on the user's event.
  - `COUNTER`, `REFRESH`, `ADD` and `DECLINECOUNTER` are shown on the message only.
- `GET /api/v1/messages/{id}/invitation` returns the invitation as clients show it: the event (expanded summary of
  the recurrence), whether it is new, updated or cancelled, the user's current response, conflicts with other busy
  events in the same time, and the event id in the user's calendar when there is one.
- An RSVP updates the user's `partstat` on the event and sends the reply the way the calendar's source needs it
  (provider API, CalDAV server scheduling, or iTIP). An iTIP `REPLY` goes through the outbox like any mail: a
  message to the organizer from the invited account, with a `text/calendar; method=REPLY` part holding only the
  replying attendee, the event's `UID`, `SEQUENCE`, `RECURRENCE-ID` when answering one occurrence, and the
  optional comment, and a short human-readable text part. Its subject follows the common form ("Accepted:
  Weekly sync").
- An event the user organizes with attendees sends `REQUEST` on creation and on changes that raise `SEQUENCE`,
  and `CANCEL` on deletion, through the same path when the provider does not do it.

#### Client sync

- The delta carries `calendars` and `calendarEvents` (series, overrides and single events as stored, without the
  raw iCalendar and provider state). Deleting a calendar implies its events are gone; deleting a mail account
  implies its calendars are gone.
- Clients keep them in their local store and expand recurrence locally (`packages/core`, `ical.js`), so the views
  work offline and switching weeks costs no request. The local copy is limited to events whose series touches the
  last 2 years or the future, which the API filters with `series_ends_at`; older history is read through the range
  endpoint.
- Reminders are scheduled by the clients from the synced events (web notifications while open, local notifications
  on mobile and desktop). Server-sent reminder push follows with phase 3 push.

#### Online meetings

A `ConferenceProvider` interface creates the meeting for an event when the user asks for one: Microsoft Teams
through Graph (`isOnlineMeeting` with `onlineMeetingProvider: teamsForBusiness` on Graph calendars; otherwise
`POST /me/onlineMeetings`), and Google Meet through `conferenceData.createRequest` on Google calendars. Which
providers an event can use depends on the calendar's account; clients read them from the calendar
(`conferenceProviders`). Join links found in descriptions and locations of other events (Zoom, Teams, Meet, Webex)
are recognized so every event with a meeting gets a join button.

#### AI features (open interfaces, Tresso Cloud implementations)

The open edition defines the interfaces and, where a deterministic version is useful on its own, implements it.
Tresso Cloud (`packages/api-bundle` in its repository) replaces them with AI versions by decorating the services;
`GET /api/v1/calendar/capabilities` tells clients which features are available, so they show only what works.

| Interface | Open edition | Tresso Cloud |
|---|---|---|
| `EventDraftInterpreter`: free text ("lunch with Sam Thursday 12:30 at Café Loos") to an event draft | none (capability off) | AI interpretation in the user's language and time zone |
| `MeetingTimeFinder`: proposed slots for a duration, attendees and a window | free/busy of the user's own calendars within working hours | adds attendees' availability where it is known and ranks by preferences and habits |
| `EventExtractor`: events and deadlines in a message | the message's `text/calendar` parts | AI extraction from the text, deadlines offered as todos |
| `MeetingBriefWriter`: a brief before a meeting (who, previous threads, open todos, attachments) | none (capability off) | AI brief from the related mail and todos |

Drafts from these features are never saved by themselves: the client shows them in the event editor or as
suggestions, and the user saves. Content sent to an AI provider follows Tresso Cloud's data processing terms.

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
