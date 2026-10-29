# Project: react-saas (multi-organization SaaS, React + Laravel)

A rebuild of `../saas-starter` (Laravel + Nuxt) with a React frontend, made to learn React. **Tasks** take the place of saas-starter's Projects. Each feature is rebuilt plan by plan, with saas-starter's code and tests as the reference. Open the matching saas-starter file before writing something it already solved, but don't copy Nuxt/Vue patterns into `web/`.

## Stack
- `api/`: Laravel (latest stable), PHP 8.4+, PostgreSQL (`react_saas`, tests `react_saas_test`), Sanctum (token auth), Pest
- `web/`: Vite + React 19 + TypeScript (strict), React Router, TanStack Query, Zustand, shadcn/ui + Tailwind v4, react-hook-form + zod, Vitest. Node 22 (`web/.nvmrc`)
- Local: macOS, Postgres running locally, API on :8100, Vite on :5173. saas-starter keeps :8000/:3000 (dev) and :8011/:3011 (e2e), so both repos can run at once.

## Commands
- API: `cd api && PHP_CLI_SERVER_WORKERS=4 php artisan serve --port=8100 --no-reload`, `php artisan migrate`, `php artisan test`
- Queue worker: `php artisan queue:work`
- Web: `cd web && npm run dev`, `npm run build`, `npm run lint`, `npm run typecheck`, `npm test`
- Always run `/verify` (or `php artisan test` and `npm run build`) before saying a task is done.
- CI (`.github/workflows/ci.yml`) runs the `/verify` steps on every push to `main` and every PR: Pint and Pest against a Postgres service (`react_saas_test`), plus oxlint, typecheck, Vitest and build. Keep it in step with `verify.sh` when a check changes. Dependabot (`.github/dependabot.yml`) only updates the workflow's actions.
- Never wipe a database. Dev and test share one Postgres, and `--env=testing` without an `.env.testing` uses `.env`. `.claude/hooks/block-destructive-db.sh` blocks `migrate:fresh`/`refresh`/`reset`/`rollback`, `db:wipe`, `dropdb` and `DROP`/`TRUNCATE` for Claude. In the app, `AppServiceProvider::guardDestructiveDatabaseCommands` refuses them on any database whose name doesn't end in `_test` (override for one run: `DB_ALLOW_DESTRUCTIVE_COMMANDS=true`). Pest rebuilds its own database.

## Multi-organization model
- Users belong to many organizations via the `organization_user` pivot. Roles are per organization (Owner, Admin, Member at first; custom roles and `App\Enums\Permission` from plan 08). Policies check permissions, never role names.
- No tenant in the URL or subdomain. The active organization is stored server-side (`users.current_organization_id`).
- Switching: `POST /api/organizations/{id}/switch`. Verify membership on the server every time.
- Tenant-owned models use the `BelongsToOrganization` trait (global scope + auto-set `organization_id` on create).
- NEVER accept an organization id from the frontend as proof of access. Always derive it from the authenticated user.
- Every tenant-owned feature needs a Pest test proving org A cannot read or write org B's data.
- `App\Support\CurrentOrganization` (scoped: fresh per request and job) is the only way to know the current organization: `id()`, `role()`, `get()`; `set()` for jobs and tests. It re-checks membership on every request. Routes that act on it go in the `organization` middleware group in `routes/api.php` (403 "No active organization." without one). In jobs and listeners use `run($organization, fn () => ...)`, which restores the previous state, not a bare `set()`.
- `apiFetch` sends `X-Organization-Id` (the organization the tab thinks is current). It is a precondition only, never used to choose: the `organization` middleware answers 409 `organization_changed` when it differs (another tab switched), and `apiFetch` then reloads `/api/me` and drops `['org']` queries.
- Until plan 08 the role is `App\Enums\OrganizationRole` (`owner`, `admin`, `member`) on `organization_user.role`. Registration creates a personal organization the user owns.
- Pest helpers (`tests/Pest.php`): `userInOrganization($role, $name)` and `inOrganization($org, fn () => ...)` to create tenant-owned rows. `TestCase::call()` forgets scoped instances, so each test request resolves the organization afresh.

## Tasks (the reference feature, plan 07)
- Tenant-owned: title, description, status (`todo`, `in_progress`, `done`; an enum), priority (`low`, `medium`, `high`), `due_date` (date, nullable), `assignee_id` (nullable, must be a member of the same organization; set to null when they leave), `created_by`.
- The list filters by status, assignee, priority, overdue and search, and is paginated. Filters live in the URL.
- Permissions: anyone in the organization lists, views and creates; `tasks.manage` (Owner, Admin) edits and deletes any task; otherwise you edit tasks you created or are assigned to, and delete the ones you created.
- The assignee is checked twice: the form request (`Rule::exists` on `organization_user` scoped to the current organization) and the actions (`LocksAssignee`: a `FOR SHARE` lock on that membership, so `RemoveMember` waits and then unassigns).
- Overdue (due before the API's today, not done) is decided by the server: `Task::scopeOverdue()` for the filter, `is_overdue` in the resource. The app never compares dates; `due_date` is a plain `YYYY-MM-DD` string.
- The `organization` middleware runs before route model binding (`bootstrap/app.php`), so a missing organization or a stale tab gets its 403/409 instead of a 404 from the scoped binding.

## Backend conventions
- Thin controllers. Business logic in Action or Service classes.
- Use Form Requests for validation and API Resources for responses.
- Migrations only. Never edit an existing migration that has been committed; add a new one.
- Do not edit `.env`. Add new keys to `.env.example` with a comment.
- Features that reach other people or cost money (invitations, billing, AI) use the `verified` middleware (403 `email_unverified`). Joining doesn't: accepting an invitation verifies the email, since its link reached that inbox (`AcceptInvitation`).
- Policies check `App\Enums\Permission` through `CurrentOrganization::can()` / `role()->can()`, never role names. You may only act on, or give, a role your own role `includes()` (its permissions are a subset of yours): that's what keeps admins away from owners. An organization always keeps an owner (`EnsuresAnOwnerRemains`: lock the organization row first, then the owner rows in id order). Resources return `can` flags (and `assignable_roles` for members) so the app never repeats the rules.
- Email: mail and notifications are queued (tries + backoff) and take plain values. Links point at the app (`config('app.frontend_url')`). Locally they go to `storage/logs/laravel.log` and need `php artisan queue:work`; `servers.sh start --mail` sends them at once.
- Tokens are only issued through `IssueToken`. A new way to log in must go through the same checks (two-factor from plan 15, suspension from plan 17).
- Email links point at the React app (`config('app.frontend_url')`), which posts them back to the API.

## Frontend conventions
- Function components and hooks, TypeScript strict, no `any`. Files: components in PascalCase `.tsx`, hooks named `useX`.
- Layout of `web/src/`: `routes/` (one file per page, wired in `router.tsx`), `features/<feature>/` (that feature's query hooks in `api.ts`, plus its components), `components/ui/` (shadcn-generated; add with `npx shadcn add`, don't hand-edit without a reason), `components/` (shared), `stores/` (Zustand), `lib/` (`api.ts`, `apiErrors.ts`, `safeRedirect.ts`), `types/api.ts`.
- API calls go through `apiFetch` (`lib/api.ts`). It adds the base URL (`VITE_API_BASE`), `Accept: application/json` and the Bearer token, and clears the session on a 401. Components never call it directly: they use TanStack Query hooks from `features/<feature>/api.ts`. No `useEffect` + `useState` for loading data.
- Query keys for org-scoped data start with `['org', currentOrganizationId, ...]`; switching organization removes them, and goes to the root of the current section (`/tasks/42?assignee=7` → `/tasks`), since ids and filters belong to the old one. Mutations invalidate what they change; a detail page still mounted marks its own query stale (`refetchType: 'none'`) instead of removing it, which would refetch it.
- List filters live in the URL (`useSearchParams`), parsed on every render and never copied into state; unknown values are dropped. Filter changes replace the history entry and reset the page; page links push one. Typing is debounced with `useDebouncedCallback`.
- The current organization comes with the user (`user.current_organization`, with the role): read it with `useCurrentOrganization()`, never copy it into other state. The organization list is `organizationsQuery` (`['organizations']`, user-level). Switch and create go through `useSwitchOrganization` / `useCreateOrganization`, which also drop `['org']` queries.
- Logged-in pages render in `AppLayout`: the shadcn sidebar (`AppSidebar`: organization switcher, the `navigation` list, `UserMenu` with appearance and log out, version). A new page adds its entry to `navigation`. Appearance is `next-themes` (`ThemeProvider` in `AppProviders`, `dark` class on `<html>`); pages use the theme tokens (`bg-background`, `text-muted-foreground`, ...), not fixed colors.
- Server data lives in TanStack Query. Zustand holds only client state: the auth store (token, current user) and the current organization. Select slices (`useAuth(s => s.user)`), not the whole store.
- The token is kept in `localStorage` (no SSR, so no httpOnly cookie without a proxy). That makes XSS a token theft: no `dangerouslySetInnerHTML` on user content, and nothing secret in `VITE_*` (it ships to the browser).
- React Router in data mode: routes are declared in `router.tsx` (`createBrowserRouter`); layouts and guards are pathless layout routes with `<Outlet>`. Data comes from TanStack Query, not route loaders. Import `RouterProvider` from `react-router/dom` (it supports `flushSync`).
- Route guards are components (`RequireAuth`, `RequireGuest`) around route groups, in place of Nuxt middleware. `?redirect=` goes through `safeRedirect`. They redirect by reacting to the auth store, so code outside React (like `apiFetch` on a 401) only clears the session and never navigates.
- `bootstrapSession()` loads `/api/me` once in `main.tsx` before the first render; only the token is persisted (`localStorage` key `auth`).
- Tests: Vitest + Testing Library (jsdom). `src/test/utils.tsx` has `renderApp(path)` (the real routes in a memory router), `mockApi({ 'POST /api/login': (init, url) => ... })` (matched without the query string, which the handler reads from `url`; fails on any unmocked request), `test/tasks.ts` (`makeTask`, `taskPage`, members), `logIn()`, the `acme`/`beta` organizations with `organizationList()`, and `logOutFromMenu()`. Logged-in pages load `GET /api/organizations` (the switcher), so mock it. `src/test/setup.ts` stubs the browser APIs jsdom lacks (`matchMedia`, `ResizeObserver`, pointer capture). Radix submenus: drive them with the keyboard in tests. Lint is oxlint (`.oxlintrc.json`: rules of hooks, exhaustive deps, no `any`).
- Forms use react-hook-form with a zod schema; 422 errors are mapped onto fields.
- Use shadcn/ui components before writing custom ones. Check the shadcn MCP before guessing a component's API.
- Show or hide controls with `useCan(permission)` (current organization) or the resource's `can` flags; the API decides. Confirm destructive actions with `components/ConfirmDialog`. A Radix `Select` in a react-hook-form form goes through `Controller` (it isn't a native input).
- Work that must happen after a mutation even if the component unmounts (leaving an organization drops the list it's in) goes in `useMutation({ onSuccess })`, not in `mutate(vars, { onSuccess })`: TanStack Query skips the latter once its component is gone. Use `onMutate`'s return value (`context`) for anything read before the request.
- Every page has loading, empty and error states. Dates render with a fixed locale and time zone.

## Later conventions
saas-starter's CLAUDE.md has the sections for features not built here yet: AI layer (plans 10–13), billing (16), audit log (09), platform admin (17), automation. Each plan brings its section over, adapted, in the same commit as the feature. Until then, read it there before planning that feature.

## Learning mode
The point of this repo is to learn React.
- Every plan's Frontend section lists the **React concepts** it introduces, one line each, compared with the Vue/Nuxt equivalent (e.g. `useState` ↔ `ref`, `useMemo` ↔ `computed`, TanStack Query ↔ `useAsyncData`, Zustand ↔ Pinia, React Router ↔ Nuxt pages, `children` ↔ slots).
- While building, explain each non-obvious React choice in a line or two.
- When the user says they'll write a piece themselves, leave a stub with a TODO listing the steps, then review their code instead of writing it.

## Claude Code tooling (`.claude/`, `.mcp.json`)
- Skills:
  - `/plan-task` (write a plan and stop for approval)
  - `/verify` (Pint, Pest, lint, typecheck, Vitest, build; skips a side that doesn't exist yet)
  - `/tenant-feature` (recipe for adding a tenant-owned feature, modeled on Tasks)
  - `/e2e-smoke` (real servers on :8112/:5112: `vite preview` of a build in `web/dist-e2e`; `start --mail` sends emails to the log at once, `mail.sh` reads them and their links)
  - `/ship-plan` (commit, push and mark the plan Done; only you can start it)
- Subagents:
  - Read-only reviewers: `code-reviewer`, `security-auditor`, `tenant-isolation-reviewer`, `database-reviewer`, and `ui-reviewer` (browser; runs on Sonnet in quick mode, checking only the items listed, unless asked for a "full sweep").
  - `flow-tester` (browser + curl; Sonnet) runs a plan's Flows end to end, emails included, and returns a results table. It doesn't edit code, uses only `e2e-ft-*` accounts and removes them; a hook limits its `servers.sh` use to `status`, `errors` and `cleanup e2e-ft-`.
  - `laravel-test-writer` can edit only `api/tests/`, enforced by a hook.
- Hooks: `block-destructive-db.sh` (every Bash call, subagents included) refuses commands that wipe or drop database data.
- Before reporting a change as done, run the reviewers that fit, in parallel:
  - always code, security and tenant isolation (once organizations exist);
  - database when there are migrations or queries;
  - UI when pages change.
- After the tests pass, when the plan has Flows, start the servers with `--mail` and run `flow-tester` on them (in the background, alongside `ui-reviewer` if it runs).
- MCP: `playwright` (headless browser for e2e and UI review) and `shadcn` (component registry and docs).

## Workflow rules
- Plan first for anything touching more than 3 files, then wait for approval.
- Plans live in `docs/plans/NN-task-name.md` (named after the task) with a Status line: Proposed → WIP (on approval) → Done (when committed, with commit hashes). Keep the index in `docs/plans/README.md` in sync.
- Build in small steps: one feature, tests passing, then commit.
- Docs change in the same commit as the code they describe: `.env.example` (new keys), `CLAUDE.md` (conventions, commands, tooling), root `README.md` (setup, run steps, features). `/ship-plan` checks this.
- From plan 03 on, a new page or a user-visible feature gets a README screenshot in the same plan: a PNG in `docs/screenshots/` plus a line in the README "Screenshots" section, captured with the Playwright MCP from e2e demo data (light mode, 1280×800, `e2e-*` accounts cleaned up). Retake an existing shot when a change makes it out of date.
- Commit message format: `type(scope): summary` (e.g. `feat(web): add task list filters`).
- Ask before adding a new dependency and say why it's needed. The stack listed above is already approved.
