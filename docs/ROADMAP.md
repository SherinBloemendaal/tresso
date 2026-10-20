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
- [x] 1.6 Gmail and Microsoft 365: OAuth connection, Gmail API and Microsoft Graph sync
- [x] 1.7 Message actions: read, flag, archive, trash and move from the clients, made on the server by the worker

## Calendar

Built next to phase 1, each step its own pull request. See "3.13 Calendar" in the architecture.

- [x] C1 Architecture: data model, sync sources, invitations, client sync, reminders and notifications, online
  meetings, AI interfaces
- [x] C2 Calendar core: calendars (provider and local Tresso calendar) and events with RFC 5545 recurrence,
  overrides, exceptions, time zones, attendees, reminders and conference data; occurrences in a range; create, edit
  and delete with "this / this and following / all"; RSVP with iTIP replies through the outbox; calendars and
  events in the delta; `text/calendar` parts parsed by the worker and applied as invitations; CalDAV sync for IMAP
  accounts (discovery, sync-token or ctag, outbound writes, a calendar status per account)
- [x] C3 Reminders and notifications: server-side scheduling with a sweep, idempotent delivery per device through a
  dispatcher other features share, phone (Expo), desktop (web push and the desktop app) and email channels with
  the user's channels per kind, multiple reminders per event with their own channels, default rules per calendar
  and kind of event, important events, leave-now with a fixed travel time or the phone's estimate by travel mode
  (the position never stored), quiet hours, working hours for work calendars, focus blocks, snooze choices,
  pre-meeting briefs and follow-up nudges
- [ ] C3b Digests and attendance: the email digest channel with tomorrow at a glance (end of the working day) and the
  week ahead, warnings when the user will be late with an offer to tell the organizer, and asking about meetings the
  user did not join (reschedule or let the organizer know)
- [ ] C4 Calendar clients: day, week, month and agenda views and the event editor in the web client, the invitation
  card with RSVP in the reading pane, a mini agenda next to mail, notification settings and push registration;
  agenda and event detail on mobile with Expo push, on shared types and recurrence expansion in `packages/core`
- [ ] C5 Google and Microsoft calendars and online meetings: Google Calendar API and Microsoft Graph calendar sync
  through the provider OAuth layer (1.6), Teams meetings through Graph, Google Meet through `conferenceData`, join
  buttons for every recognized meeting link
- [ ] C6 Calendar assistant interfaces in the open edition with the deterministic meeting time finder

Tresso Cloud: natural language event creation, meeting time suggestions with attendees' availability, events and
deadlines extracted from mail, meeting briefs.

## Phase 2: Do, hand over, know

Delivered in steps, each its own pull request:

- [x] 2.1 Smart inboxes and rule-based classification: built-in smart inboxes (spam, notification, advertise, todo,
  informational) and custom ones (name, instructions, include/exclude lists, verdict mapping), a classifier interface
  with a rule-based implementation run after ingest, the smart inbox and verdict per conversation in the delta,
  corrections remembered as sender rules, smart inboxes in the web client's sidebar, lists and settings
- [x] 2.2 Todos: synced todos with a due date, time and duration, "Make todo" from an email (one todo per email),
  automatic todos per smart inbox (only with the Do verdict), a Todos page with quick add and sections on the web,
  and a todo list on mobile

Still to come in this phase:

- Todos in the calendar: a setting puts todos with a deadline in the calendar as busy time, so nobody books a meeting
  over them; choosing a deadline or a time shows how busy each day and hour already is; a todo gets a duration
  ("takes 30 minutes"), and "Plan automatically" picks the best free slot before the deadline with the meeting time
  finder of C6 (working hours, focus blocks and travel time respected), moving it when the calendar changes
- Hand over by forwarding with a note and due date, tracked in "Waiting on" with nudges
- Triage mode (one email at a time, D / H / K)
- Daily brief for `know` mail, one-click "Noted"
- Batched delivery for advertise and notification mail, one-click unsubscribe

Tresso Cloud: AI classification with feedback learning, automatic todo extraction, AI summaries in the daily brief.

## Phase 3: Native apps

- [x] 3.1 Desktop sign-in: the system browser signs in, `tresso://auth/callback` brings the result back, tokens in the
  OS keychain
- [x] 3.2 Desktop notifications: native notifications for new unread inbox mail judged `do` or `delegate`
- [x] 3.3 Mobile sign-in: Expo Router app shell, the system's authentication browser signs in through
  `tresso://auth/callback`, tokens in the OS secure store
- [x] 3.4 Mobile mail: a SQLite copy kept current by the delta and realtime, the Unified Inbox and conversations with
  their messages
- [x] 3.5 Tauri desktop with a local SQLite cache instead of the web view's IndexedDB
- [x] 3.6 Mobile message actions: read on open, archive, trash, unread and flag, with the exact undo
- [ ] 3.7 Mobile compose and push (only `do` and `delegate` by default)

Tresso Cloud: teams, shared inboxes, assignments, internal comments, delegation rules, workload.

## Phase 4: Safety and housekeeping

- [x] 4.1 ClamAV attachment scanning and quarantine: clamd over INSTREAM in a Messenger job per attachment, verdicts
  cached by content hash, infected content moved to quarantine, the message flagged through the delta sync and
  realtime, no downloads before a scan, rescans from the console, scan states on the web client's attachments
- [x] 4.2 Bulk actions in the web client: select conversations (checkboxes, shift for a range), act on all of them,
  select everything from their senders or older than an age
- [ ] 4.3 Bulk management by smart inbox, cleanup suggestions (after 2.1)

Tresso Cloud: API keys and third-party apps, webhooks, MCP endpoint, developer tools, statistics, Year in mail.
