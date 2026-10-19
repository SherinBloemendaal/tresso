import type { RouteLocationRaw } from 'vue-router'

/**
 * Where a link the desktop app was opened with leads: a sign-in result for this app's redirect URI goes to the
 * callback screen with its parameters (code and state, or the error); anything else is not ours and is ignored.
 */
export function signInRoute(link: string, redirectUri: string): RouteLocationRaw | null {
  const queryStart = link.indexOf('?')
  const base = queryStart === -1 ? link : link.slice(0, queryStart)
  if (base !== redirectUri) {
    return null
  }
  const params = new URLSearchParams(queryStart === -1 ? '' : link.slice(queryStart + 1))
  return { name: 'auth-callback', query: Object.fromEntries(params) }
}
