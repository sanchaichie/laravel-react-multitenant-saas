# 07 — Tasks

**Status:** Done
**Commits:** `a400061` (api), `24dcd82` (web)

## Goal
Tasks, the app's reference tenant-owned feature: everyone in the current organization can create, browse, filter, edit and delete tasks, within the permissions below. A task has a title, description, status, priority, due date and an assignee who must be a member of the same organization. The list filters by status, priority, assignee, overdue and search, sorts, and paginates, with every filter in the URL. It rebuilds saas-starter plan 05 (Projects) with the fields CLAUDE.md lists for Tasks, plus plan 07's UI lessons (search in the URL, page past the end, focus return, 404 page title).

Out of scope:
- comments, attachments, subtasks, labels, multiple assignees;
- archiving or soft deletes;
- bulk actions (select many, change status);
- a board (kanban) view;
- notifications when you're assigned (it reaches other people: a later plan, with `verified`);
- audit entries (plan 09) and AI summaries (plan 10). `TaskCreated` is dispatched now so they can hook in.

## Decisions
- **Permissions.** A new `Permission::TasksManage` (`tasks.manage`), granted to Owner and Admin. Plan 08's custom roles can then hand it out. The policy checks the permission plus who created the task or is assigned to it, never role names.

  | Action | Owner | Admin | Member |
  |---|---|---|---|
  | See tasks | ✓ | ✓ | ✓ |
  | Create, assign to any member | ✓ | ✓ | ✓ |
  | Edit (every field, status included) | ✓ | ✓ | tasks they created or are assigned to |
  | Delete | ✓ | ✓ | tasks they created |

  Members get more than in saas-starter's Projects (edit own, never delete) because a task's assignee has to be able to move it to Done, and deleting your own task is ordinary.
- **The assignee must be a member of the current organization,** checked by the form request (`Rule::exists('organization_user', 'user_id')->where('organization_id', current id)`). The actions check it again under a `FOR SHARE` lock on that membership row, so a member removed at the same moment can't be assigned: `RemoveMember`'s delete waits for it, and then unassigns.
- **Leaving or being removed unassigns.** `RemoveMember` sets `assignee_id = null` on the organization's tasks assigned to that person, in its transaction (through `CurrentOrganization::run()`, a query-builder update with server-chosen values only). The FK is `nullOnDelete` too, for deleted accounts. `created_by` stays (the creator's name shows as long as the account exists); it's null on delete.
- **Overdue** = `due_date` before today and status not `done`. "Today" is the API's date (`app.timezone`, UTC), so the filter and the red label agree. The resource returns `is_overdue`; the app never compares dates itself.
- **List API:** `GET /api/tasks?status=&priority=&assignee=&overdue=1&search=&sort=&page=`.
  - `status` and `priority`: one value each.
  - `assignee`: a user id, `me`, or `none` (unassigned). An id that isn't a member simply matches nothing.
  - `search`: title and description, `ILIKE` with `%` and `_` escaped, max 100 characters (saas-starter follow-up).
  - `sort`: `newest` (default), `due` (soonest first, no date last), `priority` (high first). Ties break on id, so pages are stable.
  - 20 per page, `withQueryString()`. Unknown values → 422 (`ListTasksRequest`), so a typo in the URL is visible instead of silently ignored. The app drops unknown values before sending.
- **Dates:** `due_date` is a plain date (`Y-m-d`), sent and returned as a string, no time zone. `formatDate` already renders it right (it parses a date-only string as UTC and formats in UTC). Validation: `nullable|date_format:Y-m-d`; a past date is allowed (you may record a late task).
- **Date input: the native `<input type="date">`**, styled like shadcn's `Input`. The shadcn Date Picker needs `react-day-picker` and `date-fns`, two new dependencies, for little gain here. **If you'd rather have the shadcn picker, say so and I'll add it (needs your OK).**
- **Pages:** `/tasks` (list, create in a dialog) and `/tasks/:id` (details, edit in a dialog, delete with `ConfirmDialog`, a status select when `can.update`). A "Tasks" entry in the sidebar after Dashboard.
- **Filters live in the URL** (`useSearchParams`). Changing a filter resets to page 1 and uses `replace`, so Back isn't flooded; typing in search is debounced (300 ms). A page past the end replaces the URL with the last page. Switching organization clears the filters (an assignee id means nothing in the other one) and, on a detail page, goes back to `/tasks`.
- **Route files** stay flat and PascalCase like the existing ones (`routes/TasksPage.tsx`, `routes/TaskDetailPage.tsx`). The `tenant-feature` skill names `routes/tasks/index.tsx` and `detail.tsx`; I'll update it to the real paths in the same commit.
- **shadcn components:** `textarea`, `checkbox` (overdue filter), `pagination`. Generated files using the installed `radix-ui` and `lucide-react`. **No new packages.**
- **Rate limit:** writes `throttle:120,1,tasks` per user. Reads aren't limited beyond the default.

## Backend (`api/`)
- **Enums:**
  - `app/Enums/TaskStatus.php` (`todo`, `in_progress`, `done`, with `label()`), `app/Enums/TaskPriority.php` (`low`, `medium`, `high`, with `label()` and `rank()` for sorting).
  - `Permission::TasksManage`; `OrganizationRole::grants()` gives it to Admin (Owner gets all cases already). The web `Permission` type gains it.
- **Migration** `create_tasks_table`:
  - id; `organization_id` FK cascade; `title` string(255); `description` text nullable;
  - `status` string default `todo`; `priority` string default `medium`; `due_date` date nullable;
  - `assignee_id` FK users nullable `nullOnDelete`; `created_by` FK users nullable `nullOnDelete`; timestamps.
  - Indexes: `[organization_id, status]`, `[organization_id, assignee_id]`, `[organization_id, due_date]`, `[organization_id, created_at]`, `[created_by]`.
- **Model** `app/Models/Task.php`: `BelongsToOrganization`, `HasFactory`, `#[Fillable(['title', 'description', 'status', 'priority', 'due_date', 'assignee_id'])]` (never `organization_id` or `created_by`), casts (enums, `due_date` as `date:Y-m-d`), `assignee()`, `creator()`, `isOverdue()`, and query scopes for the filters (`filter(array $filters, User $me)`, `search()`, `sorted()`). `database/factories/TaskFactory.php` with `overdue()`, `done()` and `assignedTo($user)` states.
- **Policy** `app/Policies/TaskPolicy.php`: `viewAny`, `view`, `create` (any member), `update` (`tasks.manage`, creator or assignee), `delete` (`tasks.manage` or creator). Reads `CurrentOrganization`, never `organization_id`.
- **Actions** in `app/Actions/Tasks/`: `CreateTask` (sets `created_by`, dispatches `TaskCreated`), `UpdateTask`. Both run in a transaction and lock the assignee's membership row (`Concerns/LocksAssignee`). Delete is a plain `$task->delete()` in the controller.
- **Event** `app/Events/TaskCreated.php` (task id and organization id, plain values). No listeners.
- **`RemoveMember`:** unassigns that member's tasks in the organization, inside its transaction.
- **Form requests** in `app/Http/Requests/Tasks/`:
  - `StoreTaskRequest`: title required max 255; description nullable max 5000; status and priority `Rule::enum`, optional (defaults); `due_date` nullable `date_format:Y-m-d`; `assignee_id` nullable integer, a member of the current organization.
  - `UpdateTaskRequest`: the same rules with `sometimes` (PATCH).
  - `ListTasksRequest`: the filters above.
- **Resource** `app/Http/Resources/TaskResource.php`: id, title, description, status, priority, due_date, `is_overdue`, `assignee {id, name}|null`, `creator {id, name}|null`, created_at, updated_at, `can: {update, delete}`.
- **Controller** `app/Http/Controllers/Api/TaskController.php`: index (eager loads `assignee`, `creator`, paginates), store (201), show, update, destroy (204). Thin: `Gate::authorize`, the action, the resource.
- **Routes** inside the `organization` group: `Route::apiResource('tasks', TaskController::class)->whereNumber('task')`, with the 18-digit limit like the other ids and `throttle:120,1,tasks` on writes. `{task}` binds through the scope, so another organization's task is a 404.
- **Seeder:** a few demo tasks in the local seeder, if one seeds organizations (it'll help the screenshots).

## Frontend (`web/`)
- **Types** (`types/api.ts`): `TaskStatus`, `TaskPriority`, `Task`, `TaskPayload`, `TaskFilters`, `Paginated<T>` (Laravel's `data`, `links`, `meta`), and `tasks.manage` in `Permission`.
- **`features/tasks/api.ts`:**
  - `tasksQuery(orgId, filters)` (`['org', id, 'tasks', 'list', filters]`) with `placeholderData: keepPreviousData`, so the table keeps its rows while the next page loads;
  - `taskQuery(orgId, id)` (`['org', id, 'tasks', 'detail', id]`);
  - `useTasks(filters)`, `useTask(id)`, `useCreateTask`, `useUpdateTask` (writes the result into the detail query, invalidates the lists), `useDeleteTask` (removes the detail, invalidates the lists).
- **`features/tasks/` components and hooks:**
  - `useTaskFilters.ts`: reads the filters from `useSearchParams` (dropping invalid values), and `setFilters(patch)` that resets the page and replaces the URL;
  - `TaskFilters.tsx`: search (debounced), status, priority and assignee selects (assignee options from `membersQuery`, plus "Me" and "Unassigned"), an Overdue checkbox, sort, and "Clear filters";
  - `TasksTable.tsx`: title (link), status, priority, assignee, due date (red "Overdue" when `is_overdue`); below `sm`, assignee and due date fold under the title;
  - `TaskForm.tsx`: react-hook-form + zod, used for create and edit; status, priority and assignee through `Controller`; native date input; 422s onto fields with `applyServerErrors`;
  - `TaskFormDialog.tsx` (create and edit), `TaskStatusBadge.tsx`, `TaskPriorityBadge.tsx`;
  - `hooks/useDebouncedValue.ts` (shared).
- **Routes:** `routes/TasksPage.tsx` (`/tasks`) and `routes/TaskDetailPage.tsx` (`/tasks/:id`, a non-numeric id shows "Task not found" without a request), in `AppLayout`; `<title>`s ("Tasks", the task's title, "Task not found").
  - States: loading (skeleton rows), empty (no tasks yet, with New task), no matches (with Clear filters), error (`ErrorState` with retry), not found.
  - Without a current organization, both pages say to choose one (like Members).
- **Sidebar:** "Tasks" (`ListTodoIcon`) after Dashboard. **Dashboard:** "Tasks come next" becomes a link to your open tasks (`/tasks?assignee=me`).

React concepts (each compared with Vue/Nuxt):
- **The URL as state** (`useSearchParams`, `setSearchParams(…, { replace: true })`) ↔ `useRoute().query` with `router.replace`. No copy of the filters in `useState`: they're read from the URL every render.
- **Derived values instead of synced state** (parse the filters during render, no effect copying them) ↔ `computed`.
- **A custom hook with an effect cleanup** (`useDebouncedValue`: a timer set in `useEffect`, cleared in its cleanup) ↔ `watchDebounced`, or `watch` with `onCleanup`.
- **`placeholderData: keepPreviousData`** (old rows stay while the new page loads, with `isPlaceholderData` to dim them) ↔ `useAsyncData` with a watched key, which also keeps the old `data` while refreshing.
- **Writing to the cache after a mutation** (`setQueryData` on the detail, `invalidateQueries` on the lists) ↔ updating a `ref` by hand, then `refresh()`.
- **One form for create and edit,** reset by a `key` when the task changes ↔ the same `:key` trick in Vue; `defaultValues` ↔ the initial `reactive` state.
- **Redirect during render with `<Navigate>`** (the detail page sees the organization change and renders `<Navigate to="/tasks" replace />`) ↔ `navigateTo()` in a `watch`.
- **Uncontrolled native inputs inside react-hook-form** (the date input is `register`ed; only Radix selects need `Controller`) ↔ `v-model` on both.

## Tests
Pest (`api/tests/Feature/Tasks/`):
- `TaskCrudTest.php`:
  - create (defaults, `created_by`, `TaskCreated` with `Event::fake`), show, update, delete;
  - `organization_id` and `created_by` in the payload are ignored;
  - validation: title, lengths, enums, the date format, an assignee from another organization or a non-member → 422;
  - 401 without login, 403 without a current organization.
- `TaskFiltersTest.php`: each filter alone and combined (status, priority, assignee id / `me` / `none`, overdue excludes done and today, search on title and description with `%` and `_` escaped), each sort with stable ties, pagination with the query string kept, 422 on unknown values and a too-long search.
- `TaskPermissionsTest.php`: the table above as datasets (member edits own / assigned, can't edit others', deletes own only; admin and owner do all); `can` flags match.
- `TaskIsolationTest.php`: org A's user doesn't see B's tasks in the list, filters or search; gets 404 on show, update and delete of B's task, with B's row unchanged; can't assign one of B's members; and the same holds for a user in both organizations who hasn't switched.
- In `Members/ManageMembersTest.php`: removing or leaving unassigns that member's tasks in that organization only, not their tasks in another.
- Mutation checks: the policy allowing everything; `BelongsToOrganization` removed from `Task`; the assignee rule unscoped; each must fail tests, then be restored.

Vitest:
- `routes/TasksPage.test.tsx`: loading, empty, no matches, error with retry; the rows; filters write the URL and reset the page; search waits for the debounce; a URL with filters loads them; pagination; page past the end; create in the dialog (and a 422 on title); New task always shown; the 409 `organization_changed` path; switching organization clears the filters.
- `routes/TaskDetailPage.test.tsx`: details; not found (404 and a non-numeric id); Edit/Delete per `can`; edit; status change; delete with confirm → back to the list with a toast; switching organization → `/tasks`.
- `features/tasks/TaskForm.test.tsx`: validation, the assignee options from members, a date round-trip, 422 mapping.
- `hooks/useDebouncedValue.test.ts` with fake timers.

## README screenshots
Taken as in plan 03 (Playwright MCP, light mode, 1280×800, `e2e-*` data cleaned up):
- `tasks.png`: the list with a few tasks in each status, one overdue, a filter applied;
- `task-detail.png`: a task's page as its assignee (status select, Edit);
- retake `home.png`: the dashboard copy changes.

## Verification
- `/verify`; CI.
- **`e2e-smoke`** (curl): owner and member in org A, owner in org B. CRUD per role, filters, member assigned → can move to done, member can't delete others', org B gets 404 on A's task and an empty list, assigning B's member → 422, removing the member unassigns.
- **Reviewers in parallel:** code, security (search escaping, filter input), tenant isolation (the first real tenant feature: assignee rule, the unassign query), database (indexes, the `FOR SHARE` lock with `RemoveMember`'s locks, N+1 on the list), UI (quick: the list at 390px, keyboard through the filters and dialog, focus return after the dialog and after delete, empty/no-match/not-found states, dark mode, console).

Flows (for `flow-tester`, `e2e-ft-*` accounts; no emails, so no `--mail` needed apart from inviting the member, which uses it):
1. Register Owner, verify the email, invite `e2e-ft-mia` as Member; Mia registers from the link and joins.
2. As the owner, open Tasks → the empty state with "New task".
3. Create "Write onboarding doc" (high, due yesterday, assigned to Mia) and "Plan launch" (low, no date, unassigned) → both listed; the first shows "Overdue".
4. Filter Overdue → only "Write onboarding doc"; reload → the filter is still on; Clear filters → both.
5. Search "launch" → only "Plan launch"; the URL has `?search=launch`. (Filter changes replace the history entry, so Back leaves the page rather than stepping through filters.)
6. As Mia, Tasks → both tasks; "Assigned to me" (Dashboard link) → only hers. Open it → change status to Done → it's no longer overdue.
7. As Mia, open "Plan launch" → no Edit or Delete. Create her own task → she can edit and delete it.
8. As the owner, delete Mia's done task → confirm → back to the list with a toast.
9. Remove Mia from the organization → her remaining assigned tasks show "Unassigned".
10. Another owner, `e2e-ft-zed`, opens `/tasks/{id}` of the first organization → "Task not found"; curl → 404.
11. Switch organization while on a task page → back to `/tasks`, showing the other organization's tasks.
12. At 390px: the list and filters fit with no horizontal scroll; the create dialog is usable.

## Changed while building
- **`useDebouncedCallback`, not `useDebouncedValue`.** A debounced *value* pushed by an effect could write a stale search back after Clear filters or Back. The callback version drops a pending search whose URL changed since the keystroke (tested).
- **Switching organization goes to the section root** (`/tasks/42?assignee=7` → `/tasks`) in `useEnterOrganization`, for switch and create, instead of each page clearing its own state in an effect (lint flagged the setState-in-effect). `SelectOrganizationPage` opts out (`toSectionRoot: false`): it navigates itself. The detail page keeps its `<Navigate>` guard for the 409 path (another tab switched), which doesn't go through the switcher.
- **The `organization` middleware runs before route model binding** (`bootstrap/app.php`), so a bound `{task}` route answers 403 / 409 instead of the scope's 404.
- **`mockApi` handlers get the URL** (`(init, url)`), so list tests can answer per query string; `test/tasks.ts` holds the task fixtures.
- The task form opens fresh with each dialog (DialogContent unmounts on close), so it needs no `key`.
- No seeder change: the local seeder doesn't create organizations.
- **From review:**
  - placeholder rows are kept only within one organization (`keepPreviousData` showed the previous organization's tasks under the new one's heading while its list loaded);
  - task mutations take the organization from `onMutate`'s context;
  - Edit sends only the fields changed in the form, so it can't revert someone else's change;
  - `assignee_id` and `created_by` cast to int (the policy compares strictly);
  - your own id in `?assignee=` shows as you; an index on `assignee_id` alone (user deletion).

## Outcome
- `/verify`: Pint, 222 Pest tests (826 assertions), oxlint, typecheck, 141 Vitest tests, build.
- **Mutation checks**, each restored afterwards:
  - `TaskPolicy` allowing everything → 4 failures;
  - `BelongsToOrganization` removed from `Task` → 3;
  - the assignee rule and lock unscoped → 6 (the rule alone → 4);
  - no unassign in `RemoveMember` → 1;
  - plain `keepPreviousData` → 1;
  - no stale-search guard → 1.
- **curl** (15 checks): create with an overdue due date, the filters, the assignee marks done, a member can't edit or delete others' tasks, another organization gets an empty list and 404s, assigning its user → 422, a bad filter → 422, removing the member unassigns, a stale tab → 409.
- **`flow-tester`:** all 12 flows passed, no log errors. Flow 5's wording was corrected (see Flows).
- **`ui-reviewer`:** all 7 checks passed (390px, keyboard and focus rings, focus return, empty/no-match/not-found states and titles, the detail page, dark mode, console).
- **Reviews:**
  - **code:** the cross-organization placeholder rows (high), mutation context, Edit overwriting fields, the missing 409-before-binding test and `TaskForm` tests, the debounce race, strict ids. All fixed.
  - **tenant isolation:** no server-side holes; the same placeholder leak (medium) and the 409 test, fixed.
  - **database:** no high or medium; the `assignee_id` index added. Lock ordering with `RemoveMember` checked: no deadlock.
  - **security:** no findings.
- Screenshots: `tasks.png`, `task-detail.png`, and `home.png` retaken.

## Follow-ups
- **Scale:** the priority sort (a `CASE`) and the `ILIKE` search can't use an index, and deep pages use OFFSET. Fine at today's sizes; at 100k+ tasks per organization consider a `priority_rank` column, `pg_trgm` indexes and cursor pagination.
- **Reads aren't rate-limited** (like members and invitations). A per-user read throttle would cap search load.
- **A NUL byte in a search or title** is probably a 500 from Postgres rather than a 422. Consider a rule or middleware that rejects `\0`.
- **Switching from a filtered list** can send one list request to the new organization with the old filters before the URL catches up (the store changes first, to avoid 409s). Harmless and superseded at once.
- `phpunit/phpunit` GHSA-9vw2-f4vp-x7gf is still dev-only (from plan 06).

## Commits after approval
`feat(api): add tasks`, `feat(web): add tasks pages`, then this plan → Done.
