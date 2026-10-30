# 08 — Custom roles and permissions

**Status:** Done
**Commits:** `80e2cdc` (api), `d446317` (web)

## Goal
Each organization defines its own roles (say "Viewer" or "Project lead") from a fixed catalogue of permissions, and assigns them to members and invitations. Owner, Admin and Member become ordinary per-organization rows (Owner built in and locked), and every existing membership and invitation keeps what it can do today. Policies already check permissions (plan 05–07), so this mostly swaps where a role's permissions come from: an enum today, a `roles` row afterwards. It rebuilds saas-starter plan 10, with its Outcome and Follow-ups folded in.

Out of scope:
- per-task or per-resource permissions, more than one role per member;
- an audit log of role changes (plan 09 adds it);
- re-checking a pending invitation against its inviter's current permissions (a follow-up, as in saas-starter);
- `organization.manage` is removed rather than kept unused (see Changed while building); plan 14 adds what it needs for renaming or deleting an organization.

## Decisions
- **Permission catalogue** (`App\Enums\Permission`, with `label()` and `group()` for the UI):

  | Group | Permission | Owner | Admin | Member | Today |
  |---|---|---|---|---|---|
  | Tasks | `tasks.create` (new): create tasks, edit the ones you created or are assigned to, delete the ones you created | ✓ | ✓ | ✓ | any member |
  | | `tasks.manage`: edit and delete any task | ✓ | ✓ | | Owner, Admin |
  | Members | `members.invite`: invite, see and revoke invitations | ✓ | ✓ | | Owner, Admin |
  | | `members.manage`: change roles, remove members | ✓ | ✓ | | Owner, Admin |
  | Roles | `roles.manage` (new): create, edit and delete roles | ✓ | | | — |

  `tasks.create` is new so that a read-only role is possible: seeing tasks and the member list, and leaving, stay open to every member. `roles.manage` goes to Owner only by default (an owner can give it to Admin or any role); I kept `members.manage` as one permission rather than splitting change-role and remove, since the subset rules below already separate what each actor can do.
- **Owner** is a row with `is_owner = true`: it grants every permission (present and future), and can't be renamed, edited or deleted. Only owners give or take the owner role. `EnsuresAnOwnerRemains` keeps working, on `roles.is_owner`.
- **No privilege escalation**, the rules from CLAUDE.md with `includes()` now on roles:
  - Assigning a role (change role, invite, revoke an invitation): the new role **and** the member's (or invitation's) current role must be ones your role includes. Same behaviour as today.
  - Removing a member: their role must be one yours includes (today's rule: an admin can remove an admin). I'm keeping it rather than saas-starter's strict subset so existing behaviour and tests don't change; say if you want admins unable to remove each other.
  - Roles (`roles.manage`): you can only grant permissions you have, and only edit or delete roles that can do **strictly less** than yours: not your own, not a peer's (emptying a peer's role is a removal you couldn't do directly). This was a security finding in saas-starter.
- **Deleting a role** used by members → 422 with the count ("Move its 3 members to another role first"). Pending invitations with it lose their role (`ON DELETE SET NULL (role_id)`) and can't be accepted ("This invitation is no longer valid"); the inviter can re-invite.
- **A `role_id` from the client is only a reference:** validated with `Rule::exists('roles', 'id')->where('organization_id', current id)`, and the foreign keys are composite `(organization_id, role_id) → roles (organization_id, id)`, so the database refuses another organization's role too.
- **Races** (saas-starter follow-ups, fixed here): the assign actions lock the role row `FOR SHARE`, so a role deleted at the same moment gives a 422, not a 500; a duplicate role name racing another gives 422 (unique index caught as a validation error).
- **Migration on real data:** a new migration (nothing committed is edited) creates the three default roles per organization from its own snapshot of today's permissions, maps the `role` strings to `role_id`, then drops `role`. `down()` maps back (owner; admin if the role has `members.manage`; else member). I'll test it on a copy of the dev database before running it on the dev one (`createdb -T`, no wipe).
- **`OrganizationRole` becomes `DefaultRole`** (names and default permissions), used by `CreateDefaultRoles`, factories and the Pest helpers, so `userInOrganization(DefaultRole::Admin)` keeps working and existing tests keep their expectations.
- **Page:** `/roles`, a "Roles" sidebar entry after Members, visible to everyone (anyone can see what a role allows); controls only with `roles.manage`.
- **Limits:** role names 1–50 characters, unique per organization ignoring case; at most 50 roles per organization (422 beyond); writes `throttle:60,1,roles`.
- **No new dependencies.** shadcn `checkbox` and `table` are already installed.

## Backend (`api/`)
- **Migration** `create_roles_table` (new): `roles` (id, `organization_id` FK cascade, `name` string(50), `is_owner` bool default false, `permissions` jsonb default `[]`, timestamps; unique `(organization_id, id)` for the composite FKs; unique index on `(organization_id, lower(name))`; partial unique index: one `is_owner` per organization). `role_id` on `organization_user` (NOT NULL after backfill, composite FK `ON DELETE NO ACTION`) and `organization_invitations` (nullable, `ON DELETE SET NULL (role_id)`, needs Postgres 15+: CI runs 17), both indexed; backfill; drop `role`.
- **Model** `app/Models/Role.php`: `BelongsToOrganization`, `#[Fillable(['name', 'permissions'])]` (never `is_owner`), `permissions` as `AsEnumCollection::of(Permission::class)`, `grants()` (all for the owner role), `can()`, `includes(Role)`, `includesStrictly(Role)`, `memberships()`, `invitations()`. `Membership::role()` and `OrganizationInvitation::role()` belongsTo. `database/factories/RoleFactory.php`.
- **`CurrentOrganization`:** `role()` returns the `Role` (eager loaded with the membership: one query, as today); `can()` unchanged for callers; `run()`/`set()` take `?Role` and refuse one from another organization.
- **Actions:**
  - `app/Actions/Roles/CreateDefaultRoles.php` (used by `CreateOrganization` and factories; the migration keeps its own snapshot);
  - `CreateRole`, `UpdateRole`, `DeleteRole` (locks the role row, counts members inside the lock);
  - updated: `ChangeMemberRole`, `InviteMember`, `AcceptInvitation`, `RemoveMember`, `EnsuresAnOwnerRemains` (`role_id` and `is_owner`, the role row locked `FOR SHARE`).
- **Policies:** `RolePolicy` (viewAny: any member; create: `roles.manage` and only permissions you have; update/delete: `roles.manage`, not the owner role, target strictly included, new permissions all yours). `MembershipPolicy` and `OrganizationInvitationPolicy` take a `Role` instead of the enum. `TaskPolicy`: create, edit-own and delete-own need `tasks.create`.
- **Form requests** in `app/Http/Requests/Roles/`: `StoreRoleRequest`, `UpdateRoleRequest` (`name` required string max 50, unique per organization ignoring case; `permissions` array, `permissions.*` `Rule::enum(Permission::class)`, distinct). `UpdateMemberRequest` and `StoreInvitationRequest` take `role_id` (scoped exists) instead of `role`.
- **Resources:**
  - `RoleResource`: `{id, name, is_owner, permissions, members_count, can: {update, delete}}`.
  - `MemberResource`, `InvitationResource`, `InvitationPreviewResource` and `OrganizationResource` return `role: {id, name, is_owner}`; `assignable_roles` and `invitable_roles` become lists of those (the API still decides).
  - `GET /api/permissions` returns the catalogue: `[{value, label, group}]`.
- **Routes** in the `organization` group: `GET /api/permissions`, `apiResource('roles')->except('show')` with the 18-digit id limit. `PATCH /api/members/{user}` takes `{role_id}`, `POST /api/invitations` takes `{email, role_id}`.

## Frontend (`web/`)
- **Types** (`types/api.ts`): `Role` (`{id, name, is_owner}`), `RoleDetails` (with `permissions`, `members_count`, `can`), `PermissionInfo`; `role` fields become `Role`; `Permission` gains `tasks.create` and `roles.manage`. `roleLabel()` goes away: the name comes from the API.
- **`features/roles/api.ts`:** `rolesQuery` (`['org', id, 'roles']`), `permissionsQuery` (`['permissions']`, user-level, `staleTime: Infinity`: it only changes with a deploy), `useCreateRole`, `useUpdateRole`, `useDeleteRole` (invalidate roles and members; your own role can't be edited by you, so `['me']` stays as it is).
- **`/roles`** (`routes/RolesPage.tsx`): a table of roles (name, "Built in" badge for Owner, member count, permission summary like "Tasks: create, manage · Members: invite"). With `roles.manage`: "New role" and Edit open `RoleFormDialog` (name, and checkboxes grouped by area; permissions you don't have are disabled with a hint), Delete through `ConfirmDialog` (the 422 count shown as the error). Loading, empty (never: Owner always exists) and error states.
- **Members:** the role select and the invite form use the role objects from `assignable_roles` / `invitable_roles` and send `role_id`.
- **Tasks:** "New task" and the edit controls follow `useCan('tasks.create')` and the existing `can` flags.
- **Sidebar:** a "Roles" entry (`ShieldIcon`) after Members.

React concepts:
- A checkbox group bound to one array field: `Controller` whose `value` is the list and `onChange` adds or removes one ↔ `v-model` on several checkboxes bound to one array.
- Deriving grouped rows with `useMemo` from two queries (roles and the catalogue) ↔ a `computed` over two `useAsyncData` results.
- Rendering the form only once both queries have data, instead of an effect that waits for them ↔ `v-if` on both `pending` flags.
- Disabled-but-visible options (`disabled` plus `aria-describedby` for the reason) ↔ the same, nothing new in Vue, but React's controlled checkbox needs `checked` + `onCheckedChange` together.

## Tests
- **Pest:**
  - `tests/Feature/Roles/ManageRolesTest.php`: create, rename, change permissions, delete; validation (empty or 51-character name, duplicate name in any case, unknown permission, more than 50 roles); deleting a role in use → 422 with the count; deleting a role with pending invitations → they can't be accepted.
  - `RolePermissionsTest.php`: the matrix (Owner, Admin, Member, a custom role with `roles.manage`): can't grant a permission you lack, can't edit your own role or a peer's, can't touch the owner role, Member gets 403.
  - `RoleIsolationTest.php`: org A can't list, update, delete, assign or invite with org B's roles (404 or 422), including for someone in both organizations; a direct insert with B's role on A's membership fails on the foreign key.
  - `RoleMigrationTest.php`: run the backfill on rows in the old shape (memberships and invitations with `owner`/`admin`/`member`) → each organization has three roles and everyone keeps their permissions.
  - Updated: every existing members, invitations, organizations and tasks test switches to `DefaultRole` and `role_id` with the same expectations; a "Viewer" role (no `tasks.create`) sees tasks but gets 403 on create, edit and delete, even on a task assigned to them; new organizations get the three roles.
- **Vitest:** `RolesPage.test.tsx` (list, create with checkboxes, permissions you lack disabled, edit hidden for roles you can't edit, delete in use shows the 422, error state); `MembersPage.test.tsx` updated for role objects and `role_id`; `TasksPage.test.tsx`: no "New task" without `tasks.create`.

## README screenshots
- New: `docs/screenshots/roles.png` (the roles list as owner, with a custom "Viewer" role) and `role-edit.png` (the edit dialog with grouped checkboxes).
- Retaken: `members.png` (role names now come from roles; looks the same, retaken if anything shifts).

## Verification
- `/verify`, then the migration on a copy of the dev database (memberships keep their permissions; `migrate:rollback` is blocked by the hook, so `down()` is tested in Pest only), then on the dev database.
- Reviewers in parallel: code, security, tenant isolation, database, UI (`/roles`, `/members`, `/tasks` as owner, admin and viewer).
- `/e2e-smoke` with `--mail` and `flow-tester` on the flows below.

Flows (for `flow-tester`):
1. Owner opens Roles → sees Owner (built in), Admin and Member with their permissions and member counts.
2. Owner creates "Viewer" with no permissions → it appears with 0 members.
3. Owner invites a new address as Viewer, the invitee opens the email link and accepts → they see Tasks but no "New task", no Edit or Delete on a task assigned to them; a direct `POST /api/tasks` with their token → 403.
4. Owner gives Admin `roles.manage` → the admin opens Roles: can edit Member and Viewer, can't edit Owner or Admin; in the new-role dialog the permissions they lack are disabled; posting one anyway with curl → 403.
5. Owner tries to delete Viewer while it has a member → error with the count; moves the member to Member, deletes Viewer → gone.
6. A pending invitation's role is deleted → opening its link says it's no longer valid.
7. In a second organization, curl `PATCH /api/members/{id}` with a `role_id` from the first → 422; `PATCH /api/roles/{first org's role}` → 404.

## Commits after approval
`feat(api): add custom roles and permissions`, `feat(web): add roles page`, then this plan → Done. `CLAUDE.md` (multi-organization model: roles are rows with permissions, `DefaultRole`, the subset rules; Pest helpers) and `README.md` (status, endpoints, screenshots) change in the same commits.

## Changed while building
- **`organization.manage` removed** from `Permission` (and the web type): nothing checked it, and on the Roles page it would be a checkbox that does nothing.
- **One `RoleRequest`** for create and edit instead of two. Its `authorize()` runs the policy first (may you manage roles, or this role), so a member gets 403 before any field errors (saas-starter follow-up); the controller then checks the permissions being granted. Names also reject control and invisible characters (security review: "Admin" + a zero-width space looked like Admin).
- **No new escaping in the invitation email:** `Markdown::withSecuredEncoding()` has been on since plan 06 (a custom escape on top showed stray backslashes). A test shows a role name like `[Claim](https://…)` as text. The email now says "with the **X** role".
- **Assigning re-checks the locked role** (`LocksRole` takes the actor's role): if someone widened it between the policy and the lock, 422 instead of giving a role you no longer include (database and security reviews). `ChangeMemberRole` does the same for the member's current role.
- **Editing a role deleted meanwhile is a 404** (it's locked first in `SavesRole`), not a 200 that changed nothing.
- **The invite form's default role comes from the API** (`meta.default_role_id`: the invitable role with the fewest permissions, then the oldest), not by the name "Member" (code review: a renamed Member would have made the newest, maybe powerful, role the default).
- **`down()` deletes invitations whose role was deleted** instead of reviving them as "member".
- **The dev database had no organizations**, so testing the migration on a copy would have tested nothing: `RoleMigrationTest` runs `down()` then `up()` on rows in the old shape, and the migration ran on the dev database.
- **`members.png` not retaken:** the members page looks the same (the role select is a little wider).

## Outcome
- 273 Pest tests (49 new across `tests/Feature/Roles` and the updated suites; every existing test kept its expectations on the default roles) and 152 Vitest tests. `/verify` passes.
- Mutation checks, each caught: role edits without strict inclusion, granting without the cover check (create and update), unscoped `role_id` validation, `tasks.create` not required, `Membership::role()` through the scope, the in-use delete check, inviting any role.
- Reviewers: code, security, tenant isolation, database, UI. No high or medium findings. Fixed from their reports: the invite form's default role, Retry on /roles refetching both queries, one role lookup per member update, the locked-role re-checks, deleted-role edits, `down()` and invitations, Postgres `lower()` in the name check, invisible characters in names, and four isolation tests (the `roles()` cache in `run()`, `set()` refusing another organization's role, role choices for a member of two organizations, the invitation foreign key, the per-organization role limit).
- `flow-tester`: 7/7 flows passed (viewer invited and blocked from writing, admin with `roles.manage` limited to lesser roles and to permissions they have, in-use delete refused with the count, deleted-role invitation invalid, another organization's role ids refused), no errors in the log. `ui-reviewer`: 6/6 (390px, keyboard and focus return, disabled permissions, members role choices, dark mode, console).

## Follow-ups
- **Pending invitations outlive the inviter's permissions** (from saas-starter): re-check at accept time, or revoke on demotion. `Actions/Organizations/AcceptInvitation.php`.
- **The 50-role limit is approximate:** two creates at once can both pass at 49 (throttled per user). Lock the organization row in `CreateRole` if it ever matters.
- **Editing and deleting a role don't re-check the policy on the locked row** (only assigning does). The race needs a more privileged user changing your role at the same instant.
- **Error responses show stack traces** in the e2e environment (APP_DEBUG). Fine locally; make sure production runs with it off.
- **phpunit 13.4.x advisory** (GHSA-9vw2-f4vp-x7gf, dev only): update to 13.4.4+.

