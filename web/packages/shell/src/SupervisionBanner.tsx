import type { UserSummary } from '@kombo/api-client'

/**
 * "This screen is being run by the owner from their session, not a shift."
 *
 * Two reasons, and the second matters more. First: the owner has to be able to
 * open their own till to supervise, without registering the device and typing a
 * PIN right after signing into the dashboard.
 *
 * Second: this already happened, silently. Sanctum prefers the session cookie
 * over the token, so on a machine where somebody left the dashboard open, the
 * cashier typed their PIN and everything ran in the OWNER's name with nothing
 * saying so. The banner turns that trap into a visible fact — the screen names
 * whoever is really operating, because `/me` says so and not the stored token.
 *
 * Amber rather than red: it is not a failure, it is a "mind this".
 */
export function SupervisionBanner({ user, onLeave }: { user: UserSummary; onLeave: () => void }) {
  return (
    <div
      role="status"
      // Named: this screen has more than one `status` region, and without names
      // they are indistinguishable to a screen reader and to a test.
      aria-label="Supervisión"
      className="flex shrink-0 items-center gap-3 bg-warn-500 px-4 py-2 text-sm text-ink-900"
    >
      <span aria-hidden="true">⚠</span>

      <p className="min-w-0 flex-1 truncate font-medium">
        Supervisando · {user.name}
        {user.roleName != null && ` (${user.roleName})`}
      </p>

      {/* Not "Sign out": there is no shift to close. Whoever arrived here from
          the dashboard expects to go back to it. */}
      <button
        type="button"
        onClick={onLeave}
        className="min-h-11 shrink-0 font-medium underline-offset-2 hover:underline"
      >
        Volver al panel
      </button>
    </div>
  )
}
