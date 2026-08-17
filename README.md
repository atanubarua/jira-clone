# Jira Clone

A multi-tenant, Jira-style issue tracker built as a SaaS.

The v1 product specification — modules, entities, business rules, the status
lifecycle decision, the permission model, and every assumption made — lives in
[SPEC.md](SPEC.md). **Read that first.**

---

## Stack

| Layer | Choice | Version installed |
|---|---|---|
| Framework | Laravel | 13.25.0 |
| PHP | PHP (Sail runtime) | 8.4 |
| Database | MySQL | 8.4 LTS |
| Cache / queues / sessions | Redis | alpine |
| Frontend | Inertia + React + TypeScript | inertia-laravel 3.3.1, React 19.2, TS 5.7 (strict) |
| Styling | Tailwind + shadcn/ui | Tailwind 4 |
| Auth | Laravel Fortify | 1.38.0 |
| Mail (local) | Mailpit | latest |
| Tests | Pest | see `composer.json` |
| Formatting | Pint | 1.30.5 |
| Static analysis | Larastan | 3.10.0 |

Scaffolded from the official `laravel/react-starter-kit` (`dev-main`), which
targets Laravel `^13.17`.

---

## Where this project lives

**Inside WSL2**, at `~/projects/jira-clone` on the Linux filesystem — *not* on
`D:\`.

This is deliberate. Docker on Windows runs containers inside WSL2, so
bind-mounting code from an NTFS drive crosses the Windows↔WSL filesystem
bridge. PHP reads thousands of small files per request, and that bridge turns
~50 ms page loads into 1–3 s. Keeping the code on ext4 avoids the penalty
entirely.

Open it from Windows via `\\wsl.localhost\Ubuntu\home\atanu\projects\jira-clone`,
or from your editor's WSL remote mode (preferred — much faster indexing).

---

## Running it

All commands run from `~/projects/jira-clone` inside WSL.

Start the stack:

```bash
./vendor/bin/sail up -d
```

Stop it:

```bash
./vendor/bin/sail down
```

A shell alias makes this less tedious — add to `~/.bashrc`:

```bash
alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'
```

### Ports

Host ports 80, 3306, and 6379 were already taken on this machine — by Herd's
nginx, the `url_shortener-db` container, and the `wallet-redis` container
respectively. This stack publishes on different host ports so everything
coexists and **Herd keeps serving your other sites normally**.

| Service | URL / host port | In-container |
|---|---|---|
| App | http://localhost:8080 | 80 |
| Vite dev server | 5173 | 5173 |
| MySQL | 3307 | `mysql:3306` |
| Redis | 6380 | `redis:6379` |
| Mailpit UI | http://localhost:8025 | 8025 |
| Mailpit SMTP | 1025 | 1025 |

The `FORWARD_*` variables in `.env` control only what is published to the host.
Inside the Docker network the app still reaches `mysql:3306` and `redis:6379`,
so `DB_PORT` and `REDIS_PORT` stay at their defaults.

To connect a GUI client (TablePlus, etc.) to the database, use
`127.0.0.1:3307`, user `sail`, password `password`, database `jira_clone`.

---

## Common commands

```bash
./vendor/bin/sail artisan migrate
```

```bash
./vendor/bin/sail npm run dev
```

```bash
./vendor/bin/sail test
```

```bash
./vendor/bin/sail pint
```

```bash
./vendor/bin/sail php vendor/bin/phpstan analyse
```

The starter kit also ships a combined gate:

```bash
./vendor/bin/sail composer ci:check
```

---

## Testing against MySQL, not SQLite

`phpunit.xml` points the test suite at the **MySQL `testing` database**, which
the MySQL container creates automatically on first boot.

The starter kit's default is SQLite `:memory:`, which is faster — but SPEC
depends on MySQL-specific behaviour that SQLite does not reproduce:

- **Issue key allocation** (SPEC Module 3, rule 1) relies on
  `SELECT … FOR UPDATE` row locks to stay correct under concurrency.
- **Search** (SPEC Module 5) relies on MySQL `FULLTEXT` indexes.

Testing those on SQLite would pass while proving nothing.

---

## Notes on tooling

- **TypeScript is 5.7 with `strict: true`.** npm's `latest` tag for
  `typescript` currently resolves to 7.x (the rewritten native compiler), but
  the starter kit pins the 5.x line, which is what its ESLint and shadcn
  toolchain support. This resolves SPEC assumption A-2.
- **Larastan runs at level 7**, not the level 5 named in SPEC. The starter kit
  ships level 7 and passes at it; level 5 is a strict subset, so keeping 7 is
  free rigour. To drop it, change `level:` in `phpstan.neon`.
- **Composer inside WSL** is the native Linux build at `/usr/local/bin/composer`.
  Previously WSL fell back to the Windows binary via `/mnt/c`, which made
  `composer install` roughly an order of magnitude slower.

---

## Next step

No application code has been written yet — this is the scaffold plus the
specification. Module 1 in [SPEC.md](SPEC.md) (Tenancy, Identity & Access) is
the first thing to build; everything else scopes against it.
