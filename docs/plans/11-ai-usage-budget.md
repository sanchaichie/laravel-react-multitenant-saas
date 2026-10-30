# 11 — AI usage, budget and `ai.use`

**Status:** Done
**Commits:** `813b509` (api), `2d2ef6a` (web)

## Goal
Put cost controls on the AI layer from plan 10:
- an `ai.use` permission, so an organization decides which roles may ask for AI work;
- a monthly AI budget per organization, from a `.env` default the owner can lower, raise or set to 0 (AI off). Calls are refused once it's spent;
- an **AI usage** page showing one month of `ai_usage`: totals, by feature, by person and by day, plus the budget bar and its editor.

This rebuilds saas-starter plan 13 without its billing parts.

**Out of scope:**
- plan ceilings and bought AI credits (plan 16, billing);
- counting calls the budget refused (they never reach `ai_usage`);
- a global or per-user cap across organizations;
- charts (tables only, so no chart library).

## Decisions
- **Three new permissions**, as in saas-starter:
  - `ai.use`: request summaries now, and later writing help and chat.
  - `ai_usage.view`: open the usage page.
  - `ai_budget.manage`: change the budget. No default role gets it, so only Owner has it.

  Defaults (`DefaultRole`): Admin gets `ai.use` and `ai_usage.view`; Member gets `ai.use`.
- **Existing roles are granted by permission, never by name**, like the audit-log migration. Any non-owner role with `tasks.create` gets `ai.use`, so nobody loses what they can do today. Any non-owner role with `members.manage` gets `ai_usage.view`. Running it again changes nothing. (saas-starter matched role names; CLAUDE.md forbids that here.)
- **Budget = the organization's own value or the default.** A new column `organizations.ai_monthly_budget_usd` (`decimal(10,2)`, nullable):
  - `null` uses `config('ai.monthly_budget_usd')`, from `AI_MONTHLY_BUDGET_USD=5`. A blank value means the default, so an empty key doesn't turn AI off by accident.
  - `0` turns AI off.
  - Allowed range: 0–10,000.
- **Spend** is the sum of `ai_usage.cost_usd` since the start of the UTC month. It uses the existing `(organization_id, created_at)` index.
- **Calls running at the same time can go slightly over the budget.** This is accepted and documented; saas-starter made the same call. A lock per call isn't worth it at these amounts.
- **The budget is checked where it matters:**
  - **`BudgetedAiClient`** wraps the recording client: `Budgeted(Recording(provider))`. Any call over budget throws `AiBudgetExceeded` before reaching the provider. It extends `AiRequestFailed` with code `budget_exceeded`, so it is never recorded.
  - **`RequestTaskSummary`** checks only when a *new* summary would start (an existing pending one is still returned), so nothing is queued that can't run. In a request this is **402** `{message, code: 'ai_budget_exceeded'}`.
  - **Automatic summaries on task create** skip quietly.
  - **The backfill** skips organizations that are over budget, and stops queueing once refused.
  - **A job that runs after the budget is spent** marks its summary failed: "This organization's monthly AI budget is used up."
- **Requesting a summary needs `ai.use`** as well as edit access to the task, in `TaskSummaryPolicy::create` and so in `can.regenerate`. Reviewing still follows task edit access: approving costs nothing. The automatic summary on create, and the backfill, need the creator's role to have `ai.use`.
- **Budget changes are audited:** new `AuditAction::AiBudgetUpdated`, recording old and new values (`null` = default).
- **Controllers get `CurrentOrganization` per action, not in the constructor.** saas-starter found that the router reuses controller instances: a test run, or Octane, then carries one request's organization into the next. `MemberController` has that pattern today and gets fixed with a regression test. The new controller follows the per-action style.
- **No new dependency.** The budget bar is shadcn `progress`, added with `npx shadcn add progress` (it uses the `radix-ui` package that's already installed).

## Backend (`api/`)
1. **Config and env:** `config/ai.php` gets `monthly_budget_usd` (blank → 5). `.env.example` gets `AI_MONTHLY_BUDGET_USD=5`, with a comment: per organization per month, organizations can change theirs, 0 turns AI off.
2. **Migrations (new):**
   - `add_ai_monthly_budget_to_organizations`: `ai_monthly_budget_usd decimal(10,2) null`.
   - `grant_ai_permissions`: the jsonb grants described in Decisions; `down()` removes them.
3. **Permissions:** `Permission::AiUse` (`ai.use`, group "AI", "Ask for AI summaries and help"), `AiUsageView` (`ai_usage.view`, "View AI usage and the budget") and `AiBudgetManage` (`ai_budget.manage`, "Change the monthly AI budget"). Default roles are updated in `DefaultRole::permissions()`.
4. **`App\Ai\AiBudget`:**
   - `limit(Organization)`, `spent(Organization, ?month)` (explicit `organization_id`, so it works for any organization), `remaining()`, `exceeded(?Organization)` (default: the current one), `ensureAvailable()`;
   - `summary(Organization)`: `{limit_usd, is_default, own_limit_usd, default_limit_usd, spent_usd, remaining_usd}`.
5. **`Ai/Exceptions/AiBudgetExceeded`** (extends `AiRequestFailed`, renders as 402) and **`Ai/Clients/BudgetedAiClient`**, bound in `AppServiceProvider`.
6. **Summaries:**
   - `RequestTaskSummary` calls `ensureAvailable()` before creating a new pending summary.
   - `QueueTaskSummary` and `SummarizeTasks` check the creator's `ai.use` and the budget.
   - `TaskSummaryResource` maps `budget_exceeded` to its message.
   - `TaskSummaryPolicy::create` also needs `ai.use`.
7. **Usage report:** `App\Actions\Ai\AiUsageReport` (ported):
   - for one month: `totals`, `by_feature`, `by_user` (`{id, name}`, or `null` for work started without a person) and `by_day`;
   - each with calls, failed, input/output tokens and cost;
   - plus `budget` (always the current month) and `can.manage_budget`.
8. **Budget update:** action `UpdateAiBudget` locks the organization row, saves, and records the audit entry. Form request `UpdateAiBudgetRequest`: `monthly_budget_usd` present, nullable, numeric, 0–10000, at most 2 decimals.
9. **Policy** `AiUsagePolicy`:

   | Action | Owner | Admin | Member | Custom role |
   |---|---|---|---|---|
   | Ask for a summary (`ai.use` + task edit) | ✓ | ✓ | own / assigned tasks | if granted |
   | View usage (`ai_usage.view`) | ✓ | ✓ | ✗ | if granted |
   | Change budget (`ai_budget.manage`) | ✓ | ✗ | ✗ | if granted (only Owner can grant it: you grant only what you have) |

10. **Routes** (`organization` group):
    - `GET /api/ai-usage?month=YYYY-MM`: `ListAiUsageRequest`; the month is optional (default: this month) and no later than this month.
    - `PUT /api/ai-budget`: `throttle:20,1,ai-budget`. Returns the budget summary.

    Controller: `AiUsageController`, thin.
11. **Organization:** `ai_monthly_budget_usd` is cast to `decimal:2`, is never fillable, and isn't exposed in `OrganizationResource`.

## Frontend (`web/`)
12. **Types:** the three permissions are added to `Permission`. New `AiUsageReport`, `AiUsageRow` and `AiBudget` types.
13. **`features/aiUsage/api.ts`:**
    - `useAiUsage(month)`, key `['org', id, 'ai-usage', month]`, with `placeholderData` from the same organization while another month loads;
    - `useUpdateAiBudget()`: writes the returned budget into every cached month of the report with `setQueriesData`, and invalidates them.
14. **`routes/AiUsagePage.tsx` (`/ai-usage`):**
    - **Month picker:** a native `<input type="month">`. The month lives in the URL (`?month=`): unknown values are dropped, and an empty field means this month.
    - **Budget card:** a progress bar labelled "$1.20 of $5.00 spent this month", "Default" or "Custom", and an **Edit budget** button (if `can.manage_budget`).
    - **Stat cards:** calls, failed, tokens in/out, cost.
    - **Tables:** by feature (labels: `task_summary` → "Task summaries"), by person and by day.
    - **States:** loading, empty ("No AI calls in October 2026") and error.
15. **`features/aiUsage/BudgetDialog.tsx`:** react-hook-form + zod, with a "Use the default ($5.00)" checkbox and an amount input (0 = AI off, with a hint). 422s are mapped onto the fields.
16. **Sidebar:** an "AI usage" entry with `permission: 'ai_usage.view'`.
17. **Task summary card:**
    - The Generate and Regenerate buttons already follow `can.regenerate`, which now includes `ai.use`.
    - A 402 shows a toast: "This organization's monthly AI budget is used up."
    - A summary that failed with `budget_exceeded` shows the API's message.
18. **Formatting:** `lib/format.ts` gets `formatUsd` (a fixed `en-US` locale, 2 decimals, or 4 for amounts under a cent) and `formatMonth` ("October 2026", UTC).

React concepts:
- A controlled dialog (`open` / `onOpenChange` in the page's `useState`) ↔ `v-model:open` on a modal.
- `queryClient.setQueriesData` (plural) with a key prefix ↔ updating every cached copy in a Pinia store at once.
- Values derived during render (percent spent, labels) instead of state ↔ `computed`.
- A check across two fields with zod `superRefine` (the amount only matters when "use the default" is off) ↔ a custom validator reading both refs.
- `useWatch` to re-render on one form field ↔ `watch()` on a ref.

## Tests
- **`AiBudgetTest`:**
  - default, override, `0` = off, and a blank env value;
  - spend sums only this UTC month (across a month boundary) and only this organization;
  - over budget, a request gets 402 and no row or call is created (`assertNothingSent`);
  - an existing pending summary is still returned when over budget;
  - a job run over budget marks the summary failed with the budget message;
  - task creation still returns 201 with no summary;
  - the backfill skips an organization that's over budget.
- **`AiPermissionsTest`:** the table above, as a dataset including a custom role:
  - a role without `ai.use` gets 403 on POST summary, and `can.regenerate` is false;
  - automatic summaries skip a creator without `ai.use`;
  - review still works with only edit access.
- **`AiUsageReportTest`:** totals, breakdowns, a deleted user shown as `null`, month validation (format, future month → 422), and the default month.
- **`AiBudgetUpdateTest`:**
  - set, clear back to default, 0, out of range and too many decimals → 422;
  - audit entry (old/new); 403 without `ai_budget.manage`, including for Admin; throttle.
- **Isolation (org A vs org B):** A's report never includes B's rows; B's spend doesn't count toward A's budget; `PUT` only changes the current organization (B's column unchanged).
- **Controller reuse regression:** two requests in one test as users of different organizations hit the same `MemberController` and `AiUsageController` routes. Each sees only its own organization.
- **Grant migration:** roles with `tasks.create` get `ai.use`, roles with `members.manage` get `ai_usage.view`, Owner is untouched, and running it twice is idempotent.
- **Vitest:**
  - `AiUsagePage`: the report renders; the month comes from the URL (an invalid one is dropped); empty and error states; the budget dialog saves (default, custom, 0) and maps a 422; no Edit button without `can.manage_budget`.
  - The sidebar entry appears only with `ai_usage.view`.
  - The summary card shows the 402 toast.

## README screenshots
- New `docs/screenshots/ai-usage.png`: the AI usage page with the budget bar partly spent and a few rows in each table. Demo data comes from fake AI calls in an e2e organization, 1280×800, light mode.
- The task summary shot (plan 10) doesn't change.

## Verification
- `/verify`.
- `/e2e-smoke` with `--mail` (fake AI, sync queue).
- Reviewers in parallel:
  - code;
  - security: budget bypass, permission escalation through role editing, 402 handling;
  - tenant isolation;
  - database: two migrations, the spend query;
  - UI: the usage page and the dialog.
- UI review and flow-tester run **one after the other** (plan 10 follow-up: they share a browser).

Flows (for `flow-tester`, servers with `--mail`):
1. The owner (verified) creates two tasks with descriptions and opens **AI usage** → 2 calls under "Task summaries", their own name under people, today's date under days, and the budget bar "of $5.00 spent", marked Default.
2. **Edit budget** → set $0 → saved; the bar says AI is off. On a task, **Regenerate** → toast "This organization's monthly AI budget is used up."; no new summary.
3. **Edit budget** → "Use the default" → back to $5.00; Regenerate works again.
4. In the audit log, "Changed the AI budget" appears with $5.00 → $0.00 and back.
5. A Member (invited, verified) doesn't see **AI usage** in the sidebar, and `/ai-usage` shows "You don't have access" (403). On their own task, Regenerate is shown and works.
6. As the owner, on the Roles page, remove `ai.use` from Member. The Member reloads their task → no Generate/Regenerate button.
7. A user of another organization opens `/ai-usage` → their own empty month, never the first organization's calls.

## Changes during the build
- The amount input is a string checked with `superRefine` instead of a zod `preprocess`: an empty field must be allowed while the default is checked, and the 2-decimal check needs the text as typed.
- The month picker has no `max` from the browser's clock (lint flags `Date` in render, and the app never compares dates): the API answers 422 for a future month.
- `AiUsageReport` returns `can: {manage_budget}` next to `budget`, not inside it.
- From the reviews:
  - `ai.use` also goes to roles with only `tasks.manage` (they could ask for summaries before).
  - `PUT /api/ai-budget` needs a verified email.
  - `BudgetedAiClient` refuses a call with no current organization (`NoCurrentOrganization`), since nobody would be billed.
  - `AiBudgetExceeded` isn't logged as an error.
  - The QueueTaskSummary role lookup runs inside `rescue()`.
  - New tests: `AiIsolationTest`, for one person in two organizations.
- From the UI review: the budget dialog's Edit button is now its `DialogTrigger` (focus returns to it on close), and `components/ui/progress.tsx` passes `value` to the Radix root (shadcn's version dropped it, so screen readers got no value). The audit list lower-cases only the first letter of an action ("changed the AI budget").
- The screenshot's demo data: real fake-provider calls from creating tasks, plus a few earlier-dated `ai_usage` rows priced as Sonnet 5.5, so the bar is visibly part-spent (fake calls cost $0.000035 each).
- Existing tests that built custom roles from `tasks.create` alone now add `ai.use`: Member has it by default, so a role without it no longer includes Member.

## Follow-ups
- **The budget limits what an organization spends, not what the operator pays** (security review): any verified owner can raise their organization's budget to $10,000, and anyone can create organizations. Until billing (plan 16) sets ceilings, consider an `AI_MAX_MONTHLY_BUDGET_USD` cap (the `max:` rule and `AiBudget::limit()`) or a limit on organizations per user.
- `ai.use` isn't checked again when the job runs: a summary queued just before someone lost it still runs.
- The month's spend is summed on every check (a few per summary). Fine at small budgets; if budgets grow, a covering index `(organization_id, created_at) INCLUDE (cost_usd)` or a running monthly total.
- A database `CHECK (ai_monthly_budget_usd >= 0)`; today only `UpdateAiBudget` writes the column, behind `min:0`.
- The backfill can queue more summaries than the budget pays for (pending ones cost nothing yet); the extra jobs fail with the budget message.

## Commits after approval
`feat(api): add AI budget, usage report and ai.use permission`, `feat(web): add AI usage page and budget editor`, docs (CLAUDE.md AI section: budget and permissions; README: features, `AI_MONTHLY_BUDGET_USD`, endpoints, screenshot), then this plan → Done.
