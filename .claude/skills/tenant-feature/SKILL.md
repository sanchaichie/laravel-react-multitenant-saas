---
name: tenant-feature
description: Step-by-step recipe for adding a tenant-owned (organization-scoped) feature across api/ and web/, modeled on Tasks. Use when building any new model or resource that belongs to an organization (e.g. clients, documents, task comments).
argument-hint: "[feature, e.g. clients]"
---

# Add a tenant-owned feature

Feature: $ARGUMENTS

Tasks is the reference implementation (plan 07). Open each file named below and follow its shape rather than inventing a new one. If the change touches more than 3 files (it will), run the `plan-task` skill first and wait for approval.

## API (`api/`)
1. **Migration** (`php artisan make:migration create_<things>_table`). Reference: `database/migrations/*_create_tasks_table.php`.
   - `foreignId('organization_id')->constrained()->cascadeOnDelete()`.
   - Unique constraints are per organization: `unique(['organization_id', 'name'])`. Put `organization_id` first in composite indexes.
   - Users are referenced with `nullable()->constrained('users')->nullOnDelete()`, so a removed member doesn't delete the data.
   - Never edit a migration that has been committed; add a new one.
2. **Model + factory.** Reference: `app/Models/Task.php`, `database/factories/TaskFactory.php`.
   - `use BelongsToOrganization, HasFactory;`. This trait is what isolates organizations: a global scope that fails closed, `organization_id` forced on create, and a LogicException if it changes.
   - `#[Fillable([...])]`. Never make `organization_id` fillable.
   - In tests and seeders, create records inside `inOrganization($org, fn () => ...)`.
   - If users create, edit or delete it, also `use Auditable;` and add `<thing>.created|updated|deleted` cases (with labels) to `App\Enums\AuditAction`. List columns that shouldn't be logged in `$auditExclude`.
3. **Policy.** Reference: `app/Policies/TaskPolicy.php`. Decide by `CurrentOrganization::can()` with an `App\Enums\Permission` case (add one if the feature needs its own, with `label()` and `group()`, and decide which `DefaultRole`s get it: a new migration grants it to existing organizations' roles) and ownership fields. Never check `organization_id` here; the scope already handles that.
4. **Actions.** Reference: `app/Actions/Tasks/`. One class per write operation. It sets server-side fields such as `created_by` and dispatches domain events (`app/Events/TaskCreated.php`).
5. **Form Requests.** Reference: `app/Http/Requests/Tasks/`.
   - Unique rules: `Rule::unique('<table>', 'name')->where('organization_id', app(CurrentOrganization::class)->id())`, plus `->ignore(...)` on update.
   - `exists:` rules pointing at another tenant-owned table, or at a user (e.g. `assignee_id`), must also be scoped to the organization (for users: a member of it). Otherwise a user can link to another organization's record.
6. **Resource.** Reference: `app/Http/Resources/TaskResource.php`. Include `can: {update, delete}` from the policy so the frontend never duplicates the rules. Load relations with `whenLoaded`.
7. **Controller.** Reference: `app/Http/Controllers/Api/TaskController.php`. Keep it thin: `Gate::authorize`, call the action, return the resource. For lists, eager load (`with`), paginate (`paginate(20)->withQueryString()`), and escape `%` and `_` in search.
8. **Route.** Put it in `routes/api.php` inside the `Route::middleware('organization')` group: `Route::apiResource('<things>', ...)->whereNumber('<thing>')`.
9. **Queued jobs and listeners.** Models come back without global scopes, but any *other* scoped query inside the job returns nothing until it acts in the organization. Wrap the work in `app(CurrentOrganization::class)->run($organization, fn () => ...)`, never a bare `set()`: a job run inline (the `sync` queue, or called during a request) shares the request's instance, and `run()` restores it afterwards.
10. **Writes go through the model.** `BelongsToOrganization` forces `organization_id` on create and refuses moving a row, but only in model events: query-builder `update([...])`, `insert()`, `upsert()` and `*Quietly()` skip them. Never pass request input to a builder `update()` on a tenant model, and don't bulk-insert tenant rows.

## Tests (`api/tests/Feature/<Things>/`)
Use the helpers from `tests/Pest.php`: `userInOrganization()`, `addMember()`, `inOrganization()`.
- **`<Thing>IsolationTest.php`** is required (see `TaskIsolationTest.php`). Org A's user must:
  - not see B's records in lists or search;
  - get 404 when reading, updating or deleting one of B's records, with B's data unchanged;
  - still be isolated when they belong to both organizations but haven't switched.
- **`<Thing>PermissionsTest.php`:** the full role × action table.
- **`<Thing>CrudTest.php`:**
  - CRUD, with an `organization_id` in the request being ignored;
  - validation;
  - uniqueness per organization;
  - pagination and search;
  - events (`Event::fake`);
  - audit entries for create, update and delete (`auditEntries($org, AuditAction::...)`; jsonb reorders keys, so compare `changes` with `toEqual`);
  - 401 without login and 403 without a current organization.
- Prove the tests catch mistakes: temporarily remove the trait or make the policy allow everything, confirm tests fail, then restore it.

## Web (`web/src/`)
1. **Types** in `types/api.ts`: `<Thing>` (with `can`), `<Thing>Payload`, and `Paginated<T>`.
2. **Query hooks** in `features/<things>/api.ts` (reference `features/tasks/api.ts`): `use<Things>(filters)`, `use<Thing>(id)`, and `useCreate/Update/Delete<Thing>()` mutations, all through `apiFetch`.
   - Query keys start with `['org', currentOrganizationId, '<things>', ...]`, so switching organization never shows the old one's data (the switch removes `['org']` queries).
   - Mutations invalidate the list and update the detail query.
3. **Form component:** reference `features/tasks/TaskForm.tsx`. react-hook-form + a zod schema; 422 responses go onto fields with `applyServerErrors` (`lib/apiErrors.ts`).
4. **List route:** reference `routes/TasksPage.tsx` (filters in `features/tasks/useTaskFilters.ts` and `filters.ts`).
   - Page, filters and search live in the URL (`useSearchParams`), search debounced (`useDebouncedCallback`).
   - Switching organization already goes back to the section root (`useEnterOrganization`), dropping filters and page.
   - shadcn `Table` with an empty state, pagination, and a create `Dialog`.
5. **Detail route:** reference `routes/TaskDetailPage.tsx`.
   - A 404 shows "not found" (another organization's record shows the same thing).
   - Show Edit and Delete only according to `can`. Confirm deletes in an `AlertDialog`.
   - Go back to the list when the organization changes (a 409 from another tab doesn't go through the switcher): remember the organization on mount and render `<Navigate>`, without fetching the record in the new one.
6. **Navigation:** add the routes in `router.tsx` and an entry in `navigation` (`components/AppSidebar.tsx`).
7. Make every API call through `apiFetch`. Never send `organization_id` from the frontend.

## Finish
- Run the `verify` skill, then `e2e-smoke` for the flow.
- Run the reviewer subagents on the diff: `code-reviewer`, `security-auditor`, `tenant-isolation-reviewer` and `database-reviewer`, plus `ui-reviewer` for the pages.
- Report to the user and wait for approval before committing (the `ship-plan` skill).
