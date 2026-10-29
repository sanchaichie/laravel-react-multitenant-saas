# 03 — React app with token auth

**Status:** Done
**Commits:** `640e2c7` (web), `a319a4b` (claude)

## Goal
Create `web/`: a Vite + React 19 + TypeScript app that can register, log in, show the current user, log out, and stay logged in across reloads, against the API from plan 02. It sets up the pieces every later page builds on: `apiFetch`, the auth store, query hooks, route guards, forms with 422 mapping, shadcn/ui and Vitest. It rebuilds saas-starter plan 02 (Nuxt auth).

Out of scope: organizations, the switcher and the sidebar (plan 05), email verification (06), forgot password and account settings (14), two-factor (15), and dark mode (follows later with the sidebar layout). CI is plan 04.

## Decisions
- **Versions (current stable):** Vite 8, React 19.3, TypeScript (whatever `npm create vite` pins), React Router 8, TanStack Query 5, Zustand 5, Tailwind 4 (`@tailwindcss/vite`), shadcn 4, react-hook-form 7, zod 4, Vitest 5. Node 22 (`web/.nvmrc`: `22.23.4`, installed here).
- **New dependencies that need your OK.** They aren't named in the stack, but the stack's pieces need them:
  - `@hookform/resolvers`: connects a zod schema to react-hook-form.
  - `@testing-library/react`, `@testing-library/user-event`, `@testing-library/jest-dom`, `jsdom`: Vitest needs a DOM and a way to render and click components. These are dev-only.
  - What `shadcn init`/`add` installs. As built (shadcn 4.23, Radix base, Nova preset): `cn` (shadcn's own class merger, in place of `clsx` + `tailwind-merge`), `class-variance-authority`, `radix-ui`, `lucide-react` (icons), `sonner` (toasts), `next-themes` (its Toaster reads the theme), `tw-animate-css`, `@fontsource-variable/geist` (the font), and `shadcn` itself as a dev dependency (for `shadcn/tailwind.css`).
  - **Lint is oxlint, not ESLint**, because the Vite template now ships oxlint. Its `.oxlintrc.json` covers the rules of hooks, exhaustive deps, fast-refresh exports and no `any`, without adding ESLint. This changes the plan; approved on 2026-10-29, together with the shadcn packages above.
- **React Router in data mode** (`createBrowserRouter` + `RouterProvider`, routes declared in `router.tsx`), not framework mode. Framework mode adds its own Vite plugin, file-based routes and SSR, which is more to learn and doesn't match the `routes/` + `router.tsx` layout in CLAUDE.md. Route loaders aren't used for data: TanStack Query loads it.
- **Auth store (Zustand)** holds `token` and `user`, as CLAUDE.md says.
  - Only the token is saved to `localStorage`, through `persist` with `partialize`.
  - Login and register put both in the store from the API's response.
  - On startup, `bootstrapSession()` (in `main.tsx`, before the first render) loads `/api/me` when there's a token and no user. It's the same job as saas-starter's startup plugin, and it means no page renders "logged in" with an unknown user.
  - If the API can't be reached, the token is kept and the user stays null. `RequireAuth` then shows an error state with a Retry button.
- **`apiFetch<T>(path, { method, body })`** in `lib/api.ts`:
  - Adds `VITE_API_BASE`, `Accept: application/json`, JSON `Content-Type` and the Bearer token. It returns parsed JSON (or nothing for a 204).
  - It throws an `ApiError` with `status` and `data`, or a network error.
  - On a 401 sent with the current token, it clears the session and the query cache. No navigation happens inside `apiFetch`: `RequireAuth` is subscribed to the store, so it re-renders and redirects to `/login`. That way `apiFetch` doesn't need the router.
  - A 401 from logout itself is ignored (the token was already revoked): logout passes `endSessionOn401: false` and ends the session itself.
  - A request whose `signal` times out (`AbortSignal.timeout`) counts as a `NetworkError`. `bootstrapSession` gives `/api/me` 10 seconds, so an API that never answers leads to Retry, not an endless spinner.
- **Logout** clears the session and navigates to `/login` in one render: `navigate(..., { flushSync: true })` with `RouterProvider` from `react-router/dom`. The store's update is synchronous and the router's is a transition, so without that, `RequireAuth` rendered at the old page and added `?redirect=`. If the API couldn't confirm the logout (not a 401), the user is still logged out on this device, with a warning toast.
- **Query hooks** in `features/auth/api.ts`: `useLogin`, `useRegister`, `useLogout` (`useMutation`). There's no `useMe` query, because the user lives in the store (see above). Later features follow the same pattern with `useQuery`.
- **Guards** are components wrapping route groups:
  - `RequireAuth`: no token → `<Navigate to="/login?redirect=…">`; a token without a user → error state; otherwise `<Outlet/>`.
  - `RequireGuest`: logged in → `<Navigate to={safeRedirect(redirect)}>`.
  - `safeRedirect` comes over from saas-starter, with its tests.
- **Forms:** react-hook-form with `zodResolver`, on shadcn's form/field components. Client rules match the API's (required, email, password ≥ 8 and confirmed). A 422 from the API goes onto the fields through `setError` (`applyFieldErrors` in `lib/apiErrors.ts`). Anything else shows a toast with `errorMessage()` (429, can't reach the server, the API's message).
- **Dates** are formatted by one helper (`lib/format.ts`) in a fixed locale and time zone (`en-US`, `UTC`). The home page shows "Member since …".
- **Pages:** `/login`, `/register`, `/` (home: "Welcome, {name}", email, member since), plus a not-found page for unknown paths. Layouts: `AuthLayout` (a centered card) and `AppLayout` (a top bar with the app name and the user's name, and a Log out button; the sidebar comes in plan 05).
- **Env:** `web/.env.example` with `VITE_API_BASE=http://localhost:8100` and a comment that `VITE_*` ships to the browser. `web/.env` is gitignored, and the app falls back to that default when it's unset.

## Backend (`api/`)
None. Plan 02's endpoints and CORS (`FRONTEND_URL=http://localhost:5173`) are all this needs.

## Frontend (`web/`)
- Scaffold: `npm create vite@latest web -- --template react-ts`, then Tailwind 4 via `@tailwindcss/vite`, the `@/` path alias, `npx shadcn init`, and `npx shadcn add button card input label field sonner` (I'll check the component names and API through the shadcn MCP once `components.json` exists).
- `package.json` scripts: `dev` (port 5173), `build`, `preview`, `lint` (`oxlint --deny-warnings`), `typecheck` (`tsc -b`), `test` (`vitest run`). `verify.sh` already runs exactly these.
- `src/types/api.ts`: `User`, `AuthResponse`, `LoginPayload`, `RegisterPayload`, `ValidationErrorBody`.
- `src/lib/api.ts` (`apiFetch`, `ApiError`), `src/lib/apiErrors.ts` (`applyFieldErrors`, `errorMessage`), `src/lib/safeRedirect.ts`, `src/lib/format.ts`, `src/lib/queryClient.ts`.
- `src/stores/auth.ts`: `useAuth` with `token`, `user`, `setSession`, `setUser`, `clearSession`, plus `bootstrapSession()`.
- `src/features/auth/api.ts` (the mutations), `LoginForm.tsx`, `RegisterForm.tsx`, `schemas.ts` (the zod schemas).
- `src/components/RequireAuth.tsx`, `RequireGuest.tsx`, `AuthLayout.tsx`, `AppLayout.tsx`, `ErrorState.tsx`, `FullPageSpinner.tsx`.
- `src/routes/LoginPage.tsx`, `RegisterPage.tsx`, `HomePage.tsx`, `NotFoundPage.tsx`; `src/router.tsx`; `src/main.tsx` (QueryClientProvider, Toaster, bootstrap, then RouterProvider).
- Page titles through React 19's `<title>` in each page.
- No sidebar navigation yet.

React concepts (each compared with Vue/Nuxt):
- **JSX and function components** ↔ `<template>` + `<script setup>`. Markup is a JS expression returned from a function, so `v-if` becomes `&&`/ternaries and `v-for` becomes `.map()` with a `key`.
- **Props and `children`** ↔ props and the default slot (`AuthLayout` wraps the forms in `children`).
- **`useState`** ↔ `ref`. Setting state re-runs the whole component function, instead of updating one reactive dependency.
- **Rules of hooks:** hooks are called at the top level, in the same order every render. Nothing like this exists in Vue, where `ref()` can go anywhere in `setup`.
- **React Router `createBrowserRouter`, `<Outlet>`, `<Navigate>`, `useNavigate`, `useSearchParams`, `<Link>`** ↔ Nuxt pages, `<NuxtPage>`, `navigateTo`, `useRoute().query`, `<NuxtLink>`. Layout routes with `<Outlet>` play the role of Nuxt layouts.
- **Guard components** ↔ route middleware. A redirect is just rendering `<Navigate>`.
- **Zustand `create` + selectors** (`useAuth(s => s.user)`) ↔ a Pinia setup store. The selector is what makes the component re-render only when that slice changes, which Vue's reactivity does for you. `persist` ↔ `useCookie`/`pinia-plugin-persistedstate`.
- **TanStack Query `useMutation`, `QueryClient`** ↔ calling `$fetch` in a store action, with loading and error state handled for you. `useQuery` comes with the first list in plan 05.
- **react-hook-form `useForm` + `zodResolver`, `setError`** ↔ `UForm` with `:validate`/`:schema` and `form.setErrors`. Inputs stay uncontrolled (refs), so typing doesn't re-render the form.
- **Controlled vs uncontrolled inputs:** `value` + `onChange` versus `register()`. This is `v-model` split into its two halves.
- **React 19 `<title>` in a component** ↔ `useSeoMeta`.
- **`StrictMode` double-invoking renders in dev:** why side effects don't go in the render body.

## Tests
Vitest + Testing Library (jsdom):
- `safeRedirect.test.ts`: paths pass. `//evil.com`, `/\evil.com`, `https://…`, empty and non-string values fall back to `/`. These are ported from saas-starter.
- `api.test.ts` (with a mocked `fetch`):
  - sends the base URL, `Accept` and the Bearer token;
  - a 422 throws an `ApiError` carrying `errors`;
  - a 401 with the current token clears the session, and a 401 with an older token doesn't;
  - a 204 returns nothing;
  - a network failure throws a "can't reach" error.
- `apiErrors.test.ts`: 422 fields go to `setError`, plus the messages for 429, network failures and other statuses.
- `LoginForm.test.tsx`:
  - client validation (empty fields);
  - the API's 422 shows under `email`;
  - success stores the session and navigates to `safeRedirect(?redirect)`.
- `RegisterForm.test.tsx`: a password mismatch is caught on the client; "email taken" from the API shows under `email`.
- `guards.test.tsx`:
  - logged out on `/` → `/login?redirect=%2F`;
  - logged in on `/login` → `/`;
  - a token with no user (API down) → error state with Retry.

No Pest changes. Nothing is tenant-owned (the org A vs org B tests start in plan 05).

## README screenshots
These are the first ones. Taken with the Playwright MCP at 1280×800 in light mode, against the e2e servers, with an `e2e-*` account that's removed afterwards:
- `docs/screenshots/login.png`: the login page.
- `docs/screenshots/register-errors.png`: the register form showing the field errors (email taken, passwords don't match).
- `docs/screenshots/home.png`: home, logged in.

The README gets a "Screenshots" section with one line per shot.

## Verification
- The `verify` skill: Pint and Pest (unchanged), plus oxlint, typecheck, Vitest and build for `web/`.
- `e2e-smoke`: `servers.sh start` now builds and previews `web/` on :5112.
- Reviewers: `code-reviewer`, `security-auditor` (token in `localStorage`, no `dangerouslySetInnerHTML`, `VITE_*`, the open redirect), and `ui-reviewer` (quick mode: the three pages at 390px, focus order, labels, form errors, not found, console errors). `tenant-isolation-reviewer` and `database-reviewer` don't apply.
- Docs in the same commit:
  - root `README.md`: Node 22, web setup (`npm install`, `cp .env.example .env`), run (`npm run dev` on :5173), tests (`npm test`), screenshots;
  - `CLAUDE.md`: drop the line on `servers.sh` needing `web/` if it's there, and note data-mode routing and `bootstrapSession`;
  - `web/.env.example`.

Flows (for `flow-tester`, with `e2e-ft-*` accounts):
1. Open `/` logged out → sent to `/login?redirect=%2F`.
2. Go to Register, submit empty → each field shows an error and nothing is sent.
3. Register with mismatched passwords → "passwords don't match" under the confirmation field.
4. Register properly → home shows "Welcome, {name}" and the email.
5. Reload → still logged in, with no flash of the login page.
6. Open `/login` while logged in → sent to `/`.
7. Log out → on `/login`. Opening `/` again sends you back to `/login`.
8. Register again with the same email → "The email has already been taken." under email.
9. Log in with a wrong password → the error shows under email and the password field stays on the page.
10. Log in at `/login?redirect=//evil.example` → lands on `/`, not off-site.
11. Log in, then revoke the token with curl (`POST /api/logout` with the token from `localStorage`), then reload → sent to `/login`, and `localStorage` holds no token.
12. Open `/nope` → not-found page with a link home.
13. At 390px width: the login, register and home pages fit without horizontal scrolling.

## Outcome
- `/verify`: Pint and 44 Pest tests (unchanged), and for web: oxlint, typecheck, 57 Vitest tests in 6 files, build. Versions as built: Vite 8.3.5, React 19.3.1, TypeScript 7.0.2, React Router 8.5.1, TanStack Query 5.105, Zustand 5.0.16, Tailwind 4.3.3, react-hook-form 7.91, zod 4.6.7, Vitest 5.0.5.
- `flow-tester`: all 13 flows passed, with no log errors and no emails. Cleanup removed its account.
- After the fixes below, checked again in the browser: logout lands on `/login` with no `?redirect=`, and the stored token is null.
- Reviewers:
  - `ui-reviewer`: the error messages weren't linked to their inputs, and the password hint repeated the length error. Fixed: each error has an id that its input's `aria-describedby` points to (`lib/aria.ts`), and the error replaces the hint. A test covers it.
  - `code-reviewer`: a failed Retry said nothing; startup could hang on an API that never answers; a 401 from logout could still add `?redirect=`. All three are fixed and tested. Also: no console error for a normal expired token, a `console.error` moved out of the render body, and a test for the "session changed during /api/me" guard.
  - `security-auditor`: nothing high. Fixed: the warning when the server can't confirm a logout; `safeRedirect` also rejects backslashes and control characters (`/\t/evil.com`); a 5xx shows the generic message instead of exception text; `shadcn` moved to devDependencies.
- Found while testing: logout first went through `/` → `/login?redirect=%2F` (see Decisions → Logout). A guards test caught it.
- The bundle is 551 kB (173 kB gzipped), and Vite warns about it. Route-level code-splitting can come with more pages.

## Follow-ups
- `npm audit`: 3 low (deepmerge, through the `shadcn` CLI's registry code). This is dev only, isn't reached at runtime and isn't in the bundle. Don't `npm audit fix --force`: it would downgrade shadcn to 1.0.
- `composer audit` (API): symfony/mime CVE-2026-45070 (email header injection). It can't be reached until the app sends mail, so update it in plan 06 (invitations) at the latest.
- Tokens never expire (plan 02 follow-up). That matters more now that the token sits in `localStorage`.
- There's no logout sync between tabs: another open tab finds out on its next request (401).
- Dark mode: `next-themes` is installed for the Toaster, but there's no provider or toggle yet. The Toaster is fixed to light.

## Commits after approval
`feat(web): add React app with token auth`, then this plan → Done.
