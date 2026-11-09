# 19 — Next.js frontend, chosen at run time

**Status:** Done
**Commits:** `fe208ad` (api), `78dbcda` (web), `6df0254` (next), `8198aed` (claude)

## Goal
A second frontend, `next/` (Next.js App Router), next to the React SPA in `web/`, on the same Laravel API. One setting (`FRONTEND=react|next`) picks which one runs and which one email links point at, so a client can see the same product built both ways. The Next app is written the Next way (Server Components, Server Actions, a route-handler proxy, an httpOnly session cookie) rather than as a copy of the SPA.

First version: foundation, auth and Tasks. Out of scope for now (later plans, one each): members and invitations, roles, audit log, AI (summaries, writing help, chat), account and two-factor settings, billing, platform admin, webhooks. Until a page is ported, its nav entry isn't shown and its email links say to use the React app.

## Decisions
- **Side by side, `web/` untouched.** `next/` runs on :3100 in dev (saas-starter keeps :3000). `FRONTEND=next` in `servers.sh` swaps it in for e2e on :5112.
- **The browser never sees the token.** The login Server Action calls `POST /api/login` and stores the token in an httpOnly, `SameSite=Lax` cookie (`Secure` outside local). `app/api/[...path]/route.ts` proxies browser calls to Laravel, adding `Authorization: Bearer` from the cookie (method, body, query, `X-Organization-Id` and status pass through; the body streams). A 401 clears the cookie. The browser only talks to its own origin, so the API needs no CORS change.
- **Data.** Server Components fetch with `serverApi()` (reads the cookie, calls Laravel directly). Interactive parts are client components using TanStack Query through the proxy. `proxy.ts` (Next 16's middleware) redirects to `/login` without a cookie; the API's 401 is still the real check.
- **Shared types.** `web/src/types/api.ts` moves to `shared/types/api.ts`, `web/` keeps a one-line re-export, `next/` uses a path alias. No npm workspaces.
- **UI: TailAdmin (free Next.js edition, MIT)** instead of shadcn, so the two apps also show two UI systems. `next/` starts from its repo: its sidebar, header, dark mode and UI elements (Base UI primitives) stay; demo pages and the packages only they use go. Its license file stays in `next/`. In `next/`, use TailAdmin's components before writing new ones (the shadcn rule is for `web/`).
- **Home page: a Tasks overview.** Stat cards (to do, in progress, done, overdue, assigned to me) and a status chart (ApexCharts, from TailAdmin), each count read from `GET /api/tasks` with a filter (`meta.total`), fetched in parallel on the server. No API change.
- **New dependencies:** `next` (16) and what TailAdmin brings that the kept pages use (Base UI, ApexCharts), approved with the plan.

## Backend (`api/`)
`FRONTEND_URL` decides email links and CORS as before. One addition (security review): the Next app calls the API from its server, so without it every user would share that server's IP (one per-IP rate-limit bucket for sign-up, password reset and two-factor; its address in the device list and audit log). `TRUSTED_PROXIES` (`config('app.trusted_proxies')`, empty by default, `"*"` refused at boot) lets the listed addresses pass the client IP in `X-Forwarded-For` (`AppServiceProvider::trustProxies`, that header only). The Next app sends it from the header its own reverse proxy sets (`CLIENT_IP_HEADER`), never a client's own. Pest: `TrustedProxiesTest`.

## Frontend (`next/`)
- `src/lib/`: `session.ts` (cookie), `serverApi.ts` (server-only fetch, `ApiError`), `clientApi.ts` (browser fetch to the proxy, `X-Organization-Id`, 409 handling).
- Auth pages: `/login` (two-factor challenge in client state), `/register`, `/forgot-password`, `/reset-password`, `/verify-email`, logout. Server Actions + `useActionState`, 422s on fields.
- `app/(app)/layout.tsx`: `/api/me` and organizations on the server, TailAdmin's sidebar and header, organization switcher (Server Action, then refresh and the section root), user menu (appearance, log out).
- `/`: the Tasks overview (stat cards, status chart, links into filtered lists).
- Tasks: `/tasks` (filters from `searchParams`, pagination, overdue from the API), `/tasks/new`, `/tasks/[id]` (view and edit), delete with a confirm dialog; controls follow the resource's `can` flags and the role's permissions. `loading.tsx`, `error.tsx`, `not-found.tsx`.

React concepts (Next compared with the SPA in `web/`):
- File routing (`app/tasks/[id]/page.tsx`) ↔ `router.tsx` route objects.
- Server Components fetching on the server ↔ TanStack Query in the browser.
- `'use client'` boundary: only interactive parts ship JS.
- Server Actions + `useActionState` ↔ `useMutation` + `apiFetch`.
- Route-handler proxy + httpOnly cookie ↔ Bearer token in `localStorage`.
- `proxy.ts` redirects ↔ `RequireAuth` / `RequireGuest` components.
- `searchParams` page prop ↔ `useSearchParams`; `router.refresh()` ↔ `invalidateQueries`.
- `loading.tsx` / `error.tsx` / `not-found.tsx` ↔ hand-written pending and error states.

## Tests
Vitest in `next/`: the proxy (token forwarded, status passed through, cookie cleared on 401, no token without a cookie), login (422 mapping, two-factor challenge), the task list (filters from `searchParams`, controls hidden without `tasks.create`). The API is unchanged, so Pest stays as is.

## README screenshots
`docs/screenshots/next-overview.png` (the Tasks overview) and `docs/screenshots/next-tasks.png` (the task list), captured like the others.

## Verification
`verify` (now with a `next` side), CI job for `next/`, `e2e-smoke` with `FRONTEND=next`, code, security and UI reviewers.

Flows (for `flow-tester`, with `FRONTEND=next`):
1. Register → lands on tasks of the personal organization; the verification email's link opens the Next `/verify-email` and verifies.
2. Log out → cookie gone, `/tasks` sends to `/login?redirect=/tasks`; log in → back on `/tasks`.
3. Create a task, edit it, filter by status and search (filters in the URL), delete it.
4. Switch organization from a task page → `/tasks` of the other organization.
5. A user with two-factor on gets the code step at login.

## Commits after approval
`refactor(web): move API types to shared/`, `feat(next): Next.js frontend with auth and tasks`, `chore: choose the frontend in servers.sh, verify and CI`, then this plan → Done.

## Follow-ups
- Port the remaining pages to `next/` one plan each: members and invitations (and `/join`), roles, audit log, account and two-factor settings (and `/confirm-email`, `/revert-email`, `/revert-two-factor`), AI (summaries, writing help and chat, which will stream through the `/api` proxy), billing, platform admin, webhooks. Each adds its routes to the proxy's `ALLOWED` only if client code calls them.
- The overview reads 9 pages of `/api/tasks` for its counts (`meta.total`): a counts endpoint (`GET /api/tasks/stats`) would make it one query.
- Page loads in `next/` don't send `X-Organization-Id` (only actions do): a switch in another tab during a render could show the new organization's rows under the old one's name (actions still get the 409). Pass the page's organization to `lib/tasks.ts` and treat a 409 like a stale page.
- On a loaded machine the web Vitest suite times out at 5s per test (passes with `--maxWorkers=2 --testTimeout=20000`); consider a longer default timeout.
