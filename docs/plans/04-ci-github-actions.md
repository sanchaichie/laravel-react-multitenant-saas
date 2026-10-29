# 04 — CI on GitHub Actions

**Status:** WIP

## Goal
Run the same checks as `/verify` on GitHub for every push to `main` and every pull request: Pint and Pest for `api/`, plus oxlint, typecheck, Vitest and build for `web/`. A broken commit then shows up even when nobody ran `verify.sh` locally. It rebuilds saas-starter plan 28, with Vitest added.

Out of scope:
- e2e or browser tests in CI (`e2e-smoke` and `flow-tester` stay local);
- deployment;
- `composer audit` / `npm audit`. These would turn CI red on advisories in packages we don't control, even when no code changed (today there are 3 low npm advisories inside the shadcn CLI). `security-auditor` already runs them on every plan.
- branch protection rules. You turn these on in the GitHub settings if you want a red check to block merging, and the README says how.

## Decisions
- **One workflow, `.github/workflows/ci.yml`, with two parallel jobs: `api` and `web`.**
  - Neither job waits for the other, so a web lint error doesn't hide a Pest failure, and each log stays short.
  - The steps are the same commands `verify.sh` runs, but CI doesn't call `verify.sh` itself, because that script hunts for Homebrew PHP and nvm.
- **Triggers:** `push` to `main`, `pull_request`, and `workflow_dispatch` (re-run by hand).
  - `concurrency` cancels an older pull request run when a newer push arrives.
  - Runs on `main` are never cancelled, so every commit there keeps its own result.
- **Least privilege:** `permissions: contents: read`, and checkout uses `persist-credentials: false`. The workflow needs no repository secrets, since nothing calls an outside service yet.
- **Postgres 17 as a service container** (trust auth, database `react_saas_test`).
  - The name ends in `_test`, so Pest's rebuild passes `guardDestructiveDatabaseCommands`, the same as locally.
  - `phpunit.xml` already sets `DB_DATABASE=react_saas_test`, `MAIL_MAILER=array` and `QUEUE_CONNECTION=sync`.
  - Job-level env sets `DB_HOST=127.0.0.1`, `DB_USERNAME=postgres` and `DB_PASSWORD=''`.
  - The job does `cp .env.example .env` and `php artisan key:generate`. That `.env` exists only on the throwaway runner.
- **Versions come from the repo, not duplicated:**
  - PHP 8.4 (Pest 5 needs it; matches `verify.sh`), with `pdo_pgsql`.
  - Node from `web/.nvmrc` (`node-version-file`).
- **Caching:** Composer's cache keyed on `api/composer.lock`; npm's cache built into `setup-node`, keyed on `web/package-lock.json`. Installs are `composer install --no-interaction --prefer-dist` and `npm ci`.
- **Actions (new outside code, so they need your OK):**
  - GitHub's own `actions/checkout@v7`, `actions/setup-node@v7` and `actions/cache@v6`, on major tags (the first run used v5/v4; `cache@v4` triggered a Node 20 deprecation warning, so they were moved to the current majors, each out for months).
  - The third-party `shivammathur/setup-php`, pinned to the commit of release 2.40.0 (`eb7c497e…`, checked against the tag), so a moved tag can't change what runs. It's the same pin saas-starter uses.
  - `.github/dependabot.yml` for the `github-actions` ecosystem, checking weekly with a 7-day cooldown. It keeps the tags and the SHA current, and opens PRs only for the workflow file. Composer and npm updates stay manual.
- **Timeouts:** 15 minutes per job, so a hung test can't use up runner minutes.
- **Private repo:** GitHub Free includes 2,000 Actions minutes a month for private repos. One run is two jobs of about 2–3 minutes, so that's plenty.

## Files
- `.github/workflows/ci.yml` (new):
  - `api` job: checkout → setup-php 8.4 (`pdo_pgsql`, no coverage) → Composer cache → `composer install` → `.env` + `key:generate` → `vendor/bin/pint --test app routes tests database config bootstrap/app.php bootstrap/providers.php` (the same paths as `verify.sh`) → `php artisan test`.
  - `web` job: checkout → setup-node (from `.nvmrc`, npm cache) → `npm ci` → `npm run lint` → `npm run typecheck` → `npm test` → `npm run build`.
- `.github/dependabot.yml` (new).
- No `api/` or `web/` code changes.

## Docs
- Root `README.md`:
  - a CI badge under the title;
  - one line in "Tests": GitHub Actions runs the same checks on every push to `main` and every PR, and branch protection can require them (Settings → Branches).
- `CLAUDE.md`, "Commands": CI (`.github/workflows/ci.yml`) runs the `/verify` steps on push and PR; keep the two in step. `verify/SKILL.md` already says this.
- `.env.example`: no new keys.

## Frontend (`web/`)
None.

React concepts: none in this plan (CI only).

## Tests
No app code, so no new tests. The proof is the workflow itself:
- a green run on a branch pushed for this plan;
- one deliberate red run: a throwaway commit on that branch with a Pint violation shows the `api` job fails while the `web` job still finishes. That commit is reverted before merging, so it never reaches `main`.

## README screenshots
None. There's no new page; the badge is the only visible change.

## Verification
- `verify` locally (nothing should change).
- Push the branch `ci/github-actions`, open a PR, and follow it with `gh run watch`:
  1. both jobs green;
  2. the deliberate Pint failure: `api` red, `web` green;
  3. the revert: green again.
- Reviewers: `code-reviewer` and `security-auditor` on the workflow (permissions, no secrets, pinned actions, triggers). `tenant-isolation-reviewer`, `database-reviewer` and `ui-reviewer` don't apply: no models, queries, migrations or pages change.

Flows: none. There's no user-facing flow.

## Outcome
- PR #1 (`ci/github-actions`), four runs:
  1. first push: both jobs green (`api` 35s, `web` 36s). One warning: `actions/cache@v4` targets the deprecated Node 20.
  2. after moving to `checkout@v7`, `setup-node@v7` and `cache@v6`: green, with no Node 20 warning;
  3. deliberate Pint violation in `api/routes/web.php`: `api` failed at the Pint step, and `web` still finished green;
  4. its revert: green.
- `/verify` locally: unchanged, all passing.
- Reviewers:
  - `code-reviewer`: steps match `verify.sh`, and the Postgres service works with `phpunit.xml`. One low: the README's test commands didn't name Pint, which CI runs. Fixed.
  - `security-auditor`: no findings. It confirmed read-only permissions, no `pull_request_target`, no `${{ }}` in `run:` steps, no secrets, `persist-credentials: false`, the setup-php SHA pin (also checked by hand against tag 2.40.0) and the Dependabot config.
  - `tenant-isolation-reviewer`, `database-reviewer` and `ui-reviewer` were not run: no models, queries, migrations or pages changed.

## Follow-ups
- `symfony/mime` CVE-2026-45070 (medium; fixed in 8.1.10): `composer update symfony/mime` before the app sends mail (plan 06). This is the same item as in plan 03.
- Turn on Dependabot security alerts and security updates for Composer and npm in the repo settings. That's separate from `dependabot.yml`, and it reports advisories without turning CI red.
- Keep "Run workflows from fork pull requests" off in the Actions settings.
- Branch protection for `main` (require both checks) is up to you. The README says how.
- `ubuntu-latest` is moving to Ubuntu 26 (GitHub's annotation on every run). Nothing in the jobs depends on the Ubuntu version.

## Commits after approval
1. `ci: run Pint, Pest, lint, typecheck, Vitest and build on GitHub Actions` (workflow, Dependabot, README, CLAUDE.md), on the branch `ci/github-actions`.
2. After a green run and with your OK: squash-merge the PR into `main`, then this plan → Done (`docs: mark CI plan done`).
