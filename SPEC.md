# SPEC.md — Multi-Tenant Issue Tracker (v1)

Status: draft for approval
Date: 2026-08-17
Scope: v1 product specification only. No code, no task breakdown.

---

## 0. Verified stack versions

Checked against official sources on 2026-08-17. **Every version below was
looked up, not recalled.**

| Component | Verified current | Source | Notes |
|---|---|---|---|
| Laravel | **13.x** (released 2026-03-17) | laravel.com/docs/releases | Requires PHP 8.3–8.5. Bug fixes to Q3 2027, security to 2028-03-17. Laravel 12 loses bug-fix support 2026-08-13 — i.e. four days ago. **Scaffold on 13.** |
| PHP | **8.4** | Laravel 13 support matrix (8.3–8.5) | 8.4 is the safe middle. 8.5 is supported but ecosystem/extension lag is a real risk on a fresh SaaS. |
| Inertia server adapter | **inertiajs/inertia-laravel 3.3.1** | repo.packagist.org | Requires PHP ^8.2, Laravel `^11.35\|^12.0\|^13.0`. Laravel 13 compatible. |
| Inertia client | **@inertiajs/react 3.6.1** | registry.npmjs.org | Peer deps: `react ^19.0.0`, `react-dom ^19.0.0`. |
| React | **19.2** (19.2.7, June 2026) | react.dev/versions | Matches Inertia's peer range. |
| TypeScript | **7.0.2** is the npm `latest` tag | registry.npmjs.org | See ASSUMPTION A-2 — I am *not* defaulting to this. |
| Tailwind CSS | **4.3.3** | registry.npmjs.org | v4 config-in-CSS model; affects shadcn/ui setup. |
| MySQL | 8.x | Your constraint | Note: Laravel 13's new vector/semantic search is PostgreSQL+pgvector only. Irrelevant to v1, relevant if v2 wants semantic search. |
| Sanctum, Pest, Pint, Larastan | latest majors at scaffold time | — | Not pinned here; verify at `composer create-project` time. |

**Version-driven decisions**

- Laravel 12 is out of bug-fix support as of last week. Starting a greenfield
  SaaS on it would put you on security-only patches from day one. Laravel 13.
- Inertia 3.x is the current line, and its Laravel 13 support is explicit in
  the constraint string. No adapter risk.
- Laravel 13 ships `#[Middleware]` / `#[Authorize]` controller attributes and
  `Queue::route()`. Both are directly useful here (permission enforcement,
  queue routing for notifications) and are noted where relevant below.

---

## 1. Product framing

A workspace-per-customer issue tracker. Shared MySQL database, `workspace_id`
discriminator on every tenant-owned table, enforced by a global Eloquent scope.

**The v1 thesis:** what makes Jira/Linear/Asana usable on day one is *not*
their configurability. It is: a fast issue list, a board you can drag, a
comment thread, and an activity trail you can trust. Jira's workflow engine,
custom fields, and automation rules are what customers tolerate, not what they
buy. v1 ships the core loop and defers every configuration surface.

**What "core" means across the three references**

- **Jira**: projects, issue types, configurable workflows, boards, sprints,
  JQL, permission schemes. Enormously configurable; slow to onboard.
- **Linear**: fixed status *categories* with light per-team naming, cycles,
  keyboard-first, deliberately non-configurable.
- **Asana**: projects, tasks, sections, assignee + due date, custom fields;
  no first-class "status lifecycle" at all.

The intersection — and therefore v1 — is: **workspace → project → issue, with
an assignee, a status, a priority, a comment thread, an activity log, a list
view and a board view.** Everything else is v2.

---

## 2. Modules for v1 (HARD CAP: 5), ordered by build dependency

Each module is buildable and testable before the next begins. The dependency
order is strict — module N depends on 1..N-1 and nothing later.

| # | Module | Depends on | One-line purpose |
|---|---|---|---|
| 1 | Tenancy, Identity & Access | — | Who exists, which workspace they belong to, what they may do. |
| 2 | Projects & Membership | 1 | The container issues live in, and per-project access. |
| 3 | Issues Core | 1, 2 | The record itself: create, read, update, assign, transition. |
| 4 | Collaboration & Activity | 1, 2, 3 | Comments, mentions, history, notifications. |
| 5 | Views: List, Board & Filtering | 1, 2, 3 | Making a few thousand issues navigable. |

Module 4 before 5 is deliberate: the activity log is written by Module 3's
mutations, and getting the audit trail right while the mutation surface is
still small is far cheaper than retrofitting it after the view layer exists.

---

## Module 1 — Tenancy, Identity & Access

Foundation. Nothing else can be correctly scoped until this is done.

### Entities

**`users`** — global, *not* tenant-scoped. A person, one row, one login,
possibly a member of several workspaces.

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | ULIDs throughout; sortable, non-enumerable in URLs. |
| `name` | string(255) | |
| `email` | string(255), unique | Global unique. Login identity. |
| `email_verified_at` | timestamp, null | |
| `password` | string, null | Null permitted for invite-accepted-but-not-set. |
| `avatar_path` | string, null | |
| `timezone` | string(64) | Default `UTC`. Drives due-date display only. |
| `last_workspace_id` | ulid, null | Where to land after login. Not authorization. |
| `remember_token`, timestamps | | |

**`workspaces`** — the tenant root. Owns everything.

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | This *is* the `tenant_id` everywhere else. |
| `name` | string(120) | |
| `slug` | string(60), unique | Global unique; used in URLs `/w/{slug}/...`. |
| `owner_id` | ulid → users | Exactly one at all times. |
| `logo_path` | string, null | |
| timestamps, `deleted_at` | | Soft delete = suspension, not erasure. |

**`workspace_members`** — the membership + workspace role join.

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid → workspaces | |
| `user_id` | ulid → users | |
| `role` | enum: `owner`, `admin`, `member`, `guest` | See §4. |
| `status` | enum: `active`, `deactivated` | Deactivated retains history, loses access. |
| `joined_at` | timestamp | |
| unique | (`workspace_id`, `user_id`) | |

**`invitations`**

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid → workspaces | |
| `email` | string(255) | Not necessarily an existing user. |
| `role` | enum, same as above, `owner` excluded | |
| `token` | string(64), unique, hashed at rest | |
| `invited_by_id` | ulid → users | |
| `expires_at` | timestamp | 14 days. |
| `accepted_at` | timestamp, null | |
| unique | (`workspace_id`, `email`) where `accepted_at is null` | One live invite per email per workspace. |

### Relationships

- `User` ↔ `Workspace` many-to-many through `workspace_members`.
- `Workspace` → `Invitation` one-to-many.
- Every tenant-owned model in Modules 2–5 `belongsTo` `Workspace`.

### Business rules

1. **Tenant resolution.** `workspace_id` is resolved from the route segment
   (`/w/{slug}`), then verified against the authenticated user's memberships
   in middleware. It is never taken from a request body, header, or a
   client-supplied field. A user hitting a workspace they are not an active
   member of gets 404, not 403 — do not confirm existence.
2. **Global scope.** Every tenant-owned model applies a `BelongsToWorkspace`
   global scope reading the resolved tenant from a request-scoped container
   binding, and auto-fills `workspace_id` on create. Queue jobs and console
   commands must set the tenant explicitly; there is no ambient default.
3. **No unscoped reads.** A model that forgets the trait is a cross-tenant
   leak. Enforced by an architecture test (Pest) asserting every model under
   `App\Models` except `User` and `Workspace` uses the trait.
4. **Uniqueness is per-tenant.** Every unique index on tenant-owned tables is
   composite and leads with `workspace_id`. Same for every foreign-key-bearing
   composite index, so the tenant filter is index-covered.
5. Exactly one `owner` per workspace. Ownership transfer is an explicit action
   by the current owner; it demotes them to `admin` in the same transaction.
6. The owner cannot be removed or deactivated. Ownership must be transferred
   first.
7. Deactivating a member: their assignments remain, their comments remain,
   their sessions are invalidated, they disappear from assignee pickers.
   **Never hard-delete a user who has authored anything.**
8. Accepting an invitation for an email with no account creates the user in
   the same transaction. Accepting for an existing account just adds the
   membership.
9. Invitation tokens are single-use, hashed at rest, and compared in constant
   time.
10. A user may belong to unlimited workspaces. Workspace switching is a
    session-level context change, not a re-login.
11. Registration creates a workspace and makes the registrant its `owner`.
    There is no user without a workspace in v1.

---

## Module 2 — Projects & Membership

### Entities

**`projects`**

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid → workspaces | |
| `key` | string(10) | Uppercase `[A-Z][A-Z0-9]{1,9}`. Issue prefix: `PAY-142`. |
| `name` | string(120) | |
| `description` | text, null | Plain text in v1. |
| `lead_id` | ulid → users, null | Must be an active workspace member. |
| `visibility` | enum: `workspace`, `private` | See §4. |
| `icon`, `color` | string(32) | Cosmetic. |
| `next_issue_number` | unsigned int, default 1 | The per-project key counter. |
| `archived_at` | timestamp, null | Archived = read-only, still visible. |
| timestamps, `deleted_at` | | |
| unique | (`workspace_id`, `key`) | |

**`project_members`**

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid | Denormalized for scoping and index leading. |
| `project_id` | ulid → projects | |
| `user_id` | ulid → users | |
| `role` | enum: `project_admin`, `contributor`, `viewer` | See §4. |
| timestamps | | |
| unique | (`project_id`, `user_id`) | |

**`issue_statuses`** — seeded, workspace-owned, **not user-editable in v1**.

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid | |
| `key` | enum: `backlog`, `todo`, `in_progress`, `in_review`, `done`, `cancelled` | |
| `name` | string(40) | Display label. Seeded, read-only in v1. |
| `category` | enum: `unstarted`, `started`, `completed`, `cancelled` | The semantic bucket. See §3. |
| `position` | unsigned smallint | Board column order. |
| unique | (`workspace_id`, `key`) | |

**`issue_types`** — seeded, workspace-owned, not user-editable in v1.

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid | |
| `key` | enum: `task`, `bug`, `story` | |
| `name`, `icon`, `color` | | Seeded. |
| unique | (`workspace_id`, `key`) | |

**`labels`** — the one piece of per-workspace taxonomy users *can* edit.

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid | |
| `name` | string(40) | |
| `color` | string(7) | Hex. |
| unique | (`workspace_id`, `name`) | Workspace-wide, not per-project. |

### Relationships

- `Workspace` → `Project` one-to-many.
- `Project` ↔ `User` many-to-many through `project_members`.
- `Workspace` → `IssueStatus` / `IssueType` / `Label` one-to-many (seeded on
  workspace creation).
- `Project` → `Issue` one-to-many (Module 3).

### Business rules

1. Project `key` is immutable after creation. Changing it would orphan every
   issue key ever pasted into a Slack message or a commit. Renaming is v2 at
   best, and only with a redirect table.
2. Creating a project seeds nothing per-project — statuses and types are
   workspace-level. This is what makes cross-project boards and filters
   possible without a join explosion.
3. Creating a workspace seeds its six statuses, three types, and zero labels
   in one transaction.
4. `visibility = workspace`: every active `member`/`admin`/`owner` of the
   workspace can read it without a `project_members` row. `private`: only
   users with a `project_members` row can see it exists at all.
5. Guests **never** get implicit project access. A guest sees exactly the
   projects they have a `project_members` row for, regardless of visibility.
6. The project lead must be an active workspace member and is auto-granted
   `project_admin` on assignment.
7. Archiving a project: issues become read-only, the project drops out of
   default lists and pickers, and it stops appearing in cross-project boards.
   Reversible.
8. Deleting a project is a soft delete with a 30-day restore window, permitted
   to workspace `owner`/`admin` only. Issues cascade to the same soft-deleted
   state.
9. Labels are workspace-scoped and shared across projects. Deleting a label
   detaches it from issues; it does not delete issues.
10. Removing a user from a project does not unassign their issues. It removes
    their access. Surfacing "assigned to someone who lost access" is a v2
    hygiene report; v1 just renders the name.

---

## Module 3 — Issues Core

The heart. Everything before this was setup.

### Entities

**`issues`**

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid | |
| `project_id` | ulid → projects | |
| `number` | unsigned int | Per-project sequence. |
| `key` | string(20), generated | `{project.key}-{number}`, stored, not computed. |
| `type_id` | ulid → issue_types | |
| `status_id` | ulid → issue_statuses | |
| `priority` | enum: `none`, `low`, `medium`, `high`, `urgent` | Enum column, not a table. |
| `title` | string(255) | Required, trimmed, non-empty. |
| `description` | mediumtext, null | Markdown source. Rendered client-side, sanitized server-side. |
| `reporter_id` | ulid → users | Immutable. The creator. |
| `assignee_id` | ulid → users, null | |
| `parent_id` | ulid → issues, null | Sub-task parent. One level only. |
| `estimate` | unsigned smallint, null | Story points. Unitless integer. |
| `due_on` | date, null | Date, not datetime. Timezone pain is not worth it in v1. |
| `rank` | string(64) | Lexicographic rank for manual ordering. See rules. |
| `started_at` | timestamp, null | First transition into a `started` status. |
| `completed_at` | timestamp, null | Transition into `completed`/`cancelled`. |
| timestamps, `deleted_at` | | |
| unique | (`project_id`, `number`), (`workspace_id`, `key`) | |
| index | (`workspace_id`, `project_id`, `status_id`, `rank`) | The board query. |
| index | (`workspace_id`, `assignee_id`, `status_id`) | "My issues". |

**`issue_label`** — pivot: (`workspace_id`, `issue_id`, `label_id`), unique on
the last two.

**`issue_links`** — typed relationships between issues.

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid | |
| `source_issue_id`, `target_issue_id` | ulid → issues | |
| `type` | enum: `blocks`, `relates_to`, `duplicates` | Inverse implied, not stored. |
| unique | (`source_issue_id`, `target_issue_id`, `type`) | |

### Relationships

- `Issue` belongsTo `Project`, `IssueType`, `IssueStatus`, `reporter`,
  `assignee`, `parent`.
- `Issue` hasMany `children` (sub-tasks), `comments`, `activities`, `watchers`.
- `Issue` belongsToMany `Label`.
- `Issue` hasMany outgoing/incoming `IssueLink`.

### Business rules

1. **Key allocation is atomic.** `number` comes from
   `UPDATE projects SET next_issue_number = next_issue_number + 1` inside the
   creating transaction with a `SELECT ... FOR UPDATE` on the project row.
   MySQL 8 row locks make this correct under concurrency; an application-level
   `max(number)+1` does not. Keys are never reused, including after deletion.
2. `key` is stored, not derived, so it survives even if project keys ever
   become mutable.
3. `title` is required and non-blank. Everything else has a sane default:
   type `task`, status `backlog`, priority `none`, assignee null.
4. `reporter_id` is set once, at creation, and is never editable — it is the
   only trustworthy provenance field on the record.
5. **Assignee must be able to see the issue.** Validated at assignment: the
   target user must be an active workspace member *and* pass the project read
   check from §4. No assigning work to someone who will get a 404.
6. **Sub-tasks are one level deep.** A sub-task cannot have sub-tasks. A
   parent cannot become a sub-task. This is checked server-side, not just in
   the UI. Arbitrary hierarchies are the fastest route to unrenderable trees
   and recursive-CTE query bills.
7. A sub-task must be in the same project as its parent.
8. Closing a parent does not close its sub-tasks, and open sub-tasks do not
   block closing the parent. v1 warns in the UI; it does not enforce. Cascade
   rules are opinionated and wrong for half of teams.
9. `started_at` is stamped on the *first* transition into a `started`-category
   status and never overwritten. `completed_at` is set on every transition
   into `completed`/`cancelled` and **cleared** on any transition back out —
   this is what makes cycle-time reporting possible in v2 without a rebuild.
10. `rank` uses a sparse lexicographic scheme (LexoRank-style): inserting
    between two issues generates a midpoint string, no neighbour rewrites.
    Ranks are unique per project. A rebalance job exists for when strings grow
    past a length threshold.
11. `issue_links` cannot self-reference. `blocks` cycles are permitted in v1
    (detecting them is a graph traversal on every write and buys little); the
    UI shows the chain and does not enforce acyclicity.
12. Deleting an issue is a soft delete. Sub-tasks cascade. Links to it are
    hidden, not deleted, so restore is lossless.
13. Every mutating field change emits an activity record (Module 4) in the
    same transaction. The activity log is not best-effort.
14. Issues in archived or soft-deleted projects reject all writes at the
    policy layer.
15. Description is stored as Markdown source, sanitized server-side on render
    with a strict allowlist. No raw HTML, no script, no iframes, ever.

---

## Module 4 — Collaboration & Activity

### Entities

**`comments`**

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id`, `issue_id` | ulid | |
| `author_id` | ulid → users | Immutable. |
| `body` | mediumtext | Markdown source. |
| `edited_at` | timestamp, null | Set on any edit after creation. |
| timestamps, `deleted_at` | | |
| index | (`issue_id`, `created_at`) | |

**`activities`** — the append-only audit trail.

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid | |
| `subject_type`, `subject_id` | morph | `Issue`, `Comment`, `Project`. |
| `actor_id` | ulid → users, null | Null = system/automation. |
| `verb` | string(40) | `created`, `status_changed`, `assigned`, `commented`, … |
| `field` | string(40), null | For field changes. |
| `old_value`, `new_value` | json, null | Value plus a denormalized display label, so history renders correctly after the referenced entity is renamed or deleted. |
| `created_at` | timestamp | No `updated_at`. Rows are immutable. |
| index | (`workspace_id`, `subject_type`, `subject_id`, `created_at`) | |

**`watchers`** — (`workspace_id`, `issue_id`, `user_id`), unique on the last
two.

**`notifications`** — Laravel's standard `notifications` table plus a
`workspace_id` column and index, so notifications are tenant-scoped like
everything else.

**`mentions`** — (`workspace_id`, `comment_id`, `mentioned_user_id`).
Extracted server-side from the comment body; never trusted from the client.

### Relationships

- `Issue` hasMany `Comment`, `Activity` (morph), `Watcher`.
- `Comment` hasMany `Mention`; `Comment` hasMany `Activity` (morph).
- `User` hasMany `Notification`.

### Business rules

1. Comments are flat. **No threading in v1.** Threading changes the read
   model, the notification fan-out, and the UI all at once, for a feature most
   teams use as an unordered log.
2. Comment edits are allowed by the author indefinitely and stamp `edited_at`.
   Workspace admins may delete any comment; only the author may edit theirs.
   Edit *history* is not retained in v1 — the fact of an edit is.
3. Deleting a comment is a soft delete rendering as "comment deleted", so the
   surrounding conversation stays coherent.
4. Activity rows are immutable and never deleted, including when the subject
   issue is soft-deleted. They are written in the same DB transaction as the
   mutation that caused them — an activity log that can silently lag is not an
   audit trail.
5. `old_value`/`new_value` store both the id and a display label at write
   time. Rendering history must never require a join to a possibly-deleted row.
6. **Auto-watch rules:** the reporter watches on create; the assignee watches
   on assignment; a commenter watches on comment; a mentioned user watches on
   mention. All are individually removable, and removal is sticky — being
   mentioned again does not re-add someone who opted out.
7. Mentions are parsed server-side from `@` tokens and resolved against users
   who can actually read the issue. Mentioning someone without access is
   rejected with a clear error rather than silently dropped or, worse,
   silently granting a notification containing the issue title.
8. **Notification fan-out is queued**, never inline. Redis queue, dedicated
   `notifications` queue, routed via `Queue::route()`. Issue mutation response
   times must not depend on recipient count.
9. Notifications are deduplicated per (user, issue, minute-bucket): five field
   edits in a row produce one notification, not five.
10. An actor never notifies themselves for their own action.
11. Channels in v1: **in-app (database) and email.** Email is off by default
    for field changes, on for direct mentions and assignment. Slack, webhooks,
    and digests are v2.
12. Per-user notification preferences are a single set of workspace-level
    toggles (mentions / assignments / status changes / all comments).
    Per-project overrides are v2.

---

## Module 5 — Views: List, Board & Filtering

Nothing new is created here; this module makes Modules 3–4 usable at volume.

### Entities

**`saved_views`**

| Field | Type | Notes |
|---|---|---|
| `id` | ulid, pk | |
| `workspace_id` | ulid | |
| `project_id` | ulid, null | Null = cross-project view. |
| `owner_id` | ulid → users | |
| `name` | string(80) | |
| `filters` | json | Validated against a fixed schema; never raw SQL. |
| `grouping` | enum: `status`, `assignee`, `priority`, `type`, `none` | |
| `sort` | json | Whitelisted columns + direction only. |
| `layout` | enum: `list`, `board` | |
| `is_shared` | boolean | Shared = visible to everyone who can read the scope. |
| timestamps | | |

No other new tables. Board ordering uses `issues.rank` from Module 3.

### Behaviour

**Filter dimensions in v1** — a fixed, closed set: project, status, status
category, type, assignee (incl. "unassigned" and "me"), reporter, priority,
labels, due date (overdue / this week / range), created and updated ranges,
free-text on title and key.

**List view.** Cursor-paginated (not offset — offset pagination degrades
predictably and badly past a few thousand rows), 50 per page, grouped
optionally, with inline edit of status, assignee, and priority.

**Board view.** Columns = the six statuses, ordered by `position`. Drag within
a column writes `rank`; drag across columns writes `status_id` and `rank` in
one request and one transaction. Columns lazy-load 50 cards and paginate on
scroll. A `done` column shows only the last 14 days by default, because
otherwise it grows without bound and nobody ever looks at it.

**Search.** MySQL 8 `FULLTEXT` index on `issues.title` plus exact-match on
`key`, scoped by tenant and by the caller's readable projects. Typing `PAY-142`
jumps straight to that issue. Not Scout, not Elasticsearch — see A-11.

### Business rules

1. **Every list, board, filter, and search result is filtered by the caller's
   readable project set before pagination**, not after. Filtering after
   pagination produces short pages and leaks row counts.
2. Filter JSON is validated against a closed schema server-side. Sort columns
   come from an allowlist. There is no user-supplied SQL, no JQL, no raw
   ordering expression.
3. Saved views are private by default. `is_shared` makes a view visible to
   anyone who can read its scope; it grants no additional data access — a
   shared view seen by a user with narrower access simply returns fewer rows.
4. Cross-project views span only projects the caller can read.
5. Drag-to-rank is optimistic on the client with server reconciliation. A
   rejected move (e.g. archived project, lost permission) rolls back visibly.
6. Board and list are the same query with a different projection. One query
   builder, two renderers — divergence here is how the two views end up
   disagreeing about what exists.
7. Every issue-returning endpoint eager-loads assignee, type, status, and
   labels. N+1 on the board is not a performance nit, it is a 200-query page.

---

## 3. Issue status lifecycle — the decision

### Decision: **fixed workspace-level statuses. No per-project configurable workflows in v1.**

Six statuses, seeded identically into every workspace, mapped to four
categories:

| Status | Category | Meaning |
|---|---|---|
| Backlog | `unstarted` | Captured, not committed. |
| To Do | `unstarted` | Committed, not started. |
| In Progress | `started` | Someone is working on it. |
| In Review | `started` | Work is done, verification pending. |
| Done | `completed` | Terminal, successful. |
| Cancelled | `cancelled` | Terminal, unsuccessful. |

**Any status may transition to any other status.** There is no transition
graph, no guard conditions, no per-transition permissions, no post-functions.
The only side effects are the `started_at` / `completed_at` stamps in Module 3
rule 9, and an activity row.

### Defence

**1. Configurable workflows are the single largest complexity multiplier in an
issue tracker, and it is not close.** Shipping them means also shipping: a
transition graph editor, transition-level permission rules, guard/validator
conditions, post-functions, a workflow-scheme-to-project mapping layer, and —
the part everyone forgets — a *migration path for in-flight issues* when a
project's workflow changes underneath them. That last one alone is a module.
It would consume one of my five.

**2. It poisons every downstream feature.** With per-project workflows, a
cross-project board cannot have columns; a cross-project filter cannot have a
"status" dimension; velocity and cycle-time are not comparable between
projects. You end up inventing status *categories* anyway to make anything
aggregate — which is exactly what Jira did, and what Linear started with. So
build the categories, skip the graph.

**3. Linear is the existence proof.** It is the most-loved tracker of the
three and it deliberately does not offer arbitrary workflows — fixed
categories, light per-team status naming. Nobody churns off Linear over it.
Meanwhile Jira's workflow editor is its most-complained-about surface.

**4. Free transitions beat wrong transitions.** A transition graph guessed by
me, for teams I have not met, will be wrong for most of them, and being unable
to move a stuck ticket backwards is a daily papercut. The activity log already
records who moved what, when — which is the actual reason people want
transition control.

**5. Six statuses covers the observed space.** Jira's default is 3, Linear's
is 5 categories, Asana has none. Six with In Review is a superset of common
practice, and the category mapping is what code branches on, so adding a
seventh in v2 is a seed migration, not a refactor.

### What makes this reversible

The statuses live in a **table**, keyed per workspace, with a `category`
column — not a PHP enum on the issue. All product code branches on
`category`, never on a specific status id or key. Consequences:

- v2 "rename your statuses" = one `UPDATE` and a permission check.
- v2 "add a custom status" = one `INSERT`, plus a `project_id` column on
  `issue_statuses` when per-project sets are wanted.
- v2 "real workflows" = a new `workflow_transitions` table and a validator.
  No data migration for existing issues.

I am spending one migration's worth of foresight now to avoid a rewrite later.
I am not spending a module on it.

---

## 4. Permission model

### Shape: two layers — workspace role, then project membership

**Workspace role** answers *"what class of user are you here?"*
**Project membership** answers *"which projects, and what may you do in them?"*

Effective permission on a project is:

```
effective_role = max(
    role implied by workspace role (see grant table),
    role from an explicit project_members row
)
```

…with one hard override: **`guest` is never granted anything implicitly.** A
guest's access is exactly the union of their explicit `project_members` rows.

### Workspace roles

| Role | Intent |
|---|---|
| `owner` | Billing and existential control. Exactly one. |
| `admin` | Runs the workspace: people, projects, settings. |
| `member` | Normal employee. Sees the company's work by default. |
| `guest` | Contractor / client. Sees only what they are invited to. |

### Implied project role, by workspace role and project visibility

| Workspace role | `visibility = workspace` | `visibility = private` |
|---|---|---|
| `owner` | `project_admin` | `project_admin` |
| `admin` | `project_admin` | `project_admin` |
| `member` | `contributor` | none — needs an explicit row |
| `guest` | **none** — needs an explicit row | **none** — needs an explicit row |

Owners and admins can read every project including private ones. This is
deliberate: someone has to be able to recover a private project whose only
member left the company. It is disclosed in the UI ("workspace admins can
access all projects") rather than pretended away.

### Project roles

| Role | Intent |
|---|---|
| `project_admin` | Configures the project, manages its members. |
| `contributor` | Does the work: creates, edits, transitions, comments. |
| `viewer` | Reads and comments. Cannot change issue state. |

### Capability matrix

Workspace-level:

| Capability | owner | admin | member | guest |
|---|:-:|:-:|:-:|:-:|
| View workspace settings | ✅ | ✅ | read-only | ❌ |
| Rename workspace / change slug | ✅ | ✅ | ❌ | ❌ |
| Invite members (`member`/`guest`) | ✅ | ✅ | ❌ | ❌ |
| Invite/promote to `admin` | ✅ | ✅ | ❌ | ❌ |
| Deactivate a member | ✅ | ✅ | ❌ | ❌ |
| Transfer ownership | ✅ | ❌ | ❌ | ❌ |
| Delete workspace | ✅ | ❌ | ❌ | ❌ |
| Manage billing | ✅ | ❌ | ❌ | ❌ |
| Create a project | ✅ | ✅ | ✅ | ❌ |
| Create/edit/delete labels | ✅ | ✅ | ✅ | ❌ |
| List all projects in workspace | ✅ | ✅ | workspace-visible only | ❌ |
| Cross-project views / search | ✅ | ✅ | readable set only | readable set only |

Project-level (by **effective** project role):

| Capability | project_admin | contributor | viewer | no access |
|---|:-:|:-:|:-:|:-:|
| See project exists | ✅ | ✅ | ✅ | ❌ (404) |
| Read issues, comments, activity | ✅ | ✅ | ✅ | ❌ |
| Create issue | ✅ | ✅ | ❌ | ❌ |
| Edit issue title/description | ✅ | ✅ | ❌ | ❌ |
| Change status / assignee / priority / labels | ✅ | ✅ | ❌ | ❌ |
| Reorder (drag) issues | ✅ | ✅ | ❌ | ❌ |
| Link issues / create sub-tasks | ✅ | ✅ | ❌ | ❌ |
| Delete issue (soft) | ✅ | reporter's own only | ❌ | ❌ |
| Comment | ✅ | ✅ | ✅ | ❌ |
| Edit own comment | ✅ | ✅ | ✅ | ❌ |
| Delete any comment | ✅ | ❌ | ❌ | ❌ |
| Watch / unwatch | ✅ | ✅ | ✅ | ❌ |
| Edit project name/description/lead | ✅ | ❌ | ❌ | ❌ |
| Add/remove project members | ✅ | ❌ | ❌ | ❌ |
| Change project visibility | ✅ (+ ws admin) | ❌ | ❌ | ❌ |
| Archive project | ✅ | ❌ | ❌ | ❌ |
| Delete project | ws owner/admin only | ❌ | ❌ | ❌ |
| Create private saved view | ✅ | ✅ | ✅ | ❌ |
| Create shared saved view | ✅ | ✅ | ❌ | ❌ |

### Enforcement rules

1. **Three layers, all mandatory.** (a) The tenant global scope makes
   cross-workspace data unreachable by query. (b) Laravel Policies gate every
   action, invoked via `#[Authorize]` attributes or explicit `authorize()`
   calls — never by a manual `if` in a controller. (c) The UI hides what the
   user cannot do. The UI layer is cosmetic; it is never the enforcement.
2. **A readable-project-ids resolver is the single source of truth** for
   scoping every list, board, filter, search, and notification query. It is
   computed once per request and cached for the request lifetime. One
   function, tested exhaustively, used everywhere.
3. **404, not 403, for anything the user cannot read.** 403 confirms
   existence, which leaks project names and issue keys across tenants and
   across private projects. 403 is used only where the user can see the object
   but not perform the action.
4. **Assignment is permission-checked** (Module 3 rule 5). So is mentioning
   (Module 4 rule 7). Notification fan-out re-checks read access at send time,
   because access may have changed between the mutation and the queued job
   running.
5. Sanctum token abilities (planned v2 public API) will map onto these same
   policies rather than defining a parallel model. The policy layer is written
   now assuming a token may carry a *subset* of a user's abilities — no policy
   reads `Auth::user()` directly and assumes full session rights.
6. **Cross-tenant tests are non-negotiable.** Every module ships a Pest test
   asserting that a user in workspace A gets 404 for every endpoint against
   workspace B's records, including via ids in request bodies.

---

## 5. Deferred to v2

Everything I wanted to add and did not. Nothing here is in v1.

**Workflow & configuration**
- Configurable per-project workflows, transition rules, guards, post-functions
- Custom statuses and status renaming
- Custom fields (the single biggest v2 item; changes the read model everywhere)
- Custom issue types, per-project type sets
- Issue templates, default field values
- Screens / field configuration per type
- Resolution field distinct from status

**Planning**
- Sprints / cycles, sprint burndown, sprint reports
- Epics and a hierarchy above issues
- Roadmap / timeline / Gantt view
- Backlog grooming view distinct from list view
- Capacity planning, workload view
- Milestones, releases, versions, "fix version"

**Reporting**
- Velocity, burndown, cumulative flow, cycle/lead time
- Dashboards and widgets
- Time tracking, worklogs, original/remaining estimates
- Exports (CSV, XLSX)

**Collaboration**
- Threaded comment replies
- Emoji reactions
- Rich text editor with embeds; tables and images in descriptions
- **File attachments** (needs object storage, quotas, AV scanning, signed
  URLs, and per-tenant storage accounting — a module on its own)
- Real-time collaborative presence and live updates (Reverb/websockets)
- Digest and Slack notifications; per-project notification overrides
- Comment edit history

**Access & identity**
- SSO / SAML / SCIM provisioning
- Custom roles and granular permission schemes
- Per-issue security levels
- Public/anonymous issue portals, customer request forms
- Two-factor authentication
- Audit log export, compliance reporting

**Platform**
- Public REST API with Sanctum tokens, versioning, OpenAPI docs *(explicitly
  your planned late phase — confirmed deferred)*
- Webhooks and outgoing integrations
- Automation rules ("when X then Y")
- GitHub/GitLab commit and PR linking
- Import from Jira/Linear/Asana/CSV
- Bulk edit, bulk transition
- Saved-search subscriptions
- Email-to-issue ingestion
- Billing, plans, subscription limits, trials
- Mobile app, offline support
- Full-text search via Scout/Meilisearch/Elasticsearch
- AI features (Laravel 13's AI SDK, semantic search) — note this would want
  PostgreSQL+pgvector, which conflicts with MySQL 8; revisit deliberately

**Data**
- Issue history diffing / restore to a prior version
- Multi-level sub-task hierarchies
- Cross-workspace issue moves
- Project key renaming with redirects
- Hard-delete / GDPR erasure tooling beyond the 30-day soft-delete window

---

## 6. ASSUMPTIONS

Every decision I made on your behalf. Each is cheap to reverse now and
expensive later — please read this section even if you skim the rest.

**Stack**

- **A-1 — PHP 8.4.** Laravel 13 allows 8.3–8.5. I chose 8.4 over 8.5 because
  extension and static-analysis tooling lag on the newest PHP for months.
  Reverse if you have a specific 8.5 need.
- **A-2 — TypeScript: pin deliberately, do not blindly take `latest`.** npm's
  `latest` for `typescript` currently resolves to **7.0.2** (the rewritten
  native compiler). It is genuinely faster, but tooling around it —
  `typescript-eslint`, some Vite/Babel plugins, shadcn/ui codegen — has
  historically lagged major compiler transitions. **I assume you want the
  newest TS that your lint + shadcn toolchain fully supports, verified at
  scaffold time**, with a fallback to the latest 5.x/6.x line. `strict: true`
  either way, as you specified. Flag if you want TS 7 unconditionally.
- **A-3 — Tailwind 4.3.** v4 moved configuration into CSS; shadcn/ui setup
  differs from every v3-era tutorial. Assumed acceptable.
- **A-4 — Inertia SSR is off in v1.** It's a real ops burden (a second Node
  process) for an authenticated-only app with no SEO surface.
- **A-5 — ULIDs, not auto-increment integers, for all primary keys.** Reason:
  non-enumerable ids in a multi-tenant URL space, and no cross-tenant id
  collision guessing. Cost: 26-char keys, slightly larger indexes.
- **A-6 — Docker Compose for local dev only.** Production topology is out of
  scope here. Note the working directory is `D:\herd\jira-clone` — a Laravel
  Herd path — so I assume Herd-or-Docker locally, and Compose is not the only
  supported path.
- **A-7 — Redis is used for queues, cache, and sessions.** You specified
  queues; I extended it. Say so if you want sessions in the database.
- **A-8 — Horizon for queue supervision.** Redis queues without a dashboard
  makes failed-job triage guesswork.
- **A-9 — Larastan level 5 as specified**, but I assume the intent is "level 5
  now, ratchet upward later" rather than a permanent ceiling.

**Product**

- **A-10 — Single-region, single-database deployment.** No tenant sharding, no
  data residency requirements. Changing this later is a large migration.
- **A-11 — Search is MySQL 8 `FULLTEXT`, not Scout.** Adequate to roughly
  100k issues per workspace with title-only indexing. It will not do good
  relevance ranking on description bodies. Explicitly a v2 upgrade path.
- **A-12 — Six fixed statuses with the names in §3.** "In Review" in
  particular is an opinion; some teams will want it gone.
- **A-13 — Three issue types: Task, Bug, Story.** No Epic — epics imply a
  hierarchy level, which is v2.
- **A-14 — Priority is an enum column, not a table.** Cheaper to query and
  sort; not user-configurable. Consistent with statuses being fixed.
- **A-15 — Estimates are unitless integers (story points).** Not hours. No
  time tracking anywhere in v1.
- **A-16 — `due_on` is a date, not a datetime.** Avoids cross-timezone
  "due today" disputes entirely.
- **A-17 — Sub-tasks are exactly one level deep.** See Module 3 rule 6.
- **A-18 — Labels are workspace-wide, not per-project.** Makes cross-project
  filtering work; means label lists grow and will eventually need curation
  tooling (v2).
- **A-19 — Comments are flat.** No threads.
- **A-20 — No file attachments in v1.** This is the deferral most likely to
  draw complaints in user testing, and the one I'd promote to v1 first if you
  push back. It is genuinely a module of work (storage, quotas, scanning,
  signed URLs, tenant accounting), which is why it lost against the 5-module cap.
- **A-21 — Markdown, not a rich-text/block editor.** Storing Markdown source
  keeps the v2 editor swap open.
- **A-22 — Notification channels are in-app + email only.**
- **A-23 — Registration self-creates a workspace.** No admin-provisioned or
  waitlisted signup, no billing gate. Add billing before launch.
- **A-24 — Guests are strictly explicit-access.** If your model is
  "contractors see everything in one project", they're guests with a
  `project_members` row. If it's "clients file tickets and see only their
  own", that's per-issue security — v2.
- **A-25 — Workspace admins can read private projects.** A trust decision, not
  a technical one. Disclosed in the UI.
- **A-26 — Soft delete everywhere, 30-day restore, no hard-delete tooling.**
  If you have GDPR erasure obligations at launch, this is a gap and needs to
  enter v1 scope.
- **A-27 — English only, no i18n scaffolding.** Retrofitting is mechanical but
  touches every view.
- **A-28 — Accessibility target: keyboard-navigable, WCAG 2.1 AA on the core
  flows.** Assumed, not stated by you. Drag-and-drop on the board needs a
  keyboard alternative, which is real work — flag if you want it descoped.
- **A-29 — The public REST API is deferred as you specified.** I have
  pre-shaped the policy layer to accept token-scoped abilities (§4 rule 5) so
  that phase is additive rather than a rewrite.
- **A-30 — No seed/demo data story defined.** New workspaces start empty
  except for statuses, types, and zero labels. An onboarding sample project is
  usually worth it; call it if you want one.

**Explicitly open questions for you**

1. Attachments in v1 or v2? (A-20 — my strongest deferral, and the weakest.)
2. Is "In Review" the right sixth status for your target customer? (A-12)
3. Do workspace admins reading private projects match your trust model? (A-25)
4. Any GDPR/erasure obligation at launch? (A-26)
5. TypeScript 7 unconditionally, or newest-fully-supported? (A-2)
