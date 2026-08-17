# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

A multi-tenant, Jira-style issue tracker (SaaS). Shared MySQL database,
`workspace_id` on every tenant-owned table, enforced by a global Eloquent scope.

Three documents govern the work, in this order of authority:

| File | Role |
|---|---|
| [SPEC.md](SPEC.md) | The v1 product decision record. Entities, business rules, permission model, and 30 numbered assumptions. **Do not contradict it silently** — if the code needs to diverge, update SPEC and say so. |
| [TASKS.md](TASKS.md) | Phased build plan with acceptance criteria. Tells you what to build next. |
| [README.md](README.md) | Human-facing setup and ports. |

Current state: **Phase 0 complete** (scaffold). No application code yet.
Phase 1 (tenancy, auth, roles, policies, isolation harness) is next.

---

## Environment — read this before running anything

**The project lives inside WSL2 at `~/projects/jira-clone`, on the Linux
filesystem.** It is not on `D:\`. Code on NTFS would cross the Windows↔WSL
filesystem bridge and make PHP page loads 20–60× slower.

**Run everything through Sail.** The host WSL `npm` and `composer` on `PATH`
resolve to the *Windows* binaries via `/mnt/c`:

- Windows `npm` cannot set Unix exec bits. Running it here produces a
  `node_modules/.bin` full of non-executable files and a build that dies with
  `vite: Permission denied`. If that happens: `rm -rf node_modules` and
  `sail npm ci`.
- A native Linux Composer is installed at `/usr/local/bin/composer` and is
  fine, but `sail composer` is still preferred for parity.

```bash
./vendor/bin/sail up -d
```

Docker Desktop 4.45 on this machine intermittently fails to start with
`initializing Inference manager: … dockerInference: The file cannot be accessed
by the system`. Fix: stop Docker, rename `%LOCALAPPDATA%\Docker\run` to
anything else, restart Docker. It recreates the directory clean.

### Ports (non-default — several defaults were already taken)

| Service | Host | In-container |
|---|---|---|
| App | http://localhost:8080 | 80 |
| MySQL | 3310 | `mysql:3306` |
| Redis | 6380 | `redis:6379` |
| Mailpit | http://localhost:8025 | 8025 |

`FORWARD_*` in `.env` controls only host publishing. Inside the Docker network
the app still uses `mysql:3306` and `redis:6379` — don't "fix" those.

---

## Commands

```bash
./vendor/bin/sail test
```

```bash
./vendor/bin/sail pint
```

```bash
./vendor/bin/sail php vendor/bin/phpstan analyse
```

```bash
./vendor/bin/sail npm run types:check
```

Full gate, matching CI — run before declaring anything done:

```bash
./vendor/bin/sail composer ci:check
```

Single test file, or filter by name:

```bash
./vendor/bin/sail test tests/Feature/DashboardTest.php
```

```bash
./vendor/bin/sail artisan migrate
```

Frontend dev server (HMR):

```bash
./vendor/bin/sail npm run dev
```

---

## Stack

Laravel 13.25 · PHP 8.4 · MySQL 8.4 LTS · Redis (cache, queues, sessions) ·
Inertia 3.3 · React 19.2 · TypeScript 5.7 (strict) · Tailwind 4 · shadcn/ui ·
Fortify (auth) · Pest 4.7 · Pint · Larastan

Scaffolded from `laravel/react-starter-kit:dev-main`.

### Starter-kit specifics worth knowing

- **Fortify** provides all auth routes. Feature toggles live in
  `config/fortify.php`; user creation and password reset logic lives in
  `app/Actions/Fortify/`.
- **Wayfinder** generates type-safe route helpers into `resources/js/routes`,
  `resources/js/actions`, `resources/js/wayfinder`. These are **generated and
  gitignored**. Consequence: if you remove a backend route, you must also remove
  its frontend references, or the build fails.
- Laravel 13 leans on **PHP attributes**. The `User` model uses `#[Fillable]`
  and `#[Hidden]` rather than `$fillable`/`$hidden` properties. Use
  `#[Middleware]` and `#[Authorize]` on controllers. Follow this style.
- `resources/js/` layout: `components/`, `hooks/`, `layouts/`, `lib/`,
  `pages/`, `types/`. Inertia page components resolve to `pages/`.

---

## Non-negotiable rules

These come from SPEC and exist because violating them causes security bugs or
expensive rewrites. Do not relax them for convenience.

### Tenancy

1. `workspace_id` is resolved **only** from the `/w/{slug}` route segment.
   Never from a request body, header, or query parameter.
2. Every tenant-owned model uses the `BelongsToWorkspace` trait. A model that
   forgets it is a cross-tenant data leak — an architecture test enforces this
   and must stay green.
3. Every unique index on a tenant-owned table is composite and **leads with
   `workspace_id`**.
4. Queue jobs and console commands must set the tenant explicitly. There is no
   ambient default.

### Authorization

5. **404, not 403**, for anything the user may not read. 403 confirms
   existence, which leaks project names and issue keys. Use 403 only where the
   user can see the object but not perform the action.
6. Authorization goes through Policies, invoked via `#[Authorize]` or explicit
   `authorize()`. **Never** a hand-rolled `if` in a controller.
7. The UI hiding a control is cosmetic. It is never the enforcement.
8. No policy may read `Auth::user()` and assume full session rights — policies
   must tolerate token-scoped abilities so the Phase 7 API is additive.
9. Every new route is registered with the cross-tenant isolation suite
   (TASKS 1.12). This is part of the task, not a follow-up.

### Data model

10. Primary keys are **ULIDs**, not auto-increment integers (SPEC A-5).
11. Issue keys are allocated with `SELECT … FOR UPDATE` on the project row
    inside the creating transaction. An application-level `max(number)+1` is
    wrong under concurrency. Keys are never reused.
12. **Branch on `status.category`, never on a status id or key.** This is what
    keeps the v2 upgrade to configurable statuses from being a rewrite.
13. Activity log rows are immutable, and are written in the **same transaction**
    as the mutation that caused them. `old_value`/`new_value` store a display
    label captured at write time, so history renders without joining to
    possibly-deleted rows.
14. Sub-tasks are exactly one level deep, enforced server-side.

### Testing

15. **Tests run against MySQL, not SQLite.** `phpunit.xml` is configured this
    way deliberately: SPEC depends on `SELECT … FOR UPDATE` row locks and
    `FULLTEXT` indexes, neither of which SQLite reproduces. Testing those on
    SQLite would pass while proving nothing. Do not switch it back for speed.
16. Existing starter-kit tests are PHPUnit classes; Pest runs them unchanged.
    New tests should be Pest-style. `tests/Pest.php` binds `TestCase` and
    `RefreshDatabase` for `Feature`.
17. Larastan runs at **level 7** (the starter kit's default), not the level 5
    named in SPEC A-9. Level 5 is a strict subset, so this is free rigour. Do
    not lower it to make code pass — fix the code.
18. Every issue-returning endpoint eager-loads assignee, type, status and
    labels, and has a query-count assertion. N+1 on a board is a 200-query page.

---

## Working style here

- **Backend and tests land before UI in every phase.** TASKS.md is ordered this
  way; keep it that way.
- Prefer finishing one TASKS.md task completely (including its acceptance
  criteria) over starting several.
- When SPEC and reality disagree, update SPEC in the same change and call it
  out. Silent divergence is the failure mode that makes the spec worthless.
- Don't add anything from SPEC §5 ("Deferred to v2"). If a task starts drifting
  toward custom fields, workflows, sprints, or attachments, stop.

## Open questions that could change the plan

Three decisions from SPEC §6 are still unresolved and are cheap now, expensive
later. If work approaches them, raise them rather than guessing:

1. File attachments in v1 or v2? (SPEC A-20)
2. GDPR erasure obligations at launch? (SPEC A-26) — would add hard-delete
   tooling to Phase 1.
3. Adopt the starter kit's `dev-teams` branch for workspaces? — would
   restructure Phase 1B.
