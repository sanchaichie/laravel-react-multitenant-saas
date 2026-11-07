<div align="center">

# 🧱 Laravel × React Multi-Tenant SaaS

### Your next B2B product, minus six months of plumbing.

Organizations, roles, tasks, Claude AI, Stripe billing, outgoing webhooks, 2FA, audit trails and an admin console.<br>
A **Laravel 13** API and a **React 19** app that are tested, documented and ready to fork.

[![CI](https://github.com/sanchaichie/laravel-react-multitenant-saas/actions/workflows/ci.yml/badge.svg)](https://github.com/sanchaichie/laravel-react-multitenant-saas/actions/workflows/ci.yml)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)
![TypeScript](https://img.shields.io/badge/TypeScript-strict-3178C6?logo=typescript&logoColor=white)
![Tailwind](https://img.shields.io/badge/Tailwind-4-06B6D4?logo=tailwindcss&logoColor=white)
![Vite](https://img.shields.io/badge/Vite-8-646CFF?logo=vite&logoColor=white)
![Stripe](https://img.shields.io/badge/Stripe-Cashier-635BFF?logo=stripe&logoColor=white)
![Claude](https://img.shields.io/badge/AI-Claude-D97757)

<img src="docs/screenshots/tasks.png" alt="The tasks list with filters, search and sorting" width="860">

**[Tour](#-the-tour)** · **[Permissions](#-who-can-do-what)** · **[Security](#-security-model)** · **[Stack](#-stack)** · **[Get running](#-get-running)** · **[Tests](#-tests)** · **[API docs](docs/api.md)**

</div>

---

## 🎯 The pitch

You want to build *the thing*: the workflow, the insight, the product your customers actually pay for. Before you get there, every SaaS needs teams, invites, permissions, billing, security and support tooling, and every one of those is easy to get subtly wrong.

This repo is all of that, finished. The demo feature is **Tasks** (assign, prioritize, track, with AI help). Swap it for your own domain and keep everything else.

| 🏢 Multi-tenant | 🤖 AI-native | 💳 Monetized | 🔌 Connected | 🛡️ Hardened |
|:-:|:-:|:-:|:-:|:-:|
| Slack-style workspaces with fully isolated data | Summaries, writing help and chat, on a budget | Free/Pro plans, credit packs, invoices | Signed outgoing webhooks with a delivery log | 2FA, audit log, admin console |

## 🗺 The tour

### 1 · Getting in

New users get their own organization on sign-up, plus an email to confirm their address. Forgotten passwords are reset by email link. People in several organizations choose one after logging in.

| | |
|---|---|
| ![Log in](docs/screenshots/login.png) | ![Register with field errors](docs/screenshots/register-errors.png) |
| **Log in** | **Register**, with errors shown right next to the field |
| ![Choose an organization](docs/screenshots/select-organization.png) | ![Verify your email](docs/screenshots/verify-email-banner.png) |
| **Pick a workspace** | **Verify your email** reminder, with a resend button |

### 2 · Your workspace

Create organizations and hop between them from the sidebar. Owners and admins invite people by email: links work for new and existing accounts and expire after 7 days. Roles can be changed and members removed, anyone can leave, and an organization is never left without an owner.

| | |
|---|---|
| ![Home](docs/screenshots/home.png) | ![Organization switcher](docs/screenshots/organization-switcher.png) |
| **Dashboard**: the current organization and your role | **Switcher**: your organizations, and a button to create one |
| ![Members](docs/screenshots/members.png) | ![Join an organization](docs/screenshots/join.png) |
| **Members** and pending invitations | **Invitation link**: one click to join |

### 3 · Tasks: the demo feature

- Title, description, status (*To do → In progress → Done*), priority, due date and assignee
- Filter by status, priority, assignee, overdue or keyword, and sort. **Filters live in the URL**, so a view can be bookmarked or shared
- Overdue tasks are flagged automatically
- Members edit what they created or were assigned. Owners and admins edit anything

| | |
|---|---|
| ![Tasks](docs/screenshots/tasks.png) | ![A task](docs/screenshots/task-detail.png) |
| **Tasks**: filters, search and sorting | **A task**: change its status in place, edit or delete it |

### 4 · AI copilots ✨

Powered by **Claude**. Three rules: AI reads one task at a time, never touches another organization's data, and never acts on its own.

| Copilot | What it does |
|---|---|
| **Summaries** | A short summary with key points, saved as a draft to approve, edit or reject, and flagged if the task changes later |
| **Improve with AI** | Rewrites a description to improve, expand or shorten it, streamed word by word. Accept, retry or discard |
| **Ask AI** | A private chat about a task. Not even the owner can read yours. Stop an answer or clear the chat anytime |
| **Budget guard** | A monthly AI limit from the plan. Owners can lower it or set $0 to turn AI off. When it's spent, AI waits for next month or credits |
| **Usage page** | The month's calls and cost by feature, person and day |

| | |
|---|---|
| ![An AI task summary to review](docs/screenshots/task-summary.png) | ![AI writing help](docs/screenshots/task-writing-help.png) |
| **AI summary** awaiting review | **Improve with AI** inside the task form |
| ![Task chat](docs/screenshots/task-chat.png) | ![AI usage](docs/screenshots/ai-usage.png) |
| **Ask AI**: a private task chat | **AI usage** against the budget |

### 5 · Money 💰

All payments happen on Stripe's hosted pages, so the app never sees a card number.

- **Free** or **Pro** (Pro raises the monthly AI budget)
- **AI credit packs**: one-time top-ups for this month
- **Customer Portal** to change the card or cancel
- **Invoices** listed in the app, each linking to Stripe's copy and PDF
- Only owners manage billing, or a custom role given that permission

| | |
|---|---|
| ![Billing](docs/screenshots/billing.png) | ![Invoices](docs/screenshots/invoices.png) |
| **Billing**: plan, card, AI budget, credits | **Invoices**, live from Stripe |

### 6 · Control and accountability

**Custom roles** are assembled from a permission list, such as a read-only "Viewer". You can't hand out more than you have, so an admin can never promote themselves to owner.

The **audit log** records who changed what (tasks, members, invitations, roles, billing) with old → new values, filters and immutable entries, kept for a year by default.

| | |
|---|---|
| ![Roles](docs/screenshots/roles.png) | ![Edit a role](docs/screenshots/role-edit.png) |
| **Roles** and how many people hold each | **Edit a role**: permissions grouped by area |
| ![Audit log](docs/screenshots/audit-log.png) | |
| **Audit log** with old → new values | |

### 7 · Webhooks 🔌

Push task events to your CRM, chat or own backend the moment they happen.

- Owners add an endpoint URL and choose events: **task created, updated, deleted**
- Every payload is **HMAC-signed**, so receivers can verify it came from you
- A **delivery log** shows the request, the response and the outcome, with one-click resend and test pings
- Failing endpoints are retried **up to 6 times over ~10 hours**, then auto-paused with a reason after repeated failures
- **SSRF-safe**: only public internet targets, never the app's private network

![Webhooks: a webhook's delivery log](docs/screenshots/webhooks.png)

### 8 · Your account

From your name at the bottom of the sidebar:

- Change your name, email or password. A new email only applies once confirmed, and the old inbox gets a 7-day **undo** link
- See every signed-in device and sign any of them out
- Turn on **two-factor login** with any authenticator app, with recovery codes for a lost phone
- Delete your account. Solo organizations go with it, and it's refused while others depend on you as last owner

| | |
|---|---|
| ![Account settings](docs/screenshots/account.png) | ![Two-factor setup](docs/screenshots/two-factor-setup.png) |
| **Account settings** | **Two-factor setup** |

### 9 · Platform admin 🧑‍✈️

For the team *running* the product. It's not an organization role: it's granted only from the server's command line, and only to people with 2FA on. Admins can:

- search all users and organizations (plan, AI budget, counts; never task content or chats)
- reset a user's 2FA, sign them out everywhere, or suspend and unsuspend them
- make a member Owner of an organization whose owners are gone
- **view the app as a user**, read-only for 30 minutes, with private content hidden

Every action needs a written reason and a fresh 2FA code, lands in a platform log, and emails the user. The organization's own audit log shows it as "Platform admin".

| | |
|---|---|
| ![Platform admin: users](docs/screenshots/admin-users.png) | ![Platform admin: an organization](docs/screenshots/admin-organization.png) |
| **Users**: search everyone | **An organization**: plan, budget, counts, members |
| ![Platform admin: actions on a user](docs/screenshots/admin-user-actions.png) | ![Viewing as a user](docs/screenshots/admin-view-as.png) |
| **Actions**, each with a reason | **View as user**: read-only |

## 🔑 Who can do what

Every organization starts with three roles. Owners can reshape Admin and Member or add their own.

| | 👑 Owner | 🛠 Admin | 🙋 Member |
|---|:---:|:---:|:---:|
| See all tasks | ✅ | ✅ | ✅ |
| Create tasks; edit own and assigned | ✅ | ✅ | ✅ |
| Edit and delete anyone's tasks | ✅ | ✅ | — |
| Use AI (summaries, writing help, chat) | ✅ | ✅ | ✅ |
| Invite, change roles, remove members | ✅ | ✅ | — |
| See the audit log | ✅ | ✅ | — |
| See AI usage and cost | ✅ | ✅ | — |
| Create and edit roles | ✅ | — | — |
| Change the AI budget | ✅ | — | — |
| Manage plan, card and invoices | ✅ | — | — |
| Manage webhooks | ✅ | — | — |

An admin can't change or remove an owner.

## 🛡 Security model

| Concern | How it's handled |
|---|---|
| **Tenant isolation** | The server decides which organization you're in, on every request. The client never says. Automated tests prove it for every feature. |
| **Account takeover** | Login rate limits, optional 2FA, emails on every sensitive change with undo links, and "sign out everywhere". |
| **Payments** | Cards are entered only on Stripe's pages. Plans change only through Stripe's signed webhook. |
| **AI** | Keys never leave the server, output is always a human-approved suggestion, and spend is capped per organization. |
| **Accountability** | A per-organization audit log, plus a separate platform log for admin activity. |
| **Privacy** | Task chats are visible only to their author. Even "view as user" hides task content. |
| **Outbound calls** | Webhooks are signed and limited to public addresses. |

## 🧰 Stack

| Layer | Tech |
|---|---|
| **API** (`api/`) | Laravel 13 · PHP 8.4 · PostgreSQL · Sanctum · Laravel Cashier (Stripe) · Claude · Pest |
| **Web** (`web/`) | React 19 · TypeScript · Vite · React Router · TanStack Query · Tailwind 4 · shadcn/ui · Vitest + Testing Library |
| **Ops** | GitHub Actions CI · queued jobs (emails, AI, webhooks) · Laravel Scheduler cleanup |

Built feature by feature from written plans ([docs/plans](docs/plans/README.md)). It's the React sibling of [LaraNuxt SaaS](https://github.com/sanchaichie/laranuxt-saas) (Laravel + Nuxt), with Tasks where that one has Projects. Every endpoint is in the **[API reference](docs/api.md)**, and the code conventions are in [CLAUDE.md](CLAUDE.md).

## 🚀 Get running

**Prereqs:** PHP 8.4+, Composer, PostgreSQL, Node 22 (`web/.nvmrc`; run `nvm use` in `web/`).

```bash
# Databases: one for the app, one for tests (rebuilt every run)
createdb -h 127.0.0.1 -U postgres react_saas
createdb -h 127.0.0.1 -U postgres react_saas_test

# API
cd api
composer install
cp .env.example .env              # point DB_* at your Postgres
php artisan key:generate
php artisan migrate

# Web
cd ../web
npm install
cp .env.example .env              # VITE_API_BASE, if the API isn't on http://localhost:8100
```

No `createdb` on your PATH? Run `CREATE DATABASE ...` from any Postgres client.

**Launch:**

```bash
cd api && PHP_CLI_SERVER_WORKERS=4 php artisan serve --port=8100 --no-reload
cd api && php artisan queue:work --queue=default,webhooks     # emails, AI summaries, webhook deliveries
cd web && npm run dev                                          # → http://localhost:5173
```

Open **http://localhost:5173** and create an account.

> [!TIP]
> Develop offline with `AI_PROVIDER=fake` (canned AI answers, no key, no cost) and `BILLING_PROVIDER=fake` (no Stripe calls).

> [!WARNING]
> Vite must stay on **:5173**, the only origin the API's CORS allows (`FRONTEND_URL`).

> [!IMPORTANT]
> Database-wiping commands (`migrate:fresh`, `db:wipe`, …) refuse to run unless the database name ends in `_test`.

<details>
<summary><b>⚙️ Background work, scheduler and AI backfill</b></summary>

- **Queues.** Emails, AI summaries and webhook deliveries are queued. Webhooks have their own `webhooks` queue so a slow receiver never holds up email. Run `queue:work --queue=default,webhooks`, or give `webhooks` its own worker. Without a worker no webhook is sent; with `QUEUE_CONNECTION=sync` only the first attempt is.
- **Emails locally.** With `MAIL_MAILER=log`, emails (links included) are written to `api/storage/logs/laravel.log`.
- **Scheduler.** Daily cleanup removes expired invitations, audit entries past `AUDIT_RETENTION_DAYS` and webhook deliveries past `WEBHOOK_DELIVERY_RETENTION_DAYS`. Run `php artisan schedule:work` locally, or a cron entry for `schedule:run` in production.
- **AI usage.** Every AI call is recorded in `ai_usage` (tokens and cost). To summarize tasks created before AI was set up, run `php artisan ai:summarize-tasks` (`--organization=ID`, `--limit=50`, `--dry-run`).

</details>

<details>
<summary><b>🔧 Environment keys (<code>api/.env</code>)</b></summary>

| Key | Default | What it's for |
|---|---|---|
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `react_saas`, `postgres`, empty | The app database |
| `FRONTEND_URL` | `http://localhost:5173` | The only origin CORS allows, and the base of email links |
| `MAIL_MAILER` | `log` | Locally, emails go to `storage/logs/laravel.log` |
| `AUDIT_RETENTION_DAYS` | `365` | Days to keep audit entries; `0` keeps them forever |
| `AI_PROVIDER` | `anthropic` | `fake` for canned answers (local, e2e); `anthropic` calls Claude |
| `ANTHROPIC_API_KEY` | empty | Needed with `AI_PROVIDER=anthropic`; stays on the server |
| `AI_MODEL` | `claude-haiku-5-5` | The model for AI features |
| `AI_RATE_LIMIT_PER_MINUTE` | `5` | AI requests per organization per minute |
| `AI_STREAM_TIMEOUT` | `120` | Seconds a streamed answer may take |
| `AI_MAX_CONCURRENT_STREAMS` | `3` | Streams running at once; keep it below the PHP workers (4) |
| `AI_FAKE_STREAM_DELAY_MS` | `0` | With the fake provider, delay between streamed words (e2e uses 60) |
| `AI_MONTHLY_BUDGET_USD` | `5` | Free plan's monthly AI budget per organization; `0` turns AI off |
| `BILLING_PROVIDER` | `stripe` | `fake` for no Stripe calls (only with `APP_ENV=local` or `testing`) |
| `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | empty | Stripe keys and webhook signing secret (without it every webhook is refused) |
| `STRIPE_PRICE_PRO`, `STRIPE_PRICE_CREDITS_10` | empty | Price ids for Pro (recurring) and the $10 credit pack (one-time) |
| `BILLING_PRO_AI_BUDGET_USD` | `50` | Pro's monthly AI budget |
| `ADMIN_TWO_FACTOR_TTL_MINUTES` | `720` | Admin pages need a 2FA code on this device within this window |
| `ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES` | `15` | Admin actions need a fresher one |
| `ADMIN_IMPERSONATION_MINUTES` | `30` | Length of a "view as user" session |
| `WEBHOOK_DELIVERY_RETENTION_DAYS` | `30` | Days to keep the webhook delivery log |
| `WEBHOOK_QUEUE`, `WEBHOOK_RATE_LIMIT_PER_MINUTE` | `webhooks`, `60` | Delivery queue, and attempts per organization per minute |
| `WEBHOOKS_ALLOWED_PRIVATE_TARGETS` | empty | `host:port` pairs allowed on a private network (local test receivers; `local`/`testing` only) |

More is documented in `api/.env.example`.

</details>

<details>
<summary><b>🧑‍✈️ Making a platform admin</b></summary>

Turn on two-factor in Account settings first, then:

```bash
php artisan admin:grant you@example.com    # admin:revoke takes it away
```

Log in with an authenticator code and the **Platform admin** group appears in the sidebar. Turning 2FA off ends admin access until it's granted again.

</details>

<details>
<summary><b>💳 Trying Stripe in test mode</b></summary>

Without Stripe, the Billing page shows the Free plan and Upgrade says the plan isn't available yet. To try it for real:

1. In the Stripe dashboard (test mode), create a **Pro** product with a monthly price and an **AI credits** product with a one-time $10 price. Put the `price_...` ids in `STRIPE_PRICE_PRO` / `STRIPE_PRICE_CREDITS_10`, and the API keys in `STRIPE_KEY` / `STRIPE_SECRET`.
2. Enable the Customer Portal (Settings → Billing → Customer portal) with plan switching off.
3. Forward webhooks and copy the printed secret into `STRIPE_WEBHOOK_SECRET`:
   ```bash
   stripe listen --forward-to localhost:8100/api/stripe/webhook \
     --events customer.subscription.created,customer.subscription.updated,customer.subscription.deleted,customer.updated,customer.deleted,checkout.session.completed,checkout.session.async_payment_succeeded
   ```
4. Upgrade with `4242 4242 4242 4242` (any future date, any CVC).

**Production:** add the same events to a dashboard webhook endpoint, and set "If all retries for a payment fail" to cancel the subscription.

</details>

## 🧪 Tests

```bash
cd api && php artisan test          # ~760 Pest tests on react_saas_test (rebuilt every run)
cd api && vendor/bin/pint --test    # PHP style (vendor/bin/pint to fix)
cd web && npm test                  # Vitest + Testing Library, API mocked
cd web && npm run lint && npm run typecheck && npm run build
```

GitHub Actions runs all of it on every push to `main` and every PR. To keep `main` green, require the `API (Pint, Pest)` and `Web (lint, typecheck, test, build)` checks under Settings → Branches.

## 🤖 Claude Code inside

The repo ships its [Claude Code](https://claude.com/claude-code) setup in `.claude/` and `.mcp.json`: skills for planning (`/plan-task`), building tenant features (`/tenant-feature`), verifying (`/verify`), end-to-end checks (`/e2e-smoke`) and shipping (`/ship-plan`), plus reviewer subagents and hooks that block destructive database commands. Conventions are in [CLAUDE.md](CLAUDE.md).

<div align="center">
<sub>Laravel · React · Claude · Stripe · <a href="https://github.com/sanchaichie/laranuxt-saas">Prefer Nuxt? See LaraNuxt SaaS →</a></sub>
</div>
