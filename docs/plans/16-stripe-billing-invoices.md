# 16 — Stripe billing and invoices

**Status:** Done
**Commits:** `6535cd9` (api), `4523b20` (web), `4c7ed58` (claude)

## Goal
Let an organization pay. It can subscribe to **Pro** (Free / Pro), which raises its monthly AI budget, buy one-time **AI credit packs** that add to this month's budget, open Stripe's Customer Portal for card changes and cancelling, and list its **invoices** in the app. Rebuilds saas-starter plans 18 (billing, credits), 19 (invoices) and 20 (the per-organization Stripe rate limit) as one plan, with that plan's review findings built in from the start.

Out of scope:
- more than one paid plan, plan switching, trials, coupons, tax ids, seat-based pricing;
- refunds and disputes taking credits back (handled by hand in Stripe, as in saas-starter);
- storing invoices locally, emailing them;
- deleting an organization on its own (there's no such feature yet; `DeleteAccount` is the only way, see below).

## Decisions
- **New Composer dependency (needs your OK): `laravel/cashier` (^16.9).** Laravel's own Stripe package, the one saas-starter uses on the same Laravel 13. It brings `stripe/stripe-php`, keeps `subscriptions` in sync from webhooks, and creates Checkout and Portal sessions. Writing that by hand means owning the webhook-to-subscription sync, which is where billing bugs live. No new npm dependency: the app only redirects to Stripe's hosted pages.
- **The organization is the billable, not the user** (`Billable` on `Organization`, `Cashier::useCustomerModel`). Billing always acts on `CurrentOrganization`; nothing takes an organization id from the client.
- **Stripe-hosted pages only** (Checkout, Customer Portal). No card data in the app, no Stripe.js.
- **The webhook is the only thing that changes billing state.** `POST /api/stripe/webhook` (Cashier's routes ignored) goes to `StripeWebhookController` (extends Cashier's). `VerifyStripeWebhook` always checks the signature and refuses everything when `STRIPE_WEBHOOK_SECRET` is blank (Cashier alone would skip the check). The success redirect only refetches. Handling is idempotent: Stripe retries.
- **Behind an interface**, like `AiClient`: `App\Billing\Contracts\BillingGateway` with `StripeBillingGateway` (Cashier) and `FakeBillingGateway` (`BILLING_PROVIDER=fake`: tests, e2e). Tests never call Stripe. The app refuses to boot with `BILLING_PROVIDER=fake` in production.
- **Plans and packs in `config/billing.php`**: `free` (budget `AI_MONTHLY_BUDGET_USD`), `pro` (`STRIPE_PRICE_PRO`, `BILLING_PRO_AI_BUDGET_USD`, default 50), pack `credits_10` ($10). The client sends a plan or pack **key**, never a price id or amount. `App\Billing\Plans::for($organization)` says which plan it's on (first valid subscription).
- **The plan's budget is a ceiling.** `AiBudget::limit` = `min(own ?? plan, plan)` + credits bought this UTC month; an own budget of 0 still turns AI off. `PUT /api/ai-budget` refuses more than the plan ("Upgrade to Pro for a higher budget"), replacing today's flat $10,000 cap. Credits expire at the end of the UTC month they're bought in (the page says until when).
- **Past due keeps Pro** (`Cashier::keepPastDueSubscriptionsActive`) while Stripe retries a renewal.
- **Permission `billing.manage`** ("Manage the plan, payment details and invoices", group Organization). No default role gets it, so Owner only (like `ai_budget.manage`); a custom role can be given it. No data migration: Owner has every permission.
- **Audit**: `billing.plan_changed` (`plan: old → new`, recorded by `TrackPlanChange` around each subscription webhook, comparing the plan as Stripe reports it before and after under a lock on the organization row; a cancel at period end records nothing until Stripe deletes it) and `billing.credits_purchased`. Plan changes have no actor (Stripe did it), shown as "Stripe"; credits name the buyer while still a member.
- **Credits are granted once per Checkout session** (`ai_credit_purchases.stripe_checkout_session_id` unique), only for `mode=payment` and `payment_status=paid`, for the organization that owns the Stripe customer *and* matches `client_reference_id` (set by our checkout); the amount comes from config, never the event. Not sold while the own budget is $0.
- **Invoices are read live from Stripe** (`BillingGateway::invoices`), never stored. 20 per page with Stripe's cursor (`starting_after`, validated `in_[A-Za-z0-9_]+`, always listed for the organization's own customer, so another organization's invoice id leads nowhere). Drafts hidden; open ones show "Due" with a "Pay" link. Only `https` links on `stripe.com` hosts reach the client.
- **One `stripe` rate limiter** on every route that calls Stripe: 20 a minute per user, and 60 per organization counted only for people with `billing.manage` (so Members' 403s can't lock the owner out). JSON 429. `GET /api/billing` makes no Stripe call and isn't throttled by it.
- **Deleting your account is refused while you're the only member of an organization Stripe still bills** (422 on `account`: "Cancel the Pro plan of X first"). Otherwise the cascade would delete the organization and Stripe would keep charging.
- **Fake checkouts in e2e complete themselves.** With `BILLING_PROVIDER=fake` and `BILLING_FAKE_COMPLETE_CHECKOUT=true` (only `servers.sh` sets it), the fake writes the subscription or the credit purchase the way the webhook would, so flows and screenshots can show Pro and credits. In tests it only records the call.
- **One sidebar item, "Billing"** (`billing.manage`), active on `/billing` and `/billing/invoices`; the invoices page is linked from the billing page. saas-starter added two items, which meant retaking every owner screenshot twice.

## Backend (`api/`)
- `composer require laravel/cashier`. Migrations (new files, adapted from Cashier's, which assume `users`):
  - Cashier columns on `organizations` (`stripe_id` unique, `pm_type`, `pm_last_four`, `trial_ends_at`).
  - `subscriptions` and `subscription_items` with `organization_id` (foreign key, `cascadeOnDelete`, indexed with `stripe_status`). Not tenant-scoped by a global scope (Cashier's model): reached only through `$organization->subscriptions()` or `Plans`, never `Subscription::query()`.
  - `ai_credit_purchases`: `organization_id`, `user_id` nullable (`nullOnDelete`), `pack`, `amount_usd` decimal(10,2), `stripe_checkout_session_id` unique, timestamps; index `(organization_id, created_at)`.
- `config/billing.php` (provider, plans, credit packs). `config/cashier.php` published, currency `usd`.
- `app/Billing/`: `Plan`, `Plans`, `CreditPack`, `CreditPacks`, `Invoice`, `InvoicePage` (readonly values), `Contracts/BillingGateway` (`subscriptionCheckoutUrl`, `creditsCheckoutUrl`, `portalUrl`, `invoices`), `StripeBillingGateway` (every Stripe call through one `call()` that turns network and 5xx errors into `Exceptions\BillingUnavailable`, 503), `FakeBillingGateway` (records calls; `setInvoices()`; demo invoices from the organization's subscriptions and credit purchases).
- `Organization`: `Billable`; `stripe_id` and the card columns `$hidden` and never fillable.
- `AppServiceProvider`: bind the gateway from `config('billing.provider')`; `Cashier::useCustomerModel`, `ignoreRoutes`, `keepPastDueSubscriptionsActive`; the `stripe` limiter; refuse `fake` in production.
- `App\Enums\Permission::BillingManage`; `AuditAction::PlanChanged`, `AiCreditsPurchased` (label, group "Billing").
- `App\Policies\BillingPolicy` (`manage`), checked before validation.
- `App\Ai\AiBudget`: plan ceiling plus credits; `summary()` adds `plan`, `plan_limit_usd`, `credits_usd`, `credits_expire_at`. `UpdateAiBudgetRequest`: `max` = the plan's budget.
- Actions (`app/Actions/Billing/`): `BillingOverview`, `StartSubscriptionCheckout` (refused with 422 while a subscription is still billed), `StartCreditsCheckout`, `OpenBillingPortal` (only for a Stripe customer), `ListInvoices` (no customer: empty, no call), `TrackPlanChange`, `GrantAiCredits`.
- `App\Actions\Account\DeleteAccount`: the paid-subscription refusal above.
- Requests (`app/Http/Requests/Billing/`): `StartCheckoutRequest` (`plan` in the paid plan keys that have a price), `StartCreditsCheckoutRequest` (`pack` in the pack keys), `ListInvoicesRequest` (`starting_after`).
- Responses: `GET /billing` returns `BillingOverview`'s array, as `AiUsageController` does (plan, status, `ends_at`, `on_grace_period`, card brand and last four, the plans and packs with their display prices and whether they can be bought, the AI budget summary, `can`), `InvoiceResource` (`id, number, status, total, currency, description, created_at, hosted_url, pdf_url`; `meta.next_cursor`).
- `BillingController` (`CurrentOrganization` as an action parameter) and `StripeWebhookController` (subscription created / updated / deleted through `TrackPlanChange`, a late `updated` for an already canceled subscription ignored, events without a known customer ignored, a second paid subscription logged as a warning; `checkout.session.completed` and `async_payment_succeeded` → `GrantAiCredits`).
- Routes (`routes/api.php`):
  - in the `organization` group: `GET /billing`; `POST /billing/checkout`, `POST /billing/credits/checkout`, `POST /billing/portal` (`verified`, `throttle:stripe`); `GET /billing/invoices` (`throttle:stripe`). Success and cancel URLs point at `FRONTEND_URL/billing?checkout=success|canceled`; the portal returns to `/billing`.
  - outside auth: `POST /stripe/webhook` (`VerifyStripeWebhook`, `throttle:120,1,stripe-webhook`).
- `.env.example`: `BILLING_PROVIDER`, `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_PRO`, `BILLING_PRO_DISPLAY_PRICE`, `BILLING_PRO_AI_BUDGET_USD`, `STRIPE_PRICE_CREDITS_10`, `BILLING_CREDITS_10_DISPLAY_PRICE`, `BILLING_FAKE_COMPLETE_CHECKOUT`, each with a comment. `servers.sh` starts the e2e API with `BILLING_PROVIDER=fake BILLING_FAKE_COMPLETE_CHECKOUT=true`.

## Frontend (`web/`)
- `types/api.ts`: `Billing`, `BillingPlan`, `CreditPack`, `Invoice`, `InvoiceStatus`; `AiUsage` budget gains `plan`, `plan_limit_usd`, `credits_usd`, `credits_expire_at`.
- `features/billing/api.ts`: `billingQuery` (`['org', id, 'billing']`), `useStartCheckout`, `useBuyCredits`, `useOpenPortal` (each answers `{url}` and the hook sends the browser there with `window.location.assign`), `invoicesInfiniteQuery` (`['org', id, 'billing', 'invoices']`, `useInfiniteQuery` on `meta.next_cursor`).
- `routes/BillingPage.tsx` (`/billing`): current plan and status (renews / ends on / past due), card, "Upgrade to Pro" or "Manage billing" (portal), the credit pack with "Buy", the AI budget meter with credits, "Invoices" link. `?checkout=success` → toast, then poll `billingQuery` (`refetchInterval` 2s, at most 30s) until the plan or the credits change from what they were before leaving, then drop the param (`replace`); `?checkout=canceled` → a neutral note. Loading, error and no-permission states.
- `routes/InvoicesPage.tsx` (`/billing/invoices`): table (Date, Number, Description, Amount, Status badge, View / PDF / Pay links opening in a new tab with `rel="noopener noreferrer"`), "Load more", loading, empty ("No invoices yet…"), 503 ("Stripe is unavailable, try again"), no-permission states. Number and Description hidden at 390px. Amounts with `Intl.NumberFormat('en-US', { style: 'currency', currency })`, dates in UTC.
- `router.tsx`: both routes; `AppSidebar` `navigation`: "Billing" (`CreditCardIcon`, `permission: 'billing.manage'`), active on its sub-route.
- `features/aiUsage/BudgetDialog.tsx`: the maximum is `plan_limit_usd` (message names the plan and suggests upgrading); the AI usage page shows credits in the budget.

React concepts:
- Leaving the app for Stripe with `window.location.assign(url)` from a mutation's `onSuccess` ↔ `navigateTo(url, { external: true })`: React Router's `navigate` only moves inside the app.
- Conditional polling: `refetchInterval` as a function of the query's data (return `false` to stop) ↔ a `setInterval` in `onMounted` cleared in `onUnmounted`; TanStack Query stops it when the page unmounts or the key changes (organization switch).
- One-shot URL flags (`?checkout=success`) read with `useSearchParams` and removed with `setSearchParams(..., { replace: true })` once handled ↔ `router.replace({ query })`.
- Nested routes sharing a sidebar entry: `NavLink`'s `end` prop decides whether `/billing/invoices` keeps "Billing" active ↔ Nuxt's `exact-active-class` vs `active-class`.
- Cursor pagination with `useInfiniteQuery` (`getNextPageParam` from `meta.next_cursor`), reused from the audit log, for an external API this time.

## Tests
Pest (`tests/Feature/Billing/`), with `$this->billing` (the fake) bound in `TestCase`, `subscribe($org)` writing a subscription row and `stripeWebhook($type, $object)` posting a signed event (`tests/Pest.php`):
- **Overview**: Free by default, Pro after `subscribe()`, past due still Pro, canceled-on-grace shows `ends_at`; no gateway call.
- **Checkout / credits / portal**: return the fake's URL with the right organization, user and key; invalid plan or pack key → 422; checkout refused while subscribed; portal refused without a Stripe customer; credits refused while the own budget is $0; unverified email → 403 `email_unverified`; `BillingUnavailable` → 503.
- **Permission table** on every billing route: Owner 200; Admin and Member 403; a custom role with `billing.manage` 200; 403 before 422.
- **Webhook**: bad or missing signature → 403, no secret configured → refuses everything; subscription created / deleted switch the plan and audit `billing.plan_changed` once (retried events don't add entries); late `updated` after cancel ignored; unknown customer ignored; credits: unpaid ignored, unknown pack ignored, duplicate session granted once, amount from config, audited.
- **AI budget**: Free and Pro limits, own budget lower than the plan, `PUT /api/ai-budget` above the plan → 422, credits count only in their month, own budget 0 turns AI off with credits.
- **Invoices**: order and fields; `next_cursor` and `starting_after` pass through; bad cursor (`abc`, `in_../x`) → 422; non-Stripe URLs become `null` (`StripeBillingGateway` mapping with a stubbed Stripe HTTP client); no customer → empty, no call; unauthenticated 401.
- **Rate limit**: the 21st call in a minute → 429; three owners share 60; Members' 403s don't count toward the organization.
- **Delete account**: refused while the only member of a subscribed organization; allowed once it's canceled and ended.
- **Org A vs B**: A's owner sees only A's billing and invoices (`organization_id=B` in the query or body ignored); the gateway is only called with A; B's invoice id as a cursor stays in A's listing; a webhook for B's customer changes and credits B only; a session whose `client_reference_id` names A but whose customer is B's grants nothing; A using its 60 calls doesn't limit B.

Vitest:
- `BillingPage`: Free with "Upgrade" starts checkout and goes to the URL; Pro shows "Manage billing"; `?checkout=success` polls until Pro, then removes the param; canceled note; no-permission state; error state.
- `InvoicesPage`: rows, statuses and links, "Load more", empty, 503 message.
- `AppSidebar`: "Billing" only with `billing.manage`.
- `BudgetDialog`: the plan's maximum.

## README screenshots
- New: `billing.png` (an owner on Pro with credits bought, budget meter), `invoices.png` (a Pro invoice and a credits invoice from the fake).
- Retaken: `ai-usage.png` (credits in the budget), and the shots of owner pages, since their sidebar gains "Billing": `home.png`, `organization-switcher.png`, `members.png`, `tasks.png`, `task-detail.png`, `task-summary.png`, `task-writing-help.png`, `task-chat.png`, `roles.png`, `role-edit.png`, `audit-log.png`, `account.png`, `two-factor-setup.png` (each checked first; any that turns out not to show the sidebar stays).
- Captured as before: e2e demo data, light mode, 1280×800, `e2e-*` accounts cleaned up. `BILLING_PROVIDER=fake`, so nothing reaches Stripe.

## Verification
`verify` skill (Pint, Pest, lint, typecheck, Vitest, build); `e2e-smoke` with the fake provider; reviewers in parallel on the diff: code, security, tenant isolation, database (migrations, webhook locking), UI (billing and invoices pages). A manual test-mode run with real Stripe keys and `stripe listen --forward-to localhost:8100/api/stripe/webhook` is left to you (README gets the steps): subscribe with `4242 4242 4242 4242`, see the Pro budget, cancel in the Portal, buy credits, view an invoice and its PDF.

Flows (for `flow-tester`, fake provider with self-completing checkouts):
1. Register an owner, verify the email, open Billing → Free plan, the free AI budget, "Upgrade to Pro".
2. Upgrade → back on Billing with a success toast, then Pro and the Pro budget; the audit log shows "Plan changed: Free → Pro" by Stripe.
3. Buy $10 of credits → the budget goes up by $10 and says until when; AI usage shows the same budget.
4. Open AI usage, set a budget above the Pro budget → refused with the upgrade message; a lower one is saved.
5. Open Invoices → a Pro invoice and a credits invoice, each with a View link.
6. Invite a member, join as them → no "Billing" in the sidebar; `/billing` directly shows the no-access state; the API answers 403.
7. As the owner, switch to another organization → Billing shows that one's Free plan, no invoices.
8. Try to delete the owner's account while the Pro organization is theirs alone → refused naming it.
9. Post a webhook with a bad signature (curl) → 403, nothing changes.

## Changes during the build
- **No `plan_limit_usd` in the budget summary:** `default_limit_usd` is now the plan's budget (what applies without an own budget, and the most one can be), plus `plan` (its name), `credits_usd` and `credits_expire_at`. Same meaning, no second field.
- **The credits checkout returns to `/billing?checkout=credits`** (a plan to `?checkout=success`), so the page knows what to wait for. It compares the credits against the amount saved in `sessionStorage` before leaving.
- **`RecordAudit(..., withoutActor: true)`:** with the fake provider a logged-in owner triggers what the webhook would, and would otherwise be recorded as the actor of Stripe's plan change. Plan changes never have an actor; credits name the buyer only while a member. Both carry `metadata.source = stripe`, and the audit log shows "Stripe".
- **Plan changes are audited with plan names** (`Free → Pro`), not keys, so entries read on their own.
- **`errorMessage()` shows a 503 `billing_unavailable`'s message** ("Billing is unavailable right now..."); other 5xx stay generic.
- **`lib/leaveApp.ts`** wraps `window.location.assign`, so tests can check the redirect to Stripe (jsdom can't navigate).
- **The organization row is locked `FOR NO KEY UPDATE`** in `TrackPlanChange` and `GrantAiCredits` (saas-starter used `FOR UPDATE`), like `DeleteAccount` and member actions here: they serialize with each other, while inserts that point at the organization (tasks, AI usage) aren't blocked.
- **Review fixes:** the webhook's "already canceled" check runs under the organization lock (a concurrent "deleted" event could otherwise be undone by a late "updated" one); `TrackPlanChange` and `GrantAiCredits` do nothing for an organization deleted meanwhile (no 500s retried for days); creating the Stripe customer locks the row `FOR NO KEY UPDATE`; the budget summary sums credits once; `ai_credit_purchases.user_id` is indexed; the fake provider is allowed only with `APP_ENV` local or testing (not just "not production", so a staging copy can't hand out Pro); more org A vs B webhook tests.
- **Code review fixes:** deleting your account isn't blocked by a plan canceled at the period end (it won't renew: `Plans::renewingSubscriptions`); the billing buttons are enabled again when the browser's Back button brings the page from its back/forward cache (`pageshow`); unverified emails are tested on the three paying routes; Stripe configuration errors are logged as errors, outages as warnings (both still a 503); credits read "through Nov 30" rather than "until Dec 1"; without a remembered credit amount the page doesn't wait.
- **Screenshots:** only `billing.png`, `invoices.png` and `ai-usage.png` were taken. The other owner shots weren't retaken for the new "Billing" sidebar item: they already predate Roles, Audit log and AI usage (e.g. `tasks.png` shows three items), and earlier plans didn't retake them either. Retaking them all is a separate docs task if wanted.
- **One API commit** in place of two: invoices were ported with the rest.

## Follow-ups
- Refunds and disputes don't take AI credits back (`charge.refunded`, `charge.dispute.created`); adjust by hand in Stripe meanwhile.
- Two checkouts opened in two tabs can both be paid; `TrackPlanChange` logs a warning so the extra subscription can be refunded.
- `GrantAiCredits` trusts our own `metadata.pack`; as defense in depth it could also check the session's `amount_total` and currency against the pack's price.
- The webhook's `throttle:120,1` is per IP; Stripe sends from a few IPs, so a busy app may need a higher limit there.
- Retake the older owner screenshots (they predate Roles, Audit log, AI usage and Billing in the sidebar) as one docs task.
- `composer update phpunit/phpunit` for the dev-only advisory (GHSA-9vw2-f4vp-x7gf), present before this plan.
- Production: in Stripe, set "If all retries for a payment fail" to cancel the subscription, and keep plan switching off in the Customer Portal.

## Commits after approval
- `feat(api): add Stripe billing with Pro plan, AI credits and invoices` (Cashier, gateway, webhook, budget ceiling, invoices, rate limit, delete-account refusal; `.env.example`, CLAUDE.md "Billing" section, README setup)
- `feat(web): add billing and invoices pages` (with README features and screenshots)
- this plan → Done
