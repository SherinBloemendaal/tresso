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

Users log in with email + password (Argon2id) and, later, Google / Microsoft sign-in as identity providers. (Signing
in with Google or Microsoft to connect a mail account is separate: see Provider sign-in below.)

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

#### Provider sign-in (Gmail, Microsoft 365)

Gmail and Microsoft 365 accounts connect through the provider's OAuth 2.0 authorization code flow with PKCE (S256),
as a confidential client (the API holds the client secret). The layer is generic: one **provider grant** per
signed-in provider account, shared by every feature that uses it. Mail is the first feature; the calendar (Google
Calendar and Microsoft Graph calendars, with Meet and Teams links) adds its scopes to the same grant.

- **Start:** `POST /api/v1/provider-authorizations` (scope `settings:write`) with `{"provider": "google" | "microsoft",
  "features": ["mail"], "mailAccountId": <optional, to sign in again>}`. The API stores a pending request (the
  SHA-256 of a random 256-bit `state`, the PKCE verifier, the user, the requested scopes; 15 minutes, single use) and
  answers the provider's `authorizationUrl`. `GET /api/v1/oauth-providers` lists the providers this server has an
  OAuth app for.
- **Callback:** the provider redirects the browser to `<API_PUBLIC_URL>/connect/<provider>/callback`. That route is
  behind the browser session of the API (the one used to sign in for `/oauth/authorize`; a signed-out browser signs
  in first and comes back), and the session user must be the user who started: a sign-in link someone else started
  cannot attach your provider account to their Tresso account. The API redeems the code with the verifier, reads who
  signed in (Google: the ID token's `sub` and verified `email`; Microsoft: the ID token's `tid` and `oid`, and the
  mailbox address from Graph `/me`), checks that the scopes the features need were granted (users can untick them),
  creates or updates the grant and creates the mail account (or signs an existing one in again), then redirects to
  the web app: `/settings/accounts/new` (or `/settings/accounts` when signing in again) with `connected=<account id>`
  or `connectError=<code>` (`denied`, `expired`, `different_user`, `scopes_missing`, `wrong_account`,
  `already_connected`, `unverified_address`, `no_mailbox`, `failed`). ID tokens come straight from the token endpoint
  over TLS, so their claims are used without a signature check (OpenID Connect Core 3.1.3.7); the audience is checked.
- **Grants** (`provider_grant`): owner, provider, the provider's stable account id, the scopes the provider confirmed
  (Microsoft's normalized to lowercase without the `https://graph.microsoft.com/` prefix) and the tokens, sealed with
  the owner's DEK like passwords (additional data: the grant id). Mail accounts link to their grant; removing the
  last account of a grant deletes it and revokes the refresh token at Google (Microsoft has no revocation endpoint for
  this).
- **Access tokens for workers:** the credentials endpoint answers `{"type": "oauth2", "accessToken", "expiresAt"}`
  for these accounts. The API refreshes the access token when it has less than 5 minutes left, under a row lock on the
  grant (Microsoft replaces the refresh token on every refresh; two concurrent refreshes would lose one). A worker
  whose token the provider refused asks again with `rejected=1` and gets a new one (unless it was issued in the last
  minute). Workers never see refresh tokens. `invalid_grant` marks the grant revoked and its accounts `auth_failed`
  ("Sign in with Google again") and answers 409; a provider outage answers 503 with `Retry-After`.
- **Scopes now:** Google `openid email profile https://www.googleapis.com/auth/gmail.modify` with
  `access_type=offline`, `prompt=consent` and `include_granted_scopes=true`. Microsoft `openid email profile
  offline_access Mail.ReadWrite Mail.Send User.Read` (Graph). `gmail.modify` reads, labels, deletes and sends mail;
  it is a restricted scope, so a public Google app needs Google's verification and a security assessment.
- **Incremental consent (for the calendar):** add a `ProviderFeature` case and its scopes in `ProviderScopes`
  (Google `https://www.googleapis.com/auth/calendar.events`, plus `calendar.readonly` to list calendars; Microsoft
  `Calendars.ReadWrite` and, for work accounts, `OnlineMeetings.ReadWrite`). A sign-in for an account that has a grant
  asks for the scopes of every feature the grant covers plus the new ones, Google keeps earlier scopes through
  `include_granted_scopes`, and the grant stores the union for Microsoft. Callers ask
  `ProviderAccessTokens::accessToken($grant, $feature)`, which refuses (409 for workers) when the grant lacks the
  feature's scopes.

##### Registering the OAuth apps

Each installation registers its own apps and puts the values in `apps/api/.env.local` (never committed). A provider
without a client id is not offered. With the Docker stack, `API_PUBLIC_URL` is `http://localhost:8080`; in
production it is the public HTTPS address of the API.

Google (Google Cloud console):

1. Create a project, enable the **Gmail API** (APIs and services, Library).
2. OAuth consent screen (Google Auth Platform, Branding and Audience): app name, support address, authorized domain
   of the API; user type External (or Internal for one Workspace organization). While the app is in testing, add the
   addresses that may sign in as test users. Data access: add `openid`, `.../auth/userinfo.email`,
   `.../auth/userinfo.profile` and `https://www.googleapis.com/auth/gmail.modify`.
3. Clients, Create client, type **Web application**, authorized redirect URI
   `<API_PUBLIC_URL>/connect/google/callback` (for the Docker stack `http://localhost:8080/connect/google/callback`).
4. Set `GOOGLE_OAUTH_CLIENT_ID` and `GOOGLE_OAUTH_CLIENT_SECRET`.

Microsoft (Microsoft Entra admin center, App registrations):

1. New registration. Supported account types: "Accounts in any organizational directory and personal Microsoft
   accounts" for `MICROSOFT_OAUTH_TENANT=common` (or single tenant, then set the tenant id). Redirect URI: platform
   **Web**, `<API_PUBLIC_URL>/connect/microsoft/callback`.
2. Certificates and secrets: new client secret (note its expiry; renew it before then).
3. API permissions, Microsoft Graph, delegated: `openid`, `email`, `profile`, `offline_access`, `User.Read`,
   `Mail.ReadWrite`, `Mail.Send`. Organizations whose users may not consent themselves need an admin to grant consent.
4. Set `MICROSOFT_OAUTH_CLIENT_ID`, `MICROSOFT_OAUTH_CLIENT_SECRET` and `MICROSOFT_OAUTH_TENANT`.

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
- **Gmail** (`internal/gmailsync`, Gmail API): labels are folders (remote name = label id; INBOX, SENT, DRAFT, TRASH
  and SPAM get their roles, user labels are custom), plus a virtual `tresso:all` folder (role `all`) for everything
  outside Trash and Spam, so archived mail keeps a location. Chats, UNREAD, STARRED, IMPORTANT and CATEGORY_* are not
  folders; seen = no UNREAD, flagged = STARRED, draft = DRAFT (Gmail has no "answered"). History is account-wide, so
  one cursor (`historyId`, backfill page token) lives on `tresso:all`; the history id is taken before the backfill
  starts, so nothing is missed. Backfill fetches `format=raw` newest first, once per message, and files it in every
  folder its labels map to. Changes come from `history.list` every 30 s; touched messages are re-read (`format=minimal`)
  and reported per folder. When the history id has expired (404), the folders are listed in full and compared with
  the API's `GET /internal/sync/accounts/{id}/folders/remote-uids` to find what was deleted.
- **Microsoft 365 / Outlook.com** (`internal/graphsync`, Microsoft Graph): every request asks for immutable ids
  (`Prefer: IdType="ImmutableId"`), so a message keeps its id when it moves. Folders are the folder tree (remote name
  = folder id, display name = path), with roles from the well-known folders. Each folder has its own cursor: the
  delta link, taken before the backfill, and the backfill position (by received time, so deletions during the
  backfill do not shift pages). The inbox delta is polled every 30 s, other folders every 5 minutes; a round with
  removals re-reads the folder tree first and applies every folder's additions before any removal, so a move links
  the message to its new folder instead of deleting it. An expired delta link (410, `syncStateNotFound`) restarts the
  folder's delta and compares with the API's remote uids. "Answered" is not available through delta.
- **Both providers:** flag changes go first (`changes`), and only the uids the API answers as `unknownUids` are
  downloaded (Gmail raw, Graph `$value`) and ingested; a message entering another folder is downloaded once more and
  linked to the stored one by Message-ID. Requests are rate limited per account (Gmail 200 quota units/s, Graph
  15/s), 429 and 5xx are retried with Retry-After, and a refused access token is renewed once through
  `credentials?rejected=1` (a second refusal, or missing scopes, reports `auth_failed`). Change notifications (Gmail
  Pub/Sub, Graph webhooks) need a public endpoint and are not used yet; polling covers both.
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
  claim), sends over SMTP (whole message refused when one recipient is refused), or for Gmail and Microsoft 365
  through their APIs (Gmail `users.messages.send`; Microsoft through a draft, see below; the envelope-only Bcc
  recipients are added as a Bcc header, which both providers strip; Gmail replies carry the thread id of the message
  they answer), files a copy in Sent (except at providers that do that themselves, Gmail and Microsoft included) and
  reports sent, retry (4xx, connection problems, a wrong password: up to 6 attempts over about 4.5 hours) or failed
  (5xx). A message claimed again after an unreported attempt is first looked up, so it is never sent twice; the
  outcome and its text reach the user in the delta as `outgoingMessages` (`lastError`).
  - IMAP accounts: the Sent folder by Message-ID.
  - Gmail may replace the Message-ID, so its sends carry `X-Tresso-Outgoing-Id` (a SHA-256 of the outbox id and the
    Message-ID, nothing internal). The lookup tries the Message-ID, then searches `from:me` (Trash and Spam included,
    which `in:sent` would miss) since the outbox entry was written (its id is a UUIDv7), narrowed to the first
    recipient and the subject, and reads that header on every match, however many. Gmail search cannot match custom
    headers, so the recipient and the subject are the narrowing; each match read costs 5 quota units.
  - Microsoft may keep neither the Message-ID nor X- headers on the Sent Items copy, so mail goes out through a
    draft: created from the MIME (`POST /me/messages`), marked with a named string extended property of Tresso's own
    property set (`TressoOutgoing`, the same SHA-256 value), and sent (`POST /me/messages/{id}/send`). Custom extended
    properties stay on the item, so the lookup is one filtered request over the whole mailbox,
    `singleValueExtendedProperties/Any(ep: ep/id eq '...' and ep/value eq '...')`. Copies sent before this (with
    `sendMail`), or whose draft Exchange would not mark (a 4xx on the PATCH: the draft is then sent unmarked, logged
    once per account), are found by Message-ID and by `X-Tresso-Outgoing-Id` on the Sent Items since the entry was
    written, sent to the first recipient (all of them when Graph refuses that filter), a thousand a page. Drafts and
    the Outbox never count as sent. A draft an earlier attempt left (marked, or with our Message-ID) counts as stopped
    only when it is in Drafts and unchanged for 15 minutes: Exchange accepts a send (202) and sends a little later,
    and until then the item is still a draft, in Drafts or the Outbox. A stopped one is deleted and the message sent
    anew; a younger one, or one in the Outbox, leaves the question open. A draft whose send was refused (4xx) is
    deleted at once. Graph takes at most 4 MB per request,
    so a message of about 3 MB (base64 included) is the limit, as with `sendMail`. Docs: [extended properties
    overview](https://learn.microsoft.com/en-us/graph/api/resources/extended-properties-overview), [create
    (PATCH)](https://learn.microsoft.com/en-us/graph/api/singlevaluelegacyextendedproperty-post-singlevalueextendedproperties),
    [filter](https://learn.microsoft.com/en-us/graph/api/singlevaluelegacyextendedproperty-get), [send a
    draft](https://learn.microsoft.com/en-us/graph/api/message-send), [MIME
    messages](https://learn.microsoft.com/en-us/graph/outlook-send-mime-message).
  - When the lookup cannot decide, nothing is sent. Only a refusal (a 4xx other than 429) fails the message at once,
    with "Tresso could not check whether an earlier attempt already sent this message; it was not sent again. Check
    Sent and send it again if needed." Anything else (429 or 5xx after the retries, no answer, "not decided yet": a
    copy that may still be sending, a leftover that could not be deleted) is a retry, so the next attempt looks again.
    A retry's text shows in the outbox while the message waits, so it stays neutral ("Tresso is checking whether an
    earlier attempt already went out and will try again shortly."), or users would send the message again while the
    original is still going to be sent. The claimed message says whether the attempt is the API's last
    (`lastAttempt`); its retry ends the message, so it carries the final text: the one above, or, when the earlier
    copy may still go out, "An earlier attempt may still be waiting in the Outbox and could still be delivered; it
    was not sent again. Check the Outbox and Sent before you send it again." Lost access only waits: the check runs
    again once the user signed in.
  - Gmail narrows by subject only when it has a letter or digit; Gmail matches subjects by word, so a subject of
    punctuation alone would match nothing.
- **Drafts:** `/api/v1/drafts` (scopes `mail:read`, `mail:write`) stores the message being written with a `version`
  per save; an update names the version it builds on (`expectedVersion`) and is refused with 409 when another
  device saved in between, so nothing is overwritten silently. Clients choose the draft's id (`draftId` on create),
  so a draft has it before it reached the server. Drafts are in the delta. Once a draft rested 20 seconds after the
  last edit, the leasing worker appends a copy (`\Draft`, Bcc kept, `X-Tresso-Draft` header) to the account's
  Drafts folder, creating the folder when there is none, and only then removes the copy it replaces, so a failure
  leaves an extra copy rather than none. Copies of drafts that were sent (`draftId` on the outgoing message),
  deleted or moved to another account are queued for removal. The work comes with the outbox run: the same
  realtime notification and heartbeat hint, the same claim and report cycle. Gmail accounts file copies with
  `users.drafts.create` and remove them by Message-ID (`rfc822msgid:`), or, should Gmail have replaced it, by the
  `X-Tresso-Draft-Copy` header (a SHA-256 of the Message-ID). Gmail cannot search custom headers and batching does not
  lower quota, so that header is read only on drafts since the draft was started and at most a week back; a copy not
  found counts as removed (a copy older than a week whose Message-ID Gmail replaced stays as an extra draft). Microsoft
  365 accounts create copies from the MIME message (`POST /me/messages`), mark them with the `TressoDraftCopy` extended
  property and remove them from Drafts permanently, found by that property in one request (older copies by
  Message-ID or the header).
- **Message actions:** `POST /api/v1/message-actions` (scope `mail:write`) marks up to 500 messages read or unread,
  flags or unflags them, archives them (out of the inbox into Archive, or All Mail where there is no Archive folder,
  as on Gmail), trashes them or moves them to a folder of their account (every copy, or with `fromFolderId` only the
  copies in that folder). The change shows in Tresso at once and is
  queued per account as a `mailbox_change`; the outbox run claims an account's changes in the order they were made
  (`/internal/sync/accounts/{id}/mailbox-changes/claim`) and reports each (`.../{change}/report`: done, retry,
  failed, or gone for a move whose copy is no longer in its folder). A flag change goes on every copy of the message;
  a move takes one copy out of its folder at once and leaves a placeholder copy (remote uid `pending:<change id>`) in
  the target, which becomes the real copy under the uid the worker reports (from UIDPLUS, or found by Message-ID) or
  the folder sync finds. While a flag change is unconfirmed the folder sync leaves \Seen and \Flagged as the user set
  them. A message's changes are made in order: once one is not done, the worker leaves the message's later changes for
  the next run, and changes to a copy that is still on its way wait until it landed, without holding up other
  messages. A change the server refuses, or that still fails after six attempts with backoff, is undone, so Tresso
  shows the message as the server has it; a move that finds its copy gone (an earlier unreported attempt, Gmail taking
  every label away with the first copy moved to Trash, another app) puts nothing back. Placeholders survive a reset of
  their folder; when the folder is deleted, its moves are undone; a placeholder whose move is done but that never
  lands within an hour is dropped. The worker moves with MOVE, or COPY and then \Deleted where the server has no MOVE
  (never copying twice on a retry); it expunges only that copy (UIDPLUS) and never the whole folder, which would also
  erase messages another app merely marked deleted. Gmail accounts change labels instead: read and flag through
  UNREAD and STARRED, archive removes INBOX (the copy in the virtual All Mail folder takes the placeholder's place),
  trash and untrash through `messages.trash`/`untrash`, and a move adds the target label and removes the source one.
  Microsoft 365 accounts PATCH `isRead` and `flag` and move with `/move`; immutable ids keep the message's id, so the
  worker always reports the uid in the target.

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
  threads (with their smart inbox, verdict and classification reason), messages (metadata, flags, folder ids, smart
  inbox and verdict, never bodies), smart inboxes and `deleted` entries above N, plus the new `cursor` and
  `hasMore`. A page never splits one change number. A deleted mail account implies its folders,
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

- A smart inbox (the `category` table) = name + instructions + include list + exclude list (addresses or `@domain`,
  which covers subdomains) + verdict + flags (auto-create todo, batch delivery, notify; used by later steps). Every
  user has five built-in ones (`todo`, `informational`, `notification`, `advertise`, `spam`), created with the user;
  they can be changed but not deleted. Mail lands in exactly one smart inbox.
- `/api/v1/smart-inboxes` (scopes `settings:read`, `settings:write`) lists, creates, updates (JSON merge patch) and
  deletes them. Names are unique per user (case-insensitive), at most 50 of the user's own, a sender cannot be on both
  lists. Smart inboxes are in the delta (`smartInboxes`, tombstone type `smartInbox`). Deleting one takes its
  conversations and messages out of it in the same transaction (so clients see them change) and lets classification
  place them again.
- Classification sits behind `App\Classification\Classifier`. The open edition's `RuleBasedClassifier` decides, in
  this order: spam that no From-based rule may overrule, because a From address is easy to forge (DMARC failing a
  reject or quarantine policy, which not even the Spam exclude list overrules, and the server's spam verdict on mail
  whose sender did not pass DMARC); a sender rule (a
  correction the user made earlier, by address, then domain and parent domains); the include lists (the user's own
  smart inboxes first); then signals in the message, each pointing at a built-in smart inbox: the server's spam
  verdict (junk folder, `$Junk` keyword, `X-Spam-Flag`, `X-Spam-Status`) (spam), `Auto-Submitted` or `Precedence:
  auto_reply` (notification), marketing senders (`newsletter@`, `promo.` and similar, never for a reply) and bulk
  headers (`Precedence: bulk`, `List-Unsubscribe` with `Feedback-ID`) (advertise), addresses that take no replies
  (`noreply@`, `notifications@`, `alerts.`) (notification), a mailing list (`List-Id`; checked before the unsubscribe
  link, which discussion lists carry too) (informational), an unsubscribe link (advertise), and finally mail from a
  person: something to do (todo) unless the user is only in Cc (informational); Bcc and addresses of the user Tresso
  does not know count as direct. A smart inbox whose exclude list covers the sender is skipped at every step. The
  verdict is the smart inbox's unless a sender rule sets one. The `instructions` field is for AI classifiers such as
  the one in Tresso Cloud, which decorates the `Classifier` service and should keep sender rules and lists in charge.
- The sync worker sends the headers the rules read (`headers`) and the junk keyword (`junk`) with each message; the
  API keeps them in `message_signals`, apart from the message row and only for messages that have any. Anyone can
  write an Authentication-Results header, so a DMARC pass counts only when the receiving server wrote it: at Gmail
  and Microsoft 365 the topmost header is always theirs; on other servers its authserv-id must belong to the
  account's domain or its IMAP server's domain (`ReceivingServers`). A junk keyword
  set or cleared after ingest is not read again (flag changes carry only seen, flagged, answered and draft).
- Classification runs after the ingest commits, on its own Messenger transport (`classify`) with its own consumer
  (`classifier` in Compose), so a first sync never holds up sending. It goes in batches of 200 per transaction, ten
  per job; a longer run queues the rest as a new job. Runs for one user take turns on an advisory lock. Each incoming
  message gets a smart inbox and verdict (clients notify on `do` and `delegate`); its conversation takes those of its
  newest incoming message. An unchanged outcome is not written, so classifying again resends nothing that did not
  move. The user's own mail is never classified: mail from their accounts' addresses, and mail filed in Sent or Drafts
  and otherwise only in folders that hold everything (All Mail, an All or Archive folder), which covers an alias. A message waits with `classified_at` empty, so a lost run is caught up by the next one.
- Corrections: `PUT /api/v1/threads/{id}/classification` (scopes `mail:write` and `settings:write`, because it
  remembers a rule) with a smart inbox and optionally a verdict takes the user's smart inbox lock and then their
  classification lock (the order every path keeps), reads the conversation and the smart inbox again, puts the
  conversation and its newest incoming message there for good (reason `user`; automatic classification leaves it
  alone from then on), remembers the choice for the sender as a sender rule (one per sender), takes the sender's
  address off that smart inbox's exclude list, and classifies again the last 30 days of mail from that sender. When a
  domain entry on that exclude list covers the sender the correction is refused (422): the rule would never apply.
  `GET /api/v1/sender-rules` and `DELETE /api/v1/sender-rules/{id}` (scopes `settings:read`, `settings:write`) list
  and forget them; forgetting one classifies that sender's recent mail again.
- Every change to the smart inboxes classifies the last 30 days again after the commit; writes to one user's smart
  inboxes take turns, so the name and count checks hold. `app:classification:rerun --user=<email>` or `--all`, with
  `--days` (0 for all mail), does it by hand. Smart inboxes created before they synced get a change number on a sync
  from the start.
- Each conversation carries why it is where it is (`classificationReason`: `user`, `sender_rule`, `include_list`,
  `server_spam`, `authentication_failed`, `automated`, `marketing_sender`, `bulk`, `notification_sender`,
  `unsubscribe_link`, `mailing_list`, `direct`, `copied`, or a reason of an add-on), which clients explain.

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

The desktop app runs the web client unchanged; only signing in and keeping the tokens go through the shell
(`apps/web/src/platform/desktop.ts`). It is its own OAuth client (`desktop`) with the redirect URI
`tresso://auth/callback`: sign-in opens the authorization page in the system browser, where the user's password
manager and existing sessions work, and the operating system hands the `tresso://` link back to the running app
(single instance on Windows and Linux), which exchanges the code with its PKCE verifier. Tokens are kept in the OS
keychain (Keychain, Credential Manager, Secret Service) by Rust commands, never in web storage, and read back before
the first screen. When the keychain refuses, the app says that the sign-in will not last instead of failing quietly.
The API and the Mercure hub accept the app's origins (`tauri://localhost`, and `http://tauri.localhost` on Windows),
and the main window may only open http(s) pages in the browser and read the link it was opened with.

The desktop app keeps the mail copy in SQLite instead of the web view's IndexedDB (`apps/web/src/mail/store.ts`
picks the store). Rust commands (`apps/desktop/src-tauri/src/sql.rs`, rusqlite with SQLite bundled) open one database
per user in the app's data folder with two connections: one writes, one reads, in WAL mode, so the lists read the last
committed state while a page of the delta is stored. The commands run off the main thread, so the window stays
responsive while SQLite works or waits. The client queues its writes, so the writer never meets another one; a
transaction a failed write left open is rolled back before the next one begins. A web view that reloads leaves handles
behind; opening the same copy from the new page load drops them, rolling back what they left open, while overlapping
opens of one page load keep their handles. Sign-out closes the copy and then deletes its files, refused while any
connection (a read still running) uses them; a delete and an open of the same file never interleave. A sign-out while
the copy is still opening waits for it to be closed again before deleting it. The first time the desktop app opens a user's copy,
drafts that exist only in the old IndexedDB copy (unsaved edits, messages whose outcome is pending) are carried over,
each removed from the old copy once moved, and then that copy is removed.

The desktop app notifies natively about mail that needs the user: a message that is unread, in an inbox and judged
`do` or `delegate` for the first time, whether it arrived judged or was classified later (the mail store's `apply`
records these arrivals, `apps/web/src/mail/mailNotifications.ts` words them). Nothing pops up for the mail synced at
start or while the app has focus, nor for old mail reaching the device late (received more than 15 minutes before
notifications were switched on), and more than three at once become one summary. The browser build shows none: the
browser is meant to get Web Push from the server (see the table above), which is not built yet.

The mobile app (Expo Router, screens in `apps/mobile/src/app`) is the `mobile` OAuth client with the same redirect
URI. expo-auth-session opens the authorization page in the system's authentication browser
(ASWebAuthenticationSession on iOS, Custom Tabs on Android) with PKCE and checks the state; the code is exchanged
through `packages/core`. `Session` (`apps/mobile/src/auth/session.ts`, plain TypeScript, unit tested) keeps the tokens
in expo-secure-store (Keychain on iOS, Keystore-encrypted on Android) as three entries, because some iOS releases
refuse values above about 2 KB, refreshes them before they expire with one shared request (refresh tokens rotate),
and never lets a refresh that finishes after sign-out sign the user back in.

Mail on the phone: the mobile app keeps a SQLite copy (expo-sqlite, one database per user) of
the mail accounts, folders, threads and messages from the delta, with a summary per thread as the web client does
(`summarizeConversation` in `packages/core`), each page applied in one exclusive transaction. The same `SyncEngine`
pulls it and the same `RealtimeClient` triggers pulls while the app is in the foreground; in the background the
connection rests and the copy catches up when the app comes back. `MailSession` runs this lifecycle one switch at a
time, awaiting every step: a copy is deleted only after it is closed (on sign-out, or when another user signs in),
and a small index database lists every copy on the device, so one that could not be deleted is removed the next
time. The last signed-in user is remembered, so their copy opens before the server confirms who is signed in, also
offline. The copy is the same `SqliteMailStore` the desktop app uses (see below). Message bodies show in a web view with the same Content-Security-Policy as
the web client's frame; only links the reader taps open, in the browser, and remote images load only when the
reader asks. Opening a conversation on the phone reads it, and its action bar archives, trashes, marks unread and
flags it, with the same exact undo as the web client: the way an action changes messages (`actionResult`) and how to
undo it (`undoPlan`) live in `packages/core`, and `MailSession.act` applies it to the copy first and puts back what
the server did not take.

Writing mail on the phone works as on the web, through the same code in `packages/core`: `Composer` (autosave and
upload, sending after a few seconds so "Undo" can stop it or at a chosen time, conflicts with another device, and what
became of a sent message, announced once), `DraftSync` (one upload per draft at a time; another device's newer version
is never overwritten) and the helpers for replies, forwards, send-later times and the send request. The web client's
Pinia store and the phone's `ComposerProvider` only wrap it. The compose screen (`apps/mobile/src/app/compose.tsx`)
has the sending account, recipients as chips with suggestions from the people in synced mail, Cc and Bcc, the subject,
the signature (changeable, following the account) and the quoted original kept apart from the text. The text is
written in tiptap, as on the web, running in a web view: `apps/mobile/editor/editor.ts` is bundled by
`npm run build:editor` into a generated module (CI checks it is current) and talks to the screen through JSON messages
(`editorProtocol.ts`); its page has a Content-Security-Policy that allows no connection, and links may only be web
and mail addresses. Leaving the screen keeps the draft; Cancel asks whether to keep or delete one with content.

Shared TypeScript lives in `packages/core` (MIT): domain types, the OAuth flow, and the client side of the sync
protocol, so every client syncs the same way:

- `SyncEngine` pulls the delta into a `SyncStore` (each page and its cursor in one transaction). Pulls never overlap;
  a request during a pull makes it go round once more.
- `RealtimeClient` reads the hub through `fetch()` with the token as bearer, reconnects with backoff, renews the token
  before it expires, and asks for a pull on every (re)connect, because missed notifications are not replayed.
- `mailFrameDocument` wraps a sanitized body in the document for the sandboxed frame, with its Content-Security-Policy.

Every client keeps a copy of the user's mail behind one interface, `MailStore` in `packages/core`: the browser in
IndexedDB (`apps/web/src/mail/db.ts`), the desktop app and the phone in SQLite (`SqliteMailStore` in
`packages/core/src/store/sqlite.ts`, behind a small `Sql` interface each client implements). One database per user,
deleted on sign-out after it is closed. Next to the synced records each stores a summary per thread (senders,
snippet, unread count, whether it is in an inbox), recomputed whenever one of its messages changes, so the inbox lists
read a few dozen rows through an index however much mail is stored, plus the people the user exchanged mail with and
the drafts. How arrivals, contacts and drafts follow the delta is shared (`packages/core/src/store/rules.ts`), and
both stores run the same tests (`describeMailStore`, exported as `@tresso/core/testing`; the SQLite store on sql.js).

The SQLite store writes one change at a time (a device may give each exclusive transaction its own connection without
a busy timeout), refuses writes once closing and waits for the ones under way, and stores each page with its cursor
in one transaction. A copy made by another schema version is dropped and rebuilt, keeping the drafts that exist only
on the device. A resync (after a change the server refused could not be put back) makes the next pull start from the
beginning, and holds across a restart and against a pull that was already running.

Both stores keep the smart inboxes and list a smart inbox across all accounts through an index on smart inbox,
whether the conversation is in an inbox or junk folder, and date (`ListScope`: an inbox or a smart inbox).
Conversations in the Spam smart inbox stay out of the inboxes, also when the server left them there
(`summarizeConversation`). A correction shows at once (`classifyThread`) and is put back when the server refuses
(`restoreThread`), unless the delta changed the conversation since. In the web client the sidebar lists the smart
inboxes with unread counts, the list and the reading pane show the verdict and change it or move the conversation,
and Settings > Smart inboxes edits them and lists the remembered senders. The IndexedDB copy moved to version 5 and the
SQLite copy to schema 3; both sync again from the start.

Drafts in the web client are saved on the device first and uploaded right after (one upload at a time per
draft). Edits made here are never overwritten by the delta: when another device saved a newer version, the compose
window asks whether to keep this version or take the other one. Drafts written before they were synced are
uploaded after the update.

Message actions in the web client: opening a conversation reads it; the conversation's buttons archive, trash, mark
unread, flag and move it (shortcuts E, #, U and S, not while typing or writing), and archiving, trashing or moving
goes back to the list with an undo. The undo moves back only what the action moved, each message from where it went
into the one folder it came from (`fromFolderId` on the move); for a message that was in several folders (Gmail's
All Mail aside) no exact undo exists, so none is offered. Each action changes the local copy first
(`applyAction` of the mail store), so lists update at once; when the server does not take it (for a large selection, the
batches it did not take), it is put back, except on messages the delta updated since.

Bulk actions in the web client: conversations are selected with their checkboxes (shift selects a range), by age
from the list's Select menu, or as everything from the senders of the selected ones; the last two search the whole
mailbox on screen in the local copy, not only the part of the list that is loaded. A bar above the list then marks
read or unread, archives, moves (within one account) or trashes every message of the selected conversations, with the
same exact undo as a single conversation (`apps/web/src/mail/useMessageActions.ts`); K, E, # and Escape work while a
selection exists (not in menus or fields), and the conversation's own shortcuts wait. Conversations that leave the
mailbox meanwhile (sync from another device) drop out of the selection, and the undo puts back what it exactly can,
saying how many messages that were in several folders it leaves.

Writing mail in the web client: reply and reply all pick recipients from Reply-To, To and Cc without the user's own
addresses, and quote the original (helpers in `packages/core/src/compose.ts`, shared with mobile). Every message is
sent with a `sendAt` a few seconds ahead, so "Undo" can cancel it; "Send later" picks a time. The draft is saved in
the local copy while typing and kept until the message is confirmed sent, so a failed message can be edited and sent
again; the outcome of each sent message is announced once. Recipient suggestions come from the people in synced
mail, stored locally per user.

Attachments: files are attached to the server's copy of a draft (`POST /api/v1/drafts/{id}/attachments`, multipart,
field `file`), so the draft goes up first; a forward takes the original's files along
(`/forwarded-attachments`, without storing them again; inline images stay part of the text, malware is refused).
Files live in object storage under their SHA-256 (uploads under `uploads/`), so the same file is stored once; they go
out with the message and into the Drafts folder copy, within `MAIL_MAX_ATTACHMENT_BYTES` together (18 MB by default,
about 25 MB once encoded). A file with malware is not sent. Drafts in the delta carry their files without links,
which expire; `GET /api/v1/drafts/{id}` has fresh signed ones, and the content is served like a received
attachment's. An upload no draft refers to any more is removed ten minutes later, under a lock on its key that
uploading takes too. Sending keeps the draft on the server, out of the clients' sight, until its message went out:
an undo or a failure brings it back as it was, version and files included, so the device can go on writing it; once
the message is sent the draft goes. The web client attaches by button or by dropping files on the form; the phone
picks files (device or cloud drive) and photos (HEIC as JPEG), and shows the files of received messages.

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
- **Calendar object** (`calendar_object`, internal): one iCalendar object as a provider stores it in one resource,
  every `VEVENT` with one `UID` (a series with its overrides, or a single event). Its complete iCalendar text is the
  truth: Tresso edits the parsed object and serializes it again, so properties it does not model (`X-` properties,
  `VTIMEZONE`s, `ATTACH`, `VALARM`s of other clients) survive an edit. Provider state: `remote_id` (CalDAV href,
  Google or Graph event id) and `etag`.
- **Event** (`calendar_event`, synced) is the projection of one `VEVENT` of an object: a single event, the master of
  a recurring series, or an override of one occurrence. Rows are only written by projecting their object, so they
  always agree with its text. Unique on `(calendar_id, uid, recurrence_key)`. Fields:
  - `uid`, `sequence`, `recurrence_key` (the original start of the overridden occurrence as a UTC instant
    `YYYYMMDDTHHMMSSZ`, or the date `YYYYMMDD` for all-day series; empty on masters and single events; clients see it
    as `recurrenceId`), `status` (`confirmed`, `tentative`, `cancelled`).
  - `summary`, `description`, `location`, `url`, `transparency` (`busy`/`free`), `visibility`
    (`public`/`private`/`confidential`).
  - Time: `start` and `end` as wall-clock values with their IANA time zone (`start_tz`, `end_tz`; null means floating),
    or dates when `all_day` is set (`DTEND` exclusive, as in RFC 5545). `DURATION` is converted to an end on ingest.
    Windows time zone names from Exchange and Outlook are mapped to IANA names on ingest; unknown `VTIMEZONE`
    definitions are matched by their rules, and the original definition stays in the stored iCalendar.
  - Recurrence: `rrule` (the `RRULE` value as text), `rdates` and `exdates` (lists of recurrence keys, so they
    compare with overrides as plain strings).
  - Two UTC columns only for range queries: `starts_at` and `ends_at` of the first occurrence, and `series_ends_at`
    (end of the last occurrence; null when the series never ends). All-day and floating events are stored with
    UTC as their zone in these columns and are widened by 14 hours on each side when queried, then filtered in the
    reader's zone.
  - `organizer` and `attendees` (JSON: address, name, role, `partstat`, `rsvp`, kind), plus `self_partstat`, the
    response of the account owner, kept as a column so "needs action" can be found and indexed.
  - `reminders` (JSON list of `VALARM`s: trigger relative to start or end, or absolute, and action) and
    `conference` (JSON: provider `teams`, `meet`, `zoom`, `webex` or `other`, join URL, conference id, dial-in), read
    from RFC 7986 `CONFERENCE`, `X-MICROSOFT-*`/`X-GOOGLE-CONFERENCE`, Graph `onlineMeeting`, Google
    `conferenceData`, or a join link in the location, URL or description.
  - `message_id`: the invitation this event came from, when it came by mail; `sync_error`: why the last change did
    not reach the provider.
- Parsing, serializing and recurrence expansion use `sabre/vobject` (iCalendar and RRULE per RFC 5545, Windows
  zone mapping). Clients expand recurrence for their local copy with `ical.js` in `packages/core`; the API's range
  endpoint is the reference both are tested against.

#### API

Scopes `calendar:read` and `calendar:write`, in the user scopes of the first-party clients.

| Call | Purpose |
|---|---|
| `GET /api/v1/calendars` | The user's calendars. `PATCH` changes the user's color, visibility and the default calendar, and the name of local calendars (provider calendars are renamed at the provider). `POST`/`DELETE` only for local calendars. |
| `GET /api/v1/calendar-occurrences?from&to&timeZone&calendarId[]` | Occurrences in a range (at most 400 days), recurrence expanded: per occurrence the event id, the `recurrenceId`, start, end and the event's fields, overrides applied and exceptions removed. |
| `GET /api/v1/calendar-events/{id}` | One event as stored (series or override). |
| `POST /api/v1/calendar-events` | Create in a writable calendar. |
| `PUT /api/v1/calendar-events/{id}?scope=&recurrenceId=&notify=` | Save the editor's complete event; for a series `scope` is `this`, `following` or `all`, and `recurrenceId` the occurrence the user edited. A different `calendarId` moves the event (whole series only). |
| `DELETE /api/v1/calendar-events/{id}?scope=&recurrenceId=&notify=` | Delete with the same scopes. |
| `POST /api/v1/calendar-events/{id}/response` | RSVP: `accepted`, `tentative` or `declined`, optional comment, for the series or one occurrence, optionally without telling the organizer. |

Edits of recurring events follow what Google Calendar, Outlook and Apple Calendar agree on:

- **This occurrence:** an override with the occurrence's `recurrence_id` is created or changed. Deleting one adds an
  `EXDATE` and removes its override.
- **This and following:** the series is split. The original gets an `UNTIL` just before the occurrence (a `COUNT`
  is converted to the equivalent `UNTIL`); a new series with a new `UID` starts at the occurrence with the edited
  fields and takes over the later overrides and exceptions, moved by the same time shift. Deleting cuts the
  series with `UNTIL`.
- **All:** the master changes by the shift the user gave the edited occurrence. That shift also moves every
  override's `recurrence_id` and every `EXDATE` and `RDATE`, so exceptions stay attached to their occurrences;
  overrides keep their own changed fields. A changed recurrence rule, or a switch between all-day and timed, starts
  the series fresh: its overrides and exceptions belong to occurrences that no longer exist and are dropped.
- Every change to time, place, status or the recurrence raises `SEQUENCE`, which is what tells attendees' clients
  that an update supersedes the previous one (RFC 5546). Which iTIP messages a change needs (requests to new and
  current attendees, cancellations to removed ones, a reply to the organizer) is decided by sabre/vobject's iTIP
  broker from the object before and after the change.

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
- Reminders are scheduled and sent by the server (next section), so they reach every device, also when no app is
  open.

#### Reminders and notifications

Reminders are a reason to use Tresso over a default calendar: they fire on every device the user has, also when no
app is open, they know about travel and quiet hours, and they bracket a meeting with a brief before and a follow-up
after.

- **What can remind:**
  - Event reminders: any number per event, each with an offset (or absolute time) and its own channels. Stored as
    `VALARM`s in the event's iCalendar, so other clients see them too; the channels ride along as
    `X-TRESSO-CHANNELS` (an `ACTION:EMAIL` alarm means email). An event without own reminders, and not marked
    "no reminders", gets the user's **default rules**.
  - Default rules (`reminder_rule`, per user): which reminders an event gets when it has none, by calendar and by
    kind of event: timed or all-day, with or without attendees, organized or invited, with an online meeting, with a
    physical location. The most specific matching rule wins. New users start with 10 minutes before timed events
    (push) and 09:00 the day before all-day events (push).
  - **Leave now:** for events with a physical location, optional per rule or event: the reminder fires at
    start minus travel time minus a margin. Travel time is either fixed by the user per event ("30 minutes") or
    estimated by a `TravelTimeEstimator` from the user's last known location (shared by mobile and desktop only
    when the user allows it, kept as one coarse point with its time) and the event's location. The open edition
    implements the estimator with a self-hostable OSRM routing server and a Nominatim geocoder when they are
    configured (`ROUTING_OSRM_URL`, `GEOCODER_NOMINATIM_URL`), and without them only offers fixed travel times.
    The estimate is refreshed when the location changes and an hour, then 15 minutes, before the planned time.
  - **Pre-meeting brief:** for events with attendees, optional per rule, a few minutes before the start: who is
    coming and the recent threads with them. The open edition writes the brief from mail metadata (threads with the
    attendees in the last 60 days, their last messages); Tresso Cloud adds an AI summary through
    `MeetingBriefWriter`.
  - **Follow-up nudge:** after a meeting with attendees ends, optional per rule: a nudge to write notes (a private
    note on the event, never sent to the provider) and, once todos exist (phase 2), to turn them into todos.
- **Channels:** web push (VAPID, RFC 8030/8291, for the web app and browsers that run it), mobile push through
  Expo's push service (APNs/FCM), desktop through the realtime connection while the desktop app runs (the app shows
  a native notification), and email (from the system sender `NOTIFICATIONS_FROM`). Devices register themselves
  (`POST /api/v1/notification-devices` with their kind and push subscription or Expo token); a subscription the
  push service reports gone is removed.
- **Quiet hours and focus:** per user, days and times in their zone during which notifications are delivered
  silently (listed, no sound or banner), and focus rules: while an event marked busy is running, only reminders of
  the user's own meetings and leave-now alerts break through. A reminder whose event starts after quiet hours end is
  moved to the end of the quiet hours instead.
- **Scheduling:** a planner keeps `scheduled_notification` rows for the next 8 days of occurrences: per occurrence,
  reminder and kind, with its fire time. It replans an event whenever its rows are projected, and a rolling sweep
  extends the window. Each row is announced as a delayed Messenger message for its fire time (transport `push`),
  dispatched after commit, like the release of scheduled mail. When it fires, the handler re-checks that the row is
  still current (the event may have moved since), applies quiet hours and focus, and delivers. A sweep every
  minute (Symfony Scheduler) fires rows that are due and not delivered, so a lost message only delays a reminder.
- **Idempotent delivery:** every delivery is recorded per device (`notification_delivery`, unique on the scheduled
  notification and the device), so a retried message or a sweep never notifies a device twice. Clients dismiss or
  snooze through the API; a dismissal on one device clears the notification on the others.
- `GET /api/v1/notifications` lists recent and upcoming notifications for the in-app list; reminder rules, quiet
  hours, focus rules and devices are managed under `/api/v1/notification-settings` and
  `/api/v1/notification-devices` (scopes `settings:read` and `settings:write`).

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
| `MeetingBriefWriter`: a brief before a meeting (who, previous threads, open todos, attachments) | attendees and the recent threads with them, from mail metadata | AI summary of the related mail and todos |

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
