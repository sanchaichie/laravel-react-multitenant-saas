# 01 — Claude Code tooling

**Status:** Done
**Commits:** `6670167` (claude)

## Goal
Start the repo with saas-starter's Claude Code setup (skills, subagents, hooks, MCP, workflow rules), adapted to a React frontend, plus the roadmap of plans. No app code. `api/` and `web/` come in plans 02 and 03.

## Decisions
- **Stack:** Vite + React 19 + TypeScript, React Router, TanStack Query (the role of `useAsyncData`), Zustand (the role of Pinia), shadcn/ui + Tailwind v4 (the role of Nuxt UI), react-hook-form + zod, Vitest. It's a plain SPA, the closest match to the Nuxt app with token auth, and nothing on top of React to learn first.
- **Tasks replace Projects:** status, priority, due date, and an assignee from the organization's members, so the reference feature also exercises cross-tenant checks on a user reference.
- **Ports** :8100/:5173 for dev and :8112/:5112 for e2e, so saas-starter (:8000/:3000, :8011/:3011) can run at the same time.
- **Token in `localStorage`:** without SSR there's no httpOnly cookie unless we add a proxy. The reviewers weigh XSS with that in mind.
- **e2e web server:** `vite preview` of a build in `web/dist-e2e` with `VITE_API_BASE` baked in. Vite env is fixed at build time, so it can't be set at start like Nuxt's runtime config.
- **CLAUDE.md** holds the conventions for what's being built. The sections for later features (AI, billing, audit, admin) come over from saas-starter with their plan, so CLAUDE.md never describes code that doesn't exist.
- **Learning mode:** each plan lists the React concepts it introduces. The user can take over any piece, and Claude reviews it.
- **MCP:** `playwright` (pinned 0.0.83, as in saas-starter), `shadcn` (pinned 4.21.4). The Nuxt docs servers were dropped.
- **CI** waits for plan 04, when there's something to run.

## What came over
| From saas-starter | Here |
|---|---|
| `CLAUDE.md` | Rewritten frontend conventions, a Tasks section, learning mode, and a pointer to the later sections |
| skills `plan-task`, `ship-plan` | Same, plus a "React concepts" line in the plan template |
| skill `verify` | Adds Vitest; skips a side that doesn't exist yet |
| skill `tenant-feature` | Tasks as reference; web steps use query hooks, routes, shadcn |
| skill `e2e-smoke` (+ `servers.sh`, `mail.sh`) | Ports :8112/:5112, `vite preview`, `react_saas` |
| 7 agents | `code-reviewer` (React checklist), `security-auditor` (`dangerouslySetInnerHTML`, `localStorage`, `VITE_*`), the browser agents use the new ports, and the backend agents use Tasks |
| 3 hooks, `settings.json` | Unchanged, apart from the `mcp__shadcn` and `npm test` allows |

## Verification
- Hook pipe tests: `block-destructive-db.sh` blocks `migrate:fresh` and allows `php artisan test`; `only-api-tests.sh` blocks `web/src/x.ts` and allows `api/tests/Feature/X.php`; `flow-tester-servers.sh` blocks `servers.sh stop` and allows `servers.sh status`.
- `verify.sh` with no `api/` or `web/` skips both and passes.
- A grep for leftover saas-starter terms (nuxt, pinia, .vue, old ports, `saas_starter`, project) finds only the deliberate ones.
- In a new session in this folder: `/skills` and `/agents` list everything, and `claude mcp list` shows playwright and shadcn.

Flows: none (no app yet).

## Commits after approval
`chore(claude): add Claude Code tooling, plans and docs`, then this plan → Done.
