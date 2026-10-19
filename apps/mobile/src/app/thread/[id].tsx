import { useEffect, useMemo, useState } from 'react'
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native'
import { Stack, useLocalSearchParams } from 'expo-router'
import type { SyncedMessage, SyncedThread } from '@tresso/core'
import { MessageBodyView } from '@/components/MessageBodyView'
import { useMail } from '@/mail/MailProvider'
import { initials, senderName, shortTime } from '@/mail/format'
import { useTheme, type Theme } from '@/theme'

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
  const { thread: load, version } = mail

  useEffect(() => {
    let current = true
    void load(id).then((result) => {
      if (!current) {
        return
      }
      setMissing(result === null)
      setThread(result?.thread ?? null)
      setMessages(result?.messages ?? [])
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
  }, [load, id, version])

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
                </View>
              )
            })}
          </>
        )}
      </ScrollView>
    </>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { flex: 1, backgroundColor: theme.surface },
    content: { padding: 20, gap: 16 },
    subject: { color: theme.ink, fontSize: 24, lineHeight: 30, fontWeight: '700' },
    message: { gap: 12, paddingBottom: 16, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.line },
    head: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 44 },
    avatar: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: theme.ground },
    avatarText: { color: theme.ink, fontSize: 13, fontWeight: '600' },
    who: { flex: 1, gap: 2 },
    from: { color: theme.ink, fontSize: 16 },
    bold: { fontWeight: '600' },
    muted: { color: theme.ink2, fontSize: 13 },
  })
}
