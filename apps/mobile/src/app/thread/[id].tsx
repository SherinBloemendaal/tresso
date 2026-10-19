import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native'
import { Stack, useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router'
import { useSafeAreaInsets } from 'react-native-safe-area-context'
import type { SyncedFolder, SyncedMessage, SyncedThread } from '@tresso/core'
import { MessageBodyView } from '@/components/MessageBodyView'
import { useComposer } from '@/compose/ComposerProvider'
import { useMail } from '@/mail/MailProvider'
import { useNotices } from '@/components/Notices'
import { useMessageActions } from '@/mail/useMessageActions'
import { initials, senderName, shortTime } from '@/mail/format'
import { useTheme, type Theme } from '@/theme'

/** The action bar's height above the safe area. */
const BAR_HEIGHT = 56

/** A conversation: its messages oldest first; unread ones and the newest open, read history folded. */
export default function ThreadScreen() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const mail = useMail()
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const [thread, setThread] = useState<SyncedThread | null>(null)
  const [messages, setMessages] = useState<SyncedMessage[]>([])
  const [missing, setMissing] = useState(false)
  const [expanded, setExpanded] = useState<Set<string> | null>(null)
  const [folders, setFolders] = useState<SyncedFolder[]>([])
  const { thread: load, folders: loadFolders, version } = mail
  const router = useRouter()
  const insets = useSafeAreaInsets()
  const actions = useMessageActions()
  const { after, run, exclusive, relocate, busy } = actions
  /** The conversation that was read on open; another one (the screen reused for a link) is read again. */
  const read = useRef<string | null>(null)
  const mounted = useRef(true)
  const notices = useNotices()
  const { composer } = useComposer()

  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  // The action bar stays clear of notices while this screen is the one in front.
  useFocusEffect(
    useCallback(() => {
      notices.setBottomInset(BAR_HEIGHT)
      return () => notices.setBottomInset(0)
    }, [notices]),
  )

  useEffect(() => {
    let current = true
    void loadFolders().then((list) => current && setFolders(list))
    return () => {
      current = false
    }
  }, [loadFolders, version])

  // Another conversation in the same screen starts with its own folding.
  const [shownId, setShownId] = useState(id)
  if (shownId !== id) {
    setShownId(id)
    setExpanded(null)
  }

  /** Back to the list, if this screen is still the one on screen; from a link with no history, to the inbox. */
  function leave() {
    if (!mounted.current) {
      return
    }
    if (router.canGoBack()) {
      router.back()
    } else {
      router.replace('/')
    }
  }

  useEffect(() => {
    let current = true
    void load(id).then((result) => {
      if (!current) {
        return
      }
      setMissing(result === null)
      setThread(result?.thread ?? null)
      setMessages(result?.messages ?? [])
      // Opening a conversation reads it, once; actions taken meanwhile wait for that.
      if (result !== null && read.current !== id) {
        read.current = id
        const unread = result.messages.filter((m) => !m.flags.seen).map((m) => m.id)
        if (unread.length > 0) {
          after(run(unread, 'markRead'))
        }
      }
      setExpanded((open) => {
        if (open !== null || result === null) {
          return open
        }
        const first = new Set(result.messages.filter((m) => !m.flags.seen).map((m) => m.id))
        const newest = result.messages[result.messages.length - 1]
        if (newest) {
          first.add(newest.id)
        }
        return first
      })
    })
    return () => {
      current = false
    }
  }, [load, id, version, after, run])

  const flagged = messages.some((message) => message.flags.flagged)

  function relocateAll(action: 'archive' | 'trash') {
    void exclusive(async () => {
      if ((await relocate(messages.map((m) => m.id), action, folders)) !== null) {
        leave()
      }
    })
  }

  function markUnread() {
    void exclusive(async () => {
      const newest = messages[messages.length - 1]
      if (newest && (await run([newest.id], 'markUnread')) !== null) {
        leave()
      }
    })
  }

  function toggleFlag() {
    void exclusive(async () => {
      if (flagged) {
        await run(messages.filter((m) => m.flags.flagged).map((m) => m.id), 'unflag')
        return
      }
      const newest = messages[messages.length - 1]
      if (newest) {
        await run([newest.id], 'flag')
      }
    })
  }

  function toggle(messageId: string) {
    setExpanded((open) => {
      const next = new Set(open)
      if (!next.delete(messageId)) {
        next.add(messageId)
      }
      return next
    })
  }

  return (
    <>
      <Stack.Screen options={{ headerShown: true, title: '', headerBackTitle: 'Inbox' }} />
      <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
        {missing && <Text style={styles.muted}>This conversation no longer exists.</Text>}
        {thread !== null && (
          <>
            <Text style={styles.subject} accessibilityRole="header">
              {thread.subject || '(no subject)'}
            </Text>
            {messages.map((message) => {
              const open = expanded?.has(message.id) ?? false
              const to = [...message.recipients.to, ...message.recipients.cc].map(senderName).join(', ')
              return (
                <View key={message.id} style={styles.message}>
                  <Pressable style={styles.head} onPress={() => toggle(message.id)} accessibilityRole="button" accessibilityState={{ expanded: open }}>
                    <View style={styles.avatar}>
                      <Text style={styles.avatarText}>{initials(message.from)}</Text>
                    </View>
                    <View style={styles.who}>
                      <Text style={[styles.from, !message.flags.seen && styles.bold]} numberOfLines={1}>
                        {senderName(message.from)}
                      </Text>
                      <Text style={styles.muted} numberOfLines={1}>
                        {open ? (to === '' ? '' : `To ${to}`) : message.snippet}
                      </Text>
                    </View>
                    <Text style={styles.muted}>{shortTime(Date.parse(message.receivedAt))}</Text>
                  </Pressable>
                  {open && <MessageBodyView messageId={message.id} />}
                  {open && (
                    <View style={styles.replies} accessibilityRole="toolbar" accessibilityLabel={`Answer ${senderName(message.from)}`}>
                      <ReplyButton label="Reply" onPress={() => void composer.openReply(message, 'reply')} styles={styles} />
                      <ReplyButton label="Reply all" onPress={() => void composer.openReply(message, 'replyAll')} styles={styles} />
                      <ReplyButton label="Forward" onPress={() => void composer.openReply(message, 'forward')} styles={styles} />
                    </View>
                  )}
                </View>
              )
            })}
          </>
        )}
      </ScrollView>
      {thread !== null && (
        <View style={[styles.bar, { height: BAR_HEIGHT + insets.bottom, paddingBottom: insets.bottom }]} accessibilityRole="toolbar">
          <BarButton label="Archive" busy={busy} styles={styles} onPress={() => relocateAll('archive')} />
          <BarButton label="Trash" busy={busy} styles={styles} onPress={() => relocateAll('trash')} />
          <BarButton label="Unread" busy={busy} styles={styles} onPress={() => markUnread()} />
          <BarButton label="Flag" busy={busy} styles={styles} checked={flagged} onPress={() => toggleFlag()} />
        </View>
      )}
    </>
  )
}

function ReplyButton({ label, styles, onPress }: { label: string; styles: ReturnType<typeof makeStyles>; onPress: () => void }) {
  return (
    <Pressable style={({ pressed }) => [styles.reply, pressed && styles.replyPressed]} onPress={onPress} accessibilityRole="button">
      <Text style={styles.replyLabel}>{label}</Text>
    </Pressable>
  )
}

function BarButton({ label, busy, checked, styles, onPress }: { label: string; busy: boolean; checked?: boolean; styles: ReturnType<typeof makeStyles>; onPress: () => void }) {
  return (
    <Pressable
      style={({ pressed }) => [styles.action, (pressed || busy) && styles.pressed]}
      onPress={onPress}
      disabled={busy}
      accessibilityRole={checked === undefined ? 'button' : 'togglebutton'}
      accessibilityState={{ disabled: busy, checked }}
    >
      <Text style={[styles.actionLabel, checked === true && styles.on]}>{label}</Text>
    </Pressable>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { flex: 1, backgroundColor: theme.surface },
    content: { padding: 20, gap: 16 },
    subject: { color: theme.ink, fontSize: 24, lineHeight: 30, fontWeight: '700' },
    replies: { flexDirection: 'row', gap: 8, paddingTop: 4 },
    reply: { minHeight: 40, paddingHorizontal: 14, borderRadius: 999, borderWidth: 1, borderColor: theme.lineStrong, justifyContent: 'center' },
    replyPressed: { backgroundColor: theme.ground },
    replyLabel: { fontSize: 14, fontWeight: '600', color: theme.ink },
    message: { gap: 12, paddingBottom: 16, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.line },
    head: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 44 },
    avatar: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: theme.ground },
    avatarText: { color: theme.ink, fontSize: 13, fontWeight: '600' },
    who: { flex: 1, gap: 2 },
    from: { color: theme.ink, fontSize: 16 },
    bold: { fontWeight: '600' },
    muted: { color: theme.ink2, fontSize: 13 },
    on: { color: theme.ink },
    bar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-around', borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: theme.line, backgroundColor: theme.surface },
    action: { minWidth: 64, minHeight: 44, paddingHorizontal: 8, alignItems: 'center', justifyContent: 'center', borderRadius: 6 },
    actionLabel: { color: theme.accent, fontSize: 15, fontWeight: '600' },
    pressed: { opacity: 0.5 },
  })
}
