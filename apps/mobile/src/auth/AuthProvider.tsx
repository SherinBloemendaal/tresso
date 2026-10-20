import { createContext, useContext, useEffect, useMemo, useState, useSyncExternalStore, type ReactNode } from 'react'
import { Session, type SessionState } from '@/auth/session'
import { secureTokenStore } from '@/auth/secureTokenStore'
import { signInInBrowser } from '@/auth/browserSignIn'
import { config } from '@/config'
import { secureDeviceId } from '@/notifications/expoPush'
import { forgetPushDevice } from '@/notifications/push'

interface Auth extends SessionState {
  session: Session
  /** The sign-in from the previous launch is still being read. */
  restoring: boolean
  isAuthenticated: boolean
  /** Signs in through the system browser; resolves false when the user closed it. */
  signIn(): Promise<boolean>
  /**
   * Removes this phone from the user's push devices, then signs out. Signed out either way; rejects when the server
   * could not be told, so this phone may still get the user's notifications until someone signs in here again.
   */
  signOut(): Promise<void>
}

const AuthContext = createContext<Auth | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const session = useMemo(() => new Session(config, secureTokenStore), [])
  const state = useSyncExternalStore(
    (listener) => session.subscribe(listener),
    () => session.snapshot,
  )
  const [restoring, setRestoring] = useState(true)

  useEffect(() => {
    void session.restore().finally(() => setRestoring(false))
  }, [session])

  const auth = useMemo<Auth>(
    () => ({
      ...state,
      session,
      restoring,
      isAuthenticated: state.tokens !== null,
      async signIn() {
        const authorization = await signInInBrowser()
        if (authorization === null) {
          return false
        }
        await session.completeSignIn(authorization.code, authorization.codeVerifier)
        return true
      },
      async signOut() {
        try {
          await forgetPushDevice((path, init) => session.api(path, init), secureDeviceId)
        } finally {
          await session.signOut()
        }
      },
    }),
    [state, session, restoring],
  )

  return <AuthContext.Provider value={auth}>{children}</AuthContext.Provider>
}

export function useAuth(): Auth {
  const auth = useContext(AuthContext)
  if (auth === null) {
    throw new Error('useAuth() is used outside <AuthProvider>.')
  }
  return auth
}
