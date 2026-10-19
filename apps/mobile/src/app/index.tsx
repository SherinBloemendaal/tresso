import { useEffect, useState } from 'react'
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import type { Me } from '@tresso/core'
import { useAuth } from '@/auth/AuthProvider'
import { useTheme, type Theme } from '@/theme'

export default function Start() {
  const auth = useAuth()
  const theme = useTheme()
  const styles = makeStyles(theme)

  if (auth.restoring) {
    return (
      <SafeAreaView style={styles.screen}>
        <ActivityIndicator color={theme.accent} accessibilityLabel="Loading" />
      </SafeAreaView>
    )
  }

  return (
    <SafeAreaView style={styles.screen}>
      {auth.isAuthenticated ? <SignedIn styles={styles} /> : <SignIn styles={styles} />}
      {auth.storageError !== null && (
        <Text style={styles.error} accessibilityRole="alert">
          {auth.storageError}
        </Text>
      )}
    </SafeAreaView>
  )
}

type Styles = ReturnType<typeof makeStyles>

function SignIn({ styles }: { styles: Styles }) {
  const auth = useAuth()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function signIn() {
    setBusy(true)
    setError(null)
    try {
      await auth.signIn()
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <View style={styles.content}>
      <Text style={styles.title} accessibilityRole="header">
        Every email is something to do, to hand over, or to know.
      </Text>
      <Pressable
        style={({ pressed }) => [styles.primary, (pressed || busy) && styles.pressed]}
        onPress={signIn}
        disabled={busy}
        accessibilityRole="button"
        accessibilityState={{ busy, disabled: busy }}
      >
        <Text style={styles.primaryLabel}>{busy ? 'Signing in…' : 'Sign in'}</Text>
      </Pressable>
      {error !== null && (
        <Text style={styles.error} accessibilityRole="alert">
          Sign-in failed: {error}
        </Text>
      )}
    </View>
  )
}

function SignedIn({ styles }: { styles: Styles }) {
  const auth = useAuth()
  const [me, setMe] = useState<Me | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let current = true
    auth.session
      .me()
      .then((loaded) => current && setMe(loaded))
      .catch((e: unknown) => current && setError(e instanceof Error ? e.message : String(e)))
    return () => {
      current = false
    }
  }, [auth.session])

  return (
    <View style={styles.content}>
      {me === null && error === null && <ActivityIndicator accessibilityLabel="Loading your account" />}
      {me !== null && (
        <>
          <Text style={styles.title} accessibilityRole="header">
            {me.displayName}
          </Text>
          <Text style={styles.body}>{me.email}</Text>
        </>
      )}
      {error !== null && (
        <Text style={styles.error} accessibilityRole="alert">
          {error}
        </Text>
      )}
      <Pressable
        style={({ pressed }) => [styles.secondary, pressed && styles.pressed]}
        onPress={() => void auth.signOut()}
        accessibilityRole="button"
      >
        <Text style={styles.secondaryLabel}>Sign out</Text>
      </Pressable>
    </View>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { flex: 1, justifyContent: 'center', padding: 24, backgroundColor: theme.ground },
    content: { alignItems: 'center', gap: 16 },
    title: { color: theme.ink, fontSize: 24, lineHeight: 30, fontWeight: '700', textAlign: 'center' },
    body: { color: theme.ink2, fontSize: 16 },
    primary: { minHeight: 48, paddingHorizontal: 28, borderRadius: 6, justifyContent: 'center', backgroundColor: theme.accent },
    primaryLabel: { color: theme.onAccent, fontSize: 16, fontWeight: '600' },
    secondary: { minHeight: 44, paddingHorizontal: 20, borderRadius: 6, justifyContent: 'center', borderWidth: 1, borderColor: theme.line, backgroundColor: theme.surface },
    secondaryLabel: { color: theme.ink, fontSize: 15, fontWeight: '500' },
    pressed: { opacity: 0.7 },
    error: { color: theme.danger, fontSize: 14, textAlign: 'center', marginTop: 16 },
  })
}
