/**
 * Go to a page outside the app (Stripe's Checkout or Customer Portal): a full page
 * load, since React Router's navigate() only moves between the app's own routes.
 * Its own module, so tests can replace it (jsdom can't navigate).
 */
export function leaveApp(url: string): void {
  window.location.assign(url)
}
