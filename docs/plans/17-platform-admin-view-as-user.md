# 17 — Platform admin and view as user

**Status:** Done
**Commits:** `dfe2f29` (api), `067c769` (web)

## Goal
Give the people who run the app (not organization owners) an admin area: look up any user or organization, fix the usual support cases (lost phone, stolen session, abuse, an organization whose owners are gone), and open the app **as a user, read-only**, to see what they see. Admins need two-factor and a recent code on their token. Everything they look at or do is written to a platform log, and changes inside an organization also show in its own audit log as "Platform admin". This rebuilds saas-starter plans 25 (foundation), 26 (actions) and 27 (view as user) as one plan, with their review findings built in from the start, as plan 16 did. It's the last row of the roadmap.

Out of scope:
- admin levels or roles (one flag: you're a platform admin or not), a stats dashboard;
- acting on platform admins, yourself included (admins are managed from the command line);
- deleting users or organizations, editing profiles or emails, changing plans, budgets or credits, suspending an organization;
- reading task content (descriptions, summaries, chats) or an organization's audit log from the admin area;
- writing anything while viewing as a user, and a consent setting on the user's side (reason, code and email instead).

## Decisions
saas-starter's choices are kept (they were the user's there); what changes is noted.

**Who is an admin**
- **A flag set only from the command line.** `users.is_platform_admin` (not fillable). `php artisan admin:grant {email}` (refused without 2FA) and `admin:revoke {email}`, each logged with no actor. It has nothing to do with organization roles or `Permission`.
- **Turning 2FA off revokes it** (`DisableTwoFactor`, and `RevertTwoFactor` / `RevertEmailChange` when they clear 2FA): logged as `admin.revoked` `{reason: two_factor_disabled}`. Otherwise a password alone could swap in a new authenticator. Setting 2FA up again doesn't bring it back.

**Two-factor on the token**
- **`personal_access_tokens.two_factor_at`**: when this token last passed a code of the existing factor. Set by `IssueToken::handle(..., twoFactor: true)` from `CompleteTwoFactorChallenge` with an app code (not a recovery code: those are remade with the password alone), and by `POST /api/account/two-factor/reconfirm`. `ConfirmTwoFactor` doesn't set it: a brand-new factor proves only the password.
- **Reconfirm** (`ReconfirmTwoFactor`): a 6-digit code through `CheckTwoFactorCode::run` (so the per-account limit and "a code never works twice" apply), on the current token only. No recovery code.
- **Two freshness levels**: lookups need a code within `ADMIN_TWO_FACTOR_TTL_MINUTES` (720), actions and starting a "view as" within `ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES` (15). Both 403 `two_factor_reauth`; the app asks for a code inline and retries.

**Hiding the area**
- `EnsurePlatformAdmin` (alias `platform-admin`, `platform-admin:action` for writes) answers, in order: non-admin → the router's own unknown-route 404; no 2FA → 403 `two_factor_required`; stale code → 403 `two_factor_reauth`. It runs before `SubstituteBindings` (priority list), so missing and existing ids look the same. The `admin` limiter (60/min) counts only admins, so non-admins never see a 429. A wrong method (405) or a plain `OPTIONS` (no `Access-Control-Request-Method`) on `api/admin/*` gets the same 404. A CORS preflight still works: the app is on another origin.
- Then `verified`.

**Reading other organizations**
- Admin routes sit outside the `organization` group and never set `CurrentOrganization`. Cross-organization reads and writes live only in `app/Actions/Admin`, as explicit `withoutGlobalScope(OrganizationScope::class)->where('organization_id', …)`, so `tenant-isolation-reviewer` has one place to check. The one exception is `RecordAudit`'s `organization:`, which runs as that organization just for the insert.
- **Separate resources** (`App\Http\Resources\Admin\*`) that list every field. Never `UserResource` / `OrganizationResource`, which could leak a field added later. Never 2FA secrets, recovery codes, `stripe_id`, card columns or tokens.
- **Search**: `ILIKE` on name and email (users) or name (organizations), with `%`, `_` and `\` escaped, `q` at most 100 characters, 25 a page, newest first.

**Platform log**
- Table `platform_audit_logs`, not tenant-owned, append-only like `AuditLog`, pruned after `AUDIT_RETENTION_DAYS`. `App\Enums\PlatformAuditAction` (with labels) and `App\Actions\Admin\RecordPlatformAudit`.
- Detail views are logged (`admin.user_viewed`, `admin.organization_viewed`). List searches aren't: their text is usually an email address.

**Actions** (plan 26)
- **Reset two-factor** (clears it, deletes every token, emails `TwoFactorResetByAdmin`), **sign out everywhere** (deletes every token), **suspend / unsuspend** (`users.suspended_at`, not fillable; suspending deletes every token), **make Owner** (an existing member gets that organization's Owner role, emailed `MadeOwnerByAdmin`).
- **A reason on every action** (`AdminActionRequest`, 10–500 characters). It's kept in the platform log only, never shown to the user or the organization.
- **Never on platform admins, never on yourself** (`Concerns\ActsOnUsers`, re-checked on the locked user row; 422 "Remove admin with `admin:revoke` first"). Make Owner refuses any admin as the member, so admins can't give each other organizations.
- **Suspension blocks every way to a token**: `LoginUser` after the password check ("This account is suspended. Contact support."), `CompleteTwoFactorChallenge` under its row lock, `ResetUserPassword` (refused), `SendPasswordResetLink` (sends nothing, same answer as an unknown address), and a new `Sanctum::authenticateAccessTokensUsing` (401 for a suspended user's token, in case one is issued in a race). `IssueToken` itself refuses too, so a later login path can't forget it.
- **The organization's audit log shows "Platform admin"**: new `audit_logs.by_platform_admin`. `RecordAudit(..., byPlatformAdmin: true)` stores no actor and no IP. Make Owner records `member.role_changed` this way. The audit log page shows "Platform admin" as the actor.
- **Lock order** as in the rest of the app: the organization row (`EnsuresAnOwnerRemains::lockOrganization`), then the user / membership. Make Owner reuses `lockedRole` and does nothing (no entries) if they're already Owner.

**View as user** (plan 27)
- **A separate token for the user**: `POST /api/admin/users/{user}/impersonate` (`platform-admin:action`, reason) issues, through `IssueToken::impersonation()`, a token whose owner is the **user**, with `impersonator_id` (FK, cascade), `expires_at` (`ADMIN_IMPERSONATION_MINUTES`, 30, not renewed) and its own `organization_id` (FK, `nullOnDelete`). The admin's token is untouched. Refused for admins, yourself, suspended users and users with no organization (422).
- **The session's organization is on the token**, not `users.current_organization_id`. `User::activeOrganization()` reads it (membership-checked) for an impersonation token, and `SwitchOrganization` writes it there. The user's own current organization never moves. `personal_access_tokens.organization_id` is session state, not a tenant column: never filter by it.
- **Read-only by default**: `ReadOnlyImpersonation` joins the `auth:sanctum` group itself. For an impersonation token, every non-GET/HEAD gets 403 `impersonation_read_only`, except routes named `logout` and `organizations.switch`. So a new route is covered without anyone remembering. Streams (writing help, chat) are POSTs: no AI is called and nothing is spent.
- **Task content stays hidden**: `not-impersonating` (403 `impersonation_hidden`) on `GET tasks/{task}/summary`, `GET tasks/{task}/chat` and `GET account/devices` (the user's IPs). `TaskResource` returns `description: null, content_hidden: true`. `AuditLogResource` drops `changes` of `task.*` entries and `ip_address`. Titles stay: they're what the user sees in their list.
- **The Sanctum check ends a session (401)** when its admin is no longer an admin, has turned 2FA off or is suspended, or when the target was suspended or made an admin. Sanctum already checks `expires_at`.
- **Who's told**: the user gets an email at the start (`ViewedBySupport`, without the reason). Each organization looked at gets `support.viewed_as` (`byPlatformAdmin`) once per session (`personal_access_tokens.viewed_organization_ids` jsonb, under a lock on the organization row, then the token). The platform log gets `admin.impersonation_started` (reason, expiry) and `admin.impersonation_ended`.
- **Devices and "last active"** leave impersonation tokens out (`whereNull('impersonator_id')`: `DeviceController`, `RevokeDevice`, `ShowUser`). A password change still deletes them, which ends the session.
- `sanctum:prune-expired --hours=24`, daily.

**Web differences from saas-starter**
- **Two tokens in the auth store.** The token lives in `localStorage` here (no cookies, no SSR). Starting moves `token` to `adminToken` (persisted too); stopping, or a 401 on the impersonation token, puts it back and calls `queryClient.clear()`. No SSR, so the SSR branches of saas-starter's fixes don't apply.
- **Admin pages are lazy routes** (React Router's `lazy`), so normal users never download them.

**No new dependency.** New `.env.example` keys (in a new `config/admin.php`), each with a comment: `ADMIN_TWO_FACTOR_TTL_MINUTES`, `ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES`, `ADMIN_IMPERSONATION_MINUTES`.

## Backend (`api/`)
- Migrations (new, one set per stage):
  - Stage 1: `add_is_platform_admin_to_users_table`; `add_two_factor_at_to_personal_access_tokens_table`; `create_platform_audit_logs_table` (`id`, `actor_id` FK users `nullOnDelete`, `action`, `subject_type`, `subject_id`, `subject_label`, `metadata` jsonb, `ip_address`, `created_at`; indexes on `created_at` and `(actor_id, id)`).
  - Stage 2: `add_suspended_at_to_users_table`; `add_by_platform_admin_to_audit_logs_table`.
  - Stage 3: `add_impersonation_columns_to_personal_access_tokens_table`: `impersonator_id` FK users `cascadeOnDelete`, indexed; `organization_id` FK organizations `nullOnDelete`, indexed; `viewed_organization_ids` jsonb.
- Models: `User` gets casts (not fillable), `isPlatformAdmin()`, `isSuspended()`, and `activeOrganization()` reading the impersonation token's organization. `PlatformAuditLog` (append-only, `MassPrunable`, `actor()`). A `PersonalAccessToken` subclass (`Sanctum::usePersonalAccessTokenModel`) with the new casts and `impersonator()`.
- `config/admin.php`. `App\Support\Impersonation` (`active()`, `token()`), from the current token.
- Middleware: `EnsurePlatformAdmin` (`platform-admin[:action]`), `ReadOnlyImpersonation` (`impersonation`, in the `auth:sanctum` group), `NotImpersonating` (`not-impersonating`). `bootstrap/app.php`: priority before `SubstituteBindings`, and 405 / plain OPTIONS on `api/admin/*` → 404.
- `AppServiceProvider`: `Sanctum::authenticateAccessTokensUsing` (suspended users; impersonation rules), the `admin` limiter.
- Auth and account changes: `IssueToken` (`twoFactor:`, `impersonation()`, refuses suspended users), `CompleteTwoFactorChallenge`, `LoginUser`, `ResetUserPassword`, `SendPasswordResetLink`, `DisableTwoFactor` / `RevertTwoFactor` / `RevertEmailChange` (revoke admin), new `Account/ReconfirmTwoFactor`, `SwitchOrganization`, `AuthController@logout` (ends an impersonation through `EndImpersonation`), `DeviceController` / `RevokeDevice`.
- `RecordAudit`: `bool $byPlatformAdmin = false`.
- Actions (`app/Actions/Admin/`): `RecordPlatformAudit`, `SearchUsers`, `ShowUser` (profile, flags, last active, device count, organizations with role names; logs the view), `SearchOrganizations` (name, members, plan, created), `ShowOrganization` (plan and subscription status from `Plans`, `AiBudget::summary`, counts of members, tasks and pending invitations, the first 100 members with roles; logs the view), `ListPlatformAuditLog`, `ResetUserTwoFactor`, `SignOutUser`, `SuspendUser`, `UnsuspendUser`, `MakeMemberOwner`, `StartImpersonation`, `EndImpersonation`, `RecordImpersonatedView`; `Concerns\ActsOnUsers`.
- Commands: `GrantPlatformAdmin` (`admin:grant`), `RevokePlatformAdmin` (`admin:revoke`).
- Enums: `PlatformAuditAction` (`admin.granted`, `admin.revoked`, `admin.user_viewed`, `admin.organization_viewed`, `admin.two_factor_reset`, `admin.user_signed_out`, `admin.user_suspended`, `admin.user_unsuspended`, `admin.owner_assigned`, `admin.impersonation_started`, `admin.impersonation_ended`); `AuditAction::SupportViewedAs` ("Platform admin viewed the app as a member").
- Notifications (queued, plain values, names Markdown-escaped): `TwoFactorResetByAdmin`, `MadeOwnerByAdmin`, `ViewedBySupport`.
- Requests: `Admin\AdminSearchRequest` (`q`, `page`), `Admin\AdminActionRequest` (`reason`), `Admin\MakeOwnerRequest` (`reason`, `user_id`), `ReconfirmTwoFactorRequest` (`code`).
- Resources (`Admin\`): `AdminUserResource`, `AdminUserDetailResource`, `AdminOrganizationResource`, `AdminOrganizationDetailResource`, `PlatformAuditLogResource`. `UserResource` gets `is_platform_admin` and `impersonated_by: {name, expires_at} | null` (never the admin's email). `TaskResource` gets `content_hidden`, and `AuditLogResource` gets `by_platform_admin` and the hiding above.
- Controllers (`Api\Admin\`): `UserController` (index, show), `UserActionController` (resetTwoFactor, signOut, suspend, unsuspend; each returns the refreshed detail without a second "viewed" entry), `OrganizationController` (index, show), `OrganizationOwnerController@store`, `ImpersonationController@store` (`{token, expires_at}`), `AuditLogController@index`; `TwoFactorController@reconfirm`.
- Routes:
  - `POST /api/account/two-factor/reconfirm` (`throttle:6,1,two-factor-reconfirm`).
  - Prefix `admin`, `platform-admin`, `verified`, `throttle:admin`: `GET users`, `GET users/{user}`, `GET organizations`, `GET organizations/{organization}`, `GET audit-log`.
  - The same, plus `platform-admin:action`: `POST users/{user}/two-factor/reset`, `POST users/{user}/sign-out`, `POST users/{user}/suspend`, `POST users/{user}/unsuspend`, `POST users/{user}/impersonate`, `POST organizations/{organization}/owners`.
  - Names on `logout` and `organizations.switch`; `not-impersonating` on the three hidden GETs.
- `routes/console.php`: `PlatformAuditLog` in `model:prune`; `sanctum:prune-expired --hours=24` daily.
- Pest helpers: `platformAdmin()` (verified, 2FA, granted), `adminToken($user, $codeMinutesAgo = 0)` (a real token with `two_factor_at`, since `Sanctum::actingAs` has none), `impersonationToken($admin, $user)`.

## Frontend (`web/`)
- `types/api.ts`: `User.is_platform_admin`, `User.impersonated_by`, `Task.content_hidden`, `AuditLogEntry.by_platform_admin`; `AdminUser`, `AdminUserDetail`, `AdminOrganization`, `AdminOrganizationDetail`, `PlatformAuditLogEntry`.
- `features/admin/api.ts`: `adminUsersQuery(q, page)`, `adminUserQuery(id)`, `adminOrganizationsQuery`, `adminOrganizationQuery`, `platformLogInfiniteQuery`, under `['admin', ...]` (user-level, not `['org']`), `retry: false` on 403/404; mutations `useAdminAction` (the four user actions), `useMakeOwner`, `useReconfirmTwoFactor`, `useStartImpersonation` (all write the returned detail with `setQueryData`).
- `features/admin/AdminGate.tsx`: with `children`, renders the page, or what stops it. A non-admin (from `user.is_platform_admin`, without calling the API) or a 404 gets `NotFoundPage`. `two_factor_required` gets "Turn on two-factor to use the admin area" with a link to `/account`. `two_factor_reauth` gets "Confirm it's you" (a `CodeInput`, then reconfirm and refetch). Anything else gets `ErrorState` with Retry.
- `features/admin/AdminActionDialog.tsx`: title, what will happen, a required reason `Textarea` (react-hook-form + zod, 10–500), and a confirm button (destructive for suspend and reset). On `two_factor_reauth` it swaps to a code step, reconfirms, then retries the same action. 422s go onto the field.
- Routes (lazy, inside `AppLayout`):
  - `/admin/users`: search (`?q=&page=`, debounced), a table (name, email, badges: verified, 2FA, admin, suspended; organizations; created), pagination.
  - `/admin/users/:id`: a profile card with badges, an organizations table linking to their admin pages, and an Actions card: Reset two-factor (when 2FA is on), Sign out everywhere (when there are devices), Suspend / Unsuspend, View as user. It's hidden for admins and yourself, with a line saying why. View as user is also hidden for suspended users and users with no organization.
  - `/admin/organizations`: search and a table (name, members, plan, created).
  - `/admin/organizations/:id`: plan and subscription, the AI budget meter (reused from the AI usage page), counts, and a members table with Make Owner (not for owners, admins or yourself).
  - `/admin/audit-log`: "Load more" on cursor pages: when, admin, action, subject, reason, IP.
  - Each has loading, empty and not-found states; dates in UTC with the fixed locale.
- `AppSidebar`: a second group "Platform admin" (Users, Organizations, Platform log) when `user.is_platform_admin`, shown even with no current organization.
- **View as user**, `stores/auth.ts`:
  - `startImpersonation(token)` keeps `adminToken`, sets the session to the new token, loads `/api/me`, `queryClient.clear()`, goes to `/`.
  - `stopImpersonation({expired?})` posts `/api/logout` with the impersonation token (errors ignored), restores `adminToken`, loads `/api/me`, clears queries, and goes to `/admin/users/{id}` (or shows "The support view ended." when it expired).
  - `apiRequest`: a 401 for the impersonation token, while it's still the current one, calls `stopImpersonation({expired: true})` instead of `clearSession`. A 401 for an older token is ignored.
  - `bootstrapSession` keeps `adminToken` when reloading mid-session.
- `components/ImpersonationBanner.tsx` at the top of `AppLayout`: "Viewing as **Jane Doe** (read-only) · ends at 14:30" and "Stop viewing". `UserMenu`: "Log out" becomes "Stop viewing".
- Task detail: "Content hidden in the support view" in place of the description, the summary card and the chat when `content_hidden`. `AccountPage` devices: "Hidden in the support view" on `impersonation_hidden`. A failed save already shows the API's message ("You're viewing as this user, read-only.").
- `AuditLogPage`: "Platform admin" as the actor when `by_platform_admin`.

React concepts:
- `lazy` routes ↔ Nuxt's per-page code splitting, which Nuxt does for you: here the route says `lazy: () => import(...)`, so the admin pages are a separate chunk.
- A gate component that branches on a query's error and otherwise renders `children` ↔ a wrapper component with a default `<slot />`, with the "middleware" logic written as plain render code.
- A dialog with steps as a discriminated union in `useState` (`{step: 'reason'} | {step: 'code', pending}`) ↔ a `ref` holding the step, but TypeScript checks every branch.
- Retrying a mutation after a nested one (`mutateAsync` chained in an event handler) ↔ awaiting `$fetch` calls in a method.
- Swapping identities in Zustand: two persisted fields with `partialize`, and `queryClient.clear()` ↔ a Pinia store with two cookies and `clearNuxtData()`.
- Showing a whole nav group from a user flag, derived during render ↔ a `v-if` on a computed.

## Tests
Pest:
- **Access**, for every admin route (a dataset): guest 401; non-admin 404 with the same body as an unknown route, for missing and existing ids, wrong methods and plain OPTIONS, and never a 429; admin without 2FA 403 `two_factor_required`; a token with no code (password-only, new 2FA setup, recovery-code login) or an expired one 403 `two_factor_reauth`; action routes with a code older than the action TTL but inside the lookup TTL 403 `two_factor_reauth`; unverified 403 `email_unverified`; fresh code 200.
- **Flag**: `PATCH /api/account` and registering can't set it; grant and revoke change it and are logged; grant refuses without 2FA; an unknown email fails; turning 2FA off (and the revert links) clears it, logged, and setting 2FA up again doesn't restore it.
- **Tokens**: the challenge with an app code stamps `two_factor_at`; a password login, `ConfirmTwoFactor` and a recovery code don't. Reconfirm stamps only the current token, refuses a reused code, counts wrong codes toward the account limit, is throttled, and does nothing without 2FA.
- **Search**: by name or email, case-insensitive; `%`, `_`, `\` literal; paginated; `q` over 100 → 422.
- **Isolation**: an admin in organization A (or none) sees B's plan, members and counts; the counts are B's only with A's rows seeded alongside; the admin's current organization and memberships are unchanged afterwards; no response contains 2FA secrets, recovery codes, `stripe_id`, `pm_last_four`, token values, task descriptions or chats.
- **Platform log**: detail views log the actor and IP; searches log nothing; no update or delete; `model:prune` removes old ones; nothing lands in any organization's `audit_logs` except the `by_platform_admin` entries below.
- **Actions**: each refuses an admin target and yourself (nothing changed or logged); reason missing, under 10 or over 500 → 422. Reset 2FA clears the columns, deletes the tokens, emails the user, and a pending challenge can't complete (422 when 2FA is off). Sign out deletes only their tokens. Suspend: tokens gone, a token made afterwards gets 401, login with the right password → 422 suspended, wrong password → `auth.failed`, a challenge started before can't complete, a reset is refused and no link is sent, twice → 422; unsuspend lets them log in.
- **Make Owner** with A and B seeded: gets **that** organization's Owner role; not a member → 404; an admin as the member → 422; already Owner → no change, no entries; A's audit log gets `member.role_changed` with `by_platform_admin`, no actor, no IP, and no reason in the response; B gets nothing; email sent.
- **View as user**: start refused for admins, yourself, suspended users and users with no organization. The token belongs to the user, with impersonator, expiry and organization. The email has no reason. Platform log and `support.viewed_as` are written.
- **Read-only** as a dataset over every non-GET route from the route list (new routes are tested automatically): 403 `impersonation_read_only`, nothing changes; logout and switch are allowed.
- **Hidden**: summary, chat and devices 403; tasks have no description; the audit log has no task changes or IPs. The user's own token sees everything.
- **Switching** moves only the token's organization (once-per-organization audit entry). Org A/B: a non-member organization 404s; B's tasks aren't readable while in A; a user removed mid-session fails closed.
- **Session end**: logout deletes only that token and logs the end; expired → 401; 401 after `admin:revoke`, the admin turning 2FA off or being suspended, or the target being suspended; not in the user's devices, can't be revoked by them, doesn't change "last active"; `/me` shows `impersonated_by.name` and no admin email.
- Scheduled `sanctum:prune-expired` is registered.

Vitest:
- `AdminGate`: non-admin → not found with no request; 404; `two_factor_required`; `two_factor_reauth` → code → refetch.
- Users page: search in the URL; detail: actions shown and hidden by the rules; the dialog's reason error, the code step, then the retry.
- Organization page: Make Owner.
- Sidebar: the admin group only for admins.
- Impersonation: start → banner, the user's name, the user's organization; Stop → back to the admin's user page with the admin's token; a 401 mid-session → back to the admin with "The support view ended."; a reload mid-session keeps both tokens; hidden task content.
- Audit log: "Platform admin" actor.

## README screenshots
New, under a "Platform admin" heading:
- `docs/screenshots/admin-users.png`: the user search with results, one of them suspended.
- `docs/screenshots/admin-organization.png`: an organization's admin page with its plan, AI budget, counts and members (with Make Owner).
- `docs/screenshots/admin-user-actions.png`: a user's admin page with the Actions card and the reason dialog open for "Reset two-factor".
- `docs/screenshots/admin-view-as.png`: the task detail page viewed as a demo user, with the banner and the content hidden.

Captured as the workflow rules say (light, 1280×800, Playwright MCP, e2e demo data). The demo admin is an `e2e-*` account with 2FA set up through the app (codes worked out from its setup key) and `admin:grant` run against the e2e database. `e2e-*` accounts are cleaned up afterwards.

## Verification
`verify` skill. `e2e-smoke` with `--mail`. Reviewers in parallel: code, security (every login path vs. suspension, the read-only coverage, the token rules, the two tokens in `localStorage`), tenant isolation (the cross-organization reads in `app/Actions/Admin`, Make Owner, the session's organization), database (four migrations, search, counts, lock order), UI (admin pages, sidebar group, dialog, banner at 390px).

Flows (for `flow-tester`). The admin is an `e2e-ft-*` account with 2FA on and `admin:grant` run. The second user is in two organizations, with a task.
1. As a normal user, open `/admin/users` → "Page not found", and no "Platform admin" group in the sidebar.
2. `admin:grant` an account without 2FA → refused. Turn 2FA on, grant again → on that same login, `/admin/users` asks "Confirm it's you"; a fresh code loads the list.
3. Log in again with password + code. Search the second user by part of their email → found. Their page shows their organizations and roles, and the platform log has "viewed user".
4. Open their organization → plan, AI budget, counts and members; its own audit log (as its owner) has no new entry.
5. Reset two-factor with a 5-character reason → field error. With a proper one → their 2FA badge goes, they get the email, their logged-in browser goes to log in, and they log in with just a password.
6. Suspend them → their browser is logged out; logging in with the right password says suspended; "forgot password" sends nothing (mail log). Unsuspend → they can log in.
7. Make Owner on a Member → their role shows Owner, they get the email, and that organization's audit log shows "Platform admin" as the actor.
8. View as user with a proper reason → the app as them, with the banner and their current organization. The task shows its title, "Content hidden in the support view" for the description, summary and chat. Editing and saving shows the read-only message, and nothing changed (check as the user).
9. Switch to their other organization → its pages show. As the user in another browser, their current organization hasn't moved; they have the "support viewed your account" email; each organization's audit log has the entry once.
10. Stop viewing → back on their admin page as the admin, with the admin group. With `ADMIN_IMPERSONATION_MINUTES=1`, start again and wait → the next request goes back to the admin with "The support view ended."
11. Turn the admin's 2FA off → `/admin/users` is "Page not found" and the group is gone after a reload.
12. With curl: a non-admin `POST /api/admin/users/{id}/suspend` → 404; the impersonation token on `DELETE /api/tasks/{id}` and `PATCH /api/account` → 403 `impersonation_read_only`, on `GET /api/admin/users` → 404.

## From the reviews (done here)
- Stop viewing with the API down keeps the admin's token rather than dropping it (it never expires, so dropping it left a live token nobody could revoke) (code and security reviews).
- Switching organization in a support view checks the membership again under the organization lock (`RecordImpersonatedView`), so a member removed (or an organization deleted) in between gets the switch's 404, not a misleading audit entry or a 500 (tenant isolation and database reviews).
- Make Owner reads the member's platform admin flag on a locked row, so it can't race `admin:grant` (database review), and locks the Owner role `FOR SHARE` like any role being assigned (code review).
- Stop viewing loads the admin first, swaps back, and only then ends the session on the server with its token. Before, a request meeting the ended token's 401 mid-Stop (or a second Stop button) swapped twice and dropped the admin's token; `endSupportView` now does nothing when already back (code review).
- Stop viewing with the API down keeps the admin's token (RequireAuth offers Retry) instead of logging out; only a 401 logs out (code review).
- Stop viewing from a task page landed on `/tasks`: the task page redirects when the organization changes, and its redirect beat Stop's. Stop now goes through `SupportViewEndedNotice` like an expiry, placed after the page so its navigation runs last (UI review; a Vitest test from a task page catches it).
- Invoices (live from Stripe, with billing details) are hidden in a support view: `not-impersonating` on `GET /billing/invoices`, and the page says so (security review).
- Reconfirming inside an action dialog no longer refetches the open detail page, which logged an extra "viewed" entry: the retried action's answer refreshes it (code review).

## Follow-ups
- After a reload, a support view that expired while the tab was closed goes back to the admin quietly: only the tokens are saved, so the app doesn't know whose page to return to, and shows no "The support view ended."
- If `/api/logout` fails on Stop viewing, the session's token stays valid on the server until it expires (the tab drops it), and no end is logged.
- The user can't end a support session from their devices list (it's hidden there); changing their password does.
- Admin search is `ILIKE '%…%'` (a sequential scan, plus the paginator's count). Trigram indexes (`pg_trgm`, a new extension) on `users.name`, `users.email` and `organizations.name` when it gets slow (database review).
- Signed-out callers get 401 on admin routes but 404 on unknown paths (the admin group sits in `auth:sanctum`), so the routes can be mapped without an account; the lazy admin chunk lists them anyway. Accepted, as in saas-starter (security review).
- Platform admins' own tokens never expire; an idle timeout or `expires_at` on them would shorten what a stolen admin token is worth (security review).
- `composer audit`: `phpunit/phpunit` advisory GHSA-9vw2-f4vp-x7gf (dev only, local temp-dir issue); update to 13.4.4+ separately (security review).
- `StartImpersonation` picks the starting organization before locking it: if that one is deleted in between, the admin gets "isn't in any organization" and has to try again (database review).

## Commits after approval
Built in the three stages above, each with its tests passing, but shipped as one api and one web commit: the stages share files (`routes/api.php`, `IssueToken`, `stores/auth.ts`, the admin pages), so splitting them afterwards would have meant commits that never existed as a working state.
1. `feat(api): platform admins, admin actions and read-only view as user` (with CLAUDE.md's "Platform admin" section and `.env.example`)
2. `feat(web): platform admin area, actions and view as user` (with the README and screenshots)
3. This plan → Done.
