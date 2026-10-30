# 10 — AI layer and task summaries

**Status:** Done
**Commits:** `65b969b` (api), `efa40d6` (web), `b52f107` (claude)

## Goal
Add the AI layer (an `AiClient` interface, a Claude provider, a fake for tests, an `ai_usage` record of every call, a per-organization rate limit, an `OrganizationJob` base for queued tenant work) and one feature that uses it end to end: **AI task summaries**. A queued job asks Claude for a short summary and key points as JSON and saves them as a **draft**. Someone who may edit the task approves it (optionally after editing it) or rejects it. Approving only shows the summary on the task page; it never changes the task. This rebuilds saas-starter plan 09 as it finally shipped, plus plan 14's "better summaries" (stale flag, edit before approve, backfill command).

**Out of scope:** the `ai.use` permission, monthly budgets and the usage page (plan 11), streaming and writing help (plan 12), chat (plan 13), other providers (the interface allows them).

## Decisions
- **Laravel HTTP client, no new dependency** (saas-starter's choice). `Http::fake()` covers the provider tests. No composer or npm package is added.
- **Model from config:** `AI_MODEL=claude-haiku-5-5`. Summaries are a small task and this is a learning project; switching is one `.env` change.
- **Structured output** through `output_config.format` (JSON schema) on `POST /v1/messages`, then validated with Laravel's Validator before saving.
- **Refusal fallback off by default** (`AI_FALLBACKS` empty; Haiku doesn't support it). With Opus/Sonnet set `AI_FALLBACKS=default`; the client sends it only for models in `config/ai.php` → `fallback_models`. `ai_usage` records the model that actually answered (`response.model`).
- **Every call is recorded, failures too,** by a `RecordingAiClient` decorator bound in the container, so a feature can't forget. Cost from a price table in `config/ai.php`, stored as `decimal(12,6)`.
- **Timeouts nest:** HTTP 60s < job 75s < queue `retry_after` 90s (current default).
- **Retries:** 429, 5xx, timeouts and connection errors → `AiUnavailable` (retried, 3 exceptions max, backoff 10/60/300s). 4xx, refusals, `max_tokens` cut-offs and invalid JSON → `AiRequestFailed` (fails at once).
- **Rate limit per organization:** one named limiter `ai` with separate keys for UI requests (`request:{org}`), provider calls (`job:{org}`) and automatic summaries on create (`ai-auto:{org}`; over it the task is created without a summary). `AI_RATE_LIMIT_PER_MINUTE=5`.
- **Automatic summary on create** when the description isn't blank, through a listener on the existing `TaskCreated` event (its docblock already anticipates this). Same rule as saas-starter; a task without a description has nothing to summarize.
- **Permissions until plan 11:** reading follows `TaskPolicy::view` (everyone), requesting and reviewing follow `TaskPolicy::update` (manage, or `tasks.create` and creator/assignee). Plan 11 adds `ai.use` to requesting. No new permission now.
- **One row per generation; the page shows the latest plus the approved one.** At most one `pending` and one `approved` per task (partial unique indexes). Requesting again retires an unreviewed draft (→ `rejected`) and replaces a pending one older than 15 minutes (→ `failed`).
- **Stale flag:** the job stores `source_hash` (sha256 of the title + description it sent). The resource says `stale` when the task's text has changed since. Nothing regenerates on its own (cost).
- **Edit before approve:** approve takes optional `summary` and `key_points`, validated by the prompt's own rules. The audit entry gets `edited: true` only when a field really changed.
- **Audit:** new `AuditAction` cases `summary.requested`, `summary.approved`, `summary.rejected` (group Tasks), written through `RecordAudit` inside each action's transaction. No summary text in the metadata.
- **Prompt injection:** the prompt wraps the task's title and description in `<task>` tags; the system prompt says tagged content is data, not instructions. Only this one task's fields go in.
- **Polling, not push:** the card refetches while a summary is pending (every 3s, gives up after 60s with a note).

## Backend (`api/`)
1. **Config** `config/ai.php`: `provider` (`anthropic` | `fake`), `model`, `timeout`, `max_tokens`, `fallbacks`, `fallback_models`, `rate_limit_per_minute`, `providers.anthropic` (`api_key`, `base_url`, `version` `2023-06-01`), `pricing` (USD per 1M: opus-5-5 4/20, sonnet-5-5 2/10, haiku-5-5 0.10/0.50). Unknown model → `cost_usd = null` and a logged warning.
2. **`.env.example`** (commented): `AI_PROVIDER=anthropic`, `ANTHROPIC_API_KEY=`, `AI_MODEL=claude-haiku-5-5`, `AI_TIMEOUT=60`, `AI_FALLBACKS=`, `AI_RATE_LIMIT_PER_MINUTE=5`.
3. **`app/Ai/`**
   - `Contracts/AiClient`: `generate(AiRequest): AiResponse` (`stream()` comes in plan 12).
   - `AiRequest` (readonly: `feature`, `system`, `messages`, `?schema`, `maxTokens`, `?effort`, `?userId`), `AiResponse` (readonly: `text`, `?json`, `model`, `inputTokens`, `outputTokens`, `stopReason`).
   - `Clients/AnthropicClient` (headers, beta header only with fallbacks, `stop_reason` checked before the content, error mapping as above), `Clients/FakeAiClient` (`push()`, `assertSent()`, `assertNothingSent()`, throw on demand), `Clients/RecordingAiClient`.
   - `Exceptions/AiUnavailable`, `Exceptions/AiRequestFailed` (with an `errorCode`).
   - `Prompts/TaskSummaryPrompt`: system text, JSON schema (`summary` ≤ 600 chars, `key_points` 0–5 strings ≤ 160), `rules()` (reused by the approve request), `request(Task, User)`, `sourceHash(Task)`. Sends `effort: low`, `max_tokens: 2000`.
   - `AppServiceProvider`: bind `AiClient` → `RecordingAiClient(provider from config)`; register the `ai` limiter.
4. **Migration `create_ai_usage_table`:** id, organization_id (cascade), user_id (nullable, null on delete, indexed), feature, model, input_tokens, output_tokens, cost_usd (`decimal(12,6)`, nullable), status (`succeeded` | `failed`), error_code (nullable), created_at. Index `(organization_id, created_at)`. **Model `AiUsage`** (`BelongsToOrganization`, write-only for now).
5. **Migration `create_task_summaries_table`:** id, organization_id (cascade), task_id (cascade), status (`pending` | `draft` | `approved` | `rejected` | `failed`), summary (text, nullable), key_points (jsonb, nullable), model, error (nullable), source_hash (char 64, nullable), requested_by / reviewed_by (users, null on delete, indexed), reviewed_at, timestamps. Partial unique indexes on `task_id` where `status = 'approved'` and where `status = 'pending'`. Index `(task_id, id)` for "latest".
   **Model `TaskSummary`** (`BelongsToOrganization`, enum cast `TaskSummaryStatus`, `key_points` → array). `Task::summaries()`, `latestSummary()`, `approvedSummary()`.
6. **`app/Jobs/OrganizationJob`** (abstract): `organizationId`, `?userId`; `tries = 10`, `maxExceptions = 3`, `backoff [10, 60, 300]`, `timeout = 75`, `failOnTimeout`. Middleware `Jobs/Middleware/SetCurrentOrganization` loads the organization (deletes the job if it's gone) and runs the job inside `CurrentOrganization::run()`. An `inOrganization()` helper for `failed()`, which skips middleware.
7. **Actions** (`app/Actions/TaskSummaries/`):
   - `RequestTaskSummary(Task, User, source = 'user')`: locks the task row; returns a fresh pending summary if there is one, else retires a draft / a stale pending, creates `pending`, records `summary.requested`, dispatches the job after commit.
   - `ReviewTaskSummary(TaskSummary, User, approve, ?edits)`: locks the task row, 409 unless `draft`; approving saves edits, demotes the previous approved one to `rejected`, records `summary.approved` (`edited`) or `summary.rejected`.
8. **Job `GenerateTaskSummary extends OrganizationJob`** (+ `RateLimited('ai')`): loads summary and task through the scope (gone → nothing), calls `AiClient`, validates with `TaskSummaryPrompt::rules()`, then locks the row and saves `draft` (+ model, `source_hash`) only if it is still `pending`. `AiRequestFailed`/invalid JSON → `failed` with a generic error and `$this->fail()`; `failed()` covers exhausted retries.
9. **Listener `QueueTaskSummary`** on `TaskCreated`: if the description isn't blank and the `ai-auto` limiter allows, runs `RequestTaskSummary` inside `CurrentOrganization::run()` as the creator. Update the `TaskCreated` docblock.
10. **Command** `php artisan ai:summarize-tasks {--organization=*} {--limit=50} {--dry-run}`: tasks with a description and no summary, whose creator is still a member allowed to edit them; requester = creator, audit `metadata.source = "backfill"`, no actor. Prints counts (queued, skipped).
11. **Policy `TaskSummaryPolicy`** (delegates to `TaskPolicy`):

    | Action | Owner | Admin | Member | Role without `tasks.create` |
    |---|---|---|---|---|
    | View summary | ✓ | ✓ | ✓ | ✓ |
    | Request / regenerate | ✓ | ✓ | tasks they created or are assigned | ✗ |
    | Approve / edit / reject | ✓ | ✓ | tasks they created or are assigned | ✗ |

12. **Requests and resource:** `ApproveTaskSummaryRequest` (`summary`, `key_points` optional, from `TaskSummaryPrompt::rules()`). `TaskSummaryResource`: id, status, summary, key_points, model, error (generic, failed only), stale, requested_by / reviewed_by `{id, name}`, reviewed_at, created_at, `can: {review, regenerate}`.
13. **Routes** (in the `organization` group, `scopeBindings()`, ids constrained like the others):

    | Endpoint | Notes |
    |---|---|
    | `GET /api/tasks/{task}/summary` | `{data: latest|null, approved: approved|null, can: {regenerate}}` |
    | `POST /api/tasks/{task}/summary` | `throttle:ai` (request key). 202 with the pending summary |
    | `POST /api/tasks/{task}/summaries/{summary}/approve` | optional edits; 409 unless draft |
    | `POST /api/tasks/{task}/summaries/{summary}/reject` | 409 unless draft |

    Thin `TaskSummaryController`.
14. **Tests safety net:** `TestCase` binds `FakeAiClient` (as `$this->ai`) and calls `Http::preventStrayRequests()`.

## Frontend (`web/`)
15. **Types** `TaskSummary`, `TaskSummaryStatus`, `TaskSummaryResponse` in `types/api.ts`.
16. **`features/taskSummaries/api.ts`:** `useTaskSummary(taskId)` (key `['org', orgId, 'tasks', taskId, 'summary']`, `refetchInterval` 3s while the latest is pending, for at most 60s), `useRequestTaskSummary`, `useReviewTaskSummary` (approve with edits / reject). Mutations write the response into the cache (`setQueryData`) or invalidate it; a 429 shows a toast ("Too many AI requests for this organization, try again in a minute"); 409/404 refetch.
17. **`features/taskSummaries/TaskSummaryCard.tsx`** on `TaskDetailPage`, below the description. States:
    - none: "No summary yet" + "Generate summary" (if `can.regenerate`);
    - pending: skeleton + "Generating…", then "Still working, check back soon" after 60s;
    - draft: "AI draft" badge, summary, key points, Approve / Edit / Reject (if `can.review`);
    - approved: summary with "Approved by X on <date>", plus Regenerate;
    - rejected / failed: a short message + Regenerate, with the approved summary still shown below if there is one;
    - stale: "The task changed since this summary" + Regenerate.
18. **`features/taskSummaries/TaskSummaryEditor.tsx`:** react-hook-form + zod (same limits as the API), a textarea and a dynamic list of key point inputs (`useFieldArray`, add/remove up to 5). 422s mapped onto fields, list errors onto the group; a failed save keeps the editor open.
19. shadcn components already installed (`Card`, `Badge`, `Button`, `Skeleton`, `Alert`, `Textarea`, `Input`, `Field`); nothing new expected.

React concepts:
- `refetchInterval` as a function of the data ↔ a `setInterval` in `onMounted`/`onUnmounted` around `useAsyncData`'s `refresh()`: the query polls only while pending and stops on unmount by itself.
- `queryClient.setQueryData` ↔ assigning to a Pinia store/`useAsyncData`'s `data` after a mutation: put the server's answer in the cache without a refetch.
- `useFieldArray` ↔ a `v-for` over a `ref([])` of inputs with push/splice: react-hook-form owns the array and gives stable `key`s.
- Lifting state to the card (view/edit mode in `useState`) and passing callbacks down ↔ `emit('saved')` from a child component.
- Conditional rendering by status with an early-return per state ↔ `v-if`/`v-else-if` chains.

## Tests
- **`AnthropicClientTest`** (`Http::fake`): request shape (headers, model, schema, `effort`, `fallbacks` only when configured and supported), parsing text/JSON/usage, `response.model` used, 429/500/timeout → `AiUnavailable`, 400/refusal/`max_tokens` → `AiRequestFailed`.
- **`RecordingAiClientTest`:** success row with tokens and cost; failure row and rethrow; unknown model → null cost; `organization_id` from `CurrentOrganization`.
- **`OrganizationJobTest`:** scoped queries inside the job see only its organization; previous organization restored; deleted organization deletes the job.
- **`TaskSummaryTest`:**
  - create with description → pending → job → draft (model, source hash); blank description → nothing; `ai-auto` cap → task created, no summary;
  - invalid JSON (missing summary, 6 key points) → failed, nothing saved; exhausted retries → failed;
  - regenerate returns the fresh pending one, retires a draft, replaces a stale pending; a late job doesn't overwrite a failed row;
  - approve/reject only on drafts (409); approving replaces the previous approved one; edits saved, validated (422), `edited` audit flag only on real changes; edits ignored on reject;
  - stale on/off, including a task edited between request and job run;
  - the permission table (creator, assignee, other member, viewer role);
  - rate limit: the 6th request in a minute → 429, another organization unaffected;
  - audit entries `summary.requested|approved|rejected`.
- **Isolation (org A vs org B):** org B's task or summary → 404 on get, request, approve and reject, rows unchanged; org B's summary id under org A's task → 404; `ai_usage` rows land in the job's organization; the prompt contains only the target task's text (`assertSent`).
- **`SummarizeTasksTest`:** picks the right tasks, `--limit`, `--dry-run`, skips creators who left or can't edit, never another organization's tasks.
- **Vitest:** `TaskSummaryCard` states (none, pending → draft via polling, draft approve/reject, approved, failed, stale, no buttons without `can`), editor validation and 422 mapping, 429 toast. Add `makeTaskSummary` to `test/tasks.ts`.

## README screenshots
- New `docs/screenshots/task-summary.png`: a task page with an AI draft summary and the Approve / Edit / Reject buttons, from e2e demo data with `AI_PROVIDER=fake`.

## Verification
- `/verify` (Pint, Pest, lint, typecheck, Vitest, build).
- `/e2e-smoke` with `AI_PROVIDER=fake` and a queue worker: create a task with a description → pending → draft → edit and approve; org B gets 404. Optional real-API check with your key afterwards.
- Reviewers in parallel: code, security (key handling, prompt injection, rate-limit bypass), tenant isolation, database (two migrations, locks), UI (task page card).

Flows (for `flow-tester`, servers started with `--mail`: the API uses the fake AI provider and the sync queue, so a summary is a draft by the time the page shows it):
1. Owner creates a task with a description → the task page's "AI summary" card shows an "AI draft" summary with key points, and Approve / Edit / Reject.
2. Click Edit, change the summary, add a key point, Approve → "Approved by <owner>"; the audit log shows "Approved a task summary".
3. Edit the task's description → the card says the task changed since the summary; Regenerate → a new draft, the approved one still visible until reviewed.
4. Reject the new draft → the approved summary stays.
5. A member opens a task they didn't create and aren't assigned → sees the summary, no Generate/Approve buttons.
6. Create a task with no description → "No summary yet" with Generate summary.
7. As a user of org B, open org A's task URL → "Task not found".

## Changes during the build
- **AI needs a verified email** (security review). CLAUDE.md asks it for features that cost money, and saas-starter gates its routes the same way. Concretely:
  - `POST …/summary` is `verified` (403 `email_unverified`), and `can.regenerate` is false without a verified email.
  - Automatic summaries skip unverified creators; the task is still created.
  - The backfill skips them too.
- **Stuck summaries can be replaced from the app** (code review). Before, the card hid Regenerate while pending, so a lost job left it on "Still working" forever. The resource now has `stuck` (pending longer than `STALE_AFTER_MINUTES`) and the card offers Regenerate then.
- **Retry window instead of a try count.** `OrganizationJob` keeps trying until `retryUntil()` (20 minutes; `maxExceptions` 3) instead of `tries = 10`. Each rate-limited release used up a try, so a backfill of 50 summaries could run out of tries. `STALE_AFTER_MINUTES` went up to 30 to stay above that window, and is tested.
- **Retiring a stale pending summary is a guarded update** (`where status = pending`), so a job finishing at that moment keeps its draft (code and database reviews).
- **The listener can't fail task creation.** `QueueTaskSummary` runs under `rescue()`: an error there is reported, and the task is still created (201).
- **Schema guard:** a new migration adds `unique(organization_id, id)` on `tasks` and makes `task_summaries (organization_id, task_id)` a composite foreign key, like roles in plan 08 (tenant and database reviews). It's a separate migration because the first one had already run on the dev database.
- **`ai` limiter:** the throttle runs before the organization middleware. The organization is resolved lazily from the user, and without one the key is per user, not a shared bucket.
- **Backfill** passes the allowed creators as a subquery, not a list of ids.
- **Unknown model pricing:** a dated id is priced as its base model, and an unknown one as the configured model (with a warning), so plan 11's budget never counts a call as free. `null` only when neither is priced.
- **API shape:** `GET …/summary` also returns `can.regenerate`, so the card knows whether to offer Generate when there's no summary yet. The query key is `['org', id, 'tasks', 'detail', taskId, 'summary']`, under the task's key, so deleting the task marks it stale. Editing a task invalidates it (the `stale` flag).
- **e2e:** `servers.sh` always runs the API with `AI_PROVIDER=fake`. Without `--mail`, jobs go to an `e2e` queue so the developer's own worker (maybe with a real key) never runs them (security review). With `--mail` (sync queue) a summary is already a draft when the page opens, so Flow 1 no longer expects "Generating…".
- **Audit log details** show "Edited before approving" and backfill requests.

## Follow-ups (not done)
- **Budget and `ai.use`:** plan 11. Until then the only caps are per organization per minute; many throwaway organizations, even verified ones, multiply them.
- **Real API check:** everything ran against the fake provider and `Http::fake()`. Do one run with a key (`AI_PROVIDER=anthropic`).
- **`composer audit`:** `phpunit/phpunit` GHSA-9vw2-f4vp-x7gf (dev only, local shared temp dirs). Update to 13.4.4+ when convenient.
- **Drafts with links:** the prompt doesn't forbid URLs in the output. The task's author can already write any link, so it's not an escalation; consider a rule if summaries are ever shown more widely.
- **Concurrent browser agents:** `ui-reviewer` and `flow-tester` share one Playwright browser and log each other out (same `localStorage`). Run them one after the other, or have each use its own browser context.

## Commits after approval
`feat(api): add AI client, usage tracking and task summaries`, `feat(web): show, edit and review AI task summaries`, docs (README features/screenshot/run steps with `AI_PROVIDER`, CLAUDE.md "AI layer" section brought over and adapted, `.env.example` in the api commit), then this plan → Done.
