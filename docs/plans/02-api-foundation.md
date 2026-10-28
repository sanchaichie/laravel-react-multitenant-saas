# 02 — API foundation

**Status:** Done
**Commits:** `364596c` (api), `6b1e581` (claude)

## Goal
A fresh Laravel app in `api/` on PostgreSQL, with Sanctum token auth (register, login, logout, me), Pest tests, CORS for the React app on :5173, and the guard against destructive database commands. It rebuilds saas-starter plan 01, with `IssueToken` and the destructive-command guard there from the start.

Out of scope: organizations (plan 05), email verification and the `verified` middleware (plan 06, with invitations), password reset and account settings (plan 14), device names from the User-Agent (plan 14), two-factor (plan 15) and suspension (plan 17).

## Decisions
- **Laravel 13, Sanctum 4, Pest 5 on PHP 8.4**, the versions saas-starter runs (local PHP is 8.4.7, Composer 2.8.9). No packages beyond Laravel's own (`laravel/sanctum` via `php artisan install:api`, Pest and its Laravel plugin). These are all in the approved stack.
- **API only.** Remove Laravel's frontend scaffold from `api/` (`package.json`, `vite.config.js`, `resources/css`, `resources/js`, the welcome view). `GET /` returns `{"name": "<APP_NAME> API"}`. `web/` is the only frontend, so there's a single `package.json` to install. Also gone: the `.npmrc`, the npm steps in `composer setup`, the `composer dev` script (`artisan dev` runs through Node), Laravel's stock `api/README.md` (now a pointer to the root one), and the `CLAUDE.md`/`AGENTS.md` that `create-project` writes into `api/` (the root CLAUDE.md is the only one).
- **Tokens only through `App\Actions\Auth\IssueToken`** (CLAUDE.md). For now it takes the user and an optional `device_name` (default `web`). Plan 14 adds the IP/User-Agent columns, and plans 15 and 17 add the two-factor and suspension checks, all in this one place.
- **Responses:** register → 201 `{user, token}`, login → 200 `{user, token}`, me → `{data: user}` (a Resource), logout → 204. This is saas-starter's shape, so its tests port over.
- **The same error for an unknown email and a wrong password** (`auth.failed` on `email`). That way login doesn't reveal which accounts exist.
- **Emails are stored in lowercase.** Register validates with `lowercase`, login lowercases the email before the lookup, and a `User::email()` mutator lowercases on every write, so later write paths (invitations, account settings, tinker) can't store two spellings past the case-sensitive unique index.
- **Throttle:** register at `6,1` per IP (prefix `register`). Login uses a named `login` limiter (AppServiceProvider): 6/min per email + IP, and 30/min per email alone, so many IPs can't each take 6 guesses at one account. Each has its own counter.
- **Guests get JSON 401, never a redirect:** `redirectGuestsTo(fn () => null)` plus `shouldRenderJsonWhen` for `api/*`. saas-starter fixed a 500 caused by the missing `login` route; here it's in from the start.
- **CORS:** only `api/*`, only from `FRONTEND_URL` (default `http://localhost:5173`), and `supports_credentials` false because the token is sent as a Bearer header and no cookies are used. `config('app.frontend_url')` is added too, for the email links in later plans.
- **Destructive-command guard:** `AppServiceProvider::guardDestructiveDatabaseCommands()` comes over, with one fix: it reads the database name the connection really uses (a `DB_URL` wins over `DB_DATABASE`, via `ConfigurationUrlParser`), so `DB_DATABASE=react_saas_test` plus a `DB_URL` to `react_saas` can't slip past it. Destructive commands only run on databases whose names end in `_test`/`_test_N`, or with `DB_ALLOW_DESTRUCTIVE_COMMANDS` set for one run. It backs up the Claude hook.
- **Databases:** `react_saas` and `react_saas_test`. Neither existed, and creating them is not destructive (`createdb` isn't on this machine's PATH, so they were made with `CREATE DATABASE` over PDO). `phpunit.xml` points tests at `react_saas_test`.
- **e2e tooling before `web/` exists:** `servers.sh start` skips the web build and preview when there's no `web/`, as `verify.sh` already does. `cleanup` skips the organization step until `organization_user` exists. Without this, the e2e check for this plan can't run.

## Backend (`api/`)
- `composer create-project laravel/laravel api`, then `php artisan install:api` (Sanctum + `personal_access_tokens` migration + `routes/api.php`), then Pest 5 with `pestphp/pest-plugin-laravel` in place of PHPUnit's example tests.
- No new migrations of our own: Laravel's `users`, `cache` and `jobs`, plus Sanctum's.
- `app/Models/User.php`: `HasApiTokens`, `password` hashed cast, `$fillable` name/email/password.
- `app/Actions/Auth/IssueToken.php`, `RegisterUser.php` (create the user, issue a token), `LoginUser.php` (check the credentials, issue a token).
- `app/Http/Requests/Auth/RegisterRequest.php` (name, email lowercase + unique, password confirmed + `Password::defaults()`, device_name nullable) and `LoginRequest.php`.
- `app/Http/Resources/UserResource.php`: id, name, email, created_at.
- `app/Http/Controllers/Api/AuthController.php`: register, login, logout, me. These stay thin and call the actions.
- `routes/api.php`: `POST /register`, `POST /login` (throttled), and inside `auth:sanctum`: `POST /logout`, `GET /me`.
- `bootstrap/app.php`: JSON 401 for guests and JSON errors for `api/*`. Health check at `/up` (servers.sh waits on it).
- `config/cors.php`, `config/app.php` (`frontend_url`), `config/database.php` (`allow_destructive_commands`).
- `.env.example`: `APP_NAME="React SaaS"`, `APP_URL=http://localhost:8100`, `FRONTEND_URL=http://localhost:5173`, pgsql `react_saas`, `PHP_CLI_SERVER_WORKERS`, `DB_ALLOW_DESTRUCTIVE_COMMANDS` (commented out, with a warning), `MAIL_MAILER=log`, `QUEUE_CONNECTION=database`. Each new key gets a comment.

## Frontend (`web/`)
None. `web/` arrives in plan 03.

React concepts: none in this plan (backend only).

## Tests
Ported from saas-starter and adapted (no organizations, no verification):
- `tests/Feature/Auth/RegisterTest.php`: 201 with a working token, password hashed, validation (required, email format, unique, lowercase, confirmation, weak password), throttle.
- `LoginTest.php`: valid login with a working token, wrong password, unknown email gets the same error, required fields, mixed-case email, throttle per IP, the per-account limit across IPs, another account still usable from a throttled IP, `device_name` (given, or `web`), separate from the register counter.
- `LogoutTest.php`: only the current token is deleted (a second device stays logged in), and that token then gets 401.
- `MeTest.php`: returns exactly id/name/email/created_at, 401 without a token or with an unknown one, and JSON 401 with no `Accept` header (not a 500 or a redirect).
- `tests/Feature/AppTest.php`: `GET /` is JSON, `/up` answers, and emails are lowercased on any write.
- `tests/Feature/CorsTest.php`: the preflight from `http://localhost:5173` is allowed (without credentials), and other origins are not.
- `tests/Feature/Console/DestructiveDatabaseCommandsTest.php`: refused on `react_saas`, allowed on `react_saas_test`/`react_saas_test_test_3`, names like `test_react_saas` are refused, a `DB_URL` to `react_saas` is refused even when `DB_DATABASE` is a test one, and the env override works.

The org A vs org B tests start in plan 05. Nothing here is tenant-owned yet.

## README screenshots
None. There's no page yet (screenshots start with plan 03).

## Verification
- The `verify` skill (Pint, Pest; web is skipped).
- `e2e-smoke` with curl against :8112 (API only): register `e2e-smoke-1@example.com` → token, `GET /me` with it → 200, logout → 204, the same token → 401, a wrong password → 422, a preflight from `http://localhost:5112` gets that origin back, `servers.sh errors` → none, then `cleanup e2e-` removes the user.
- Reviewers on the diff: `code-reviewer`, `security-auditor` and `database-reviewer` (users and tokens). `tenant-isolation-reviewer` doesn't apply yet, because there are no organizations.
- Docs in the same commit: root `README.md` (requirements, setup: `createdb`, `composer install`, `cp .env.example .env`, `key:generate`, `migrate`, serve on :8100, `php artisan test`), the CLAUDE.md line about the guard (from "Plan 02 also adds" to present tense), `.env.example`, and the e2e-smoke note in its `SKILL.md` about running without `web/`.

Flows: none. There's no UI yet, and the curl smoke above covers the endpoints.

## Outcome
- 44 Pest tests (159 assertions), Pint clean. Laravel 13.38, Sanctum 4.3.3, Pest 5.3.3.
- e2e-smoke (API only on :8112): register 201, me 200, wrong password 422, mixed-case login 200, logout 204, same token 401, JSON 401 without `Accept`, preflight from :5112 echoed and an other origin not, no log errors, cleanup removed 1 user.
- Reviewers: code, security and database found nothing high. Fixed from their lows: the `DB_URL` gap in the guard (database, medium), per-account login limit (security), the email mutator (database), tests for `/`, `/up` and login `device_name`, the stock `api/README.md` (code).

## Follow-ups
- Tokens never expire (`sanctum.expiration` null) and nothing prunes them: plan 14 (devices) sets an expiry and schedules `sanctum:prune-expired`.
- Two simultaneous registrations with one email: the loser gets 500 from the unique index instead of 422 (rare, throttled, no bad data).
- An unknown email skips bcrypt, so it answers faster than a wrong password. Register already tells which emails exist, so this adds little.

## Commits after approval
`feat(api): add Laravel API with Sanctum token auth`, `chore(claude): let e2e servers run without web`, then this plan → Done.
