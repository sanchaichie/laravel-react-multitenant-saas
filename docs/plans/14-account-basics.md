# 14 — Account basics

**Status:** Done
**Commits:** `aafd9a7` (api), `d9ae7f0` (web)

## Goal
The account features every SaaS needs and this app still lacks:
- **forgot and reset password**;
- an **Account settings** page, for your name, email and password, and to delete your account;
- the list of **signed-in devices** (Sanctum tokens), where you can revoke one.

This rebuilds saas-starter plan 22 and its follow-ups. Email verification, the banner and the `verified` gate came over in plan 06, so they're done.

**Out of scope:**
- two-factor authentication (plan 15);
- social login;
- transferring ownership as its own action (an owner can already make another member Owner on the Members page);
- deleting an organization on its own (it only happens as part of deleting your account, below);
- billing checks on deletion: there's no billing until plan 16, which adds "an active subscription blocks deletion".

## Decisions
- **No new dependency.** Laravel's password broker (the `password_reset_tokens` table already exists, with 60-minute tokens) and our own small user-agent parser. No Fortify: it's built around sessions and views, and the parts needed here are small.
- **Links point at the React app.** The reset email links to `FRONTEND_URL/reset-password?token=…&email=…`, and the app posts the values back to the API. This is the same pattern as verification (plan 06).
  - `index.html` gets `<meta name="referrer" content="same-origin">`, so the token in that URL can never leak through a `Referer` header (a saas-starter follow-up).
- **No account enumeration.** Forgot password always answers 200 with the same message, whether the address exists or not.
  - It's throttled per IP (`throttle:6,1,password-reset`, its own counter).
  - The broker also limits one link per address per 60 seconds.
- **A reset logs you in on every path.** A successful reset revokes all your tokens, marks the email verified (the link reached that inbox), and returns a new token through `IssueToken`. The app then continues as after a login, with `/select-organization` if you're in several organizations.
- **Changing your email:**
  - It needs your current password.
  - It sets `email_verified_at = null` and sends a verification link to the new address.
  - The old address gets an "Your email address was changed" notice, with the new address partly masked.
  - It signs out your other devices.
  - Invitations are matched on your current email and accepting verifies it, so nothing can be accepted through an address you don't control.
- **Changing your password** needs the current one and revokes every other token. The device you're on stays signed in.
- **Daily caps on mail to addresses users type** (a saas-starter follow-up): email changes are limited to 6 a minute and 10 a day per user. Verification resends already have their hourly cap.
- **Deleting your account:**
  - It needs your password.
  - **What blocks it:** being the last owner of an organization that has other members. You get a 422 that lists those organizations, so you can make someone else Owner first.
  - **Organizations where you're the only member** are deleted with all their data, through the existing cascading foreign keys.
  - **Your other memberships** go through `RemoveMember`, so each of those organizations gets its `member.removed` audit entry and your tasks there are unassigned.
  - **Then** your user, tokens and any reset token are deleted. Your chats go with the memberships, and your audit entries keep their names with `actor_id = null`.
  - **Order of locks:** organization rows in id order, then memberships, as `EnsuresAnOwnerRemains` does. `AcceptInvitation` already locks the organization, so someone joining at that moment can't be wiped out by the cascade.
- **Devices:**
  - A new migration adds `ip_address` and `user_agent` to `personal_access_tokens`. `IssueToken` sets them, and names the token from the user agent ("Chrome on macOS") with `App\Support\DeviceName`, a small parser.
  - The list shows the name, IP address, when it was last used, and "This device".
  - You can revoke any of your own tokens. Revoking the current one logs you out; anyone else's token gives a 404.
  - The list shows your latest 100.
- **Not in the organization audit log.** These are account events, and the audit log is per organization, so it would show one organization another member's account activity. The memberships removed on deletion still write `member.removed`.
- **The account page is user-level, not organization-level.** Its routes sit outside the `organization` group, so it works with no current organization, and switching organization doesn't touch it. Its query keys don't start with `['org', …]`.

## Backend (`api/`)
1. **Migration `add_device_columns_to_personal_access_tokens_table`:** `ip_address` (string 45, nullable) and `user_agent` (string 512, nullable).
2. **`IssueToken::handle(User, Request)`:**
   - It names the token with `DeviceName::fromUserAgent()` and stores the IP address and user agent.
   - Login, register and reset all go through it, as CLAUDE.md requires. The current `deviceName` argument goes, since the server names devices.
3. **`App\Support\DeviceName`:** the browser (Edge, Chrome, Firefox, Safari, or "Browser") on the OS (Windows, macOS, iOS, Android, Linux), else "Unknown device". It's unit-tested with a table of real user agents.
4. **Notifications:**
   - `ResetPassword`: queued, linking to the app, like `VerifyEmail`.
   - `EmailChanged`: queued, to the old address, with the new address masked (`d•••@example.com`).
5. **Actions:**
   - `app/Actions/Auth`: `SendPasswordResetLink` and `ResetUserPassword`.
   - `app/Actions/Account`: `UpdateProfile`, `ChangeEmail`, `ChangePassword`, `DeleteAccount` and `RevokeDevice`.
6. **Form requests:** `ForgotPasswordRequest`, `ResetPasswordRequest`, `UpdateProfileRequest`, `ChangeEmailRequest` (`current_password`, the email unique), `ChangePasswordRequest` (`current_password`, `Password::defaults()`, confirmed) and `DeleteAccountRequest` (`current_password`).
7. **Resources:** `DeviceResource` (`id`, `name`, `ip_address`, `last_used_at`, `created_at`, `is_current`).
8. **Controllers:** `PasswordResetController`, `AccountController` and `DeviceController`, all thin.
9. **Routes:**
   - **Guest:** `POST /api/forgot-password` (`throttle:6,1,password-reset`) and `POST /api/reset-password` (`throttle:6,1,password-reset`).
   - **`auth:sanctum`, outside the `organization` group:**
     - `PATCH /api/account` (name);
     - `PUT /api/account/email` (`throttle:account-email`: 6 a minute, 10 a day);
     - `PUT /api/account/password` (`throttle:6,1,account-password`);
     - `DELETE /api/account` (`throttle:6,1,account-delete`);
     - `GET /api/account/devices`;
     - `DELETE /api/account/devices/{token}`.
10. **`.env.example`:** no new keys. A comment by `MAIL_MAILER` says reset and verification emails go there too.

## Frontend (`web/`)
11. **Pages** (guest layout, `RequireGuest`): `routes/ForgotPasswordPage.tsx` and `routes/ResetPasswordPage.tsx`.
    - The reset page reads `token` and `email` from the URL.
    - On success it stores the session, as login does, then goes where login would go.
    - A missing or invalid token shows an error with a link to ask again.
    - The login page gets a "Forgot password?" link.
12. **`routes/AccountPage.tsx` (`/account`)** has one card per section, each its own small form:
    - **Profile:** your name.
    - **Email:** the new address and your current password. It says when the address isn't verified, with Resend (reusing the banner's hook).
    - **Password:** current, new and confirmation.
    - **Devices:** a table with the device name, IP address and last used, a "This device" badge, and Revoke behind `ConfirmDialog`. Revoking this device logs you out.
    - **Danger zone:** Delete account, behind a dialog that asks for your password. A 422 lists the organizations that block it, with a link to Members.
13. **`features/account/api.ts`:**
    - `useDevices()`, with key `['account', 'devices']` (user-level);
    - mutations `useUpdateProfile`, `useChangeEmail`, `useChangePassword`, `useDeleteAccount` and `useRevokeDevice`.

    Each mutation writes the returned user into the auth store (`setUser`), or clears the session where that applies.
14. **`UserMenu`:** an "Account settings" item, linking to `/account`.
15. **`lib/afterLogin.ts`:** where to go after logging in (the `redirect` parameter, `/select-organization`, or home), shared by the login, register and reset pages instead of repeated in each.
16. **Types:** `Device`.

React concepts:
- Several independent `useForm` instances on one page, one per section, each with its own submit and errors ↔ several `<UForm>`s each with their own `reactive` state.
- A form inside a dialog (the password before deleting) that resets when the dialog closes, because the content unmounts ↔ `v-if` on a modal's body.
- Reading one-time values from the URL (`useSearchParams`) without copying them into state ↔ `useRoute().query`.
- Clearing the session from a mutation's `onSuccess`, and letting the route guard redirect ↔ a Pinia action plus middleware.
- Shared navigation logic in a plain function (`afterLogin`) instead of a hook, since it needs no React state ↔ a utility in `utils/` rather than a composable.

## Tests
- **Password reset:**
  - A link is sent for a known address and not for an unknown one, but both get the same 200.
  - A wrong or expired token fails, and a mismatched email fails.
  - Success changes the password, revokes all old tokens, verifies the email, and returns a working new token.
  - It's throttled, and the notification links to `FRONTEND_URL/reset-password`.
- **Profile:** updating the name; validation.
- **Email:**
  - A change needs the right password.
  - It resets verification, sends a link to the new address and a masked notice to the old one, and revokes other tokens but keeps the current one.
  - A taken address is refused, and so is the 11th change in a day.
- **Password:** a change needs the current one, enforces the rules and the confirmation, and revokes other tokens but keeps the current one.
- **Delete account:**
  - The wrong password fails.
  - The last owner of an organization with other members is blocked, and the 422 lists it.
  - Otherwise:
    - organizations where you're the only member are gone, with their tasks, roles, summaries, chats, usage and audit;
    - your other memberships are removed, each with a `member.removed` entry and your tasks unassigned;
    - other people's organizations are untouched;
    - your tokens stop working;
    - your audit entries elsewhere keep their names with `actor_id` null.
  - A co-owner's organization isn't blocked.
- **Devices:**
  - The list shows only your tokens, with `is_current`.
  - Login stores the IP address, user agent and name.
  - Revoking another of your devices works; revoking the current one logs you out (the next request gets 401).
  - Someone else's token gives 404.
- **`DeviceName`:** a table of user agents and the expected names.
- **Isolation:**
  - Deleting an account in org A never touches org B's data, except B's own `member.removed` entry and unassigning your tasks there.
  - The device endpoints can't reach another user's tokens.
  - Nothing goes in any organization's audit log beyond `member.removed`.
- **Vitest:**
  - Forgot password: one message whatever the address; the 429 message.
  - Reset: the success path logs in and goes where login would; an invalid or expired link shows an error with a link to try again; field errors.
  - The account page: each section saves, and a 422 lands on its field.
  - Devices: "This device" and Revoke; revoking the current one logs out.
  - Delete: the blocked list, then success logs out.
  - The menu has the Account settings item.

## README screenshots
- New `docs/screenshots/account.png`: the account settings page with the profile, email, password, devices (two devices) and danger zone cards. 1280×800, light mode, e2e demo data.
- No retakes.

## Verification
- `/verify`.
- `/e2e-smoke` with `--mail` (reset and changed-email notices go to the log; `mail.sh` reads them).
- Reviewers in parallel:
  - code;
  - security: enumeration, token handling, revocation, deletion locks, rate limits;
  - tenant isolation: deletion across organizations;
  - database: migration, deletion order and locks;
  - UI: the two new pages and the account page.
- UI review and flow-tester run one after the other.

Flows (for `flow-tester`, servers with `--mail`):
1. On the login page, **Forgot password?** → enter a registered address → "If an account exists…" message. Enter an unknown address → the same message. Only the registered address gets an email.
2. Open the reset link from the email while logged out → set a new password → logged in (home, or Choose an organization if in several). The old password no longer works; the new one does.
3. Open the same reset link again → "This link is invalid or has expired", with a way to ask again.
4. Log in on a second "device" (curl login). On **Account settings → Devices**, two devices show, one marked "This device". Revoke the other → it disappears, and that token gets 401.
5. Change password (wrong current password → field error; right one → saved). The second device, if logged in again, is signed out; this one stays.
6. Change email to a new address (with password) → the banner says to verify; the new address gets a verification email, and the old one gets the "email was changed" notice. Open the verification link → verified.
7. As the sole owner of an organization with another member, **Delete account** → blocked, and the organization is listed. Make the other member Owner, then delete → logged out to /login; logging in fails. The other member's organization still exists, and its audit log shows the removal.
8. A user who is only in their own personal organization deletes their account → the organization and its tasks are gone (the API 404s for its ids from another account's view, and the e2e cleanup finds nothing left).

## Changes during the build
- **`IssueToken` keeps the client's optional `device_name`** (for non-browser clients) and reads the User-Agent and IP from the injected request, as saas-starter does. Without a `device_name`, the token is named from the User-Agent.
- **No `lib/afterLogin.ts`:** where to go after logging in already lives in `RequireGuest` and the session store. Instead, `useLogin` and `useResetPassword` share `withOrganizations()` (a plain function) and `useStartSession()`, so a reset also asks which organization when there are several.
- **No Resend in the Email card:** the verify banner, on every logged-in page, already has it. The card shows a Verified or Not verified badge.

- **Email changes are pending until the new address confirms them** (your choice, after the security review). The original design changed the email at once. Someone who knew the password could then take the account for good, because "forgot password" with the old address no longer found it. Now:
  - `PUT /api/account/email` stores `pending_email`, mails a signed link to the new address (`ConfirmEmailChange`, `/confirm-email`), and notifies the current address (`EmailChangeRequested`).
  - Following the link while logged in switches the email (verified), signs out other devices, and sends `EmailChanged` to the old address.
  - A newer request replaces the link. Cancelling in Account settings, or a password reset, clears it.
- **The security re-review found one more gap:** someone who has the password can request and confirm within seconds, before the owner's notice arrives. So the "email was changed" notice to the old address now carries a signed **undo link** (7 days, no login; `RevertEmailChange`, `/revert-email`). It puts the old address back (verified, stored in `users.previous_email` until then), signs out every device, and mails that address a password reset link. Also from that review:
  - Confirming locks the user row and checks the request and the current token again, so a reset or cancel at the same moment wins cleanly.
  - At most 3 confirmation emails a day go to one address, answered the same way either way.
  - A password change also cancels a pending change.
  - The notice text no longer suggests that Cancel signs anyone out.
- **From the other reviews:**
  - `DeleteAccount` locks each organization row `FOR NO KEY UPDATE` (as member actions do), then only the owner rows and your own. `FOR UPDATE` on the organization and every membership could deadlock with task inserts.
  - It skips an organization you were removed from meanwhile, and refuses (asks to try again) if you joined one while it ran.
  - Email and password changes run in a transaction with the token revocation, and their notices go out after the commit.
  - A taken email is a 422, not a 500.
  - The three routes that check your current password share one limit (5 a minute, 20 an hour). The daily email cap counts only requests that send mail.
  - Web: the "Name saved" and "Your account was deleted" toasts moved into their mutations (their components unmount first). The delete dialog's block message says to switch organization instead of linking to the current one's Members page.

## Follow-ups
- From the security review:
  - A per-address daily cap on reset emails (today it's per IP plus one a minute per address).
  - Send the reset link after the response, so timing doesn't tell real accounts apart (registration already does).
  - Clear the reset token from the URL with `history.replaceState` once it's read.
  - Mail a "your password was changed" notice.
- PHPUnit 13.4.3 has a dev-only advisory (code coverage on shared machines). Updating it waits for your OK.

## Commits after approval
1. `feat(api): password reset, account settings, devices and account deletion`.
2. `feat(web): password reset pages and account settings`.
3. Docs:
   - CLAUDE.md: the account conventions, `IssueToken` with the request, and the deletion order;
   - README: features, endpoints, screenshot.
4. This plan → Done.
