import { describe, expect, it, vi } from 'vitest'
import { MAX_MESSAGES_PER_ACTION, PartialActionError, type ChangePage, type MessageActionRequest, type SyncedMessage } from '@tresso/core'
import { databaseName, MailSession, type MailState } from '../mailSession'
import type { Sql } from '../sql'
import { memorySql } from '@tresso/core/testing/sql'

const account = { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null, calendarStatus: 'unknown' as const, calendarStatusDetail: null }

function page(cursor: number, extra: Partial<ChangePage> = {}): ChangePage {
  return { cursor, hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], smartInboxes: [], calendars: [], calendarEvents: [], deleted: [], ...extra }
}

/** Databases by name, as on a device: deleting one that is open fails, as expo-sqlite does. */
function device() {
  const files = new Map<string, Sql>()
  const open = new Map<string, number>()
  let failDelete = false
  return {
    files,
    open,
    set failDelete(value: boolean) {
      failDelete = value
    },
    async openSql(name: string) {
      if (!files.has(name)) {
        files.set(name, await memorySql())
      }
      open.set(name, (open.get(name) ?? 0) + 1)
      const sql = files.get(name)!
      return {
        ...sql,
        close: async () => {
          open.set(name, (open.get(name) ?? 1) - 1)
        },
      }
    },
    async deleteSql(name: string) {
      if ((open.get(name) ?? 0) > 0) {
        throw new Error(`${name} is open`)
      }
      if (failDelete) {
        throw new Error('disk busy')
      }
      files.delete(name)
    },
  }
}

function session(
  disk: ReturnType<typeof device>,
  fetchPage = async (since: number) => page(since === 0 ? 1 : since, since === 0 ? { mailAccounts: [account] } : {}),
  postAction: (request: MessageActionRequest) => Promise<void> = async () => {},
) {
  const states: MailState[] = []
  const mail = new MailSession({
    openSql: (name) => disk.openSql(name),
    deleteSql: (name) => disk.deleteSql(name),
    fetchPage,
    fetchAccess: () => Promise.reject(new Error('no realtime in this test')),
    postAction,
    drafts: {
      create: () => Promise.reject(new Error('no drafts in this test')),
      update: () => Promise.reject(new Error('no drafts in this test')),
      get: () => Promise.reject(new Error('no drafts in this test')),
      remove: () => Promise.reject(new Error('no drafts in this test')),
      attach: () => Promise.reject(new Error('no drafts in this test')),
      forward: () => Promise.reject(new Error('no drafts in this test')),
      detach: () => Promise.reject(new Error('no drafts in this test')),
    },
    onState: (state) => states.push(state),
  })
  return { mail, states, last: () => states[states.length - 1]! }
}

describe('MailSession', () => {
  it("opens the user's copy, syncs it, and deletes it on sign-out only after closing it", async () => {
    const disk = device()
    const { mail, last } = session(disk)

    await mail.switchTo('kim')
    expect(last()).toMatchObject({ userId: 'kim', ready: true })
    await vi.waitFor(async () => expect((await mail.database?.mailAccounts())?.map((a) => a.id)).toEqual(['work']))
    await vi.waitFor(() => expect(last().syncState).toBe('idle'))

    await mail.switchTo(null)
    expect(last()).toMatchObject({ userId: null, ready: false })
    expect(disk.files.has(databaseName('kim'))).toBe(false)
    expect(mail.database).toBeNull()
  })

  it("never shows one user's copy to the next, and removes it", async () => {
    const disk = device()
    const { mail } = session(disk)
    await mail.switchTo('kim')

    await mail.switchTo('lisa')

    expect(disk.files.has(databaseName('kim'))).toBe(false)
    expect(disk.files.has(databaseName('lisa'))).toBe(true)
  })

  it('leaves nothing behind when signed out while the copy was still opening', async () => {
    const disk = device()
    let release: () => void = () => {}
    const slow = session(disk, (since) => (since === 0 ? new Promise<ChangePage>((resolve) => (release = () => resolve(page(1, { mailAccounts: [account] })))) : Promise.resolve(page(since))))

    const opening = slow.mail.switchTo('kim')
    await vi.waitFor(() => expect(disk.files.has(databaseName('kim'))).toBe(true))
    // The first pull is still on its way: signing out does not wait for it.
    await opening
    await slow.mail.switchTo(null)
    release()

    expect(disk.files.has(databaseName('kim'))).toBe(false)
    expect(slow.last()).toMatchObject({ userId: null, ready: false })
  })

  it('removes a copy that could not go before, the next time', async () => {
    const disk = device()
    const first = session(disk)
    await first.mail.switchTo('kim')
    disk.failDelete = true
    await first.mail.switchTo(null)
    expect(disk.files.has(databaseName('kim'))).toBe(true)
    expect(first.last().syncError).toContain('could not be removed yet')

    disk.failDelete = false
    const afterRestart = session(disk)
    await afterRestart.mail.switchTo(null)

    expect(disk.files.has(databaseName('kim'))).toBe(false)
  })

  it('rests the connection in the background and catches up in front', async () => {
    const disk = device()
    const fetchPage = vi.fn(async (since: number) => page(since === 0 ? 1 : since, since === 0 ? { mailAccounts: [account] } : {}))
    const { mail, last } = session(disk, fetchPage)
    await mail.switchTo('kim')
    await vi.waitFor(() => expect(last().syncState).toBe('idle'))
    const pulls = fetchPage.mock.calls.length

    mail.setForeground(false)
    expect(last().live).toBe(false)
    mail.setForeground(true)
    await vi.waitFor(() => expect(fetchPage.mock.calls.length).toBeGreaterThan(pulls))
    await mail.switchTo(null)
  })

  it('shows an action at once, sends it in batches, and puts back only what the server did not take', async () => {
    const disk = device()
    const ids = Array.from({ length: MAX_MESSAGES_PER_ACTION + 2 }, (_, i) => `m${i}`)
    const message = (id: string): SyncedMessage => ({
      id,
      mailAccountId: 'work',
      threadId: `t-${id}`,
      folderIds: ['inbox'],
      subject: '',
      from: { address: 'a@example.test', name: null },
      recipients: { to: [], cc: [], bcc: [], replyTo: [] },
      snippet: '',
      sentAt: null,
      receivedAt: '2026-10-19T08:00:00Z',
      flags: { seen: false, flagged: false, answered: false, draft: false },
      hasAttachments: false,
      smartInboxId: null,
      verdict: null,
      summary: null,
      calendarMethod: null,
    })
    const requests: MessageActionRequest[] = []
    const { mail } = session(
      disk,
      async (since) =>
        since === 0
          ? page(1, {
              mailAccounts: [account],
              folders: [{ id: 'inbox', mailAccountId: 'work', name: 'INBOX', displayName: 'Inbox', role: 'inbox' }],
              threads: ids.map((id) => ({ id: `t-${id}`, mailAccountId: 'work', subject: '', messageCount: 1, lastMessageAt: '2026-10-19T08:00:00Z', smartInboxId: null, verdict: null, classificationReason: null })),
              messages: ids.map(message),
            })
          : page(since),
      async (request) => {
        requests.push(request)
        if (requests.length === 2) {
          throw new Error('Try again later.')
        }
      },
    )
    await mail.switchTo('kim')
    await vi.waitFor(async () => expect((await mail.database?.inbox(null, 1000))?.length).toBe(ids.length))

    const failure = await mail.act(ids, 'markRead').catch((error: unknown) => error)

    expect(failure).toBeInstanceOf(PartialActionError)
    expect((failure as PartialActionError).rejected).toBe(2)
    expect(requests.map((r) => r.messageIds.length)).toEqual([MAX_MESSAGES_PER_ACTION, 2])
    const seen = async (id: string) => (await mail.database?.thread(`t-${id}`))?.messages[0]?.flags.seen
    expect(await seen('m0')).toBe(true)
    expect(await seen(`m${MAX_MESSAGES_PER_ACTION}`)).toBe(false)
    await mail.switchTo(null)
  })

  it('says so when a refused change cannot be put back, and syncs the copy from the start to put it right', async () => {
    const disk = device()
    const since: number[] = []
    const { mail } = session(
      disk,
      async (from) => {
        since.push(from)
        return page(from === 0 ? 1 : from, from === 0 ? { mailAccounts: [account] } : {})
      },
      async () => {
        throw new Error('Try again later.')
      },
    )
    await mail.switchTo('kim')
    await vi.waitFor(async () => expect(await mail.database?.cursor()).toBe(1))
    vi.spyOn(mail.database!, 'revertAction').mockRejectedValue(new Error('disk full'))
    since.length = 0

    await expect(mail.act(['m1'], 'markRead')).rejects.toThrow('Try again later. This phone shows the change until it has synced your mail again.')

    await vi.waitFor(() => expect(since).toContain(0))
    await mail.switchTo(null)
  })

  it('refuses an action while no copy is open, instead of pretending it worked', async () => {
    const { mail } = session(device())

    await expect(mail.act(['m1'], 'archive')).rejects.toThrow('Your mail is not open on this phone yet.')
  })
})
