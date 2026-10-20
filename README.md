# Tresso

[![CI](https://github.com/gettresso/tresso/actions/workflows/ci.yml/badge.svg)](https://github.com/gettresso/tresso/actions/workflows/ci.yml)

**One T, three ways out.** Tresso is an email client built around a single idea: every email is something
to **do**, to **hand over**, or to **know**.

| Verdict | What happens | Done when |
|---|---|---|
| **Do** | It becomes a todo, linked to the email, with a due date when there is one | The todo is completed and the email archives itself |
| **Hand over** | It goes to someone else with a note and a due date | They finish it; until then it sits in *Waiting on* |
| **Know** | It lands in the daily brief | One click on *Noted* |

This repository is the open source edition: a complete, self-hostable email client for one person.

## Highlights

- **Unified Inbox** across all your accounts, grouped into smart inboxes (To do, Informational, Notifications,
  Newsletters, Invitations, Spam) plus your own, defined by a name, instructions and sender include/exclude lists.
- **Built-in todos** that sync across devices and can be created automatically from email.
- **Triage mode**: one email at a time, settled with D, H or K.
- **One account, every device**: settings, mail accounts and HTML signatures stay in sync.
- **Attachment virus scanning** before anything can be opened.
- **Everywhere**: web, desktop (Tauri) and mobile (Expo), with notifications only for mail that needs you.

## Open source and Tresso Cloud

Tresso is open core. This repository has everything you need to run Tresso yourself. **Tresso Cloud**, the hosted
service, adds AI classification and summaries, teams with shared inboxes and delegation, API keys, webhooks and an
MCP endpoint for other AIs, developer tools, statistics and Year in mail.

Cloud features are add-ons: the open code never depends on them, and you can build your own add-ons the same way.
See [Open core](docs/ARCHITECTURE.md#open-core).

## Status

Phase 0 (foundation) is done: the architecture, the Docker stack, the API with its OAuth2 server and domain schema,
the sync worker that leases accounts, and the web, desktop and mobile shells. Mail sync itself starts in phase 1.
See the [roadmap](docs/ROADMAP.md).

## Stack

| Part | Technology |
|---|---|
| API | PHP 8.5, Symfony 8.1 on FrankenPHP (worker mode), Doctrine, Messenger, OAuth2 server |
| Sync workers | Go 1.27 |
| Web | Vue 3, Vite, TypeScript, Pinia |
| Desktop | Tauri 2 (Rust) wrapping the web app |
| Mobile | Expo (React Native) |
| Data | PostgreSQL 18, Redis, S3 storage (RustFS locally), Meilisearch |
| Scanning | ClamAV |

## Repository

```
apps/
  api/        Symfony API: OAuth2 server, domain, background jobs
  sync/       Go sync workers
  web/        Vue 3 web client
  desktop/    Tauri 2 shell around apps/web
  mobile/     Expo app
packages/
  core/       Shared TypeScript: domain types, OAuth2 PKCE client
docker/       Dockerfiles and server config
docs/         Architecture and roadmap
```

## Getting started

You need Docker, Node 24 or newer, and Rust for the desktop app. Go and PHP run inside containers.

1. Start the stack:

   ```bash
   docker compose up -d
   ```

   On first boot the API installs its dependencies, generates OAuth2 signing keys, runs the migrations and creates
   the first-party OAuth2 clients (`web`, `desktop`, `mobile`, `sync-worker`).

2. Create a user (you are asked for a password):

   ```bash
   docker compose exec api php bin/console app:user:create you@example.com "Your Name"
   ```

3. Install the JavaScript workspaces and start the web app:

   ```bash
   npm install
   ```

   ```bash
   npm run dev:web
   ```

   Open http://localhost:5173 and sign in.

The desktop and mobile apps start with:

```bash
npm run dev:desktop
```

```bash
npm run dev:mobile
```

### Gmail and Microsoft 365

Connecting Gmail and Microsoft 365 accounts needs an OAuth app at Google and at Microsoft, registered by whoever runs
the server. [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#registering-the-oauth-apps) lists the steps. In short:

- Redirect URIs: `http://localhost:8080/connect/google/callback` and `http://localhost:8080/connect/microsoft/callback`
  for the Docker stack (`<API_PUBLIC_URL>/connect/<provider>/callback` elsewhere).
- Scopes: Google `openid email profile https://www.googleapis.com/auth/gmail.modify` (with the Gmail API enabled);
  Microsoft Graph delegated `openid email profile offline_access User.Read Mail.ReadWrite Mail.Send`.
- Put the client ids and secrets in `apps/api/.env.local`:

  ```dotenv
  GOOGLE_OAUTH_CLIENT_ID=...
  GOOGLE_OAUTH_CLIENT_SECRET=...
  MICROSOFT_OAUTH_CLIENT_ID=...
  MICROSOFT_OAUTH_CLIENT_SECRET=...
  MICROSOFT_OAUTH_TENANT=common
  ```

Without them the web app only offers IMAP accounts.

### Local services

| Service | Address |
|---|---|
| API | http://localhost:8080 (health check at `/health`) |
| Web app | http://localhost:5173 |
| Mailpit (outgoing mail) | http://localhost:8025 |
| RustFS (S3) | http://localhost:9000, console on 9001 |
| Meilisearch | http://localhost:7700 |
| Postgres | `localhost:54320` (user, password and database: `app`) |
| Redis | `localhost:63790` |
| GreenMail (test mail server) | IMAP `localhost:3143`, IMAPS 3993, SMTP 3025, SMTPS 3465 |

The sync worker in the dev stack has `MAIL_ALLOW_UNSAFE_SERVERS=1` so it can reach GreenMail on the Docker network;
in production that must stay off. The integration tests empty the `kim@tresso.test` mailbox.

GreenMail has two mailboxes for development, `kim@tresso.test` (password `dev-password-kim`) and `lisa@tresso.test`
(`dev-password-lisa`). Connect them in the app with host `greenmail`, IMAP port 3143 and SMTP port 3025, security
`none`.

ClamAV needs about 1.5 GB of memory, so it only starts when asked. `CLAMAV_HOST` points the API at it; without it,
attachments are marked as not scanned and open only after a warning. `ATTACHMENTS_REQUIRE_SCAN=1` removes that
override (and then needs a scanner: the health check fails without one). Scans run in their own worker, `scanner`.

```bash
CLAMAV_HOST=clamav docker compose --profile scan up -d
```

After a signature update, or once clamd is back after scans failed, scan attachments again with
`php bin/console app:attachments:rescan --all` (or `--user=<email>`, optionally `--status=failed`).

## Development

Create the test database once:

```bash
docker compose exec -e APP_ENV=test api sh -c 'php bin/console doctrine:database:create --if-not-exists && php bin/console doctrine:migrations:migrate -n'
```

Then run the checks for the part you changed:

| Part | Command |
|---|---|
| API tests (per file) | `docker compose exec -e APP_ENV=test api php bin/phpunit tests/Functional/SyncLeaseTest.php` |
| API static analysis | `docker compose exec api sh -c 'bin/console cache:warmup --env=test && vendor/bin/phpstan analyse'` |
| API docs (dev only) | http://localhost:8080/docs |
| Sync worker | `docker run --rm -v "$PWD/apps/sync":/src -w /src golang:1.27 go test -race ./...` |
| Sync worker against GreenMail | `docker run --rm --network tresso_default -e GREENMAIL_HOST=greenmail -v "$PWD/apps/sync":/src -w /src golang:1.27 go test -race -tags integration ./...` |
| Shared core | `npm test --workspace @tresso/core` |
| Web | `npm run test:unit --workspace @tresso/web` and `npm run type-check --workspace @tresso/web` |
| Desktop | `cargo check` in `apps/desktop/src-tauri` |
| Mobile | `npx vitest run`, `npx tsc --noEmit` and `npx expo lint` in `apps/mobile` |

Every push and pull request runs all of these in [CI](.github/workflows/ci.yml).

Commits follow [Conventional Commits](https://www.conventionalcommits.org/) with a required scope from
[`.agents/commit-scopes.md`](.agents/commit-scopes.md), for example `feat(api): add todo endpoints`.

### Good to know

- FrankenPHP keeps the app in memory. After clearing the cache inside the container, restart it with
  `docker compose restart api`.
- After `compose.yaml` changes (new environment variables, for example), recreate the services with
  `docker compose up -d`: `restart` keeps a container's old environment.
- Secrets in `compose.yaml` and the committed `.env` files are development values only. Real secrets go in
  `.env.local` files or the deployment environment. In production set at least `APP_SECRET`, `OAUTH_PASSPHRASE`,
  `OAUTH_ENCRYPTION_KEY`, `SYNC_WORKER_CLIENT_SECRET`, `DATA_KEYS` and `DATA_KEY_CURRENT`, the Mercure hub
  settings (`MERCURE_PUBLIC_URL`, `MERCURE_INTERNAL_URL`, `MERCURE_CORS_ORIGINS` and two different keys of at least
  32 bytes in `MERCURE_PUBLISHER_JWT_KEY` and `MERCURE_SUBSCRIBER_JWT_KEY`), the object storage settings
  (`S3_ENDPOINT`, `S3_REGION`, `S3_BUCKET`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`), and keep `MAIL_ALLOW_UNSAFE_SERVERS=0`.
- Desktop and mobile sign in through the `tresso://auth/callback` scheme: the system browser signs in and the link
  brings the result back to the app. Windows and Linux register the scheme when `npm run dev:desktop` starts; macOS
  only takes it from an app bundle, so to sign in there build one with
  `npm run tauri --workspace @tresso/desktop -- build --debug --bundles app` and open the `.app` it reports.
- The mobile app signs in only from a development build (`npx expo run:ios` or `npx expo run:android` in
  `apps/mobile`): Expo Go would need an `exp://` redirect, which the authorization server does not accept. The iOS
  simulator reaches the API on `localhost`; for the Android emulator or a phone set `EXPO_PUBLIC_API_URL` in
  `apps/mobile/.env.local` (`http://10.0.2.2:8080`, or the computer's address on the network).

## Documentation

- [Architecture](docs/ARCHITECTURE.md): components, open core, auth, sync protocol, processing pipeline, scaling
- [Roadmap](docs/ROADMAP.md): phases from foundation to native apps

## License

Tresso is licensed under the [GNU Affero General Public License v3.0](LICENSE). The shared libraries in `packages/`
are [MIT licensed](packages/core/LICENSE), so you can use them in your own projects.
