# Plans

One file per task: `NN-task-name.md`, numbered in order. Each starts with a **Status**:

- **Proposed**: written, waiting for approval
- **WIP**: approved, being built
- **Done**: built, tests passing, committed (commit hashes listed in the plan)

Rows marked **Not planned yet** are the roadmap; `/plan-task` writes their file when we get there. The last column is the saas-starter plan each one rebuilds.

| # | Plan | Status | saas-starter |
|---|---|---|---|
| 01 | [Claude Code tooling](01-claude-code-tooling.md) | Done | 06 |
| 02 | [API foundation (Laravel, Sanctum, Pest, CORS)](02-api-foundation.md) | Done | 01 |
| 03 | [React app with token auth (Vite, Router, Query, Zustand, shadcn, Vitest)](03-react-app-token-auth.md) | Done | 02 |
| 04 | [CI on GitHub Actions](04-ci-github-actions.md) | Done | 28 |
| 05 | [Organizations, switcher and sidebar layout](05-organizations-switcher-sidebar.md) | Done | 03, 12, 21 |
| 06 | [Members and invitations](06-members-invitations.md) | Done | 04, 22 |
| 07 | [Tasks (status, assignee, due date, priority, filters)](07-tasks.md) | Done | 05, 07 |
| 08 | [Custom roles and permissions](08-custom-roles.md) | Done | 10 |
| 09 | [Audit trail](09-audit-trail.md) | Done | 11 |
| 10 | [AI layer and task summaries](10-ai-layer-task-summaries.md) | Done | 09, 14 |
| 11 | [AI usage, budget and `ai.use`](11-ai-usage-budget.md) | Done | 13 |
| 12 | [Streaming and AI writing help](12-ai-streaming-writing-help.md) | Done | 15 |
| 13 | [Task chat](13-task-chat.md) | Done | 16 |
| 14 | [Account basics](14-account-basics.md) | Done | 22 |
| 15 | [Two-factor authentication](15-two-factor-authentication.md) | Done | 24 |
| 16 | Stripe billing and invoices | Not planned yet | 18, 19, 20 |
| 17 | Platform admin and view as user | Not planned yet | 25, 26, 27 |
