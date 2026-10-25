# Plans

One file per task: `NN-task-name.md`, numbered in order. Each starts with a **Status**:

- **Proposed**: written, waiting for approval
- **WIP**: approved, being built
- **Done**: built, tests passing, committed (commit hashes listed in the plan)

Rows marked **Not planned yet** are the roadmap; `/plan-task` writes their file when we get there. The last column is the saas-starter plan each one rebuilds.

| # | Plan | Status | saas-starter |
|---|---|---|---|
| 01 | [Claude Code tooling](01-claude-code-tooling.md) | Done | 06 |
| 02 | API foundation (Laravel, Sanctum, Pest, CORS) | Not planned yet | 01 |
| 03 | React app with token auth (Vite, Router, Query, Zustand, shadcn, Vitest) | Not planned yet | 02 |
| 04 | CI on GitHub Actions | Not planned yet | 28 |
| 05 | Organizations, switcher and sidebar layout | Not planned yet | 03, 12, 21 |
| 06 | Members and invitations | Not planned yet | 04 |
| 07 | Tasks (status, assignee, due date, priority, filters) | Not planned yet | 05, 07 |
| 08 | Custom roles and permissions | Not planned yet | 10 |
| 09 | Audit trail | Not planned yet | 11 |
| 10 | AI layer and task summaries | Not planned yet | 09, 14 |
| 11 | AI usage, budget and `ai.use` | Not planned yet | 13 |
| 12 | Streaming and AI writing help | Not planned yet | 15 |
| 13 | Task chat | Not planned yet | 16 |
| 14 | Account basics | Not planned yet | 22 |
| 15 | Two-factor authentication | Not planned yet | 24 |
| 16 | Stripe billing and invoices | Not planned yet | 18, 19, 20 |
| 17 | Platform admin and view as user | Not planned yet | 25, 26, 27 |
