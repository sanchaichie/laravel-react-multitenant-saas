/**
 * Where someone in several organizations goes right after logging in: the
 * picker, which then continues to `redirect`. Not when `redirect` is already the
 * picker (no picker inside a picker) or an invitation link (/join/..., plan 06),
 * where accepting chooses the organization anyway.
 */
export function chooseOrganizationTarget(redirect: string): string {
  if (redirect.startsWith('/select-organization') || redirect.startsWith('/join/')) {
    return redirect
  }

  return `/select-organization?redirect=${encodeURIComponent(redirect)}`
}
