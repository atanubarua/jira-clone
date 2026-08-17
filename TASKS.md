# TASKS.md — Build Plan

Derived from [SPEC.md](SPEC.md). Phases are ordered so the application is
runnable and demoable as early as possible, and stays that way.

**Status:** Phases 0 and 1 complete. Phase 2 (Projects & Membership) is next.

Phase 1 shipped 107 passing tests (298 assertions), Larastan level 7 clean,
Pint clean, `tsc --noEmit` clean, and a full `migrate:fresh --seed` /
`migrate:rollback` cycle verified from an empty database.

---

## How to read this

- Tasks are atomic: one task is one focused commit (or a small stack of them).
- **Every phase does backend + tests before any UI.** The UI block in each
  phase must not start until that phase's backend block is green.
- Acceptance criteria are written so they can be checked mechanically. If a
  criterion cannot be demonstrated by a test or a command, it is not done.
- Task IDs are stable. Do not renumber; append instead.

### Definition of Done (applies to every task)

- [ ] `sail test` passes
- [ ] `sail pint --test` clean
- [ ] `sail php vendor/bin/phpstan analyse` — no errors
- [ ] `sail npm run types:check` clean (if the task touched TypeScript)
- [ ] New behaviour is covered by tests, not just exercised by them
- [ ] No `@phpstan-ignore`, no skipped tests, no `->markTestSkipped()`

### Phase exit criteria (applies to every phase)

A phase is not finished until all of these hold:

- [ ] `sail artisan migrate:fresh --seed` succeeds from an empty database
- [ ] `sail artisan migrate:fresh` then `migrate:rollback` succeeds — every
      migration has a working `down()`
- [ ] The app returns 200 and the phase's feature is demoable in the browser
- [ ] The full quality gate passes (`sail composer ci:check`)
- [ ] The cross-tenant isolation suite (Task 1.12) still passes in full

---

## Phase 0 — Scaffold ✅ COMPLETE

Already delivered and pushed. Recorded here for provenance.

- [x] **0.1** Laravel 13.25 + Inertia 3.3.1 + React 19.2 + TS 5.7 (strict) +
      Tailwind 4 + shadcn/ui, from `laravel/react-starter-kit:dev-main`
- [x] **0.2** Sail stack: PHP 8.4, MySQL 8.4 LTS, Redis, Mailpit; host ports
      remapped (8080 / 3310 / 6380) around Herd and existing containers
- [x] **0.3** Redis wired for cache, queues and sessions
- [x] **0.4** Pest 4.7, Pint, Larastan (level 7), `tsc --noEmit`
- [x] **0.5** Test suite targets MySQL, not SQLite — SPEC depends on
      `SELECT … FOR UPDATE` and `FULLTEXT`, which SQLite does not reproduce
- [x] **0.6** Repo pushed to `atanubarua/jira-clone`

---

## Phase 1 — Tenancy, Identity, Access & the Isolation Harness ✅ COMPLETE

**SPEC Module 1.** The security foundation. Everything in every later phase
scopes against what is built here, so it is built first and completely —
**permissions and policies are part of this phase, not a later one.**

**Demoable at end of phase:** register → land in your own workspace → invite a
teammate → they accept and appear in the member list → switch between
workspaces → a non-member gets a 404, not a 403.

### 1A. Primary key conversion (must precede every other table)

#### 1.1 — Convert `users` to ULID primary keys
SPEC A-5 requires ULIDs on all primary keys; the starter kit ships
`$table->id()`. Do this before any table references `users`.

- [x] `users.id` is `ulid` primary key; `User` uses `HasUlids`
- [x] `sessions.user_id` and the passkeys table's user FK converted to `ulid`
- [x] Two-factor columns migration still applies cleanly
- [x] Migrations edited **in place** (no production data exists yet) rather
      than layered with alter-migrations
- [x] `migrate:fresh` succeeds; existing 39 starter-kit tests still pass
- [x] `UserFactory` produces ULID keys

#### 1.2 — Establish the ULID + timestamp conventions
- [x] A documented base convention exists for new models (ULID PK, `timestamps`,
      `softDeletes` where SPEC says so)
- [x] An architecture test asserts every model under `App\Models` uses
      `HasUlids`

### 1B. Tenancy backend

#### 1.3 — `workspaces` table and model
- [x] Fields per SPEC: `id`, `name`, `slug` (globally unique), `owner_id`,
      `logo_path`, timestamps, `deleted_at`
- [x] Slug is URL-safe, lowercase, immutable after creation
- [x] `Workspace::owner()` relation

#### 1.4 — `workspace_members` table and model
- [x] Fields per SPEC: `workspace_id`, `user_id`, `role` enum
      (`owner`/`admin`/`member`/`guest`), `status` enum
      (`active`/`deactivated`), `joined_at`
- [x] Unique on (`workspace_id`, `user_id`)
- [x] `User::workspaces()` and `Workspace::members()` many-to-many

#### 1.5 — Tenant resolution middleware
- [x] `workspace_id` is resolved **only** from the `/w/{slug}` route segment —
      never from a request body, header, or query parameter
- [x] Resolved workspace is bound into the container for the request lifetime
- [x] Authenticated non-member of an existing workspace receives **404**
- [x] Unauthenticated request redirects to login
- [x] Test: a request body containing `workspace_id` for another tenant is
      ignored entirely

#### 1.6 — `BelongsToWorkspace` global scope trait
- [x] Applies a global scope filtering by the resolved workspace
- [x] Auto-fills `workspace_id` on create
- [x] Throws (loudly) if used with no tenant bound — no silent unscoped query
- [x] Queue jobs and console commands must set the tenant explicitly; there is
      no ambient default
- [x] Test: same query returns different rows under two different tenants

#### 1.7 — Workspace creation on registration
- [x] Registering creates a workspace and makes the registrant its `owner`
- [x] Happens in one transaction; a failure leaves no orphan user or workspace
- [x] `last_workspace_id` set so login lands somewhere sensible
- [x] Test: no user can exist without at least one workspace

#### 1.8 — Ownership rules
- [x] Exactly one `owner` per workspace, enforced at the model/service layer
- [x] Ownership transfer demotes the previous owner to `admin` in the same
      transaction
- [x] The owner cannot be removed or deactivated — transfer is required first
- [x] Tests cover all three rules

#### 1.9 — Invitations
- [x] `invitations` table per SPEC: `email`, `role` (never `owner`), `token`,
      `invited_by_id`, `expires_at` (14 days), `accepted_at`
- [x] Token is **hashed at rest**, single-use, compared in constant time
- [x] Partial-unique behaviour: one live invitation per email per workspace
- [x] Accepting for an unknown email creates the user in the same transaction
- [x] Accepting for an existing user just adds the membership
- [x] Expired and already-accepted tokens are rejected with a clear error
- [x] Tests cover: happy path (new user), happy path (existing user), expired,
      reused, wrong workspace

#### 1.10 — Member deactivation
- [x] Deactivation preserves authored content and assignments
- [x] Deactivated user's sessions are invalidated
- [x] Deactivated user disappears from member/assignee pickers
- [x] A user who has authored anything can never be hard-deleted
- [x] Tests cover each

### 1C. Authorization

#### 1.11 — Policies, roles and the capability matrix
The workspace-level half of SPEC §4. Project-level roles arrive in Phase 2 and
must slot into the same structure without rewriting it.

- [x] `WorkspacePolicy` covering the workspace capability matrix in SPEC §4
- [x] Policies are invoked via `#[Authorize]` attributes or explicit
      `authorize()` — **never** a hand-rolled `if` in a controller
- [x] **404, not 403**, for anything the user may not read; 403 only where the
      user can see the object but not perform the action
- [x] No policy reads `Auth::user()` directly and assumes full session rights —
      written now to accept token-scoped abilities (SPEC §4 rule 5), so Phase 7
      is additive
- [x] A test asserts every capability row in the SPEC §4 workspace matrix, for
      all four roles

### 1D. The isolation test harness ← *the deliverable that protects everything later*

#### 1.12 — Cross-tenant isolation suite
This is a reusable harness, not a one-off test. Every later phase registers its
routes with it.

- [x] A helper that, given a route and a role, asserts a user in workspace A
      gets 404 for workspace B's records
- [x] Covers ids supplied in the URL **and** in request bodies
- [x] An architecture test asserts every model under `App\Models` (except
      `User` and `Workspace`) uses `BelongsToWorkspace` — a model that forgets
      the trait is a cross-tenant leak and must fail CI
- [x] Documented in CLAUDE.md so every new module is added to it
- [x] Suite is green

#### 1.13 — Guard against unscoped queries in tests
- [x] Test-time assertion that no query against a tenant-owned table runs
      without a `workspace_id` predicate
- [x] Fails loudly rather than warning

### 1E. UI (only after 1.1–1.13 are green)

#### 1.14 — Workspace switcher and member management screens
- [x] Workspace switcher in the sidebar; switching is a session context change,
      not a re-login
- [x] Member list showing role and status
- [x] Invite form; pending invitations list with revoke
- [x] Accept-invitation page (works for both logged-in and new users)
- [x] UI hides what the user cannot do — **cosmetic only**, never the enforcement
- [x] Routes registered with the isolation harness (1.12)

---

## Phase 2 — Projects & Membership

**SPEC Module 2.** Adds the container issues live in, and the second half of
the permission model.

**Demoable at end of phase:** create a project → set it private → confirm a
teammate can't see it → add them as a member → they can.

### 2A. Backend + tests

#### 2.1 — `projects` table and model
- [ ] Fields per SPEC incl. `key`, `visibility`, `lead_id`,
      `next_issue_number`, `archived_at`, soft deletes
- [ ] `key` matches `[A-Z][A-Z0-9]{1,9}`, unique per workspace, **immutable
      after creation** (enforced, with a test)
- [ ] Uses `BelongsToWorkspace`

#### 2.2 — `project_members` table and model
- [ ] Fields per SPEC incl. denormalised `workspace_id` and `role` enum
      (`project_admin`/`contributor`/`viewer`)
- [ ] Unique on (`project_id`, `user_id`)

#### 2.3 — Workspace seeding of statuses, types and labels
- [ ] `issue_statuses` seeded with SPEC's six statuses and four categories
- [ ] `issue_types` seeded with `task`, `bug`, `story`
- [ ] Seeding happens in the same transaction as workspace creation
- [ ] Statuses and types are **not user-editable in v1**
- [ ] Test: a new workspace has exactly 6 statuses and 3 types

#### 2.4 — `labels` table and model
- [ ] Workspace-scoped, unique name per workspace
- [ ] Deleting a label detaches it from issues; it does not delete issues
      (test deferred to Phase 3 when issues exist)

#### 2.5 — Effective project role resolver
The heart of SPEC §4. One function, used everywhere.

- [ ] Implements `max(workspace-implied role, explicit project_members role)`
- [ ] **Guests are never granted anything implicitly**, regardless of project
      visibility
- [ ] Workspace `owner`/`admin` resolve to `project_admin` on every project,
      including private ones
- [ ] `member` resolves to `contributor` on `workspace`-visible projects and
      to nothing on `private` ones
- [ ] Exhaustive test: every (workspace role × visibility × membership) combination

#### 2.6 — Readable-project-ids resolver
- [ ] Single source of truth for scoping every later list, board, filter,
      search and notification query
- [ ] Computed once per request and cached for the request lifetime
- [ ] Test: returns exactly the expected set for each of the four roles

#### 2.7 — `ProjectPolicy`
- [ ] Implements the project capability matrix in SPEC §4
- [ ] Archived and soft-deleted projects reject all writes at the policy layer
- [ ] Project delete restricted to workspace `owner`/`admin`
- [ ] Test asserts every row of the matrix

#### 2.8 — Project lifecycle
- [ ] Lead must be an active workspace member; assignment auto-grants
      `project_admin`
- [ ] Archiving makes the project read-only and drops it from default lists
- [ ] Soft delete with a 30-day restore window
- [ ] Tests for each

#### 2.9 — Register Phase 2 routes with the isolation harness
- [ ] All new routes added to the 1.12 suite; suite green

### 2B. UI

#### 2.10 — Project list, create and settings screens
- [ ] Project list respecting the readable-project set
- [ ] Create form with key preview and live uniqueness check
- [ ] Settings: name, description, lead, visibility, archive
- [ ] Member management with project roles
- [ ] Label management screen

---

## Phase 3 — Issues Core

**SPEC Module 3.** The heart of the product. After this phase the app is
genuinely usable, if plain.

**Demoable at end of phase:** create an issue, get `PROJ-1`, assign it, change
its status, add a sub-task, link two issues.

### 3A. Backend + tests

#### 3.1 — `issues` table and model
- [ ] All fields per SPEC, including `rank` (populated now, **consumed in
      Phase 6** — the board must not require a migration later)
- [ ] Composite indexes per SPEC: board query and "my issues"
- [ ] Soft deletes; `BelongsToWorkspace`

#### 3.2 — Atomic issue key allocation ← *highest-risk task in the project*
- [ ] `number` allocated via `SELECT … FOR UPDATE` on the project row inside
      the creating transaction
- [ ] `key` is **stored**, not derived
- [ ] Keys are never reused, including after deletion
- [ ] **Concurrency test**: N parallel creates produce N distinct sequential
      keys with no gaps and no duplicates. This test must actually run
      concurrent transactions against MySQL — a serial loop does not prove
      anything here.

#### 3.3 — Issue creation and validation
- [ ] `title` required, trimmed, non-blank; everything else defaults per SPEC
- [ ] `reporter_id` set once at creation and never editable (test)
- [ ] Writes rejected on archived or soft-deleted projects

#### 3.4 — Assignment rules
- [ ] Assignee must be an active workspace member **and** pass the project read
      check — no assigning work to someone who will get a 404
- [ ] Test: assigning a guest without project membership fails

#### 3.5 — Sub-tasks
- [ ] One level deep only, enforced **server-side**
- [ ] A sub-task cannot have sub-tasks; a parent cannot become a sub-task
- [ ] Sub-task must be in the same project as its parent
- [ ] Closing a parent does not close sub-tasks, and open sub-tasks do not
      block closing the parent (v1 warns in UI, does not enforce)
- [ ] Tests for each rule

#### 3.6 — Status transitions and lifecycle stamps
- [ ] Any status may transition to any other — no transition graph
- [ ] All product code branches on `category`, **never** on a status id or key
      (enforced by a test or a static check)
- [ ] `started_at` stamped on first entry to a `started` category, never
      overwritten
- [ ] `completed_at` set on entry to `completed`/`cancelled` and **cleared** on
      transition back out
- [ ] Tests cover the stamp/clear cycle explicitly

#### 3.7 — Labels and issue links
- [ ] `issue_label` pivot; label delete detaches without deleting issues
- [ ] `issue_links` with `blocks`/`relates_to`/`duplicates`
- [ ] Self-referencing links rejected
- [ ] `blocks` cycles are permitted in v1 (documented, not enforced)

#### 3.8 — Soft delete semantics
- [ ] Deleting an issue soft-deletes it and cascades to sub-tasks
- [ ] Links to a deleted issue are hidden, not deleted, so restore is lossless
- [ ] Restore test proves losslessness

#### 3.9 — Markdown sanitisation
- [ ] Description stored as Markdown source
- [ ] Rendered with a strict server-side allowlist
- [ ] Test: `<script>`, `<iframe>`, `onerror=` and `javascript:` URLs are stripped

#### 3.10 — `IssuePolicy` and isolation registration
- [ ] Contributor may delete only their own reported issues; project_admin any
- [ ] Viewer cannot mutate issue state
- [ ] Routes registered with the 1.12 harness; suite green

### 3B. UI

#### 3.11 — Issue create, detail and edit screens
- [ ] Create dialog with type, title, description, assignee, priority, labels
- [ ] Detail page: all fields, sub-task list, linked issues
- [ ] Inline edit of status, assignee, priority
- [ ] Markdown preview
- [ ] Eager-load assignee, type, status, labels — no N+1 (assert query count)

---

## Phase 4 — Collaboration & Activity

**SPEC Module 4.** Deliberately before the view layer: the activity log is
written by Phase 3's mutations, and getting the audit trail right while the
mutation surface is still small is far cheaper than retrofitting it.

**Demoable at end of phase:** comment on an issue, @mention a teammate, watch
the activity trail build, receive a notification.

### 4A. Backend + tests

#### 4.1 — `comments`
- [ ] Flat, no threading (SPEC decision)
- [ ] Author may edit indefinitely, stamping `edited_at`; edit history not retained
- [ ] Workspace admins may delete any comment; only the author may edit theirs
- [ ] Soft delete renders as "comment deleted" so the thread stays coherent

#### 4.2 — `activities` append-only log
- [ ] Morph subject (`Issue`, `Comment`, `Project`), `verb`, `field`,
      `old_value`, `new_value`, `created_at` only — rows are immutable
- [ ] Written in the **same transaction** as the mutation that caused it
- [ ] `old_value`/`new_value` store id **and** a display label captured at
      write time — rendering history must never join to a possibly-deleted row
- [ ] Test: rename then delete a referenced entity; history still renders correctly
- [ ] Test: a failed mutation writes no activity row

#### 4.3 — Every Phase 3 mutation emits activity
- [ ] Create, status change, assignment, priority, labels, links, sub-tasks
- [ ] Activity survives soft-deletion of its subject
- [ ] Test asserts one row per field changed, with correct before/after

#### 4.4 — Watchers
- [ ] Auto-watch: reporter on create, assignee on assignment, commenter on
      comment, mentioned user on mention
- [ ] Removal is **sticky** — being mentioned again does not re-add someone
      who opted out (test)

#### 4.5 — Mentions
- [ ] Parsed **server-side**; never trusted from the client
- [ ] Resolved only against users who can actually read the issue
- [ ] Mentioning someone without access is rejected with a clear error —
      not silently dropped, and never silently notified
- [ ] Test: mentioning a guest without project access fails

#### 4.6 — Queued notification fan-out
- [ ] Fan-out is queued (Redis), never inline; routed via `Queue::route()`
- [ ] Read access is **re-checked at send time**, because access may have
      changed between the mutation and the job running (test)
- [ ] Deduplicated per (user, issue, minute-bucket)
- [ ] An actor never notifies themselves
- [ ] Channels: in-app (database) + email; email off by default for field
      changes, on for mentions and assignment
- [ ] Test: issue mutation response time does not scale with recipient count

#### 4.7 — Notification preferences
- [ ] Workspace-level toggles: mentions / assignments / status changes / all comments
- [ ] No per-project overrides in v1

### 4B. UI

#### 4.8 — Comment thread, activity feed, notification centre
- [ ] Comment composer with @mention autocomplete (scoped to users who can read)
- [ ] Merged comment + activity timeline
- [ ] Watch/unwatch control
- [ ] In-app notification list with read state

---

## Phase 5 — List View, Filtering & Search

**SPEC Module 5, first half.** Makes a few thousand issues navigable. The board
is deliberately **not** here.

**Demoable at end of phase:** filter to "my open bugs in project X", save it,
share it, jump straight to an issue by typing its key.

### 5A. Backend + tests

#### 5.1 — Filter engine
- [ ] Closed, fixed set of dimensions per SPEC — no JQL, no user-supplied SQL
- [ ] Filter JSON validated against a schema server-side
- [ ] Sort columns from an allowlist only
- [ ] **Scoped by the readable-project set (2.6) before pagination**, never
      after — test proves no short pages and no row-count leak

#### 5.2 — Cursor pagination
- [ ] Cursor-based, not offset (offset degrades badly past a few thousand rows)
- [ ] Stable ordering under concurrent inserts (test)

#### 5.3 — Search
- [ ] MySQL `FULLTEXT` index on `issues.title`
- [ ] Exact-match on `key` — typing `PAY-142` jumps straight to that issue
- [ ] Scoped by tenant **and** readable projects
- [ ] Test: a user cannot find another tenant's issue by key or title

#### 5.4 — `saved_views`
- [ ] Private by default; `is_shared` makes it visible to anyone who can read
      its scope
- [ ] Sharing grants **no additional data access** — a shared view seen by a
      narrower user simply returns fewer rows (test)
- [ ] Cross-project views span only readable projects

### 5B. UI

#### 5.5 — Issue list screen
- [ ] Grouping by status/assignee/priority/type
- [ ] Inline edit of status, assignee, priority
- [ ] Filter builder bound to the closed dimension set
- [ ] Saved view management
- [ ] Query-count assertion — no N+1

---

## Phase 6 — Kanban Board

**SPEC Module 5, second half.** Its own late phase, after the data model has
been stable through three phases. The `rank` column has existed since Phase 3,
so **this phase adds no migration to `issues`**.

**Demoable at end of phase:** drag a card across columns and watch status and
order persist.

### 6A. Backend + tests

#### 6.1 — LexoRank-style sparse ranking
- [ ] Inserting between two issues generates a midpoint string with **no
      neighbour rewrites** (test asserts only one row is updated)
- [ ] Ranks unique per project
- [ ] Rebalance job for when rank strings exceed a length threshold, with a test
      that forces rebalancing

#### 6.2 — Board move endpoint
- [ ] A cross-column drag writes `status_id` **and** `rank` in one request and
      one transaction
- [ ] Emits the same activity records as a normal status change (Phase 4)
- [ ] Rejects moves on archived projects and for `viewer` role
- [ ] Concurrency test: two simultaneous moves do not corrupt ordering

#### 6.3 — Board query
- [ ] Columns are the six statuses ordered by `position`
- [ ] Per-column pagination, 50 cards
- [ ] `done` column defaults to the last 14 days only — otherwise it grows
      without bound
- [ ] Shares one query builder with the list view (Phase 5) — one builder, two
      renderers, so the views cannot disagree about what exists

### 6B. UI

#### 6.4 — Board screen
- [ ] Drag within and across columns
- [ ] Optimistic update with server reconciliation; a rejected move rolls back
      **visibly**
- [ ] Lazy-load and paginate on scroll per column
- [ ] **Keyboard-accessible alternative to drag-and-drop** (SPEC A-28) — this
      is real work, not a checkbox
- [ ] Query-count assertion — a board must not be a 200-query page

---

## Phase 7 — Public REST API

**Final feature phase**, per SPEC and the original brief. Additive by design:
Phase 1 wrote policies to accept token-scoped abilities, so this phase should
add no new authorization logic.

**Demoable at end of phase:** issue a token, list issues over HTTP, hit a rate
limit, read the generated OpenAPI docs.

### 7A. Backend + tests

#### 7.1 — Sanctum token issuance and management
- [ ] Token CRUD with named abilities
- [ ] Tokens are workspace-scoped
- [ ] Revocation is immediate (test)

#### 7.2 — Token abilities map onto existing policies
- [ ] **No parallel authorization model** — abilities constrain the existing
      policies rather than replacing them
- [ ] A token carrying a subset of a user's abilities cannot exceed that user's
      own permissions (test)
- [ ] A token for workspace A cannot read workspace B (added to the 1.12 harness)

#### 7.3 — Versioned API surface
- [ ] `/api/v1/` prefix; versioning strategy documented
- [ ] Resources for workspaces, projects, issues, comments
- [ ] Consistent error envelope and validation error shape
- [ ] Rate limiting per token

#### 7.4 — OpenAPI documentation
- [ ] Generated from the code, not hand-maintained
- [ ] CI fails if the spec drifts from the routes
- [ ] Published at a documented URL

#### 7.5 — API isolation suite
- [ ] Every API route registered with the 1.12 harness
- [ ] Suite green across web **and** API surfaces

---

## Deliberately not in this plan

Everything in SPEC §5 ("Deferred to v2"). If a task here starts growing toward
custom fields, configurable workflows, sprints, or file attachments, stop — it
belongs in v2, and SPEC §5 already records the decision.

The three open questions from SPEC §6 that could still change this plan:

1. **Attachments in v1?** (SPEC A-20) Would become a new phase between 4 and 5.
2. **GDPR erasure at launch?** (SPEC A-26) Would add hard-delete tooling to Phase 1.
3. **Starter kit `dev-teams` branch?** Would restructure Phase 1B — cheap now,
   expensive after Phase 2.
