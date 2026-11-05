# 18 — Outgoing webhooks

**Status:** Done
**Commits:** `3b7d337` (api), `f2d8a1c` (web), `f42aa3d` (claude)

## Goal
An organization can register **webhook endpoints** (HTTPS URLs) and receive a signed JSON POST when its tasks are created, edited or deleted. Each send is a queued **delivery** with retries, logged so the owner can see what was sent, what came back, and resend it. This is the first automation feature: it brings over saas-starter's "Automation conventions" (which saas-starter wrote but never built), adapted, as a CLAUDE.md section.

Out of scope:
- incoming webhooks from other services (Stripe's stays the only one);
- events other than tasks (members, roles, billing): the enum makes adding them a small follow-up;
- emailing anyone when an endpoint keeps failing (it's disabled and shown on the page);
- a separate queue or worker for deliveries; ordering guarantees (receivers order by the payload's timestamps);
- limiting webhooks to the Pro plan (every plan gets them).

## Decisions
- **Domain events → a small listener → one queued job per delivery.** `TaskCreated` exists; add `TaskUpdated` and `TaskDeleted`. All three implement `ShouldDispatchAfterCommit`, so a rolled-back change sends nothing. The listener `QueueWebhookDeliveries` runs in the request: it builds the payload once (the task as it is now; a deleted task's snapshot travels in the event, since the row is gone), inserts a `webhook_deliveries` row per active endpoint subscribed to that event, and dispatches `DeliverWebhook` for each. It runs under `rescue()`: webhooks never fail a task change.
- **`RemoveMember` sends `task.updated`** for each task it unassigns. Otherwise receivers would keep an assignee who left. *(Built: it locks those task rows `FOR UPDATE` as it reads them, so a task reassigned at the same moment keeps its new assignee, and dispatches one `TasksUnassigned` event for all of them, which the listener handles in chunks. Database review.)*
- **Signed like Stripe's.** Each endpoint has its own secret (`whsec_` + 32 random bytes, `encrypted` cast, `$hidden`), shown **once**: in the create answer and after "Roll secret". Headers: `X-Webhook-Id` (the event's uuid, the same on a resend so receivers can dedupe), `X-Webhook-Timestamp` (unix seconds), `X-Webhook-Signature: v1=<hex hmac_sha256(secret, "{timestamp}.{body}")>`. The README shows how to verify it (timestamp within 5 minutes, `hash_equals`).
- **Payload**: `{id, type, created_at, data}`. Task events: `data.task` = `id, title, description, status, priority, due_date, assignee {id, name} | null, created_by, created_at, updated_at` (`App\Webhooks\WebhookPayload::task()`, not `TaskResource`, which depends on who's asking); `task.updated` adds `data.changed` (the field names). No emails, no organization data beyond `{id, name}`. `ping` (Send test event): `data.endpoint.id`.
- **SSRF is the main risk: the server POSTs to a URL a user typed.** `App\Webhooks\WebhookUrl` refuses non-`https`, credentials in the URL, and hosts that resolve to anything but public addresses (loopback, private, link-local and the cloud metadata address `169.254.169.254`, CGNAT `100.64/10`, multicast, reserved, IPv6 ULA/link-local, IPv4-mapped IPv6), checked with an explicit CIDR list. Checked when saving (422 on `url`) **and** again on every send, and the connection is pinned to the address just checked (`CURLOPT_RESOLVE`), so DNS rebinding can't swap it. Redirects are not followed (a 3xx is a failure). DNS goes through `App\Webhooks\Contracts\HostResolver` so tests fake it. *(Built, after the security review: hosts must be plain ASCII, an IDN in its `xn--` form and no trailing dot, since curl would otherwise look up a different name than the one pinned; only the URL's protocol is allowed; the address curl used is compared with the checked one; and a host that can't be found gets the same message as a private one, so validation can't map internal DNS.)*
- **e2e needs a local receiver**, so `WEBHOOKS_ALLOWED_PRIVATE_TARGETS` (e.g. `127.0.0.1:8113`): exact `host:port` pairs that skip the public-address check and may use `http`. The app refuses to boot with it set unless `APP_ENV` is `local` or `testing` (like `BILLING_PROVIDER=fake`). Only `servers.sh` sets it; the metadata address stays refused in e2e.
- **Retries the delivery owns, not the queue's.** Each job makes one attempt (10s HTTP timeout, 5s connect, job timeout 20s). *(Built: the job claims its attempt with a conditional update, so a duplicate run does nothing; its `retryUntil` counts from when the attempt is due, since the queue fixes it at push time without the delay, which would have failed every retry after the second (code review); on the sync queue a failed attempt leaves the next pending instead of running all six at once. Deliveries run on their own `webhooks` queue with a per-organization `RateLimited('webhooks')`, so one tenant can't hold up mail and AI jobs (security review).)* 2xx succeeds. Anything else records the attempt (status code, first 1 KB of the body, or the error: "Timed out", "Connection refused", "Address not allowed") and, until 6 attempts, dispatches the next one delayed 1m, 5m, 30m, 2h, 8h (`next_attempt_at` on the row, so the page shows it). A job that dies (`failed()`) counts as a failed attempt. The job sends nothing if the endpoint was disabled, deleted or unsubscribed meanwhile (`canceled`).
- **An endpoint that keeps failing is disabled**: 15 deliveries in a row that used up their attempts (`consecutive_failures`, reset by any success, updated on the locked endpoint row). It records `webhook.disabled` with no actor. Turning it back on resets the count.
- **Permission `webhooks.manage`** ("Manage webhooks", group Organization). No default role gets it, so Owner only: an endpoint sends every task's content out of the app, a bigger power than editing tasks. A custom role can be given it. No data migration.
- **At most 5 endpoints per organization** (checked with the organization row locked). Each subscribes to at least one of `task.created`, `task.updated`, `task.deleted` (`App\Enums\WebhookEvent`).
- **`verified`** on everything that creates or changes an endpoint or sends (it reaches outside the app).
- **Audit**: `WebhookEndpoint` is `Auditable` (`webhook.created|updated|deleted`), with the URL recorded as its host only (a URL's path can itself be a secret, and Admins read the log without `webhooks.manage`); plus `webhook.secret_rolled` and `webhook.disabled`. Deliveries aren't audited (they're their own log).
- **Support view**: the deliveries route is `not-impersonating` (payloads hold task content), and endpoint URLs show only their host (`url_hidden`). Writes are already refused there.
- **Deliveries are kept 30 days** (`WEBHOOK_DELIVERY_RETENTION_DAYS`, `MassPrunable`, already in the daily `model:prune`).

**No new dependency.** New `.env.example` keys, each with a comment: `WEBHOOKS_ALLOWED_PRIVATE_TARGETS`, `WEBHOOK_DELIVERY_RETENTION_DAYS`, `WEBHOOK_QUEUE`, `WEBHOOK_RATE_LIMIT_PER_MINUTE`. The queue worker becomes `queue:work --queue=default,webhooks`.

## Backend (`api/`)
- Migrations (new):
  - `create_webhook_endpoints_table`: `organization_id` FK `cascadeOnDelete`, `url` (2048), `description` nullable, `events` jsonb, `secret` text (encrypted), `disabled_at` nullable, `disabled_reason` nullable (`manual`, `failing`), `consecutive_failures` int default 0, timestamps; unique `(organization_id, id)` for the composite key below.
  - `create_webhook_deliveries_table`: `organization_id`, `webhook_endpoint_id` with composite FK `(organization_id, webhook_endpoint_id)` → endpoints `cascadeOnDelete`; `event_id` uuid, `event` string, `payload` jsonb, `status` (`pending`, `succeeded`, `failed`, `canceled`), `attempts`, `response_status` nullable, `response_body` nullable (1 KB), `error` nullable, `duration_ms` nullable, `next_attempt_at` nullable, `delivered_at` nullable, timestamps; index `(organization_id, webhook_endpoint_id, id)` for the cursor list, index `created_at` for pruning.
- Models: `WebhookEndpoint` (`BelongsToOrganization`, `Auditable`, `$hidden` secret, fillable `url`, `description`, `events`; `isActive()`, `subscribesTo()`), `WebhookDelivery` (`BelongsToOrganization`, `MassPrunable`, `endpoint()`).
- `config/webhooks.php`: allowed private targets, retention, attempts and delays, timeouts, max endpoints, failure threshold.
- `app/Webhooks/`: `WebhookUrl` (validate, resolve, pin), `Contracts/HostResolver` + `DnsHostResolver`, `WebhookPayload`, `WebhookSigner` (`sign($secret, $timestamp, $body)`), `WebhookSecret::generate()`.
- Enums: `WebhookEvent` (label), `WebhookDeliveryStatus`; `Permission::WebhooksManage`; `AuditAction::WebhookCreated|Updated|Deleted|SecretRolled|Disabled` (group "Webhooks").
- Events: `TaskUpdated(taskId, organizationId, changed)`, `TaskDeleted(organizationId, array task)`, `TasksUnassigned(organizationId, taskIds)`; `TaskCreated` gains `ShouldDispatchAfterCommit`. Dispatched by `UpdateTask` (only when something changed), `DeleteTask` (snapshot taken before the delete), `RemoveMember`.
- Listener `QueueWebhookDeliveries` (all three events; inside `CurrentOrganization::run()` for the event's organization; a single insert for the rows).
- Job `DeliverWebhook extends OrganizationJob` (delivery id): loads the delivery and its endpoint in the scope, signs, sends through `Http::withOptions` (pinned address, no redirects), records the attempt, schedules the next or settles the endpoint's failure count.
- `App\Policies\WebhookEndpointPolicy` (`viewAny`, `view`, `create`, `update`, `delete`: `webhooks.manage`), checked in the Form Requests before validation.
- Actions (`app/Actions/Webhooks/`): `CreateWebhookEndpoint` (limit, secret), `UpdateWebhookEndpoint` (re-enabling resets the failure count), `DeleteWebhookEndpoint`, `RollWebhookSecret`, `SendTestWebhook` (a `ping` delivery), `ResendWebhookDelivery` (a new row with the same `event_id` and payload), `QueueDelivery` (row + job, shared by the listener, test and resend).
- Requests: `StoreWebhookEndpointRequest`, `UpdateWebhookEndpointRequest` (`url` via the `WebhookUrl` rule, `description` max 200, `events` array of `WebhookEvent` values, distinct, min 1; `enabled` boolean on update), `ListWebhookDeliveriesRequest` (`cursor`).
- Resources: `WebhookEndpointResource` (`id, url, url_hidden, host, description, events, enabled, disabled_reason, last_delivery {status, created_at} | null, created_at`; `secret` only when the action just made it), `WebhookDeliveryResource` (`id, event_id, event, status, attempts, response_status, response_body, error, duration_ms, next_attempt_at, delivered_at, created_at, payload`).
- `WebhookController` (index, store, show, update, destroy, rollSecret, test), `WebhookDeliveryController` (index, resend); `CurrentOrganization` as an action parameter.
- Routes, in the `organization` group, `{webhook}` and `{delivery}` bound through the scope (`scopeBindings`, delivery belongs to webhook), `throttle:60,1,webhooks`:
  - `GET /webhooks`, `GET /webhooks/{webhook}`;
  - `POST /webhooks`, `PATCH /webhooks/{webhook}`, `POST /webhooks/{webhook}/secret` (`verified`); `DELETE /webhooks/{webhook}`;
  - `POST /webhooks/{webhook}/test` and `POST /webhooks/{webhook}/deliveries/{delivery}/resend` (`verified`, plus `throttle:10,1,webhook-send`);
  - `GET /webhooks/{webhook}/deliveries` (`not-impersonating`, cursor pages of 20).
- `AppServiceProvider`: bind `HostResolver`; refuse `WEBHOOKS_ALLOWED_PRIVATE_TARGETS` outside local/testing; listener registration (auto-discovery already finds `QueueTaskSummary`; the new one the same way).
- `.claude/skills/e2e-smoke/servers.sh`: a queue worker of its own on `e2e,e2e-webhooks` (without `--mail`, jobs used to wait on `e2e` forever), start a receiver on `:8113` (`php -S` running `webhook-receiver.php`: logs each request's headers and body to a file; `/fail` answers 500), API with `WEBHOOKS_ALLOWED_PRIVATE_TARGETS=127.0.0.1:8113`; `servers.sh webhooks` prints what it received. `flow-tester-servers.sh` hook allows `webhooks`.
- Pest helpers: `createWebhook($org, [...])`, `fakeDns(['example.com' => ['93.184.216.34']])`.

## Frontend (`web/`)
- `types/api.ts`: `WebhookEndpoint`, `WebhookEvent`, `WebhookDelivery`, `WebhookDeliveryStatus`.
- `features/webhooks/api.ts`: `webhooksQuery` (`['org', id, 'webhooks']`), `webhookQuery(id)` (`['org', id, 'webhooks', webhookId]`), `deliveriesInfiniteQuery` (under the webhook's key, `useInfiniteQuery` on the cursor, polling every 3s while a delivery on the loaded pages is `pending`, stopping after 2 minutes); mutations `useCreateWebhook`, `useUpdateWebhook`, `useDeleteWebhook`, `useRollSecret`, `useSendTestWebhook`, `useResendDelivery` (invalidate the list, the detail and deliveries).
- `routes/WebhooksPage.tsx` (`/webhooks`): explanation line and a docs link (README section), the endpoints as a table (URL, events, status badge Active / Disabled / Failing, last delivery), "Add endpoint" (hidden at 5), loading, empty ("No webhooks yet…"), error and no-permission states.
- `features/webhooks/WebhookForm.tsx` (dialog on create, card on the detail page): URL, description, events as a checkbox group (`Controller` with an array value), zod schema, 422s onto fields.
- `features/webhooks/SecretDialog.tsx`: the secret once, monospace, a Copy button ("Copied" for 2s), "I've saved it" to close; the secret never goes into the query cache.
- `routes/WebhookDetailPage.tsx` (`/webhooks/:id`): settings form, Enabled switch (with why it was disabled), Roll secret and Delete (`ConfirmDialog`), Send test event; the deliveries table (when, event, status badge, attempts, response code, duration; next attempt for pending ones), a row expands (shadcn `Collapsible`) to the request body (`<pre>`, as text, never HTML) and the response; Resend on each; "Load more". A deleted or another organization's id → not found.
- `router.tsx`: both routes; `AppSidebar` `navigation`: "Webhooks" (`WebhookIcon`, `permission: 'webhooks.manage'`), active on its detail pages.
- Switching organization on a detail page goes to `/webhooks` (the existing section-root rule).
- No Switch component: Turn on / Turn off is a button (no new shadcn component needed).

React concepts:
- `refetchInterval` as a function of the loaded pages ↔ a `setInterval` you'd start and clear yourself in Vue (or `useIntervalFn` with a `watch`): TanStack Query decides each time from the data.
- A checkbox group bound to one array field through `Controller` ↔ `v-model` on several checkboxes with an array `ref`.
- One-time data kept in a mutation's result / component state, never the cache ↔ a local `ref` that disappears with the component instead of `useState()` shared state.
- A timer in `useEffect` with a cleanup (the "Copied" label) ↔ `onBeforeUnmount(clearTimeout)`.
- Expandable table rows with each row's open state inside its own component ↔ a child component with its own `ref`, instead of a map of open ids in the parent.

## Tests
Pest:
- **Permissions** (dataset over every route): Owner allowed; Admin and Member 403 (before validation: an invalid body is still 403); a custom role with `webhooks.manage` allowed; unverified 403 `email_unverified` on writes and sends; no organization 403.
- **Org A cannot read or write org B**: B's endpoint id → 404 on show, update, delete, roll, test, deliveries; B's delivery under A's endpoint → 404 on resend; A's task events never create deliveries for B's endpoints; listing shows only A's.
- **Validation**: `http`, credentials, an IP literal or a host resolving to 127.0.0.1, 10.x, 192.168.x, 169.254.169.254, 100.64.x, `::1`, `fd00::`, `::ffff:127.0.0.1`, or a mix of public and private → 422; public → ok; events empty, unknown or duplicated → 422; the 6th endpoint → 422. The allowed private target works only for that exact host and port, and boot refuses it in production.
- **Secret**: shown in the create and roll answers only; never in show or index; stored encrypted; rolling changes it and is audited without it.
- **Events**: create, edit (only when something changed, with `changed`), delete (the snapshot) each queue one delivery per subscribed active endpoint, none for disabled or unsubscribed ones; a rolled-back transaction queues nothing; a failing listener doesn't fail the task request; `RemoveMember` queues `task.updated` for each unassigned task.
- **Delivery** (`Http::fake`, fake DNS): body and headers; the signature verifies with the secret and fails with another; pinned to the checked address; a host that now resolves privately → failed "Address not allowed", nothing sent; a redirect not followed; 500 and a timeout → attempt recorded and the next job dispatched with the right delay; the 6th failure → `failed`, no next; success resets `consecutive_failures`; the 15th failed delivery disables the endpoint and audits `webhook.disabled`; an endpoint disabled or deleted before the job → `canceled`, nothing sent; response body cut to 1 KB.
- **Resend** keeps `event_id` and payload, new row; **test** sends `ping`.
- **Audit**: created/updated/deleted record the host, never the full URL or secret.
- **Support view**: deliveries 404/403 (`not-impersonating`), endpoints show the host only, writes refused.
- **Pruning**: deliveries older than the retention removed.

Vitest:
- Webhooks page: list, empty, no-permission, error; nav item only with `webhooks.manage`; add endpoint → secret dialog → copy; 422 onto the URL field; the limit hides Add.
- Detail page: edit and save, disable/enable, roll secret (confirm, then the new secret), delete (confirm, back to the list), send test; deliveries expand to show the payload as text; resend; polling while pending stops when settled; not found.

## README screenshots
- `docs/screenshots/webhooks.png`: the webhook detail page for an Acme e2e owner, with an endpoint pointing at the local receiver and a few deliveries (succeeded, one failed with its response expanded). Shown in a new README "Webhooks" feature section (what it is, in plain words); the developer details (events, payload, verifying the signature in PHP and Node) go in `docs/api.md`.

## Verification
`verify` skill (Pint, Pest, lint, typecheck, Vitest, build), `e2e-smoke`, then reviewers: code, security (SSRF and secrets above all), tenant isolation, database, UI.

Flows (for `flow-tester`, servers started with the receiver):
1. As an owner, open Webhooks, add `http://127.0.0.1:8113/hook` subscribed to all three events → the secret shows once; closing it and reopening the endpoint shows no secret.
2. Add an endpoint at `https://169.254.169.254/` → error on the URL field, nothing saved.
3. Create a task → within seconds the detail page shows a succeeded `task.created` delivery; `servers.sh webhooks` shows the request, and its `X-Webhook-Signature` matches an HMAC computed with the secret from step 1 (openssl).
4. Edit the task's status, then delete it → `task.updated` (with `changed: ["status"]`) and `task.deleted` deliveries, both succeeded.
5. Point the endpoint at `/fail`, Send test event → delivery failed with 500 and a next attempt time; point it back, Resend → succeeded, same `X-Webhook-Id` at the receiver.
6. Untick `task.created`, create a task → no new delivery. Disable the endpoint, edit a task → none.
7. Roll the secret → new secret shown; the next delivery's signature matches the new one, not the old.
8. As an Admin of the same organization → no Webhooks nav item; `/webhooks` shows no permission; the audit log shows the webhook entries with the host only.
9. As a member of another organization → opening the first organization's `/webhooks/{id}` shows not found.

## Commits after approval
`feat(api): outgoing webhooks for task events`, `feat(web): webhooks page and delivery log`, `chore(claude): webhook receiver for e2e servers`, then this plan → Done (with the CLAUDE.md "Automation and webhooks (plan 18)" section replacing "Later conventions", README and `.env.example` in the same commits as their code).

## Follow-ups
- e2e never reaches a delivery's final `failed` state (the retry schedule takes about 10 hours) and then resends it. Pest covers both halves. A test-only short `retry_delays` on the e2e servers would let flow-tester check it.
- Dev databases that ran the first version of `create_webhook_deliveries_table` have the index `(webhook_endpoint_id, id)` instead of `(organization_id, webhook_endpoint_id, id)`. Only speed is affected, and only before this plan was committed.
- More events (member joined, invitation accepted) can go through the same listener, `WebhookEvent` cases and `WebhookPayload`.
