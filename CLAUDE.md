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
- Never wipe a database. Dev and test share one Postgres, and `--env=testing` without an `.env.testing` uses `.env`. `.claude/hooks/block-destructive-db.sh` blocks `migrate:fresh`/`refresh`/`reset`/`rollback`, `db:wipe`, `dropdb` and `DROP`/`TRUNCATE` for Claude. Plan 02 also adds the `AppServiceProvider::guardDestructiveDatabaseCommands` guard from saas-starter. Pest rebuilds its own database.

## Multi-organization model
- Users belong to many organizations via the `organization_user` pivot. Roles are per organization (Owner, Admin, Member at first; custom roles and `App\Enums\Permission` from plan 08). Policies check permissions, never role names.
- No tenant in the URL or subdomain. The active organization is stored server-side (`users.current_organization_id`).
- Switching: `POST /api/organizations/{id}/switch`. Verify membership on the server every time.
- Tenant-owned models use the `BelongsToOrganization` trait (global scope + auto-set `organization_id` on create).
- NEVER accept an organization id from the frontend as proof of access. Always derive it from the authenticated user.
- Every tenant-owned feature needs a Pest test proving org A cannot read or write org B's data.

## Tasks (the reference feature, plan 07)
- Tenant-owned: title, description, status (`todo`, `in_progress`, `done`; an enum), priority (`low`, `medium`, `high`), `due_date` (date, nullable), `assignee_id` (nullable, must be a member of the same organization; set to null when they leave), `created_by`.
- The list filters by status, assignee, priority, overdue and search, and is paginated. Filters live in the URL.

## Backend conventions
- Thin controllers. Business logic in Action or Service classes.
- Use Form Requests for validation and API Resources for responses.
- Migrations only. Never edit an existing migration that has been committed; add a new one.
- Do not edit `.env`. Add new keys to `.env.example` with a comment.
- Features that reach other people or cost money (invitations, joining, billing, AI) use the `verified` middleware (403 `email_unverified`).
- Tokens are only issued through `IssueToken`. A new way to log in must go through the same checks (two-factor from plan 15, suspension from plan 17).
- Email links point at the React app (`config('app.frontend_url')`), which posts them back to the API.

## Frontend conventions
- Function components and hooks, TypeScript strict, no `any`. Files: components in PascalCase `.tsx`, hooks named `useX`.
- Layout of `web/src/`: `routes/` (one file per page, wired in `router.tsx`), `features/<feature>/` (that feature's query hooks in `api.ts`, plus its components), `components/ui/` (shadcn-generated; add with `npx shadcn add`, don't hand-edit without a reason), `components/` (shared), `stores/` (Zustand), `lib/` (`api.ts`, `apiErrors.ts`, `safeRedirect.ts`), `types/api.ts`.
- API calls go through `apiFetch` (`lib/api.ts`). It adds the base URL (`VITE_API_BASE`), `Accept: application/json` and the Bearer token, and clears the session on a 401. Components never call it directly: they use TanStack Query hooks from `features/<feature>/api.ts`. No `useEffect` + `useState` for loading data.
- Query keys for org-scoped data start with `['org', currentOrganizationId, ...]`; switching organization removes them. Mutations invalidate what they change.
- Server data lives in TanStack Query. Zustand holds only client state: the auth store (token, current user) and the current organization. Select slices (`useAuth(s => s.user)`), not the whole store.
- The token is kept in `localStorage` (no SSR, so no httpOnly cookie without a proxy). That makes XSS a token theft: no `dangerouslySetInnerHTML` on user content, and nothing secret in `VITE_*` (it ships to the browser).
- Route guards are components (`RequireAuth`, `RequireGuest`) around route groups, in place of Nuxt middleware. `?redirect=` goes through `safeRedirect`.
- Forms use react-hook-form with a zod schema; 422 errors are mapped onto fields.
- Use shadcn/ui components before writing custom ones. Check the shadcn MCP before guessing a component's API.
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
