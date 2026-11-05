# API reference

Every endpoint of the Laravel API, for developers. For what the product does, see the [README](../README.md).

Locally the API answers on `http://localhost:8100/api`:

| Endpoint | What it does |
|---|---|
| `POST /api/register` | Create an account (`name`, `email`, `password`, `password_confirmation`); returns `{user, token}` |
| `POST /api/login` | `email`, `password`; returns `{user, token}`, or `{two_factor: true, challenge}` when two-factor is on |
| `POST /api/two-factor-challenge` | `challenge` plus `code` (6 digits from the app) or `recovery_code`; returns `{user, token}`. A challenge lasts 5 minutes and 5 tries; 10 wrong codes in 15 minutes pause the account's logins and email it |
| `POST /api/logout` | Revokes the token it's called with |
| `POST /api/forgot-password` | `email`; always the same answer, and emails a reset link if an account uses it (6 a minute per IP, one link a minute per address) |
| `POST /api/reset-password` | `token`, `email`, `password`, `password_confirmation` from the link; signs out every device, verifies the email, and returns `{user, token}` (a challenge when two-factor is on) |
| `PATCH /api/account` | Your `name` |
| `PUT /api/account/email` | `email`, `current_password`: asks for the change (10 a day). Nothing changes until the link mailed to the new address is followed; the current one gets a notice. `pending_email` shows on the user |
| `POST /api/account/email/revert/{id}/{old}/{new}` | The undo link from the "email was changed" notice (7 days, no login): the old address comes back, every device is signed out, two-factor set up since the change is turned off, and it gets a password reset link |
| `POST /api/account/email/confirm/{id}/{hash}` | The link from that email, posted by `/confirm-email` while logged in: the new address becomes the email (verified), and other devices are signed out. `DELETE /api/account/email/pending` cancels the request |
| `PUT /api/account/password` | `current_password`, `password`, `password_confirmation`; other devices are signed out |
| `DELETE /api/account` | `password`. Refused (422) while you're the last owner of an organization with other members, or the only member of one with a paid plan that still renews; organizations where you're alone are deleted with their data, and you leave the others |
| `POST /api/account/two-factor` | `current_password`: starts the setup and returns `secret` and `otpauth_uri` (for the QR code); still off |
| `POST /api/account/two-factor/confirm` | `code` from the app: turns it on, signs out other devices and returns 8 `recovery_codes` (shown once) |
| `POST /api/account/two-factor/recovery-codes` | `current_password`, `code`: a new set of recovery codes; the old ones stop working |
| `POST /api/account/two-factor/revert/{id}/{at}` | The "This wasn't me" link from the "two-factor was turned on" email (7 days, no login): turns it off, signs out every device and sends a password reset link |
| `DELETE /api/account/two-factor` | `current_password` and a `code` (or a `recovery_code`): turns it off |
| `POST /api/account/two-factor/reconfirm` | `code` from the app: marks this device as having just passed one (platform admin routes need a recent one). 6 a minute |
| `GET /api/account/devices` | Your signed-in devices (`name`, `ip_address`, `last_used_at`, `is_current`); `DELETE /api/account/devices/{id}` signs one out |
| `GET /api/me` | The current user, with `current_organization` (and your role in it) |
| `GET /api/organizations` | Your organizations with your role, and `meta.current_organization_id` |
| `POST /api/organizations` | Create one (`name`); you're its owner and it becomes current. 20 a minute |
| `POST /api/organizations/{id}/switch` | Make one of yours current (404 if you're not a member) |
| `POST /api/email/verify/{id}/{hash}` | The signed link from the verification email (the app posts it); `POST /api/email/verification-notification` sends a new one |
| `GET /api/members` | Members of the current organization, with what you may do to each (`can`, `assignable_roles`) |
| `PATCH /api/members/{id}` | Change a role (`role_id`: one of the organization's roles) |
| `DELETE /api/members/{id}` | Remove someone, or leave (your own id) |
| `GET /api/invitations` | Pending invitations of the current organization |
| `POST /api/invitations` | Invite (`email`, `role_id`); needs a verified email. Emails a 7-day link to `/join/{token}` |
| `DELETE /api/invitations/{id}` | Revoke |
| `GET /api/join/{token}` / `POST /api/join/{token}` | See and accept an invitation (logged in as the invited address) |
| `GET /api/tasks` | Tasks of the current organization, 20 a page. Filters: `status`, `priority`, `assignee` (an id, `me` or `none`), `overdue=1`, `search`; `sort` = `newest`, `due` or `priority`; `page` |
| `POST /api/tasks` | Create (`title`, and optionally `description`, `status`, `priority`, `due_date` as `YYYY-MM-DD`, `assignee_id`: a member) |
| `GET /api/tasks/{id}` | One task, with what you may do (`can`) and `is_overdue` |
| `PATCH /api/tasks/{id}` | Change any of the fields above |
| `DELETE /api/tasks/{id}` | Delete |
| `GET /api/tasks/{id}/summary` | The task's AI summary: `data` (the latest, any status: `pending`, `draft`, `approved`, `rejected`, `failed`), `approved` (the one shown until a newer one is approved) and `can.regenerate` |
| `POST /api/tasks/{id}/summary` | Ask for a new one (202, pending; or the one already pending). For whoever may edit the task and has `ai.use`, with a verified email; 5 a minute per organization; 402 `ai_budget_exceeded` once the monthly budget is spent |
| `POST /api/tasks/writing-help` | AI writing help for the New task form (`mode`: `improve`, `expand`, `shorten`; `title`, `description`), answered as server-sent events (`delta`, `done`, `error`). Needs `ai.use` and `tasks.create`, a verified email; 402 over budget, 429 over the rate limit or with too many answers already streaming (2 per organization, 2 per person, 3 on the server). `/api/tasks/{id}/writing-help` does the same for a task you may edit |
| `GET /api/tasks/{id}/chat` | Your last 50 messages about the task, oldest first (`role`, `content`, `status`: `complete`, `stopped`, `failed`). Needs `ai.use`; only ever your own |
| `POST /api/tasks/{id}/chat` | Ask a question (`message`, up to 2000 characters); the answer streams back as server-sent events and is saved. Verified email, `throttle:ai`, budget (402) and stream limits (429), as for writing help |
| `DELETE /api/tasks/{id}/chat` | Clear your conversation about the task (204); other people's stay |
| `POST /api/tasks/{id}/summaries/{summaryId}/approve` | Approve a draft, optionally edited (`summary`, `key_points`); `.../reject` rejects it. 409 unless it's a draft |
| `GET /api/roles` | The organization's roles (Owner first) with their permissions, member counts and what you may do (`can`) |
| `POST /api/roles` | Create (`name`, `permissions`: a list from the catalogue); needs `roles.manage`, and only permissions you have |
| `PATCH /api/roles/{id}` | Rename or change permissions (the whole list); only roles that can do strictly less than yours, never Owner |
| `DELETE /api/roles/{id}` | Delete a role nobody has; its pending invitations stop working |
| `GET /api/permissions` | The permission catalogue (`value`, `label`, `group`) |
| `GET /api/audit-logs` | The organization's audit log, newest first, 25 at a time (`meta.next_cursor`, sent back as `cursor`). Filters: `action`, `actor` (a user id), `from` and `to` (`YYYY-MM-DD`). Needs `audit_log.view`; IP addresses only for owners |
| `GET /api/audit-log/actions` | The action catalogue for the filter (`value`, `label`, `group`) |
| `GET /api/ai-usage` | One month (`month=YYYY-MM`, default this one, UTC) of AI usage: `totals`, `by_feature`, `by_user`, `by_day` (calls, failed, tokens, cost), this month's `budget` and `can.manage_budget`. Needs `ai_usage.view` |
| `PUT /api/ai-budget` | Set the monthly AI budget (`monthly_budget_usd`: 0 up to the plan's budget, cents at most; `null` = the plan's budget). Needs `ai_budget.manage` (Owner) and a verified email; 20 a minute |
| `GET /api/billing` | The plan, subscription (`status`, `ends_at`), card, the plans and credit packs on offer, the AI budget with credits, and `can`. Needs `billing.manage` (Owner); no Stripe call |
| `POST /api/billing/checkout` | `plan` (a key: `pro`); returns `{url}` of Stripe Checkout. Refused while a subscription is still billed. Verified email |
| `POST /api/billing/credits/checkout` | `pack` (a key: `credits_10`); returns `{url}` of Stripe Checkout. The credits last until the end of the UTC month |
| `POST /api/billing/portal` | Returns `{url}` of Stripe's Customer Portal (card, cancel), once the organization has paid once |
| `GET /api/billing/invoices` | The organization's invoices from Stripe, newest first, 20 at a time (`meta.next_cursor`, sent back as `starting_after`); links only to Stripe's own pages |
| `POST /api/stripe/webhook` | From Stripe only (signed): keeps subscriptions in sync and grants paid credits. 120 a minute |
| `GET /api/admin/users` / `GET /api/admin/organizations` | Platform admins only (404 for everyone else): every user (`q` searches name and email) or organization (`q` searches the name), newest first, 25 a page. 403 `two_factor_required` without two-factor, `two_factor_reauth` without a code on this device in `ADMIN_TWO_FACTOR_TTL_MINUTES`; 60 a minute |
| `GET /api/admin/users/{id}` | A user: flags, signed-in devices, last active, organizations and roles. Logged in the platform log |
| `GET /api/admin/organizations/{id}` | An organization: plan and subscription, AI budget, counts, members (first 100). Never task content. Logged |
| `GET /api/admin/audit-log` | The platform log, newest first, 25 at a time (`meta.next_cursor`) |
| `POST /api/admin/users/{id}/two-factor/reset`, `/sign-out`, `/suspend`, `/unsuspend` | Act on a user, with a `reason` (10–500 characters, platform log only) and a code within `ADMIN_ACTION_TWO_FACTOR_TTL_MINUTES`. Never on a platform admin. Returns the user |
| `POST /api/admin/organizations/{id}/owners` | `user_id` (a member of that organization) and `reason`: makes them Owner; its audit log shows "Platform admin" |
| `POST /api/admin/users/{id}/impersonate` | `reason`: a read-only token for the user (`{token, expires_at}`, `ADMIN_IMPERSONATION_MINUTES`). Every write is refused (403 `impersonation_read_only`) except logout and switching organization; task descriptions, summaries, chats and devices are hidden; the user is emailed |

| `GET /api/webhooks` | The organization's webhooks (`url`, `host`, `events`, `enabled`, `disabled_reason`, `last_delivery`), and `meta.max` (5). Needs `webhooks.manage` (Owner). Never the secret |
| `POST /api/webhooks` | `url` (https, resolving only to public addresses), `description`, `events` (any of `task.created`, `task.updated`, `task.deleted`); returns the webhook and `meta.secret`, shown this once. Verified email; 60 a minute |
| `GET /api/webhooks/{id}` / `PATCH /api/webhooks/{id}` / `DELETE /api/webhooks/{id}` | One webhook; change any of the fields above, or `enabled` (turning it on resets its failure count); delete it with its delivery log |
| `POST /api/webhooks/{id}/secret` | A new signing secret (`meta.secret`, shown once); the old one stops working at once |
| `POST /api/webhooks/{id}/test` | Sends a `ping` event (202, the delivery). 10 a minute with resends |
| `GET /api/webhooks/{id}/deliveries` | What was sent and what came back, newest first, 20 at a time (`meta.next_cursor`, sent back as `cursor`). Not in a support view |
| `POST /api/webhooks/{id}/deliveries/{deliveryId}/resend` | The same event (same id and payload) as a new delivery (202) |
Organization-scoped routes (members, invitations, tasks, roles, audit log, AI usage, billing, webhooks) act on your current organization: there's never an organization id in the URL.

Send the token as `Authorization: Bearer <token>`, with `Accept: application/json`. Register allows 6 attempts a minute per IP; login 6 a minute per email and IP, and 30 per email. Billing routes that call Stripe allow 20 a minute per person and 60 per organization.

## Receiving webhooks
Each delivery is a `POST` with a JSON body:
```json
{
  "id": "9b7c1f0e-2a3d-4c5b-8e6f-7a8b9c0d1e2f",
  "type": "task.updated",
  "created_at": "2026-11-08T10:00:00Z",
  "data": {
    "task": { "id": 42, "title": "Ship it", "description": "…", "status": "done", "priority": "high",
              "due_date": "2026-11-10", "assignee": { "id": 7, "name": "Grace" }, "created_by": 3,
              "created_at": "…", "updated_at": "…" },
    "changed": ["status"]
  }
}
```
`task.created` and `task.deleted` carry `data.task` (a deleted task as it was); `task.updated` adds `data.changed`, the fields that changed; `ping` (Send test event) carries `data.endpoint.id`. Events can arrive out of order: compare `created_at`.

Headers: `X-Webhook-Id` (the event id: the same on a retry or a resend, so ignore one you've already handled), `X-Webhook-Event`, `X-Webhook-Timestamp` (unix seconds) and `X-Webhook-Signature: v1=<hex>`, the HMAC-SHA256 of `"{timestamp}.{raw body}"` with the webhook's secret. Answer with any 2xx within 10 seconds; anything else (redirects included) is retried after 1 minute, 5 minutes, 30 minutes, 2 hours and 8 hours.

Verify before trusting it, on the raw body (before parsing the JSON):
```php
$timestamp = (int) $request->header('X-Webhook-Timestamp');
$expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
$valid = hash_equals($expected, (string) $request->header('X-Webhook-Signature'))
    && abs(time() - $timestamp) <= 300; // refuse old replays
```
```js
import { createHmac, timingSafeEqual } from 'node:crypto'

const expected = Buffer.from('v1=' + createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex'))
const given = Buffer.from(signatureHeader)
const valid = given.length === expected.length && timingSafeEqual(given, expected) && Math.abs(Date.now() / 1000 - timestamp) <= 300
```
