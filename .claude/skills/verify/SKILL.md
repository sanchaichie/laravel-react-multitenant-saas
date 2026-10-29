---
name: verify
description: Run every check before saying a task is done - Pint, Pest (php artisan test), oxlint, tsc typecheck, Vitest and npm run build, with the right PHP 8.4 and Node versions. Use before reporting any api/ or web/ change as finished, and before committing.
argument-hint: "[api|web|all]"
allowed-tools: Bash(.claude/skills/verify/verify.sh *), Bash(.claude/skills/verify/verify.sh)
---

# Verify

Run from the repo root:

```bash
.claude/skills/verify/verify.sh $ARGUMENTS
```

- `api` runs Pint and Pest, `web` runs lint, typecheck, tests (once `web/` has a `test` script) and build, and `all` (the default) runs both.
- The script finds PHP 8.4+ even if `php` on PATH is older, and switches to the Node version in `web/.nvmrc` through nvm.
- CI (`.github/workflows/ci.yml`) runs the same steps on GitHub. When you add or change a step here, change it there too.
- A side whose folder doesn't exist yet (`api/` before plan 02, `web/` before plan 03) is skipped and said so, not failed.
- It ends with `ALL PASSED` or `FAILED: <steps>` and exits non-zero on failure.

## Reporting
- Report the real result: test count, any failures with their output, and which steps ran.
- Never say "done" after a failed or skipped step. Fix the failure and run it again, or tell the user exactly what fails.
- If only one side changed, running just that side is fine. Say which side you ran.
- Pint issues: run `vendor/bin/pint <paths>` (with PHP 8.4) to fix them, then run verify again.
