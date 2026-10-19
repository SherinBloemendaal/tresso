import { useEffect, useMemo, useState } from 'react'
import { FlatList, Pressable, StyleSheet, Text, View } from 'react-native'
import { Stack } from 'expo-router'
import type { Draft } from '@tresso/core'
import { useComposer } from '@/compose/ComposerProvider'
import { useMail } from '@/mail/MailProvider'
import { shortTime } from '@/mail/format'
import { useTheme, type Theme } from '@/theme'

function recipients(draft: Draft): string {
  const people = [...draft.to, ...draft.cc, ...draft.bcc].map((recipient) => recipient.name ?? recipient.address)
  return people.length === 0 ? 'No recipients yet' : `To ${people.join(', ')}`
}

/** Drafts on this phone (and synced from other devices), most recently changed first. */
export default function DraftsScreen() {
  const mail = useMail()
  const { composer } = useComposer()
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const [drafts, setDrafts] = useState<Draft[]>([])
  const { store, version } = mail

  useEffect(() => {
    let current = true
    // Sent messages wait here until they are confirmed; they are not drafts to the user.
    void (store()?.drafts() ?? Promise.resolve([])).then((list) => current && setDrafts(list.filter((draft) => draft.outgoingId === null)))
    return () => {
      current = false
    }
  }, [store, version])

  return (
    <>
      <Stack.Screen options={{ headerShown: true, title: 'Drafts', headerBackTitle: 'Inbox' }} />
      <FlatList
        style={styles.screen}
        data={drafts}
        keyExtractor={(draft) => draft.id}
        ListEmptyComponent={<Text style={styles.empty}>No drafts.</Text>}
        renderItem={({ item }) => (
          <Pressable
            style={({ pressed }) => [styles.row, pressed && styles.pressed]}
            onPress={() => void composer.openDraft(item.id)}
            accessibilityRole="button"
            accessibilityLabel={`${item.subject || '(no subject)'}. ${recipients(item)}. ${item.remoteVersion !== null ? 'Changed on another device. ' : ''}${shortTime(item.updatedAt)}`}
          >
            <View style={styles.texts}>
              <Text style={styles.subject} numberOfLines={1}>
                {item.subject || '(no subject)'}
              </Text>
              <Text style={styles.muted} numberOfLines={1}>
                {recipients(item)}
              </Text>
              {item.remoteVersion !== null && <Text style={styles.conflict}>Changed on another device</Text>}
            </View>
            <Text style={styles.muted}>{shortTime(item.updatedAt)}</Text>
          </Pressable>
        )}
      />
    </>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { flex: 1, backgroundColor: theme.surface },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 64, paddingHorizontal: 20, paddingVertical: 10, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.line },
    pressed: { backgroundColor: theme.ground },
    texts: { flex: 1, gap: 2 },
    subject: { fontSize: 16, fontWeight: '600', color: theme.ink },
    muted: { fontSize: 13, color: theme.ink2 },
    conflict: { fontSize: 13, color: theme.danger },
    empty: { padding: 20, fontSize: 15, color: theme.ink2 },
  })
}
