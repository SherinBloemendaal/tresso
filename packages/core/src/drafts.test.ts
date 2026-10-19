import { beforeEach, describe, expect, it } from 'vitest'
import type { DraftRequest } from './compose'
import { defaultSignatureOf, DraftSync, newDraft, reopenedDraft, replyDraft, sendRequest, sentOutcome, type DraftApi } from './drafts'
import { accounts, draft, message, signature } from './store/contract'
import { memorySql } from './store/memorySql'
import { SqliteMailStore } from './store/sqlite'
import type { SyncedDraft, SyncedOutgoingMessage } from './sync/types'

class ApiFailure extends Error {
  constructor(readonly status: number) {
    super(`HTTP ${status}`)
  }
}

/** The drafts API as the server answers it: versions go up with every save, and stale versions are refused. */
class FakeDraftApi implements DraftApi {
  readonly drafts = new Map<string, SyncedDraft>()
  readonly calls: string[] = []
  /** Runs while a request is under way, to make edits meanwhile. */
  during: (() => Promise<void>) | null = null

  async create(id: string, request: DraftRequest): Promise<SyncedDraft> {
    this.calls.push(`create ${id}`)
    await this.during?.()
    return this.store(id, request, 1)
  }

  async update(id: string, request: DraftRequest, expectedVersion: number): Promise<SyncedDraft> {
    this.calls.push(`update ${id} @${expectedVersion}`)
    await this.during?.()
    const current = this.drafts.get(id)
    if (current === undefined) throw new ApiFailure(404)
    if (current.version !== expectedVersion) throw new ApiFailure(409)
    return this.store(id, request, current.version + 1)
  }

  async get(id: string): Promise<SyncedDraft> {
    const current = this.drafts.get(id)
    if (current === undefined) throw new ApiFailure(404)
    return current
  }

  async remove(id: string): Promise<void> {
    this.calls.push(`remove ${id}`)
    if (!this.drafts.delete(id)) throw new ApiFailure(404)
  }

  private store(id: string, request: DraftRequest, version: number): SyncedDraft {
    const saved: SyncedDraft = { id, ...request, version, updatedAt: '2026-10-19T08:00:00Z' }
    this.drafts.set(id, saved)
    return saved
  }
}

describe('DraftSync', () => {
  let store: SqliteMailStore
  let api: FakeDraftApi
  let conflicts: number
  let sync: DraftSync

  beforeEach(async () => {
    store = await SqliteMailStore.open(await memorySql())
    api = new FakeDraftApi()
    conflicts = 0
    sync = new DraftSync(() => store, api, () => (conflicts += 1))
  })

  it('creates a new draft on the server, then updates it on its version', async () => {
    await store.saveDraft(draft('d1', { pendingSave: true }))
    await sync.upload('d1')
    expect(await store.draft('d1')).toMatchObject({ version: 1, pendingSave: false })

    await store.saveDraft({ ...(await store.draft('d1'))!, subject: 'Second', pendingSave: true, updatedAt: 2 })
    await sync.upload('d1')

    expect(api.calls).toEqual(['create d1', 'update d1 @1'])
    expect(api.drafts.get('d1')).toMatchObject({ subject: 'Second', version: 2 })
    expect(await store.draft('d1')).toMatchObject({ version: 2, pendingSave: false })
  })

  it('keeps edits made during an upload pending and sends them right after, never two uploads at once', async () => {
    await store.saveDraft(draft('d1', { pendingSave: true, updatedAt: 1 }))
    let edited = false
    api.during = async () => {
      if (!edited) {
        edited = true
        await store.saveDraft({ ...(await store.draft('d1'))!, subject: 'Typed meanwhile', updatedAt: 2 })
        // Asked for while the first upload runs: it joins that one.
        void sync.upload('d1')
      }
    }

    await sync.upload('d1')

    expect(api.calls).toEqual(['create d1', 'update d1 @1'])
    expect(api.drafts.get('d1')?.subject).toBe('Typed meanwhile')
    expect(await store.draft('d1')).toMatchObject({ version: 2, pendingSave: false })
  })

  it('notes a newer version from another device instead of overwriting it, and settles it either way', async () => {
    await store.saveDraft(draft('d1', { pendingSave: true }))
    await sync.upload('d1')
    api.drafts.set('d1', { ...api.drafts.get('d1')!, subject: 'Elsewhere', version: 5 })
    await store.saveDraft({ ...(await store.draft('d1'))!, subject: 'Here', pendingSave: true, updatedAt: 2 })

    await sync.upload('d1')

    expect(await store.draft('d1')).toMatchObject({ subject: 'Here', remoteVersion: 5, pendingSave: true })
    expect(conflicts).toBe(1)
    // Noted: no more uploads until it is settled.
    await sync.upload('d1')
    expect(api.calls).toEqual(['create d1', 'update d1 @1'])

    expect(await sync.resolveConflict('d1', 'mine')).toMatchObject({ subject: 'Here', version: 6, remoteVersion: null, pendingSave: false })
    expect(api.drafts.get('d1')?.subject).toBe('Here')

    api.drafts.set('d1', { ...api.drafts.get('d1')!, subject: 'Elsewhere again', version: 9 })
    await store.saveDraft({ ...(await store.draft('d1'))!, remoteVersion: 9 })
    expect(await sync.resolveConflict('d1', 'theirs')).toMatchObject({ subject: 'Elsewhere again', version: 9, remoteVersion: null, pendingSave: false })
  })

  it('makes a draft deleted on another device a new one again', async () => {
    await store.saveDraft(draft('d1', { pendingSave: true }))
    await sync.upload('d1')
    api.drafts.delete('d1')
    await store.saveDraft({ ...(await store.draft('d1'))!, pendingSave: true, updatedAt: 2 })

    await sync.upload('d1')

    expect(api.calls).toEqual(['create d1', 'update d1 @1', 'create d1'])
    expect(await store.draft('d1')).toMatchObject({ version: 1, pendingSave: false })
  })

  it('leaves sent drafts and drafts without edits alone, and uploads the rest that are pending', async () => {
    await store.saveDraft(draft('pending', { pendingSave: true }))
    await store.saveDraft(draft('saved', { pendingSave: false, version: 3 }))
    await store.saveDraft(draft('sent', { pendingSave: true, outgoingId: 'o1' }))

    await sync.uploadPending()

    expect(api.calls).toEqual(['create pending'])
  })

  it('deletes a draft here and on the server, also when the server lost it already', async () => {
    await store.saveDraft(draft('d1', { pendingSave: true }))
    await sync.upload('d1')
    await store.saveDraft(draft('local', { pendingSave: true }))

    await sync.deleteEverywhere('d1')
    await sync.deleteEverywhere('local')

    expect(api.calls).toEqual(['create d1', 'remove d1'])
    expect(await store.drafts()).toEqual([])

    await store.saveDraft(draft('gone', { version: 2 }))
    await sync.deleteEverywhere('gone')
    expect(await store.draft('gone')).toBeNull()
  })

  it('says whether the server has the draft before it is sent', async () => {
    expect(await sync.uploadForSending(draft('d1', { pendingSave: true }))).toBe(true)

    api.create = async () => Promise.reject(new ApiFailure(503))
    expect(await sync.uploadForSending(draft('offline', { pendingSave: true }))).toBe(false)
    expect(await store.draft('offline')).not.toBeNull()
  })
})

describe('writing mail', () => {
  const signatures = [signature('sig-work', 'Work')]

  it('starts a reply to the sender and a reply to all without the user, with the quote apart', () => {
    const original = message('m1', 't1', 'work', ['work-inbox'], '2026-10-19T08:00:00+00:00', {
      subject: 'Budget',
      recipients: { to: [{ address: 'kim@company.example', name: 'Kim' }, { address: 'jeroen@example.test', name: 'Jeroen' }], cc: [], bcc: [], replyTo: [] },
    })

    const reply = replyDraft(original, { html: '<p>Numbers</p>', text: null }, 'reply', accounts, signatures)
    const all = replyDraft(original, { html: '<p>Numbers</p>', text: null }, 'replyAll', accounts, signatures)
    const forward = replyDraft(original, { html: null, text: 'Numbers' }, 'forward', accounts, signatures)

    expect(reply).toMatchObject({ kind: 'reply', subject: 'Re: Budget', to: [{ address: 'm1@sender.example' }], cc: [], inReplyToMessageId: 'm1', signatureId: 'sig-work', html: '' })
    expect(reply.quoteHtml).toContain('<p>Numbers</p>')
    expect(all.to.map((a) => a.address).concat(all.cc.map((a) => a.address))).toEqual(['m1@sender.example', 'jeroen@example.test'])
    expect(forward).toMatchObject({ kind: 'forward', subject: 'Fwd: Budget', to: [] })
    expect(forward.quoteHtml).toContain('Numbers')
  })

  it('picks the default signature only when it still exists', () => {
    expect(defaultSignatureOf(accounts, signatures, 'work')).toBe('sig-work')
    expect(defaultSignatureOf(accounts, [], 'work')).toBeNull()
    expect(defaultSignatureOf(accounts, signatures, 'home')).toBeNull()
  })

  it('builds the send request with signature and quote, naming the draft only when the server has it', () => {
    const written = { ...newDraft('reply', 'work', 'sig-work', 'd1'), to: [{ address: 'anna@example.test', name: null }], html: '<p>Yes</p>', quoteHtml: '<blockquote>Q</blockquote>', inReplyToMessageId: 'm1' }
    const at = new Date('2026-10-19T09:00:05Z')

    const request = sendRequest(written, signatures, at, true)

    expect(request).toMatchObject({ mailAccountId: 'work', sendAt: '2026-10-19T09:00:05.000Z', inReplyToMessageId: 'm1', draftId: 'd1' })
    expect(request.html.indexOf('<p>Yes</p>')).toBeLessThan(request.html.indexOf('<p>Work</p>'))
    expect(request.html.indexOf('<p>Work</p>')).toBeLessThan(request.html.indexOf('<blockquote>Q</blockquote>'))
    expect(sendRequest(written, signatures, at, false)).not.toHaveProperty('draftId')
    expect(() => sendRequest({ ...written, mailAccountId: null }, signatures, at, true)).toThrow('Choose the account to send from.')
  })

  it('tells each outcome of a sent draft once, and reopens a failed one for editing', () => {
    const outgoing = (status: SyncedOutgoingMessage['status']): SyncedOutgoingMessage => ({
      id: 'o1',
      mailAccountId: 'work',
      status,
      subject: 'S',
      recipients: { to: [], cc: [], bcc: [] },
      sendAt: '2026-10-19T09:00:00Z',
      sentAt: null,
      lastError: null,
      inReplyToMessageId: null,
    })
    const sent = draft('d1', { outgoingId: 'o1' })

    expect(sentOutcome(sent, outgoing('sent'))).toBe('sent')
    expect(sentOutcome(sent, outgoing('failed'))).toBe('failed')
    expect(sentOutcome(sent, outgoing('scheduled'))).toBe('other')
    expect(sentOutcome({ ...sent, noticedStatus: 'failed' }, outgoing('failed'))).toBeNull()
    expect(sentOutcome(sent, null)).toBeNull()
    expect(sentOutcome(draft('d2'), outgoing('sent'))).toBeNull()

    expect(reopenedDraft({ ...sent, noticedStatus: 'failed', pendingSave: false })).toMatchObject({ outgoingId: null, noticedStatus: null, pendingSave: true })
    expect(reopenedDraft(draft('d2'))).toEqual(draft('d2'))
  })
})
