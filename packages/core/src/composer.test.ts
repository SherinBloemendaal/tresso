import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { SendMessageRequest } from './compose'
import { Composer, type ComposerNotice } from './composer'
import { DraftSync, type DraftApi } from './drafts'
import { accounts, message, page, signature } from './store/contract'
import { memorySql } from './store/memorySql'
import { SqliteMailStore } from './store/sqlite'
import type { SyncedDraft, SyncedOutgoingMessage } from './sync/types'

class ApiFailure extends Error {
  constructor(
    readonly status: number,
    readonly violations: Record<string, string> = {},
  ) {
    super(`HTTP ${status}`)
  }
}

function outgoing(status: SyncedOutgoingMessage['status'], lastError: string | null = null): SyncedOutgoingMessage {
  return { id: 'o1', mailAccountId: 'work', status, subject: 'Re: Budget', recipients: { to: [], cc: [], bcc: [] }, sendAt: '2026-10-19T09:00:00Z', sentAt: null, lastError, inReplyToMessageId: 'm1' }
}

const original = message('m1', 't1', 'work', ['work-inbox'], '2026-10-19T08:00:00+00:00', { subject: 'Budget' })

describe('Composer', () => {
  let store: SqliteMailStore
  let sent: SendMessageRequest[]
  let cancelled: string[]
  let notices: ComposerNotice[]
  let serverDrafts: Map<string, SyncedDraft>
  let sendFails: unknown
  let composer: Composer

  beforeEach(async () => {
    vi.useFakeTimers()
    store = await SqliteMailStore.open(await memorySql())
    await store.apply(page({ cursor: 1, mailAccounts: accounts, signatures: [signature('sig-work', 'Work')] }))
    sent = []
    cancelled = []
    notices = []
    serverDrafts = new Map()
    sendFails = null
    const api: DraftApi = {
      create: async (id, request) => {
        const saved = { id, ...request, version: 1, updatedAt: '2026-10-19T08:00:00Z' }
        serverDrafts.set(id, saved)
        return saved
      },
      update: async (id, request, expectedVersion) => {
        const saved = { id, ...request, version: expectedVersion + 1, updatedAt: '2026-10-19T08:00:00Z' }
        serverDrafts.set(id, saved)
        return saved
      },
      get: async (id) => serverDrafts.get(id)!,
      remove: async (id) => {
        serverDrafts.delete(id)
      },
    }
    composer = new Composer({
      store: () => store,
      drafts: new DraftSync(() => store, api),
      accounts: () => accounts,
      signatures: () => [signature('sig-work', 'Work')],
      defaultAccount: () => 'work',
      body: async () => ({ html: '<p>Numbers?</p>', text: null }),
      send: async (request) => {
        if (sendFails !== null) throw sendFails
        sent.push(request)
        return outgoing('scheduled')
      },
      cancel: async (id) => {
        cancelled.push(id)
        return outgoing('cancelled')
      },
      notify: (notice) => notices.push(notice),
      explain: (error) => ({ message: error instanceof Error ? error.message : String(error), violations: error instanceof ApiFailure ? error.violations : {} }),
    })
  })

  afterEach(() => {
    composer.dispose()
    vi.useRealTimers()
  })

  it('opens a new message from the default account with its signature, and tells listeners', () => {
    const listener = vi.fn()
    composer.subscribe(listener)

    composer.openNew([{ address: 'anna@example.test', name: null }])

    expect(composer.state.draft).toMatchObject({ kind: 'new', mailAccountId: 'work', signatureId: 'sig-work', to: [{ address: 'anna@example.test' }] })
    expect(composer.state.revision).toBe(1)
    expect(listener).toHaveBeenCalled()
  })

  it('saves and uploads shortly after typing stops', async () => {
    composer.openNew()
    composer.update({ subject: 'Hello' })
    expect(await store.draft(composer.state.draft!.id)).toBeNull()

    await vi.advanceTimersByTimeAsync(1000)

    expect(await store.draft(composer.state.draft!.id)).toMatchObject({ subject: 'Hello', version: 1, pendingSave: false })
    expect(composer.state.savedAt).not.toBeNull()
  })

  it('refuses to send without an account or recipients, or with a wrong address', async () => {
    composer.openNew()
    await composer.send()
    expect(composer.state.error).toBe('Add at least one recipient.')

    composer.update({ to: [{ address: 'not an address', name: null }] })
    await composer.send()
    expect(composer.state.error).toBe('not an address is not a valid address.')

    composer.update({ to: [{ address: 'anna@example.test', name: null }], mailAccountId: null })
    await composer.send()
    expect(composer.state.error).toBe('Choose the account to send from.')

    composer.update({ mailAccountId: 'work', subject: 'Two\nlines' })
    await composer.send()
    expect(composer.state.error).toBe('The subject cannot contain line breaks.')

    composer.update({ subject: 'x'.repeat(999) })
    await composer.send()
    expect(composer.state.error).toBe('The subject is too long.')

    composer.update({ subject: 'Fine', to: Array.from({ length: 101 }, (_, index) => ({ address: `p${index}@example.test`, name: null })) })
    await composer.send()
    expect(composer.state.error).toBe('A message can have at most 100 recipients.')
    expect(sent).toEqual([])
  })

  it('sends after the undo window with the draft, and brings it back on undo', async () => {
    await composer.openReply(original, 'reply')
    composer.update({ html: '<p>Yes.</p>' })
    const id = composer.state.draft!.id

    await composer.send()

    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({ mailAccountId: 'work', subject: 'Re: Budget', draftId: id, inReplyToMessageId: 'm1' })
    expect(Date.parse(sent[0]!.sendAt!) - Date.now()).toBeGreaterThanOrEqual(4900)
    expect(sent[0]!.html.indexOf('<p>Yes.</p>')).toBe(0)
    expect(composer.state.draft).toBeNull()
    expect(composer.state.undoable?.outgoingId).toBe('o1')
    expect(await store.draft(id)).toMatchObject({ outgoingId: 'o1', pendingSave: false })

    await composer.undo()

    expect(cancelled).toEqual(['o1'])
    expect(composer.state.draft).toMatchObject({ id, outgoingId: null, pendingSave: true })
  })

  it('lets the undo lapse after the window', async () => {
    await composer.openReply(original, 'reply')
    await composer.send()

    await vi.advanceTimersByTimeAsync(5000)

    expect(composer.state.undoable).toBeNull()
  })

  it('schedules a message for later and says when', async () => {
    composer.openNew([{ address: 'anna@example.test', name: null }])

    await composer.send(new Date('2026-10-20T08:00:00Z'))

    expect(sent[0]?.sendAt).toBe('2026-10-20T08:00:00.000Z')
    expect(composer.state.undoable).toBeNull()
    expect(notices[0]?.text).toMatch(/^Scheduled for /)
  })

  it('keeps the draft open and shows why when sending fails', async () => {
    composer.openNew([{ address: 'anna@example.test', name: null }])
    sendFails = new ApiFailure(422, { subject: 'Too long.' })

    await composer.send()

    expect(composer.state.draft).not.toBeNull()
    expect(composer.state).toMatchObject({ error: 'HTTP 422', violations: { subject: 'Too long.' }, sending: false })
  })

  it('removes a draft once sent, and offers a failed one for editing once', async () => {
    await composer.openReply(original, 'reply')
    const id = composer.state.draft!.id
    await composer.send()

    await store.apply(page({ cursor: 2, outgoingMessages: [outgoing('failed', 'Mailbox full')] }))
    await composer.followUp()
    await composer.followUp()

    expect(notices.filter((notice) => notice.tone === 'error').map((notice) => notice.text)).toEqual(['"Re: Budget" was not sent. Mailbox full'])
    expect(notices[0]?.action?.label).toBe('Edit and send again')
    expect(await composer.draftFor('o1')).toMatchObject({ id })

    await store.apply(page({ cursor: 3, outgoingMessages: [outgoing('sent')] }))
    await composer.followUp()
    expect(await store.draft(id)).toBeNull()
  })

  it('closes the form keeping the draft, and discards one here and on the server', async () => {
    composer.openNew()
    composer.update({ subject: 'Kept' })
    const kept = composer.state.draft!.id
    composer.close()
    await vi.advanceTimersByTimeAsync(10)
    expect(composer.state.draft).toBeNull()
    expect(await store.draft(kept)).toMatchObject({ subject: 'Kept' })

    await composer.openDraft(kept)
    await composer.discard()
    expect(await store.draft(kept)).toBeNull()
    expect(serverDrafts.has(kept)).toBe(false)
  })
})
