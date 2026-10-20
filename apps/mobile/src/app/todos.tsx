import { useEffect, useMemo, useState } from 'react'
import { Pressable, SectionList, StyleSheet, Text, View } from 'react-native'
import { Stack, useRouter } from 'expo-router'
import { dueLabel, groupTodos, plannedLabel, readerTimeZone, startOfWallDay, todoSection, toWall, type SyncedMessage, type Todo } from '@tresso/core'
import { askTodoPlan } from '@/calendar/askTodoPlan'
import { todoPlanChoices, type TodoPlanAction } from '@/calendar/todoPlan'
import { useNotices } from '@/components/Notices'
import { useMail } from '@/mail/MailProvider'
import { stillPending, withPending, type PendingDone } from '@/mail/pendingDone'
import { senderName } from '@/mail/format'
import { useTheme, type Theme } from '@/theme'

/** Todos on this phone by section: Overdue, Today, This week, Later and Done. Checking one off shows at once. */
export default function TodosScreen() {
  const mail = useMail()
  const theme = useTheme()
  const styles = useMemo(() => makeStyles(theme), [theme])
  const router = useRouter()
  const notices = useNotices()
  const [todos, setTodos] = useState<Todo[]>([])
  const [emails, setEmails] = useState(new Map<string, SyncedMessage>())
  /** Done or open as the user just set it, by todo id, until the loaded list shows it (see stillPending). */
  const [pending, setPending] = useState<PendingDone>(new Map())
  const [now, setNow] = useState(() => new Date())
  const { todos: loadTodos, messages: loadMessages, version } = mail

  useEffect(() => {
    let current = true
    void loadTodos().then(async (list) => {
      const found = await loadMessages(list.flatMap((todo) => (todo.messageId === null ? [] : [todo.messageId])))
      if (current) {
        setTodos(list)
        // A check-off stays shown until the list read after its sync carries it.
        setPending((map) => stillPending(map, list))
        setEmails(new Map(found.map((message) => [message.id, message])))
        setNow(new Date())
      }
    })
    return () => {
      current = false
    }
  }, [loadTodos, loadMessages, version])

  // The sections go by the clock: "Today 17:00" turns overdue on time.
  useEffect(() => {
    const ticking = setInterval(() => setNow(new Date()), 60_000)
    return () => clearInterval(ticking)
  }, [])

  const shown = useMemo(() => withPending(todos, pending, now), [todos, pending, now])
  const sections = useMemo(() => groupTodos(shown, now).map((group) => ({ title: group.label, data: group.todos })), [shown, now])
  const open = shown.filter((todo) => todo.completedAt === null).length

  async function toggle(todo: Todo): Promise<void> {
    const done = todo.completedAt === null
    setPending((map) => new Map(map).set(todo.id, done))
    try {
      await mail.setTodoDone(todo.id, done)
    } catch (error) {
      // Refused: back to how the copy has it at once.
      setPending((map) => {
        const next = new Map(map)
        if (next.get(todo.id) === done) {
          next.delete(todo.id)
        }
        return next
      })
      notices.notify({ text: error instanceof Error ? error.message : String(error), tone: 'error' })
    }
  }

  /** Plans the todo in the calendar, plans it again, or gives its time back, after asking which. */
  function planMenu(todo: Todo): void {
    const zone = readerTimeZone()
    const where = plannedLabel(todo, zone, startOfWallDay(toWall(now.getTime(), zone)))
    const run = async (action: TodoPlanAction): Promise<void> => {
      try {
        if (action === 'plan') await mail.planTodo(todo.id)
        else if (action === 'unplan') await mail.unplanTodo(todo.id)
      } catch (error) {
        notices.notify({ text: error instanceof Error ? error.message : String(error), tone: 'error' })
      }
    }
    askTodoPlan(todo.title, where === null ? 'Tresso finds free time before the due date. That time is busy for others.' : `${where}.`, todoPlanChoices(todo), (action) => void run(action))
  }

  return (
    <>
      <Stack.Screen options={{ headerShown: true, title: 'Todos', headerBackTitle: 'Inbox' }} />
      <SectionList
        style={styles.screen}
        sections={sections}
        keyExtractor={(todo) => todo.id}
        ListHeaderComponent={<Text style={styles.lead}>{open === 1 ? '1 open' : `${open} open`}, synced to all your devices</Text>}
        ListEmptyComponent={<Text style={styles.lead}>Nothing to do. Make a todo from an email.</Text>}
        renderSectionHeader={({ section }) => (
          <Text style={styles.section} accessibilityRole="header">
            {section.title}
          </Text>
        )}
        renderItem={({ item }) => {
          const done = item.completedAt !== null
          const email = item.messageId === null ? undefined : emails.get(item.messageId)
          const due = dueLabel(item, now)
          return (
            <View style={styles.row}>
              <Pressable
                style={[styles.check, done && styles.checked]}
                onPress={() => void toggle(item)}
                hitSlop={10}
                accessibilityRole="checkbox"
                accessibilityState={{ checked: done }}
                accessibilityLabel={`${done ? 'Reopen' : 'Complete'} ${item.title}`}
              >
                {done && <Text style={styles.tick}>✓</Text>}
              </Pressable>
              <Pressable
                style={styles.texts}
                disabled={email === undefined}
                onPress={() => email !== undefined && router.push(`/thread/${email.threadId}`)}
                accessibilityRole={email === undefined ? 'text' : 'link'}
              >
                <Text style={[styles.title, done && styles.doneTitle]} numberOfLines={2}>
                  {item.title}
                </Text>
                <Text style={[styles.muted, !done && todoSection(item, now) === 'overdue' && styles.overdue]} numberOfLines={1}>
                  {[due, email !== undefined ? senderName(email.from) : item.messageId === null ? 'Added by you' : 'From an email'].filter((part) => part !== null).join(' · ')}
                </Text>
              </Pressable>
              {!done && todoPlanChoices(item).length > 0 && (
                <Pressable
                  style={[styles.plan, item.planState === 'conflict' && styles.planConflict]}
                  onPress={() => planMenu(item)}
                  hitSlop={8}
                  accessibilityRole="button"
                  accessibilityLabel={item.plannedStart === null ? `Plan ${item.title} in the calendar` : `${item.title} in the calendar`}
                >
                  <Text style={[styles.planLabel, item.planState === 'conflict' && styles.planConflictLabel]}>{item.plannedStart === null ? 'Plan' : item.planState === 'conflict' ? 'Conflict' : 'Planned'}</Text>
                </Pressable>
              )}
            </View>
          )
        }}
      />
    </>
  )
}

function makeStyles(theme: Theme) {
  return StyleSheet.create({
    screen: { flex: 1, backgroundColor: theme.surface },
    lead: { paddingHorizontal: 20, paddingVertical: 12, fontSize: 14, color: theme.ink2 },
    section: { paddingHorizontal: 20, paddingTop: 16, paddingBottom: 6, fontSize: 13, fontWeight: '700', color: theme.ink2, backgroundColor: theme.surface },
    row: { flexDirection: 'row', alignItems: 'center', gap: 14, minHeight: 56, paddingHorizontal: 20, paddingVertical: 10, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.line },
    check: { width: 22, height: 22, borderRadius: 6, borderWidth: 2, borderColor: theme.lineStrong, alignItems: 'center', justifyContent: 'center' },
    checked: { borderColor: theme.accent, backgroundColor: theme.accent },
    tick: { color: theme.onAccent, fontSize: 14, fontWeight: '700' },
    texts: { flex: 1, gap: 2 },
    title: { fontSize: 16, fontWeight: '600', color: theme.ink },
    doneTitle: { color: theme.ink2, textDecorationLine: 'line-through' },
    muted: { fontSize: 13, color: theme.ink2 },
    overdue: { color: theme.danger, fontWeight: '600' },
    plan: { paddingHorizontal: 10, paddingVertical: 4, borderRadius: 12, borderWidth: 1, borderColor: theme.do },
    planConflict: { borderColor: theme.danger, borderStyle: 'dashed' },
    planLabel: { fontSize: 13, fontWeight: '600', color: theme.do },
    planConflictLabel: { color: theme.danger },
  })
}
