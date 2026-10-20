import type { MakeTodoRequest, NewTodo, Todo, TodoUpdate } from '@tresso/core'

/** An authenticated request to the API that returns the parsed answer (see `AuthSession.api`). */
export type Api = <T>(path: string, init?: RequestInit) => Promise<T>

export interface TodoApi {
  create(todo: NewTodo): Promise<Todo>
  /** The email's todo: made now, or the one it has already. */
  makeFromMessage(messageId: string, request?: MakeTodoRequest): Promise<Todo>
  update(id: string, changes: TodoUpdate): Promise<Todo>
  remove(id: string): Promise<void>
}

/** /api/v1/todos and /api/v1/messages/{id}/todo. Changes are partial updates (JSON merge patch). */
export function todoApi(api: Api): TodoApi {
  const send = (method: string, body: unknown, contentType = 'application/json'): RequestInit => ({ method, headers: { 'Content-Type': contentType }, body: JSON.stringify(body) })

  return {
    create: (todo) => api<Todo>('/api/v1/todos', send('POST', todo)),
    makeFromMessage: (messageId, request = {}) => api<Todo>(`/api/v1/messages/${encodeURIComponent(messageId)}/todo`, send('POST', request)),
    update: (id, changes) => api<Todo>(`/api/v1/todos/${encodeURIComponent(id)}`, send('PATCH', changes, 'application/merge-patch+json')),
    remove: async (id) => {
      await api(`/api/v1/todos/${encodeURIComponent(id)}`, { method: 'DELETE' })
    },
  }
}
