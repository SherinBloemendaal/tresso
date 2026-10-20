import { useEffect, useMemo, useRef, useState } from 'react'
import { Pressable, SectionList, StyleSheet, Text, View } from 'react-native'
import { Stack, useRouter } from 'expo-router'
import { handOverStatus, type SyncedHandOver, type SyncedOutgoingMessage } from '@tresso/core'
import { shortDue, statusText } from '@/handOver/format'
import { useHandOvers } from '@/handOver/HandOverProvider'
import { useMail } from '@/mail/MailProvider'
import { senderName } from '@/mail/format'
import { useTheme, type Theme } from '@/theme'

/** Closed hand-overs listed below the open ones; older ones stay out of the way. */
const CLOSED_SHOWN = 20

/** Conversations handed over, waited on until the delegate replies. */
export default function WaitingScreen() {
  const mail = useMail()
  const handOvers = useHandOvers()
  const router = useRouter()
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const [list, setList] = useState<SyncedHandOver[]>([])
  const [nudges, setNudges] = useState(new Map<string, SyncedOutgoingMessage | null>())
  const [busy, setBusy] = useState<string | null>(null)
  const { handOvers: load, outgoing, version } = mail

  useEffect(() => {
    let current = true
    void load().then(async (loaded) => {
      const next = new Map<string, SyncedOutgoingMessage | null>()
      for (const handOver of loaded) {
        if (handOver.lastNudgeId !== null) {
          next.set(handOver.id, await outgoing(handOver.lastNudgeId))
        }
      }
      if (current) {
        setList(loaded)
        setNudges(next)
      }
    })
    return () => {
      current = false
    }
  }, [load, outgoing, version])

  const sections = [
    { title: 'Handed over', data: list.filter((handOver) => handOver.state === 'open') },
    { title: 'Closed', data: list.filter((handOver) => handOver.state !== 'open').slice(0, CLOSED_SHOWN) },
  ].filter((section) => section.data.length > 0)

  // Set at once, unlike state, so a second tap before the list updates does not nudge twice.
  const acting = useRef(false)

  async function act(handOver: SyncedHandOver, run: (handOver: SyncedHandOver) => Promise<void>) {
    if (acting.current) {
      return
    }
    acting.current = true
    setBusy(handOver.id)
    try {
      await run(handOver)
    } finally {
      acting.current = false
      setBusy(null)
    }
  }

  return (
    <>
      <Stack.Screen options={{ headerShown: true, title: 'Waiting on', headerBackTitle: 'Inbox' }} />
      <SectionList
        style={styles.screen}
        sections={sections}
        keyExtractor={(handOver) => handOver.id}
        ListEmptyComponent={<Text style={styles.empty}>Nothing handed over. Hand over a conversation to forward it with a note and a due date; it shows here until they reply.</Text>}
        renderSectionHeader={({ section }) => (
          <Text style={styles.section} accessibilityRole="header">
            {section.title}
          </Text>
        )}
        renderItem={({ item }) => {
          const status = handOverStatus(item)
          const text = statusText(item, nudges.get(item.id) ?? null)
          return (
            <View style={styles.row}>
              <Pressable
                style={({ pressed }) => [styles.texts, pressed && styles.pressed]}
                onPress={() => item.threadId !== null && router.push(`/thread/${item.threadId}`)}
                disabled={item.threadId === null}
                accessibilityRole="button"
                accessibilityLabel={`${item.subject || '(no subject)'}. Handed over to ${senderName(item.delegate)}. Due ${shortDue(item.dueAt)}. ${text}.`}
              >
                <Text style={styles.subject} numberOfLines={1}>
                  {item.subject || '(no subject)'}
                </Text>
                <Text style={styles.muted} numberOfLines={1}>
                  {senderName(item.delegate)}, due {shortDue(item.dueAt)}
                </Text>
                <Text style={[styles.status, status === 'overdue' && styles.late]}>{text}</Text>
              </Pressable>
              {item.state === 'open' ? (
                <View style={styles.actions}>
                  <RowButton label="Nudge" busy={busy === item.id} styles={styles} onPress={() => void act(item, handOvers.nudge)} />
                  <RowButton label="Done" busy={busy === item.id} styles={styles} onPress={() => void act(item, handOvers.markDone)} />
                </View>
              ) : (
                <RowButton label="Wait again" busy={busy === item.id} styles={styles} onPress={() => void act(item, handOvers.reopen)} />
              )}
            </View>
          )
        }}
      />
    </>
  )
}

function RowButton({ label, busy, styles, onPress }: { label: string; busy: boolean; styles: ReturnType<typeof makeStyles>; onPress: () => void }) {
  return (
    <Pressable style={({ pressed }) => [styles.button, (pressed || busy) && styles.pressed]} onPress={onPress} disabled={busy} accessibilityRole="button" accessibilityState={{ disabled: busy }}>
      <Text style={styles.buttonLabel}>{label}</Text>
    </Pressable>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { flex: 1, backgroundColor: theme.surface },
    section: { paddingHorizontal: 20, paddingTop: 18, paddingBottom: 6, fontSize: 13, fontWeight: '600', color: theme.ink2, backgroundColor: theme.surface },
    row: { flexDirection: 'row', alignItems: 'center', gap: 8, minHeight: 72, paddingHorizontal: 20, paddingVertical: 10, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.line },
    texts: { flex: 1, gap: 2 },
    subject: { fontSize: 16, fontWeight: '600', color: theme.ink },
    muted: { fontSize: 13, color: theme.ink2 },
    status: { fontSize: 13, fontWeight: '600', color: theme.ink2 },
    late: { color: theme.danger },
    actions: { flexDirection: 'row', gap: 6 },
    button: { minHeight: 40, minWidth: 44, paddingHorizontal: 12, borderRadius: 999, borderWidth: 1, borderColor: theme.lineStrong, justifyContent: 'center' },
    buttonLabel: { fontSize: 14, fontWeight: '600', color: theme.ink },
    pressed: { opacity: 0.5 },
    empty: { padding: 20, fontSize: 15, color: theme.ink2 },
  })
}
