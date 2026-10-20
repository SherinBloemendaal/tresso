import { useEffect, useMemo, useState } from 'react'
import { Pressable, SectionList, StyleSheet, Text, View } from 'react-native'
import { Stack, useRouter } from 'expo-router'
import { batchGroups, batchTimeLabel, type ConversationSummary } from '@tresso/core'
import { useMail } from '@/mail/MailProvider'
import { senderName, shortTime } from '@/mail/format'
import { useTheme, type Theme } from '@/theme'

/** How many delivered conversations the screen lists. */
const LIMIT = 200

/** What the batches delivered, newest batch first: where a tap on a batch's summary notification leads. */
export default function BatchesScreen() {
  const mail = useMail()
  const router = useRouter()
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const [conversations, setConversations] = useState<ConversationSummary[]>([])
  const { store, version } = mail

  useEffect(() => {
    let current = true
    void (store()?.list({ kind: 'batch' }, LIMIT) ?? Promise.resolve([])).then((list) => current && setConversations(list))
    return () => {
      current = false
    }
  }, [store, version])

  const sections = useMemo(
    () => batchGroups(conversations).map((group) => ({ title: `Delivered at ${batchTimeLabel(group.batchedAt)}`, data: group.conversations })),
    [conversations],
  )

  return (
    <>
      <Stack.Screen options={{ headerShown: true, title: 'Batches', headerBackTitle: 'Inbox' }} />
      <SectionList
        style={styles.screen}
        sections={sections}
        keyExtractor={(item) => item.threadId}
        stickySectionHeadersEnabled={false}
        renderSectionHeader={({ section }) => (
          <Text style={styles.batch} accessibilityRole="header">
            {section.title}
          </Text>
        )}
        ListEmptyComponent={<Text style={styles.empty}>No batch delivered yet.</Text>}
        renderItem={({ item }) => (
          <Pressable
            style={({ pressed }) => [styles.row, pressed && styles.pressed]}
            onPress={() => router.push({ pathname: '/thread/[id]', params: { id: item.threadId } })}
            accessibilityRole="button"
            accessibilityLabel={`${item.unreadCount > 0 ? 'Unread. ' : ''}${item.senders.map(senderName).join(', ')}. ${item.subject || '(no subject)'}. ${shortTime(item.lastMessageAt)}`}
          >
            <View style={styles.texts}>
              <Text style={[styles.senders, item.unreadCount > 0 && styles.bold]} numberOfLines={1}>
                {item.senders.map(senderName).join(', ')}
              </Text>
              <Text style={styles.subject} numberOfLines={1}>
                {item.subject || '(no subject)'}
              </Text>
            </View>
            <Text style={styles.muted}>{shortTime(item.lastMessageAt)}</Text>
          </Pressable>
        )}
      />
    </>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { flex: 1, backgroundColor: theme.surface },
    batch: { paddingHorizontal: 20, paddingTop: 18, paddingBottom: 6, color: theme.ink2, fontSize: 13, fontWeight: '600' },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 64, paddingHorizontal: 20, paddingVertical: 10, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.line },
    pressed: { backgroundColor: theme.ground },
    texts: { flex: 1, gap: 2 },
    senders: { fontSize: 16, color: theme.ink },
    bold: { fontWeight: '600' },
    subject: { fontSize: 15, color: theme.ink },
    muted: { fontSize: 13, color: theme.ink2 },
    empty: { padding: 20, fontSize: 15, color: theme.ink2 },
  })
}
