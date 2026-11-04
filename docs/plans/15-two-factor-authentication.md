# 15 — Two-factor authentication

**Status:** Done
**Commits:** `7c853e1` (api), `958d8d1` (web)

## Goal
Let any user protect their account with an authenticator app (TOTP: the 6-digit codes from Google Authenticator, 1Password, Authy…). One-time recovery codes cover a lost phone. Logging in, and logging in after a password reset, then asks for a code once the password is right. Plan 17 (platform admin) builds on it by requiring two-factor for admins.

Out of scope:
- requiring two-factor for an organization's members (could become an organization setting);
- SMS or email codes, passkeys/WebAuthn, "remember this device";
- an admin resetting someone's two-factor, and the per-token "recent code" mark admin routes need (both plan 17).

## Decisions
- **Two new npm dependencies (need your OK):**
  - **`uqr`** draws the QR code the authenticator app scans: no dependencies, about 10 kB, the same one saas-starter uses. Without it, people would type a 32-character key. Its `encode()` returns a grid of on/off cells, which a component draws as an SVG `<path>` in JSX, so no `dangerouslySetInnerHTML` is needed.
  - **`input-otp`**, through the shadcn `input-otp` component (`npx shadcn add input-otp`). The 6-box code input that the shadcn docs show, with paste, autofill from SMS/password managers (`autocomplete="one-time-code"`) and screen reader support built in.
  - If you'd rather not add `input-otp`, a plain `Input` with `inputMode="numeric"` works; it just looks plainer.
- **TOTP written in the app, no Composer package.** Copy `App\Support\Totp` from saas-starter: RFC 6238, about 40 lines, tested against the RFC's own vectors.
  - The secret comes from `random_bytes(20)`, base32 encoded.
  - Codes are compared with `hash_equals`; one 30-second step either side is accepted.
- **Login becomes two steps when two-factor is on.**
  - `POST /api/login` with the right password answers `{ two_factor: true, challenge }` instead of a token.
  - The challenge is a random 40-character string. The cache stores its sha256 for 5 minutes with the user id, the client's `device_name` and a fingerprint of the account state.
  - `POST /api/two-factor-challenge` with `challenge` plus `code` (or `recovery_code`) answers the usual `{ user, token }` through `IssueToken`.
- **A password reset doesn't get around it.** With two-factor on, `reset-password` still changes the password and signs out every device. It then answers a challenge instead of a token, so the inbox alone isn't enough to take over the account.
- **Limits and one-time use** (from saas-starter's reviews):
  - each challenge allows 5 tries and works once;
  - a code's step is stored (`two_factor_last_used_step`), so the same code can't be used twice;
  - a challenge stops working once the password or two-factor setup changes (its fingerprint);
  - a challenge is used up while the user row is locked, so two requests with two valid codes can't both get a token;
  - 10 wrong codes in 15 minutes for one account, across challenges and IPs, pause two-factor logins for that account and email it once (`TwoFactorLockedOut`);
  - `throttle:6,1,two-factor` on the challenge route, a counter separate from login's.
- **Storage on `users`:**
  - `two_factor_secret` (`encrypted` cast);
  - `two_factor_recovery_codes` (JSON array of sha256 hashes; the codes are random, so a slow hash isn't needed);
  - `two_factor_confirmed_at` and `two_factor_last_used_step`.

  All of them are `$hidden` and never fillable. Two-factor only counts as on once `confirmed_at` is set, so a setup that wasn't finished never locks anyone out.
- **Recovery codes:** 8, formatted `xxxxx-xxxxx`, each usable once. They're shown when setup is confirmed and again only when you make a new set. Using one emails "a recovery code was used, N left".
- **Turning it on, off, and new codes:**
  - Starting setup needs your password; confirming needs a code from the app.
  - Turning it on signs out your other devices, like changing your password.
  - **Turning it off and making new recovery codes need your password and a current code** (or a recovery code, to turn it off after losing the phone). In saas-starter this was a follow-up from the security review: without it, a stolen session plus a phished password could remove two-factor. Wrong codes count toward the same per-account limit as login.
  - Each of these emails the account: `TwoFactorEnabled`, `TwoFactorDisabled`, `RecoveryCodesRegenerated`.
- **Undoing an email change turns two-factor off.**
  - Plan 14's undo link (`RevertEmailChange`) means someone else had the account. If they turned two-factor on with their own phone, the reset link the owner receives would still ask for their code.
  - So the undo link also clears two-factor, and the "your old email is back" message says to turn it on again.
  - It can't be used to remove two-factor quietly: the undo link only exists after an email change, which needed a login (password plus code).
- **Not audited.** These are account events, outside any organization's audit log (same as plan 14).

## Backend (`api/`)
- Migration (new) `add_two_factor_columns_to_users_table`: `two_factor_secret` (text), `two_factor_recovery_codes` (json), `two_factor_confirmed_at` (timestamp), `two_factor_last_used_step` (bigInteger), all nullable.
- `App\Support\Totp` (copied): `generateSecret()`, `verify($secret, $code, ?$lastUsedStep): ?int`, `provisioningUri($secret, $email, $issuer)` (issuer `config('app.name')`), base32.
- `App\Support\RecoveryCodes` (copied): `generate()`, `hash()`.
- `User`: casts, `$hidden`, `hasTwoFactorEnabled()`, docblock properties.
- Actions, `app/Actions/Auth`:
  - `IssueTwoFactorChallenge` (copied: TTL 300 s, 5 tries, `fingerprint()` from the password hash and `confirmed_at`);
  - `CompleteTwoFactorChallenge`: adapted from saas-starter without the suspension check (plan 17) or the `twoFactor:` token mark.
  - `LoginUser` and `ResetUserPassword` return `{user, token}` or `{two_factor: true, challenge}`.
- Actions, `app/Actions/Account`:
  - `StartTwoFactorSetup`: a new unconfirmed secret, returns `secret` and `otpauth_uri`; 422 if already on.
  - `ConfirmTwoFactor`: checks the code on the locked row, sets `confirmed_at` and `last_used_step`, creates the codes, revokes the other tokens and notifies after commit. Returns the plain codes.
  - `DisableTwoFactor`: verifies the code or recovery code on the locked row, then clears the four columns and notifies.
  - `RegenerateRecoveryCodes`: verifies a current code, replaces the set and notifies.
  - Code checks shared by these and the challenge go in one place (`App\Support\TwoFactorCode::verify($user, $code, $recoveryCode)`), so the step and the per-account counter work the same everywhere.
  - `RevertEmailChange`: also clears the two-factor columns.
- Notifications, queued like `EmailChanged`: `TwoFactorEnabled`, `TwoFactorDisabled`, `RecoveryCodesRegenerated`, `RecoveryCodeUsed`, `TwoFactorLockedOut`.
- Form Requests:
  - `Account/StartTwoFactorRequest` (`current_password`);
  - `Account/ConfirmTwoFactorRequest` (`code`: 6 digits);
  - `Account/DisableTwoFactorRequest` (`current_password`, plus `code` or `recovery_code`);
  - `Account/RegenerateRecoveryCodesRequest` (`current_password`, `code`);
  - `Auth/TwoFactorChallengeRequest` (`challenge`, plus `code` or `recovery_code`).
- `UserResource`: `two_factor_enabled` (bool).
- Controllers:
  - `TwoFactorController` (account side);
  - `TwoFactorChallengeController`;
  - `AuthController::loginResponse` and `PasswordResetController` pass a challenge answer through unchanged.
- Routes:
  - Guest: `POST /api/two-factor-challenge` (`throttle:6,1,two-factor`).
  - Authenticated, outside the `organization` group like the other account routes:
    - `POST /api/account/two-factor` (start; `throttle:current-password`)
    - `POST /api/account/two-factor/confirm` (`throttle:6,1,two-factor-setup`)
    - `POST /api/account/two-factor/recovery-codes` (`throttle:current-password`)
    - `DELETE /api/account/two-factor` (`throttle:current-password`)
- `.env.example`: no new keys.
- `CLAUDE.md`: a two-factor bullet in Backend conventions; the `IssueToken` line names the challenge.

## Frontend (`web/`)
- Types:
  - `User.two_factor_enabled`;
  - `TwoFactorChallenge = { two_factor: true; challenge: string }`;
  - `LoginResult = AuthResponse | TwoFactorChallenge`, narrowed with `'challenge' in result`;
  - `TwoFactorSetup` (`secret`, `otpauth_uri`) and `RecoveryCodeList`.
- `features/auth/api.ts`:
  - `useLogin` and `useResetPassword` start the session for a token, and otherwise return the challenge to the page;
  - `useCompleteTwoFactor()` posts the code, then `withOrganizations` and `useStartSession` like a login.
- `features/auth/TwoFactorChallengeForm.tsx`, used by the login and reset pages:
  - "Enter the code from your authenticator app" over the 6-box `InputOTP`, which submits when all six are filled;
  - "Use a recovery code instead" switches to a text input; "Back" returns to the password step;
  - an expired challenge (`challenge` error) sends you back with the message.
- `LoginPage` / `LoginForm`: hold the challenge in component state (`useState`, never persisted), showing the code step in place of the password form. `ResetPasswordPage` does the same after a reset.
- `features/account/TwoFactorCard.tsx`, between Password and Signed-in devices:
  - **Off:** a short explanation and "Turn on".
  - **Turning it on:** one Dialog with three steps:
    1. your password;
    2. the QR code (`QrCode.tsx`, from `uqr`'s `encode()`), the key in groups of four with Copy, and the code;
    3. the recovery codes with Copy, Download (.txt) and "I've saved them" (the dialog can't be dismissed on this step).
  - **On:** an "On" badge, "New recovery codes" (password and code, then the codes step) and "Turn off" (password and code, or a recovery code).
- `features/account/api.ts`: `useStartTwoFactor`, `useConfirmTwoFactor`, `useDisableTwoFactor`, `useRegenerateRecoveryCodes`. Each refreshes the user with `setUser`; confirming also invalidates devices, since the other devices were signed out.
- `RevertEmailPage`: the success message mentions that two-factor was turned off, when it was.
- `README.md`: Status sentence, the endpoints table (challenge, the four account routes), the login steps.

React concepts:
- **A discriminated union as the mutation result** (`'challenge' in result`): TypeScript narrows it like a `v-if` on a type tag, with no casts.
- **One page, two steps, held in `useState`:** `useState<string | null>` ↔ a `ref` choosing between two child forms, plus `key` to reset a form when you go back.
- **A dialog run by a small state machine:** a `step` union (`'password' | 'setup' | 'codes'`) in `useState` in place of several booleans; Radix `onOpenChange` / `onInteractOutside` keeps it open on the codes step (↔ Nuxt UI's `dismissible`).
- **A third-party input through react-hook-form's `Controller`:** `InputOTP` isn't a native input, so `register()` can't wire it (the same as `Select`).
- **Deriving the QR code during render with `useMemo`** ↔ `computed`, drawn as JSX SVG, not an HTML string.
- **Browser APIs from an event handler:** `navigator.clipboard.writeText`, and a `Blob` plus `URL.createObjectURL` for the .txt download. Neither needs an effect: they run on click.

## Tests
- `TotpTest` (copied): the RFC 6238 SHA1 vectors; one step either side accepted, two refused; a used step refused; base32 round trip.
- `TwoFactorTest` (account):
  - start needs the password and is refused while on;
  - a wrong code leaves it off; the right one turns it on, returns 8 codes, revokes the other tokens (not the current one) and sends `TwoFactorEnabled`;
  - an unfinished setup doesn't affect login;
  - turning off needs the password and a code (or a recovery code); it then clears the columns and sends `TwoFactorDisabled`;
  - new codes need the password and a code, replace the old set (old codes stop working) and send `RecoveryCodesRegenerated`;
  - wrong codes there count toward the per-account limit;
  - `/me` never contains the secret or the codes.
- `TwoFactorChallengeTest` (login):
  - with two-factor on, login gives a challenge and no token; a right code gives a working token named after the device;
  - a reused code is refused;
  - the 5th wrong try ends the challenge;
  - a challenge expires after 5 minutes;
  - a password reset after the challenge was issued makes it stop working;
  - a recovery code works once and sends `RecoveryCodeUsed`;
  - two requests can't use one challenge twice (the second finds it gone);
  - 10 wrong codes lock the account and email it once;
  - the route is throttled;
  - login without two-factor is unchanged.
- `PasswordResetTest`: with two-factor on, the password changes and tokens are revoked, but the answer is a challenge.
- `AccountSettingsTest`: the email undo link clears two-factor.
- Isolation: no tenant-owned models. One user's challenge can't log in as another, and the account routes only touch the logged-in user.
- Vitest:
  - login with two-factor (code step, recovery code, back, expired challenge);
  - reset with two-factor;
  - `TwoFactorCard` (turn on through all three steps, wrong code error, codes view not dismissible, turn off, new codes).

## README screenshots
- New `docs/screenshots/two-factor-setup.png`: the setup step of the dialog with the QR code and the key. The demo account is deleted afterwards, so its secret is worthless.
- Retake `docs/screenshots/account.png`, which gains the two-factor card.
- Light mode, 1280×800, `e2e-*` accounts cleaned up.

## Verification
- `verify` skill.
- `e2e-smoke` with `--mail`.
- Reviewers in parallel: code, security, tenant isolation, database (migration and locks), UI (login, reset and account pages).

Flows (for `flow-tester`). Codes come from the key shown at setup, through `php artisan tinker --execute` with `App\Support\Totp::codeAt`.
1. Sign up and verify. On Account, Turn on with a wrong password → error, still off.
2. Turn it on and enter a wrong code → error, still off. Enter the right code → recovery codes shown, the card says On, and the "turned on" email arrives.
3. Log out and log in with the password → asked for a code. A wrong code → error. The right code → logged in.
4. Log out and log in with a recovery code → logged in, and the "recovery code used" email arrives. The same recovery code again → refused.
5. Forgot password, then reset from the email link → asked for a code, and logged in only after entering it.
6. New recovery codes (password and code) → an old recovery code no longer works. Turn it off (password and code) → the next login doesn't ask for a code, and the "turned off" email arrives.

## Changes during the build
- The shared code check is an action, `App\Actions\Auth\CheckTwoFactorCode` (it sends `TwoFactorLockedOut`), not `App\Support\TwoFactorCode`. `useCode()` works on a row the caller locked (the login challenge); `run()` locks the row, checks the code and runs the account change in one transaction (turning off, new codes). Wrong codes are counted outside the transaction, so a database cache store can't roll the count back.
- When the account is paused, the login challenge reports it under `challenge`, so the app goes back to the password step; the account actions report it under `code`.
- Confirming setup answers the recovery codes and the user (`data.user`), and turning it off answers the user, so the app stores it without reloading `/me`.
- `RevertEmailChange` returns whether two-factor was on, and the undo page's message says it was turned off.
- After a reset with two-factor on, leaving the code step (Back or an expired challenge) shows "Your new password is saved" with a link to log in, since the password already changed.
- Login and reset controllers share `Concerns\RespondsWithLogin`; `AccountController` and `TwoFactorController` share `Concerns\UsesCurrentToken`.
- Notifications drop saas-starter's "contact support" (there's no support here).
- `test/setup.ts` stubs `document.elementFromPoint`, which input-otp calls and jsdom lacks. `form.watch()` became `useWatch()` (the React Compiler lint rule).

### Review fixes
- **Reset race (database):** `ResetUserPassword`'s row lock never held, since the password broker opens no transaction, so a reset could read "two-factor off" while a setup was being confirmed and log in without a code. The broker call now runs in `DB::transaction` (a full closure: an arrow function copied `$resetUser`).
- **Lock order (database):** `DeleteAccount` locks the user row before deleting its tokens, like every other account action. `ChangePassword` locks the row too. `two_factor_recovery_codes` is `jsonb`, like the repo's other JSON columns (converted in place on dev, since the migration had run and rollbacks are off-limits).
- **Revoked session (database/security):** `ConfirmTwoFactor` refuses when the current token was revoked meanwhile.
- **Undo link vs the owner's two-factor (security):** the email undo link cleared any two-factor, so the old inbox alone could take over an account whose owner had two-factor. New `users.email_changed_at` (set when a change is confirmed): it now clears only a setup made since the change.
- **Hijacker locks the owner out (security):** someone with the password could turn two-factor on with their phone, and then even a reset needs their code. The "turned on" email carries a signed "This wasn't me" link (7 days, bound to that setup's `two_factor_confirmed_at`): `RevertTwoFactor` turns it off, signs out every device and mails a reset. The same trade-off as the email undo link: the inbox can undo a setup in its first 7 days.
- **Lockout race (security):** tries are counted before the code is checked and given back after a right one; a right code no longer clears the count, so someone with the password gets 10 wrong guesses per 15 minutes however often the owner logs in. `Cache::add` before `increment`, so a forgotten attempts key can't come back without a TTL.
- **Chained email changes (security re-check, from plan 14):** a second email change made the first one's undo link useless (it's bound to that change), which also skipped the two-factor clearing above. `ChangeEmail` now refuses a change for 7 days after the last confirmed one. Without `email_changed_at` (changes from before it existed) the undo link keeps two-factor. Giving a try back never takes the count below zero.
- **Codes lost on close (code):** the recovery codes step is set from the hooks' own `onSuccess` (`onRecoveryCodes`), which still runs if the dialog was closed mid-request; mutate()'s callbacks wouldn't, and a new set would have replaced the old codes unseen.
- **Focus after a wrong code (code):** the code boxes stay enabled while sending and get the Controller's `ref`, so focus comes back for the next try.
- **UI:** the setup step focuses the code input; the key wraps between its groups of four.

## Follow-ups
- Let a recovery code skip the per-account pause, so someone with the password can't keep the owner out 15 minutes at a time.
- A reset with two-factor on still changes the password before the code is asked for: someone with only the inbox can't log in, but can sign the owner out. Ask for the code first.
- Lockouts and recovery-code use are only emailed; an "account activity" list could show them.
- Upgrade PHPUnit to 13.4.4 or later (dev-only advisory).

## Commits after approval
`feat(api): add two-factor authentication with TOTP and recovery codes`, `feat(web): add two-factor setup and the login code step`, then this plan → Done.
