---
name: e2e-smoke
description: Check a flow end to end against real running servers - API on :8112 and the built web app on :5112 (never the dev ports :8100/:5173) - with curl or the Playwright browser, then remove the test data. Use after a feature passes its tests, to confirm it works in the actual app, including cross-organization checks.
argument-hint: "[flow to check, e.g. tasks]"
allowed-tools: Bash(.claude/skills/e2e-smoke/servers.sh *), Bash(.claude/skills/e2e-smoke/mail.sh *)
---

# End-to-end smoke check

Flow to check: $ARGUMENTS

## 1. Start the servers
```bash
.claude/skills/e2e-smoke/servers.sh start           # builds web first (VITE_API_BASE baked in); SKIP_BUILD=1 to reuse web/dist-e2e
.claude/skills/e2e-smoke/servers.sh start --mail    # same, and emails are sent at once (sync queue) to the log, with links to :5112
```
- Use `--mail` whenever the flow sends email (verification, password reset, invitations). It doesn't need the developer's `queue:work`, and doesn't touch `.env`. It runs **every** queued job inline (AI summaries, webhooks too), so retries and backoff aren't exercised, and a failing job shows as a 500 on the request.
- Without `web/` (before plan 03), `start` runs the API alone and says so; check with curl only.
- :8100 and :5173 are the developer's own `artisan serve` and `npm run dev`; :8000/:3000 and :8011/:3011 belong to saas-starter. Never stop or reuse those ports.
- The servers use the dev database (`react_saas`), so every account you create must be `e2e-<something>@example.com`. Cleanup only deletes those.
- Agents sharing the servers use their own prefix, so cleanup doesn't delete each other's accounts: `flow-tester` uses `e2e-ft-<name>@example.com`.

## 2. Set up two organizations
Register two users through the API, so each gets their own organization, and keep their tokens:
```bash
curl -s -X POST localhost:8112/api/register -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"E2E A","email":"e2e-a@example.com","password":"password123","password_confirmation":"password123"}'
```
- In zsh, build JSON with `printf '%s'`, not `echo`, which mangles `\n`.
- Variables aren't word-split in zsh, so put each header in its own `-H` argument.

## 3. Check the flow
- **API (curl):** the happy path, the permission cases (`can` flags, 403 for the wrong role), and that user A gets **404** for B's records on read, update and delete and never sees them in lists or search.
- **Browser (Playwright MCP, `mcp__playwright__*`):**
  - Store a token (in `localStorage`) by logging in through the `/login` form at `http://localhost:5112`.
  - Log out is in the account menu (top right, or the sidebar footer): open the menu first, then its items appear in the snapshot.
  - Click through the flow and take screenshots of the key states: list, empty, form errors, detail, not found.
  - Read the console messages and report any errors.
  - Also check at 390px width.
- If the Playwright tools aren't available, say so and do the API checks only. Never claim a browser check you didn't run.

## 4. Emails
Follow links from the email, never by building the URL yourself. `mail.sh` reads only what was logged since `start`:
```bash
.claude/skills/e2e-smoke/mail.sh list                                      # recipient and subject of each email
.claude/skills/e2e-smoke/mail.sh link verify-email e2e-a@example.com       # newest link to :5112/verify-email in emails to that address
.claude/skills/e2e-smoke/mail.sh link join                                 # also reset-password; recipient is optional
.claude/skills/e2e-smoke/mail.sh count e2e-b@example.com                   # how many emails went there
```

## 5. Clean up, always
Run this even if a check failed:
```bash
.claude/skills/e2e-smoke/servers.sh errors              # ERROR entries in laravel.log during the run (for the report)
.claude/skills/e2e-smoke/servers.sh cleanup             # e2e-*@example.com users, their tokens, organizations only they belong to
.claude/skills/e2e-smoke/servers.sh cleanup e2e-ft-     # only e2e-ft-* (what flow-tester uses), leaving other agents' accounts
.claude/skills/e2e-smoke/servers.sh stop                # whoever started the servers stops them
```

## Report
Use this format, so every run reads the same:

| # | What I did | Result |
|---|---|---|
| 1 | Signed up, then opened the link from the verification email while logged out | ✅ Sent to log in, then back to the link; email verified |
| 2 | … | ❌ Expected "…", got 500 (screenshot `.playwright-mcp/…`) |

Then, in this order:
- **Emails:** `mail.sh list`, or "none".
- **Errors in the log:** `servers.sh errors`, or "none".
- **Data:** what was created, and that cleanup removed it (the count it printed).
- **Noticed:** anything outside the checks, one line each, or leave it out.
