---
name: ship-plan
description: Commit and push an approved, finished plan - verify, separate api/web/docs commits, mark the plan Done with commit hashes. Use only when the user approves the result ("all good, commit and push").
disable-model-invocation: true
argument-hint: "[plan number, e.g. 05]"
---

# Ship a plan

Plan: $ARGUMENTS. If no number is given, use the plan marked WIP in `docs/plans/README.md`.

## Steps
1. **Check scope.** Run `git status --short` and `git diff --stat`. Every changed file should belong to this plan. If something unrelated is there, stop and ask; never sweep it into a commit.
2. **Verify.** Run `.claude/skills/verify/verify.sh` for the sides that changed. If anything fails, stop and report. Never commit red.
2b. **Docs check.** For each line below, decide whether this plan triggers it. Update the doc in the same commit as the change it describes; never leave it for later.
   - New env key → `api/.env.example`, with a comment.
   - New or changed convention, command, port or architecture rule → `CLAUDE.md`.
   - Setup or run steps, or a user-visible feature, added or changed → `README.md` (root).
   - New page or visible feature, or a change that makes a shot out of date → screenshot in `docs/screenshots/` and the README "Screenshots" section. If it's missing, stop and take it first.
   - New `.claude/` skill, agent or MCP server → the tooling section of `CLAUDE.md`.
   - Follow-ups found but not done → the plan's "Follow-ups" section.

   List in the report which docs you updated, or "no doc changes needed".
3. **Commit api, then web,** as separate commits in the format `type(scope): summary`:
   ```bash
   git add api && git commit -m "feat(api): <summary>

   <one short paragraph on what and why>

   Co-Authored-By: <the attribution line from the system reminder>"
   ```
   Do the same with `git add web` for `feat(web): ...`. Skip a side that has no changes. Use `.claude/` changes → `chore(claude): ...`.
4. **Mark the plan Done.**
   - In `docs/plans/NN-*.md`, set the lines under the title to:
     ```
     **Status:** Done
     **Commits:** `<api hash>` (api), `<web hash>` (web)
     ```
   - Replace any existing `**Commits:**` line; don't add a second one.
   - Update the plan's row in `docs/plans/README.md` to `Done`.
5. **Commit the docs:** `git add docs && git commit -m "docs: mark <plan> plan done"`.
6. **Push:** `GIT_TERMINAL_PROMPT=0 git push`. If it fails on auth, report it; don't change remotes or credentials.
7. **Report:** list the commit hashes and messages, and confirm `git status` is clean.

## Never
- Commit `.env`, `.env.backup` or `.claude/settings.local.json`.
- Use `--no-verify`, force-push, or amend commits that are already pushed.
