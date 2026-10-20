import { describe, expect, it } from 'vitest'
import { todoApi, type Api } from '../todoApi'

describe('todoApi', () => {
  function recording() {
    const calls: { path: string; method: string | undefined; contentType: string | null; body: unknown }[] = []
    const api: Api = async <T,>(path: string, init: RequestInit = {}) => {
      calls.push({ path, method: init.method, contentType: new Headers(init.headers).get('Content-Type'), body: init.body === undefined ? undefined : JSON.parse(String(init.body)) })
      return {} as T
    }
    return { calls, todos: todoApi(api) }
  }

  it('creates todos and makes them from emails', async () => {
    const { calls, todos } = recording()

    await todos.create({ title: 'Call Anna', dueDate: '2026-10-23', dueTime: null })
    await todos.makeFromMessage('m/1')

    expect(calls).toEqual([
      { path: '/api/v1/todos', method: 'POST', contentType: 'application/json', body: { title: 'Call Anna', dueDate: '2026-10-23', dueTime: null } },
      { path: '/api/v1/messages/m%2F1/todo', method: 'POST', contentType: 'application/json', body: {} },
    ])
  })

  it('changes todos as a merge patch and deletes them', async () => {
    const { calls, todos } = recording()

    await todos.update('t1', { done: true })
    await todos.remove('t1')

    expect(calls).toEqual([
      { path: '/api/v1/todos/t1', method: 'PATCH', contentType: 'application/merge-patch+json', body: { done: true } },
      { path: '/api/v1/todos/t1', method: 'DELETE', contentType: null, body: undefined },
    ])
  })

  it('plans a todo in the calendar and gives the time back', async () => {
    const { calls, todos } = recording()

    await todos.plan('t1')
    await todos.unplan('t1')

    expect(calls).toEqual([
      { path: '/api/v1/todos/t1/plan', method: 'POST', contentType: null, body: undefined },
      { path: '/api/v1/todos/t1/plan', method: 'DELETE', contentType: null, body: undefined },
    ])
  })
})
