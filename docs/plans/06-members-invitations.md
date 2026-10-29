# 06 — Members and invitations

**Status:** Done
**Commits:** `0db6230` (api), `c482de1` (web)

## Goal
Manage the people in the current organization:
- **Owners and admins** invite people by email, change roles and remove members.
- **Anyone** can leave.
- **An invitation link** works whether you have an account or not: log in, or register with the invited email, then accept.
- **Email verification:** registration sends a verification link, and features that reach other people (inviting) need a verified email.

Everything acts on the current organization: no organization id in any URL, and permissions are checked on the server. It rebuilds saas-starter plan 04, and brings email verification (saas-starter plan 22) forward because CLAUDE.md requires it for invitations.

Out of scope:
- custom roles (plan 08);
- renaming or deleting an organization;
- a dedicated ownership transfer (changing a role covers it);
- a Resend button on invitations (inviting again covers it);
- password reset and changing your email (plan 14);
- notifications to removed members.

## Decisions
- **Permissions, not role names, in the policies** (CLAUDE.md).
  - A small `App\Enums\Permission` arrives now: `members.invite`, `members.manage`, `organization.manage`. `OrganizationRole::grants()` maps the roles to them: Owner gets all three, Admin gets invite and manage, Member gets none.
  - **You can only act on, or give, a role whose permissions are a subset of yours** (the rule saas-starter uses with custom roles). So an admin can't invite an owner, make someone an owner, or change or remove an owner.
  - Plan 08 swaps the mapping for a `roles` table, and the policies stay as they are.
  - `OrganizationResource` returns `permissions`, so the app can show or hide controls. The server still decides.

  | Action | Owner | Admin | Member |
  |---|---|---|---|
  | See members | ✓ | ✓ | ✓ |
  | See and revoke pending invitations | ✓ | ✓ | – |
  | Invite as member or admin | ✓ | ✓ | – |
  | Invite as owner | ✓ | – | – |
  | Change a role | any member, to any role | admins and members, to admin or member | – |
  | Remove someone | anyone | admins and members | – |
  | Leave | ✓ | ✓ | ✓ |
- **An organization always keeps an owner:** demoting, removing or leaving as the last owner → 422. The owner rows are locked in a transaction, so two owners can't demote each other at the same moment.
- **Invitations:**
  - The token is 48 random characters. Only its sha256 hash is stored.
  - The plain token appears only in the email link (`FRONTEND_URL/join/{token}`).
  - An invitation expires after 7 days.
  - Inviting the same address again replaces the pending invitation, with a new link and expiry, unless that one is for a role you couldn't give.
  - Existing members can't be invited.
  - `OrganizationInvitation` is the first real tenant-owned model (`BelongsToOrganization`). The token lookup for joining is the one documented place that bypasses the scope, since the person joining isn't acting in that organization yet.
- **Accepting:**
  - Requires login, and the account's email must be the invited one: 403 otherwise, with "log out and use the invited account".
  - Unknown, revoked, expired or used links all give the same 404.
  - The organization, then the invitation, are locked. You join with the invitation's role and switch to the organization.
  - **Accepting also verifies your email.** The link was delivered to that inbox, which is what verification proves. So someone who registers from an invitation can join at once. Joining therefore doesn't use the `verified` middleware, and CLAUDE.md's line changes from "invitations, joining" to "invitations (joining verifies the email by itself)". **This needs your OK.**
- **Email verification:**
  - `User` implements `MustVerifyEmail`. Registration queues a `VerifyEmail` notification whose link points at the React app (`/verify-email?id&hash&expires&signature`); the page posts it back to `POST /api/email/verify/{id}/{hash}` (signed relative to the API, 60-minute expiry).
  - The link must belong to the logged-in account and its current email, so following it while logged out goes through login and comes back.
  - `POST /api/email/verification-notification` sends a new link (6 a minute).
  - The `verified` middleware answers 403 `{code: 'email_unverified'}`.
  - `/api/me` gains `email_verified`.
  - Accounts that already exist (dev data only) stay unverified, and can verify from the banner.
- **Leaving or being removed:** if it was your current organization, you move to another of yours (first by name), or to none. The app then reloads the user, drops `['org']` queries and goes home, with a toast.
- **Mail** is queued, with 3 tries and backoff. Locally it goes to `storage/logs/laravel.log` (`MAIL_MAILER=log`, needs `php artisan queue:work`); the e2e servers' `--mail` sends it at once. Before any of this ships: `composer update symfony/mime` for CVE-2026-45070 (plan 03 follow-up).
- **Rate limits (named, per user):**
  - `invitations`: 20 a minute;
  - `members`: 60 a minute, for changing roles and removing;
  - `join`: 20 a minute;
  - `verify-email`: 6 a minute, for verifying and resending.
- **The app:**
  - `/members` page, with a "Members" entry in the sidebar:
    - a members table: name, email, role, joined, a role select, and Remove or Leave, shown per `can` flags from the API;
    - an invite form (email and role) and the pending invitations with Revoke, shown with `members.invite`;
    - confirmations use shadcn `AlertDialog`.
  - `/join/:token`:
    - shows "Ada invited you to join Acme as Admin" with an Accept button;
    - clear states for the wrong account (with log out), an invalid or expired link, and already being a member;
    - works logged out (RequireAuth sends you through login or register, and back).
  - `/verify-email`: posts the link once on load and shows success, an expired link, or the wrong account.
  - A "Verify your email" banner on logged-in pages while unverified, with Resend.
  - A 403 `email_unverified` shows "Verify your email to invite people".
- **shadcn components:** `table`, `select`, `alert-dialog`, `badge`, `alert`. These are only generated files and the `radix-ui` package that's already installed. **No new packages.**

- **Added after review:**
  - The organization row is locked `FOR NO KEY UPDATE`, not `FOR UPDATE`. That still serializes the member actions, but no longer blocks the `KEY SHARE` lock Postgres takes for any insert that points at the organization, so re-inviting while someone accepts can't deadlock.
  - Under the lock, the member's role is read again and the action refused (422) if it changed since the policy check. That stops an admin acting on someone just made owner.
  - A partial unique index (`organization_id, email WHERE accepted_at IS NULL`) allows one pending invitation per address. A simultaneous second one gets a 422. There's also an index on `invited_by`.
  - `OrganizationInvitation` is `Prunable`: invitations accepted or expired over 30 days ago are deleted daily (`model:prune` in `routes/console.php`).
  - `Markdown::withSecuredEncoding()`, so a user-chosen organization or inviter name can't become a link in the invitation email.
  - Mail caps:
    - verification: 6 a minute and 10 an hour per user;
    - invitations: 20 a minute per user, 200 a day per organization, and 3 an hour to one address.
  - `GET /api/invitations` returns `meta.invitable_roles` (from the policy). The invite form offers those, never a role name, and is keyed by organization.

## Backend (`api/`)
- **Migrations:**
  - `create_organization_invitations_table`:
    - id; organization_id FK cascade; email; role string; `token_hash` unique;
    - `invited_by` FK users null on delete; `expires_at`; `accepted_at`; timestamps;
    - index `[organization_id, email]`.
  - `users.email_verified_at` already exists in Laravel's table.
- **Enums:** `app/Enums/Permission.php`; `OrganizationRole::grants()`, `can()`, `includes(OrganizationRole $other)` (the subset rule).
- `CurrentOrganization::can(Permission)`.
- **Model:** `app/Models/OrganizationInvitation.php`: `BelongsToOrganization`, `findByToken()`, `pending()`, `isUsable()`, `inviter()`, `LIFETIME_DAYS`.
- **Policies:** `app/Policies/MembershipPolicy.php` (viewAny, update with the new role, delete), `app/Policies/OrganizationInvitationPolicy.php` (viewAny, create with a role, delete). Both read `CurrentOrganization`.
- **Actions** in `app/Actions/Organizations/`:
  - `InviteMember`;
  - `AcceptInvitation` (with the email check and marking the email verified);
  - `ChangeMemberRole` and `RemoveMember`, with a `Concerns/EnsuresAnOwnerRemains` trait (organization lock, owner rows locked in id order).
- **Auth actions:** `app/Actions/Auth/VerifyUserEmail.php`. `RegisterUser` sends the verification.
- **Mail and notifications:** `app/Mail/OrganizationInvitationMail.php` (queued Markdown mail, plain values), `app/Notifications/VerifyEmail.php` (link to the app).
- **Middleware:** `app/Http/Middleware/EnsureEmailIsVerified.php`, aliased `verified`.
- **Form requests:** `Organizations/StoreInvitationRequest` (email, role: owner|admin|member), `Organizations/UpdateMemberRequest` (role).
- **Resources:**
  - `MemberResource`: id, name, email, role, joined_at, `can: {update, delete}`, and `is_me`.
  - `InvitationResource`: id, email, role, invited_by name, expires_at.
  - `InvitationPreviewResource`: organization name, inviter name, role, email.
  - `UserResource` gets `email_verified`, and `OrganizationResource` gets `permissions`.
- **Controllers:** `MemberController` (index, update, destroy), `InvitationController` (index, store, destroy), `JoinController` (show, store), `EmailVerificationController` (verify, resend).
- **Routes:**
  - inside `auth:sanctum`:
    - `POST /email/verify/{id}/{hash}` (`signed:relative`, `throttle:verify-email`, named `verification.verify`);
    - `POST /email/verification-notification`;
    - `GET`/`POST /join/{token}`;
  - inside the `organization` group:
    - `GET /members`, `PATCH`/`DELETE /members/{member}` (up to 18 digits);
    - `GET /invitations`, `POST /invitations` (`verified`, `throttle:invitations`), `DELETE /invitations/{invitation}`.
- **Config:** `auth.verification.expire` (60). No new env keys (`FRONTEND_URL` already exists).

## Frontend (`web/`)
- **Types:** `Permission`, `Organization.permissions`, `User.email_verified`, `Member`, `Invitation`, `InvitationPreview`.
- **`features/members/api.ts`:** `membersQuery` (`['org', id, 'members']`), `invitationsQuery` (`['org', id, 'invitations']`), `useChangeRole`, `useRemoveMember` (also used to leave), `useInvite`, `useRevokeInvitation`.
- **`features/members/` components:** `MembersTable.tsx`, `InviteForm.tsx`, `PendingInvitations.tsx`, `RoleSelect.tsx`, `ConfirmDialog.tsx` (or in `components/` if shared).
- **`features/join/api.ts`:** `invitationPreviewQuery`, `useAcceptInvitation`.
- **`features/auth/`:** `useVerifyEmail`, `useResendVerification`, `VerifyEmailBanner.tsx`.
- **`hooks/useCan.ts`:** `useCan(permission)` reads the current organization's `permissions`. It only shows or hides controls.
- **Routes:** `routes/MembersPage.tsx` (in `AppLayout`), `routes/JoinPage.tsx` and `routes/VerifyEmailPage.tsx` (logged in, in the auth-card layout); `router.tsx`; a "Members" entry in the sidebar's `navigation`.
- `lib/apiErrors.ts`: `isEmailUnverified(error)` and its message.

React concepts (each compared with Vue/Nuxt):
- **`useQuery` with an org-scoped key** (`['org', id, 'members']`) ↔ `useAsyncData` with a key that includes the organization. The key changes on a switch, so the data refetches by itself, where Nuxt needed a `watch`.
- **Loading, empty and error states from `isPending`/`isError`/`data`** ↔ `pending`/`error` from `useAsyncData`.
- **`useParams`** (the join token) ↔ `useRoute().params`.
- **Controlled select** (`value` + `onValueChange`) ↔ `v-model` on a select. The role select is controlled by server data, so it changes only when the mutation succeeds.
- **Mutation lifecycle** (`isPending` per row via `variables`, `onSuccess` invalidating a query) ↔ awaiting `$fetch`, then `refresh()`.
- **Confirm dialog as a reusable component** that takes `onConfirm` and `children` ↔ a modal component with emits and slots.
- **`useEffect` for a one-time action on load** (the verify page posts its link) and why StrictMode runs it twice in development: a `useRef` guard ↔ `onMounted`, which Vue runs once.
- **A custom hook as a permission helper** (`useCan`) ↔ the `useOrganizationPermissions` composable.

## Tests
Pest:
- `Members/MemberPermissionsTest.php`: the whole table above, as datasets.
  - Admins can't touch owners or give the owner role.
  - The last owner can't be demoted, removed or leave (422).
  - Two owners can demote each other only until one owner is left.
- `Members/ManageMembersTest.php`:
  - list (role, `can`, `is_me`), change role, remove;
  - leave, with the current organization moving to another or to null;
  - a removed member gets 403 from the organization routes.
- `Invitations/InviteTest.php`:
  - the mail is queued with a working `/join/` link;
  - the token is stored hashed;
  - inviting again replaces the pending invitation (and refuses if it's for a role you can't give);
  - a member can't be invited;
  - validation; the throttle; `verified` (403 `email_unverified`);
  - revoke.
- `Invitations/JoinTest.php`:
  - preview;
  - accept (membership with the role, the organization becomes current, the email becomes verified);
  - wrong email → 403;
  - unknown, revoked, expired or used → the same 404;
  - already a member keeps their role;
  - 401 without a token.
- `Auth/EmailVerificationTest.php`:
  - registration queues the notification, and its link points at the app;
  - verify works;
  - a wrong account or old email → 403;
  - an expired or tampered signature → 403;
  - resend, and the throttle;
  - `/me` includes `email_verified`.
- **Org A cannot read or write org B:** `Members/MembersIsolationTest.php` and `Invitations/InvitationsIsolationTest.php`. Org A's admin can't list, change, remove or revoke org B's members and invitations (404, B unchanged), and B's members and invitations never appear in A's lists. Also checked for someone in both organizations who hasn't switched.

Vitest:
- `MembersPage.test.tsx`:
  - loading, error and the list;
  - controls follow `can`;
  - changing a role; removing with a confirm;
  - leaving → home with a toast;
  - a member sees no invite form;
  - the 409 `organization_changed` path.
- `InviteForm.test.tsx`: validation; a 422 on email; a 403 `email_unverified` message; success adds a pending row.
- `JoinPage.test.tsx`: preview and accept (switches organization); wrong account; invalid link; logged out → login → back to the join page.
- `VerifyEmailPage.test.tsx`: posts once (StrictMode-safe); success; expired. And the banner's Resend.

## README screenshots
Taken as in plan 03:
- `members.png`: members (owner, admin, member) with pending invitations, as an owner;
- `join.png`: the invitation page;
- `verify-email-banner.png`: the dashboard with the banner (unverified).

## Verification
- `/verify`; CI.
- **`e2e-smoke --mail`** (curl): invite → `mail.sh link join` → register the invitee → join → org B's admin gets 404 on A's members.
- **Reviewers in parallel:** code, security (tokens, signed links, redirects, mail), tenant isolation (invitations are the first tenant model), database (migration, locks), UI (quick: the members page at 390px, keyboard through the role select and dialogs, the join page, the banner).

Flows (for `flow-tester`, with `e2e-ft-*` accounts, servers started with `--mail`):
1. Register Owner → the banner says "Verify your email"; open `/members` → you're listed as Owner, with no Remove.
2. Invite someone as admin while unverified → "Verify your email to invite people"; nothing is sent.
3. Open the verification email's link (`mail.sh link verify-email`) → "Email verified", and the banner is gone.
4. Invite `e2e-ft-ana` as Admin → pending invitation listed; `mail.sh link join e2e-ft-ana@example.com` has the link.
5. In a fresh browser, open the join link logged out → sent to log in → "Create one" → register as `e2e-ft-ana` → back on the join page → Accept → the sidebar shows the owner's organization, role Admin.
6. Open the same join link again → "This invitation is no longer valid".
7. As Ana (Admin), invite `e2e-ft-ben` as Member → works; the role choices don't include Owner.
8. Ben registers with a different email and opens Ben's link → "This invitation was sent to a different email address", with a log-out option.
9. As the owner, change Ana to Member → her row updates. As Ana (now Member), `/members` shows no invite form and no role controls.
10. Ana leaves → back home in her own organization, with a toast. Her `/members` no longer lists the owner's organization.
11. The owner tries to leave as the last owner → "An organization needs at least one owner".
12. The owner revokes Ben's invitation → it's gone; Ben's link → "no longer valid".
13. Org isolation: another owner, `e2e-ft-zed`, gets 404 for the first organization's members via curl.
14. At 390px: the members table and invite form fit, with no horizontal scroll.

## Outcome
- `/verify`: Pint, 163 Pest tests (587 assertions), oxlint, typecheck, 106 Vitest tests, build.
- **Mutation checks**, each restored afterwards:
  - policies allowing everything → 9 failures;
  - no last-owner guard → 2;
  - no email check when joining → 1;
  - `Markdown::withSecuredEncoding()` off → 1.
- **curl with real emails** (`--mail`):
  - unverified invite → 403 `email_unverified`;
  - the verification link → verified, then the invite → 201;
  - the join link → preview, then accept (Admin, email verified);
  - using the link again → 404;
  - another organization → 404;
  - the last owner can't leave;
  - CORS allows `x-organization-id`.
- **`flow-tester`:** all 14 flows passed, with 7 emails as expected and no log errors.
- **`ui-reviewer`:** all 7 checks passed (390px, keyboard through the selects and dialogs, labels, join page, banner, dark mode, console).
- **Reviews:**
  - **database (high):** the deadlock between an `FOR UPDATE` organization lock and foreign-key checks, fixed with `FOR NO KEY UPDATE`. Also: the partial unique index on pending invitations, pruning, and the `invited_by` index.
  - **security (medium):** Markdown injection in the invitation email, plus mail volume caps.
  - **code:** the invite form chose roles by role name, now `invitable_roles` from the API. Also: the banner after an "already verified" resend, the invitations list after a change to your own role, and smaller cleanups.
  - **tenant isolation:** no findings; three extra isolation tests added.
- **Found while testing:**
  - leaving an organization lost its toast and redirect, because the list unmounts on leave. Handled in `useMutation`'s own `onSuccess`, with `onMutate` context.
  - a test mock that answered 409 forever looped. The real API stops answering 409 once the tab has caught up.
- `symfony/mime` 8.1.10 (CVE-2026-45070) is in.

## Follow-ups
- **Invitations from a demoted inviter.** An invitation still works after its inviter is demoted or removed. Consider checking the inviter at accept time, or revoking their pending invitations when they lose `members.invite`.
- **Email change (plan 14).** Accepting must verify the email only while the account's address is still the invited one. Make sure an unverified new address can't be used to accept.
- **Unbounded lists.** The members and invitations lists aren't paginated. Fine at today's sizes (invites are rate-limited and pruned); paginate before seats or billing.
- **Join token in the URL.** It sits in the URL path, so it ends up in browser history and access logs. Consider `Referrer-Policy: no-referrer` on the web host. It's single-use and tied to one email.
- **Test gap:** a concurrent role change during an admin's action is refused under the lock, but no test covers it (it's hard to race in Pest).
- **PHPUnit advisory.** `phpunit/phpunit` GHSA-9vw2-f4vp-x7gf is dev-only; `composer update phpunit/phpunit` found nothing newer within the current constraint.

## Commits after approval
`feat(api): add members, invitations and email verification`, `feat(web): add members page, join and verify-email pages`, then this plan → Done.
