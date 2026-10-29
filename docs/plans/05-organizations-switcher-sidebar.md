# 05 — Organizations, switcher and sidebar layout

**Status:** Done
**Commits:** `4ae9531` (api), `b0d7492` (web), `27766ca` (claude)

## Goal
Users belong to many organizations, each with a role, and have one current organization kept on the server. The plan adds the multi-tenant base every later feature uses (`CurrentOrganization`, `BelongsToOrganization`, the `organization` route middleware), and its org A vs org B test. In the app it adds:
- a collapsible sidebar (organization switcher at the top, navigation, account menu at the bottom) in place of the top bar;
- creating an organization and switching between them;
- a "Choose an organization" step after login for people in more than one.

It rebuilds saas-starter plans 03 (organizations), 12 (sidebar) and 21 (choose after login).

Out of scope: members, invitations and leaving an organization (plan 06), renaming or deleting an organization, custom roles and permissions (plan 08), and any tenant-owned feature (Tasks, plan 07).

## Decisions
- **The role is an enum on the membership row for now** (`organization_user.role`: `owner`, `admin`, `member`), as in saas-starter plan 03. Plan 08 replaces it with a `roles` table through a new migration. Nothing in this plan checks a role: there are no organization actions to protect yet, and plan 06 brings the first policies.
- **The current organization is stored on the server** (`users.current_organization_id`, null on delete). It's never taken from the request.
  - `CurrentOrganization` is a scoped service, built fresh for each request and job. It re-checks membership on every request, so someone removed from an organization loses access at once.
  - Switching (`POST /api/organizations/{id}/switch`) looks the organization up through the user's own memberships. A non-member gets the same 404 as for an id that doesn't exist.
- **`BelongsToOrganization` fails closed:**
  - reads see only the current organization's rows, and none when there's no current organization;
  - `organization_id` is set from the current organization on create, overwriting any value passed in;
  - a row can't be moved to another organization.
- **Registration creates a personal organization** ("{name}'s organization"), and the new user is its Owner. This happens in the same transaction as the user.
- **Creating an organization** is limited to 20 requests a minute per user, and a user can own at most 20 organizations (422 on `name` beyond that; added after review), so a script can't create thousands.
- **`/api/me`** (and the login/register responses) include `current_organization: {id, name, role}`, or null.
- **`GET /api/organizations`** returns `{data: [{id, name, role}], meta: {current_organization_id}}`, sorted by name.
- **Web state:**
  - the current organization comes with the user, in the auth store (`user.current_organization`), read through a `useCurrentOrganization()` selector. One source, so the sidebar and the pages can't disagree.
  - the list of organizations is server data, so it lives in TanStack Query (`['organizations']`). The switcher refetches it each time it opens.
  - switching or creating updates the user's current organization in the store and removes every query under `['org', …]`. That's the CLAUDE.md rule, and it means no page shows the previous organization's data. It also refreshes `['organizations']`.
- **Choose an organization after login** (saas-starter plan 21):
  - `useLogin` fetches `/api/organizations` with the new token before it stores the session.
  - With more than one organization, the store gets a `chooseOrganization` flag, and `RequireGuest` sends you to `/select-organization?redirect=…` instead of the redirect.
  - The exceptions: a redirect that is already the picker, or an invitation link (`/join/…`, plan 06, where accepting sets the organization anyway).
  - If that fetch fails, login carries on to the redirect.
  - The picker (`/select-organization`) is a logged-in page in the auth-card layout:
    - one button per organization (name, role, "Last used" on the current one);
    - choosing switches (unless it's already current) and goes to `safeRedirect(redirect)`;
    - with one organization or none, it goes straight on.
  - Register skips the picker, since a new account has one organization.
- **Sidebar:** the shadcn `sidebar` component.
  - `collapsible="icon"`, and its open/closed state is remembered in a cookie, which is what the component already does.
  - On mobile (below `md`) it's a sheet that slides in, opened from the top bar's trigger. It closes after a switch or navigation. The top bar (trigger and app name) stays at every width, so a collapsed sidebar can be reopened with a button, not only the rail.
  - **Header:** the organization switcher, a dropdown with the organizations (name and role, a check on the current one) and "Create organization", which opens a dialog with a name field. Collapsed, it shows an icon with a tooltip.
  - **Content:** navigation. Only "Dashboard" for now; Members (06) and Tasks (07) join it later.
  - **Footer:** the account menu (name and email; Appearance: Light / Dark / System; Log out) and a small "v0.1.0 · Source" line.
- **Dark mode:** `next-themes` is already installed (shadcn's Toaster uses it). This plan mounts its `ThemeProvider` (class strategy, system by default) and the Toaster follows it.
  - Screenshots stay in light mode.
  - The appearance choice is saved in `localStorage` (`theme`), a per-viewer preference.
- **App version:** `web/package.json` → `version: 0.1.0`, exposed through Vite's `define` as `__APP_VERSION__`. No env key.
- **No new npm or Composer packages.** `npx shadcn add sidebar dropdown-menu dialog tooltip` brings only Radix parts already covered by `radix-ui`, plus generated files (`sheet`, `skeleton`, `separator`, `use-mobile`).

## Backend (`api/`)
- **Migration** `create_organizations_tables`:
  - `organizations` (id, name, timestamps);
  - `organization_user` (id, organization_id FK cascade, user_id FK cascade, `role` string, timestamps, unique `[organization_id, user_id]`, index `user_id`);
  - `users.current_organization_id` (nullable FK → organizations, null on delete).
- `app/Enums/OrganizationRole.php`: Owner, Admin, Member, with `label()`.
- **Models:**
  - `app/Models/Membership.php`: a pivot with `role` cast to the enum.
  - `app/Models/Organization.php`: `members()`.
  - `app/Models/User.php`: `organizations()`, `currentOrganization()`, `activeOrganization()` (null unless still a member), `isMemberOf()`.
  - `database/factories/OrganizationFactory.php` with `withMember($user, $role)`.
- `app/Support/CurrentOrganization.php` (scoped, bound in `AppServiceProvider`): `get()`, `id()`, `role()`, `set()` for jobs and tests, `forget()`.
- `app/Models/Concerns/BelongsToOrganization.php`, `app/Models/Scopes/OrganizationScope.php`, `app/Exceptions/NoCurrentOrganization.php`.
- `app/Http/Middleware/EnsureCurrentOrganization.php`, aliased `organization`: 403 "No active organization." The empty `Route::middleware('organization')` group goes in `routes/api.php` now, for plans 06 and 07.
- **Added after review:**
  - **`X-Organization-Id` precondition.** `apiFetch` sends the organization the tab thinks is current. The middleware answers 409 `organization_changed` when it isn't the server's current one (another tab switched). The header never chooses the organization, so a header naming an organization you're not in is just another 409. On that 409, `apiFetch` reloads `/api/me`, drops `['org']` queries and shows a toast.
  - **`CurrentOrganization::run($org, fn)`.** It acts in an organization and then restores the previous state. Jobs use it, and so does the Pest `inOrganization()` helper.
  - **Plain 404s.** A missing model on `api/*` answers `{"message": "Not found."}`, so the model class and id aren't echoed back.
  - **Unchecked relation removed.** `User::currentOrganization()` went: it returned the stored organization without checking membership. `Membership` documents why it isn't tenant-scoped.
- **Actions:**
  - `app/Actions/Organizations/CreateOrganization.php`: in a transaction, create the organization, attach the user as Owner, make it current, then `forget()`.
  - `app/Actions/Organizations/SwitchOrganization.php`: `findOrFail` through `$user->organizations()`, save, then `forget()`.
  - `RegisterUser` calls `CreateOrganization` inside its transaction.
- **HTTP:**
  - `app/Http/Requests/Organizations/StoreOrganizationRequest.php`: name required, string, max 255, trimmed.
  - `app/Http/Resources/OrganizationResource.php`: id, name, role (when loaded through the user), created_at.
  - `UserResource` gets `current_organization`.
  - `app/Http/Controllers/Api/OrganizationController.php` (index, store, switch), with routes inside `auth:sanctum`:
    - `GET /organizations`;
    - `POST /organizations` (`throttle:20,1,organizations`);
    - `POST /organizations/{organization}/switch` (up to 18 digits, so a longer id is a 404, not a 500).
- `tests/TestCase.php`: `call()` forgets scoped instances before each request, as saas-starter does, so a test's second request doesn't reuse the first one's `CurrentOrganization`.

## Frontend (`web/`)
- **Types:** `OrganizationRole`, `Organization {id, name, role}`, `User.current_organization: Organization | null`, `OrganizationList`.
- **Auth store:**
  - `setSession(token, user, { chooseOrganization })`;
  - `setCurrentOrganization(org)`;
  - a `chooseOrganization` flag and `organizationChosen()`;
  - the `useCurrentOrganization()` hook;
  - `apiFetch` takes an optional `token` (used only by login, before the session is stored).
- **`features/organizations/api.ts`:**
  - `organizationsQuery` (`queryOptions`) and `useOrganizations()`;
  - `useSwitchOrganization()` and `useCreateOrganization()`. Both set the current organization, remove `['org']` queries and invalidate `['organizations']`.
  - `roleLabel()`.
- **`features/organizations/`:** `OrganizationSwitcher.tsx`, `CreateOrganizationDialog.tsx` (react-hook-form + zod, 422 on `name`; it gives focus back to the switcher on close), `afterLogin.ts` (`chooseOrganizationTarget`). The picker's list stays inside `SelectOrganizationPage`.
- **`features/auth/api.ts`:** `useLogin` fetches the organizations with the new token and sets `chooseOrganization`.
- **`components/`:**
  - `AppSidebar.tsx`, `UserMenu.tsx` (account dropdown with the appearance submenu and Log out);
  - `ThemeProvider` usage in `main.tsx`;
  - `AppLayout.tsx` rewritten: `SidebarProvider` + `AppSidebar` + `SidebarInset`, with a top bar (trigger and app name) at every width;
  - `RequireGuest` sends you to the picker when `chooseOrganization` is set.
- **Routes:**
  - `routes/SelectOrganizationPage.tsx`, under `RequireAuth` and in `AuthLayout`;
  - `HomePage` shows the current organization and your role in it.
- `router.tsx`: `/select-organization`.
- `vite.config.ts`: `define: { __APP_VERSION__ }`, plus `src/env.d.ts` to declare it.

React concepts (each compared with Vue/Nuxt):
- **Context** (`SidebarProvider`, `ThemeProvider`, `QueryClientProvider`) ↔ `provide`/`inject`. A provider makes a value available to every component below it without passing props down.
- **`useQuery` + `queryOptions`** ↔ `useAsyncData`/`useFetch`. Cached by key, with loading and error states, and refetched on demand (`refetch()` when the switcher opens).
- **Query keys, `removeQueries` and `invalidateQueries`** ↔ `refreshNuxtData`/`clearNuxtData`. How a switch drops the old organization's data.
- **Compound components and `asChild`** (`DropdownMenu.Trigger asChild`, `Tooltip`) ↔ named and scoped slots. Parts that share state through context, and `asChild` passes behavior to your own element instead of wrapping it.
- **A controlled dialog** (`open` + `onOpenChange` with `useState`) ↔ `v-model:open`.
- **Custom hooks** (`useCurrentOrganization`, shadcn's `useIsMobile`) ↔ composables. Plain functions that call other hooks, bound by the rules of hooks.
- **`key` to reset a component** (the create dialog's form starts empty each time it opens) ↔ `:key` in Vue. Same idea, used more often in React.
- **Derived state, not stored state:** the current organization is read from the user, not copied into a second `useState`. This is the `computed` habit, without `computed`.

## Tests
Pest:
- `Organizations/BelongsToOrganizationTest.php` (**org A cannot read or write org B**): a test-only `TenantNote` model in a table created by the test.
  - it reads only the current organization's rows;
  - `find`/`findOrFail` of B's row fails;
  - `update`/`delete` queries on B's rows change 0 rows;
  - `organization_id` from the input is ignored on create;
  - a row can't be moved;
  - no current organization means no rows, and creating throws;
  - removing the user from an organization hides its rows;
  - switching makes the rows follow.
- `ListOrganizationsTest.php`: only your own organizations, with your role, sorted, plus `meta.current_organization_id`; null when you have none; 401 without a token.
- `CreateOrganizationTest.php`: you're the Owner and it becomes current; name required, max 255, trimmed; throttle; 401.
- `SwitchOrganizationTest.php`: switch to your own (`/api/me` agrees); 404 for someone else's, for a missing id, and after you were removed (the current organization stays put); 401.
- `CurrentOrganizationTest.php`: re-checks membership on each request; `organization` middleware gives 403 without a current organization (through a test-only route).
- `RegisterTest`: creates "{name}'s organization" with the user as Owner and current. `MeTest`: includes `current_organization` with the role, and null once removed.

Vitest:
- `OrganizationSwitcher.test.tsx`:
  - it lists organizations with roles and checks the current one;
  - switching calls the API, updates the sidebar and removes `['org', oldId, …]` queries;
  - a failed switch shows a toast and refetches.
- `CreateOrganizationDialog.test.tsx`: empty name → error; 422 → error on the field; success → current organization and the dialog closes.
- `SelectOrganizationPage.test.tsx`:
  - login with two organizations → picker;
  - choosing the other one → switch, then lands on `?redirect`;
  - choosing the current one → no API call;
  - one organization → straight to the redirect;
  - `/join/…` redirect → no picker.
- `AppLayout.test.tsx`: the account menu shows name and email; Log out still lands on `/login` with no redirect; the appearance choice adds or removes `dark` on `<html>`.

## README screenshots
Taken as in plan 03 (e2e servers, light mode, 1280×800, `e2e-*` accounts removed):
- `home.png` (retaken): the dashboard with the sidebar, showing the organization and role.
- `organization-switcher.png` (new): the switcher open, with two organizations and "Create organization".
- `select-organization.png` (new): the picker after login.

## Verification
- `/verify` (Pint, Pest, oxlint, typecheck, Vitest, build); CI on the PR or push.
- `e2e-smoke` with curl:
  - user A can't switch to B's organization (404), and `/api/me` keeps A's;
  - creating an organization makes it current.
- Reviewers in parallel: `code-reviewer`, `security-auditor`, `tenant-isolation-reviewer` (this is the plan that adds the isolation itself), `database-reviewer` (the migration, foreign keys, indexes, the per-request membership query), and `ui-reviewer` (quick mode):
  - sidebar expanded and collapsed (remembered across a reload);
  - switcher and create dialog with the keyboard;
  - the mobile sheet at 390px closes after a switch;
  - dark mode;
  - console errors.

Flows (for `flow-tester`, `e2e-ft-*` accounts):
1. Register → home shows "{name}'s organization" and "Owner"; the sidebar switcher shows the same name.
2. Open the switcher → one organization, checked, plus "Create organization".
3. Create organization with an empty name → error under Name; nothing created.
4. Create "Beta" → the dialog closes, the switcher and home show Beta (Owner).
5. Reload → still Beta (kept on the server).
6. Switch back to the personal organization → home updates without a reload.
7. Log out, log in → "Choose an organization" lists both, with "Last used" on the personal one; choose Beta → home shows Beta.
8. Log out, open `/` (sent to `/login?redirect=%2F`), log in → picker → choose → lands on `/`.
9. Register a second account `e2e-ft-solo` and log in again → no picker (one organization).
10. As user A, `POST /api/organizations/{B's personal org}/switch` with curl → 404; `/api/me` still shows A's current organization.
11. Collapse the sidebar → icons only, with a tooltip on the switcher; reload → still collapsed.
12. Account menu → Appearance → Dark → the page turns dark; reload → still dark; set Light again.
13. At 390px: the sidebar is hidden, the menu button opens it, switching closes it, and there's no horizontal scroll.

## Outcome
- `/verify`: Pint, 80 Pest tests (293 assertions), oxlint, typecheck, 82 Vitest tests, build.
- **Mutation check:** with `OrganizationScope` switched off, 7 of the 10 `BelongsToOrganizationTest` cases fail. The 3 that pass are covered by the create/update hooks instead.
- **curl on :8112:**
  - A switching to B's organization gets 404, and A's `/me` is unchanged;
  - creating an organization makes it current, as owner;
  - the list and switching back work;
  - the CORS preflight allows `x-organization-id`.
- **`flow-tester`:** all 13 flows passed, with no console or log errors. That run was before the review fixes; the fixes are covered by tests.
- **`ui-reviewer`:**
  - the sidebar expands, collapses and remembers it, and works with the keyboard;
  - the 390px sheet closes on a switch;
  - the picker works at both widths;
  - there are no console errors;
  - dark mode was checked by eye (dialog, sidebar, home card).
  - It found focus landing on `<body>` after Escape in the create dialog. Fixed and tested.
- **Fixed from the reviews:**
  - **database (medium):** a 241–255 character name gave a 500, because the personal organization's name overflowed the column. The name is now shortened to fit, with a test.
  - **database:** an index on `users.current_organization_id`, which was also added to the already-migrated dev database.
  - **database:** `servers.sh` cleanup now runs in one transaction.
  - **code:** the switch id gave a 500 when too long; the picker flag stayed set after an invitation-link login; and the home page's "not in an organization" wording.
  - **security:** the cap on owned organizations, and the plain 404 message.
  - **tenant isolation (medium):** the multi-tab drift, fixed with the `X-Organization-Id` precondition; and jobs should use `run()`, which is now in the tenant-feature skill.
  - **tenant isolation:** in-flight org requests are cancelled before a switch, the unchecked `User::currentOrganization()` is removed, and `Membership` documents why it isn't scoped.

## Follow-ups
- **Bulk writes skip the guard.** `organization_id` is only guarded in model events. Query-builder `update`/`insert`/`upsert` and quiet saves skip it. That's written into the tenant-feature skill (step 10). A database trigger or a builder guard could make it a hard rule.
- **No browser test of the 409 yet.** There are no org-scoped routes, so a real two-tab check of the 409 waits for plan 06 (members).
- **No queued-job test yet.** A test that a queued job doesn't leak its organization waits for the first job.
- **`symfony/mime` CVE-2026-45070.** `composer update symfony/mime` before invitation emails (plan 06).
- **Rate limits for plan 06.** Give the mutating routes for members and invitations their own named limiters.
- **One duplicate query to watch.** A route in the `organization` group that also returns a `UserResource` runs the membership query twice. If that matters, let `CurrentOrganization` hand its result to the user.

## Commits after approval
`feat(api): add organizations, switching and BelongsToOrganization`, `feat(web): add sidebar, organization switcher and picker`, then this plan → Done.
