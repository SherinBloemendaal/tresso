import { useEffect, useState } from 'react'
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native'
import { SafeAreaView } from 'react-native-safe-area-context'
import { useAuth } from '@/auth/AuthProvider'
import { InboxList } from '@/components/InboxList'
import { useMail } from '@/mail/MailProvider'
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
    <SafeAreaView style={auth.isAuthenticated ? styles.signedIn : styles.screen}>
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
  const mail = useMail()
  const [accounts, setAccounts] = useState(0)
  const { mailAccounts, version } = mail

  useEffect(() => {
    let current = true
    void mailAccounts().then((list) => current && setAccounts(list.length))
    return () => {
      current = false
    }
  }, [mailAccounts, version])

  return (
    <View style={styles.fill}>
      <InboxList
        header={
          <View style={styles.header}>
            <View style={styles.titles}>
              <Text style={styles.heading} accessibilityRole="header">
                Unified Inbox
              </Text>
              <Text style={styles.body}>
                {accounts === 1 ? '1 account' : `${accounts} accounts`}
                {mail.live ? '' : ' · not live'}
              </Text>
            </View>
            <Pressable style={({ pressed }) => [styles.secondary, pressed && styles.pressed]} onPress={() => void auth.signOut()} accessibilityRole="button">
              <Text style={styles.secondaryLabel}>Sign out</Text>
            </Pressable>
          </View>
        }
      />
    </View>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { flex: 1, justifyContent: 'center', padding: 24, backgroundColor: theme.ground },
    signedIn: { flex: 1, backgroundColor: theme.surface },
    fill: { flex: 1 },
    header: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 20, paddingTop: 16, paddingBottom: 8 },
    titles: { flex: 1, gap: 2 },
    heading: { color: theme.ink, fontSize: 28, lineHeight: 34, fontWeight: '700' },
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
