# 09 — Audit trail

**Status:** Done
**Commits:** `1565522` (api), `024ca13` (web)

## Goal
An organization's audit log: who changed what and when. It covers members, invitations and roles (security events) and tasks (the data). Owners, and roles given the new `audit_log.view` permission, read it on an Audit log page with filters. Entries are append-only and pruned after a configurable retention.

Out of scope: logging logins and organization switches (per user, not organization events), exporting the log, and a platform-wide log (plan 17).

## Decisions
- **Two ways to write, as in saas-starter.** The `Auditable` trait records create/update/delete on models people edit (`Task`, `Role`), with a diff of the changed columns. Other actions (inviting, joining, role changes, removals) call `RecordAudit` themselves. Both write inside the change's transaction, so a rolled-back change leaves no entry.
- **New permission `audit_log.view`.** Admin gets it by default (in `DefaultRole`), Member doesn't. Existing roles get it through a data migration, chosen by permission (non-owner roles with `members.manage`), never by the name "Admin".
- **IP addresses only for owners** (`role()->is_owner`): it's personal data, and `audit_log.view` alone shouldn't show it.
- **The log keeps names, not just ids.** The subject's label (task title, role name) and the role names in role changes are copied into the entry. Task assignees are recorded as `{id, name}`, not a bare `assignee_id`. That way entries still read correctly after a task, role or member is gone.
- **Task content isn't hidden.** saas-starter hid project content from viewers without `projects.view`. Here everyone in the organization reads tasks, so there's nothing to hide.
- **Cursor pagination with "Load more"** (Laravel `cursorPaginate`, ordered by `id desc`). This was saas-starter's follow-up: no count query per page. In the app it's a TanStack Query `useInfiniteQuery`. The cursor isn't in the URL, but the filters are.
- **Retention:** `AUDIT_RETENTION_DAYS` (default 365, `0` = keep forever), with `model:prune` scheduled daily next to the invitations one.
- **Task unassignments from `RemoveMember` aren't logged one by one.** It stays a builder update; the `member.removed` entry records how many tasks lost their assignee (`metadata.unassigned_tasks`).
- **New thin actions** so nothing bypasses logging: `RevokeInvitation` (from `InvitationController::destroy`) and `DeleteTask` (from `TaskController::destroy`).
- **No new npm or composer dependency.** The row expander is shadcn `collapsible`. It comes from the `radix-ui` package that's already installed, added with `npx shadcn add collapsible`.

## Backend (`api/`)
**Migrations (new):**
- `create_audit_logs_table`: `id`; `organization_id` (FK, cascade on delete); `actor_id` (nullable FK users, null on delete); `action` (string 64); `subject_type` (string 64, nullable); `subject_id` (nullable bigint); `subject_label` (string, nullable); `changes` and `metadata` (jsonb, nullable); `ip_address` (string 45, nullable); `created_at` (not null, no `updated_at`).
  - Indexes `(organization_id, id)`, `(organization_id, action, id)` and `(organization_id, actor_id, id)` match `ORDER BY id DESC` under each filter.
  - `created_at` gets its own index, for the date filter and pruning.
- `grant_audit_log_view`: appends `audit_log.view` to non-owner roles whose permissions contain `members.manage`, unless they already have it (jsonb, so running it twice changes nothing). `down()` removes it from every role.

**Model** `App\Models\AuditLog`:
- `BelongsToOrganization` and `MassPrunable`; `UPDATED_AT = null`.
- Written with `forceFill`; nothing is fillable.
- `updating` and `deleting` throw `LogicException`.
- `prunable()` uses `withoutGlobalScope(OrganizationScope::class)`, since the scheduler has no current organization. It matches nothing when retention is 0.
- Casts: `action` → `AuditAction`, `changes`/`metadata` → array.
- Relation: `actor()` belongsTo User.

**Enum** `App\Enums\AuditAction`, with `label()` and `group()` like `Permission`:
- Organization: `organization.created`.
- Members:
  - `member.invited` (metadata: email, role `{id, name}`; never the token);
  - `invitation.revoked` (email);
  - `member.joined` (role);
  - `member.role_changed` (old and new role `{id, name}`, read under the existing lock);
  - `member.removed` (`left: true` when it was the user themselves, plus `unassigned_tasks`).
- Roles: `role.created`, `role.updated`, `role.deleted` (Auditable; permission edits show in the `permissions` diff).
- Tasks: `task.created`, `task.updated`, `task.deleted` (Auditable).

**Writing:**
- `App\Actions\Audit\RecordAudit::handle(AuditAction, ?array $subject, array $changes = [], array $metadata = [], ?Organization $organization = null)`.
  - The actor is the logged-in user (null in jobs and console).
  - The IP is recorded only for a logged-in user's request.
  - With `$organization`, it writes inside `CurrentOrganization::run()`. That's needed for `AcceptInvitation` and `CreateOrganization`, which don't act in the current organization.
- `App\Models\Concerns\Auditable`, adapted from saas-starter:
  - It never records keys, `organization_id`, timestamps, `$hidden` or `$auditExclude`.
  - It compares full values and shortens stored strings to 500 characters.
  - An update that changes nothing relevant isn't logged.
  - `withoutAuditing()` turns it off for a callback.
  - It throws if the model's `organization_id` isn't the current organization.
  - Models can override `auditLabel()` and `auditAttributes()`.
- `Task`: label = title; `created_by` excluded (the actor shows it); `assignee_id` is recorded as `assignee: {id, name}|null`.
- `Role`: label = name; `is_owner` excluded (never changes).
- Curated calls are added to `CreateOrganization`, `InviteMember`, `RevokeInvitation` (new), `AcceptInvitation`, `ChangeMemberRole` and `RemoveMember`. `CreateOrganization` wraps `CreateDefaultRoles` in `Role::withoutAuditing`, so creating an organization logs one `organization.created` entry and no `role.created`.
- `CreateRole`, `UpdateRole` and `DeleteRole` already run in transactions. `UpdateTask` gets one if it lacks it. `DeleteTask` is new.

**Permission:** `Permission::AuditLogView = 'audit_log.view'`, group "Organization", label "View the audit log". It's added to `DefaultRole::Admin`.

**Reading** (in the `organization` middleware group):
- `GET /api/audit-logs`:
  - `AuditLogPolicy::viewAny` (`audit_log.view`).
  - `ListAuditLogsRequest` validates `action` (enum), `actor` (integer), and `from`/`to` (`Y-m-d`, `to` on or after `from`, read as days in `app.timezone`).
  - Query: `latest('id')->with('actor:id,name')->cursorPaginate(25)->withQueryString()`.
- `GET /api/audit-log/actions`: the catalogue `[{value, label, group}]` for the filter, like `/api/permissions`.
- `AuditLogResource` returns:
  - `id`, `action`, `action_label`;
  - `actor {id, name}|null`;
  - `subject {type, id, label}|null`;
  - `changes`, `metadata`;
  - `ip_address`, only when the viewer's role `is_owner`;
  - `created_at`.

**Config:** `config/audit.php` (`retention_days`). `.env.example` gets `AUDIT_RETENTION_DAYS=365` with a comment. `routes/console.php` gets the daily `model:prune` for `AuditLog`.

## Frontend (`web/`)
- `types/api.ts`:
  - `'audit_log.view'` in `Permission`;
  - `AuditLog`;
  - `AuditActionInfo`;
  - `CursorPage<T>` (`data`, `meta.next_cursor`).
- `features/audit/api.ts`:
  - `useAuditLog(filters)`: `useInfiniteQuery` with key `['org', id, 'audit-log', filters]` and `getNextPageParam` from `meta.next_cursor`.
  - `useAuditActions()`: key `['audit-actions']`, `staleTime: Infinity`.
  - Role, member, invitation and task mutations don't invalidate it. The page refetches when it mounts (the default), which is enough for a log.
- `routes/AuditLogPage.tsx`, wired in `router.tsx` as `/audit-log`:
  - **Filters in the URL:** action (Select, grouped), actor (Select of current members from `useMembers`), and from/to (`<input type="date">`). They're parsed on every render; changes replace the history entry.
  - **List:** a table of time, actor, action and subject. Each row opens with "Details" (Collapsible) to show the changes and metadata, with a "Load more" button below.
  - **States:** loading, empty ("Nothing recorded yet" / "No entries match these filters") and error. A 403 shows "You don't have access to the audit log."
- `features/audit/AuditChanges.tsx` renders every value as plain text:
  - old → new per field;
  - `permissions` as added and removed labels, from the permissions catalogue;
  - `assignee` and roles by name;
  - a missing value as "—".
- `AppSidebar`: the Audit log entry (`ScrollTextIcon`) has `permission: 'audit_log.view'`, and the list is filtered by the current organization's permissions. It's the first nav entry that depends on a permission.

React concepts:
- `useInfiniteQuery` ↔ `useAsyncData` plus your own array you push pages onto. TanStack keeps the pages in its cache, and a new query key (other filters) starts over by itself.
- A row component with its own `useState` (open/closed) ↔ a `ref` inside a child component in `v-for`. Each row keeps its own state because React matches rows by `key`.
- Filtering a list during render (`navigation.filter(...)` from permissions) ↔ a `computed` feeding `v-for`. There's no stored copy to keep in step.
- Text in JSX is always escaped ↔ `{{ }}` / `v-text`, never `v-html`. That's why untrusted log values are safe to show as they are.

## Tests
**Pest** (`api/tests/Feature/AuditLog/`):
- **Isolation:**
  - org B never sees org A's entries;
  - filtering org B's log by an org A user's id returns nothing;
  - `member.joined` lands in the invitation's organization, not the user's previous current one;
  - an Auditable model changed while acting in another organization throws.
- **Permissions:**
  - Owner and Admin get 200; Member gets 403;
  - a custom role with `audit_log.view` gets 200, without it 403;
  - `ip_address` is visible only to owners;
  - the migration grants the permission to existing non-owner roles with `members.manage` and leaves the others alone.
- **Auditable:**
  - task create, update and delete write diffs;
  - excluded fields are absent;
  - the assignee is recorded by name;
  - an update that changes nothing writes nothing;
  - role permission edits show in the diff;
  - creating an organization writes one `organization.created` and no `role.created`.
- **Curated:**
  - each action writes the expected action, actor, subject and metadata;
  - the invitation token never appears in the entry;
  - a rolled-back action (for example the last-owner refusal) leaves no entry;
  - `member.removed` counts unassigned tasks.
- **Filters and validation:**
  - action, actor and date filters;
  - an invalid action or a `to` before `from` → 422;
  - the cursor's next page doesn't overlap the first.
- **Retention:**
  - `model:prune` deletes old entries in every organization;
  - `0` keeps everything;
  - updating or deleting a single entry throws.

**Vitest** (`routes/AuditLogPage.test.tsx`):
- lists entries and loads more;
- filters write to the URL and refetch;
- details show old → new as text (including a `<b>` title shown literally);
- the empty and error states, and the 403 message;
- the sidebar hides "Audit log" without the permission.

## README screenshots
- New `docs/screenshots/audit-log.png`: the Audit log page with a few entries from e2e demo data (task edits, a role change, an invitation), one row expanded to show a diff.
- Light mode, 1280×800, `e2e-*` accounts cleaned up.

## Verification
- `/verify`.
- Reviewers in parallel: code, security, tenant isolation, database.
- `ui-reviewer` on `/audit-log`: 390px, keyboard focus in the filters and expanders, empty state, a Member sees no link and gets the 403 state, dark mode, console.

Flows (for `flow-tester`):
1. As an owner, create a task, edit its title and assignee, then delete it → three task entries on `/audit-log`, with the edit showing old → new title and the assignee by name.
2. Invite `e2e-ft-…` as Member, accept the invitation from the email link → "Invited someone" and "Joined" entries, and the email isn't shown with any token.
3. Change that member's role to Admin, then edit the Admin role's permissions → "Changed a member's role" (Member → Admin) and "Edited a role" (permission added/removed by label).
4. Log in as the member (now Admin) → the Audit log link is shown and the page loads, without IP addresses. Change them back to Member → no link, and `/audit-log` shows the no-access message.
5. Filter by action "Edited a task" and by a date range → only matching rows; the URL keeps the filters after a reload; "Load more" appears only when there are more.
6. Switch to another organization → its own log, with none of the first organization's entries.

## Commits after approval
`feat(api): add audit log`, `feat(web): add audit log page`, then this plan → Done.

## Changed while building
- **Emails only with `members.invite`.** A role with only `audit_log.view` (an "Auditor") would have listed every invitee's email, which `GET /api/invitations` refuses it. `AuditLogResource` now hides `metadata.email` and invitation labels from roles without `members.invite` (security review).
- **`RecordAudit` takes an `actor:`.** Registering creates the personal organization before anyone is logged in, so its first entry had no actor. `CreateOrganization` passes the owner (code review).
- **The action catalogue is under `['org', id, 'audit-actions']`**, not user-level. It's the same everywhere, but its 403 belongs to one organization: a cached 403 would have outlived a switch to an organization where you may read the log (code review).
- **The cursor is validated:** one without an `id` was a 500 from the paginator, now a 422.
- **An `actor_id` index**, for setting it to null when a user is deleted (database review; no user deletion exists yet).
- **`CreateDefaultRoles` itself runs `withoutAuditing`**, not just `CreateOrganization`, so organization factories in tests don't log either.
- **Giving a member the role they already have records nothing** (it used to be a no-op write).
- **"Ana joined", not "Ana joined: Ana":** the list leaves out the subject when it's the actor themselves (joining, leaving).

## Outcome
- Reviewers:
  - database, tenant isolation and security found no high or medium issues;
  - code review's one medium (registration's entry had no actor) is fixed;
  - the low points are fixed above, and tenant isolation's test gaps are added (a 404 on another organization's records writes nothing, and a cursor can't leave the organization).
- Breaking it on purpose: 8 rules were disabled one at a time (the scope, the policy, owner-only IP, the organization check in `Auditable`, `organization:` when joining, skipping empty updates, retention 0, same-role changes), and each one made a test fail. Empty updates needed a new test, since an unchanged PATCH never saves.
- flow-tester: 6/6 flows passed. ui-reviewer: layout at 390px, details, empty state, member access, dark mode and console all passed. The Details focus ring was checked again in the browser.
- 313 Pest tests and 160 Vitest tests. `/verify` passes.

## Follow-ups
- A Postgres trigger (or a separate database role) would make the log append-only below Eloquent too: `DB::table('audit_logs')->delete()` still works.
- When deploying behind a load balancer, set trusted proxies to its exact addresses, so entries record the client's IP and not the proxy's.
- Entries keep personal data (emails, up to 500 characters of a deleted task's description) for `AUDIT_RETENTION_DAYS`. Say so in a privacy note when there is one.
- From earlier plans: re-check an invitation's role when it's accepted, phpunit 13.4.4 or later, and `APP_DEBUG` off in production.
