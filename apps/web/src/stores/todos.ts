import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { compareTodos, type MadeTodo, type MakeTodoRequest, type NewTodo, type Todo, type TodoUpdate } from '@tresso/core'
import { useAuthStore } from '@/stores/auth'
import { useMailStore } from '@/stores/mail'

/**
 * Todos, read from the synced copy and written through the API. After a write the copy is pulled at once. Checking a
 * todo off shows at once: the change is kept on top of the copy until the delta brings the server's version.
 */
export const useTodosStore = defineStore('todos', () => {
  const auth = useAuthStore()
  const mail = useMailStore()

  /** Done or open as the user just set it, by todo id, while the request is under way. */
  const pending = ref(new Map<string, boolean>())

  const todos = computed<Todo[]>(() =>
    mail.todos
      .map((todo) => {
        const done = pending.value.get(todo.id)
        if (done === undefined || done === (todo.completedAt !== null)) {
          return todo
        }
        return { ...todo, completedAt: done ? new Date().toISOString() : null }
      })
      .sort(compareTodos),
  )
  const openCount = computed(() => todos.value.filter((todo) => todo.completedAt === null).length)

  async function create(todo: NewTodo): Promise<Todo> {
    const created = await auth.send<Todo>('POST', '/api/v1/todos', todo)
    await mail.syncNow()

    return created
  }

  /** The email's todo: made now, or the one it has already. */
  async function makeFromMessage(messageId: string, request: MakeTodoRequest = {}): Promise<MadeTodo> {
    const todo = await auth.send<MadeTodo>('POST', `/api/v1/messages/${encodeURIComponent(messageId)}/todo`, request)
    await mail.syncNow()

    return todo
  }

  async function update(id: string, changes: TodoUpdate): Promise<Todo> {
    const updated = await auth.send<Todo>('PATCH', `/api/v1/todos/${encodeURIComponent(id)}`, changes)
    await mail.syncNow()

    return updated
  }

  /** Checks a todo off or opens it again; it shows at once and goes back when the server refuses. */
  async function setDone(id: string, done: boolean): Promise<void> {
    pending.value = new Map(pending.value).set(id, done)
    try {
      await update(id, { done })
    } finally {
      const next = new Map(pending.value)
      if (next.get(id) === done) {
        next.delete(id)
      }
      pending.value = next
    }
  }

  /**
   * Checks a todo off and archives the conversation it came from, the way the user's done with an email to do. When
   * the archive fails the todo stays done; the error is the caller's to show.
   */
  async function completeAndArchive(todo: Todo): Promise<void> {
    await setDone(todo.id, true)
    const threadId = await threadOf(todo)
    if (threadId === null) {
      return
    }
    const messageIds = await mail.messageIdsOf([threadId])
    await mail.act(messageIds, 'archive')
  }

  /** The conversation of the email a todo links to, when this device has it. */
  async function threadOf(todo: Todo): Promise<string | null> {
    if (todo.messageId === null) {
      return null
    }
    const [message] = await mail.messages([todo.messageId])
    return message?.threadId ?? null
  }

  async function remove(id: string): Promise<void> {
    await auth.send<void>('DELETE', `/api/v1/todos/${encodeURIComponent(id)}`)
    await mail.syncNow()
  }

  return { todos, openCount, create, makeFromMessage, update, setDone, completeAndArchive, threadOf, remove }
})
