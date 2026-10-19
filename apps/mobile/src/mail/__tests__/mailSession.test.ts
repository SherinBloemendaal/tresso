import { describe, expect, it, vi } from 'vitest'
import type { ChangePage } from '@tresso/core'
import { databaseName, MailSession, type MailState } from '../mailSession'
import type { Sql } from '../sql'
import { memorySql } from './sqljs'

const account = { id: 'work', emailAddress: 'kim@company.example', displayName: 'Work', provider: 'imap', status: 'active' as const, statusDetail: null, lastSyncedAt: null, defaultSignatureId: null }

function page(cursor: number, extra: Partial<ChangePage> = {}): ChangePage {
  return { cursor, hasMore: false, mailAccounts: [], folders: [], threads: [], messages: [], outgoingMessages: [], signatures: [], drafts: [], deleted: [], ...extra }
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

function session(disk: ReturnType<typeof device>, fetchPage = async (since: number) => page(since === 0 ? 1 : since, since === 0 ? { mailAccounts: [account] } : {})) {
  const states: MailState[] = []
  const mail = new MailSession({
    openSql: (name) => disk.openSql(name),
    deleteSql: (name) => disk.deleteSql(name),
    fetchPage,
    fetchAccess: () => Promise.reject(new Error('no realtime in this test')),
    onState: (state) => states.push(state),
  })
  return { mail, states, last: () => states[states.length - 1]! }
}

describe('MailSession', () => {
  it("opens the user's copy, syncs it, and deletes it on sign-out only after closing it", async () => {
    const disk = device()
    const { mail, last } = session(disk)

    await mail.switchTo('kim')
    expect(last()).toMatchObject({ userId: 'kim', ready: true, syncState: 'idle' })
    expect((await mail.database?.mailAccounts())?.map((a) => a.id)).toEqual(['work'])

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
    const leaving = slow.mail.switchTo(null)
    release()
    await opening
    await leaving

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
    const pulls = fetchPage.mock.calls.length

    mail.setForeground(false)
    expect(last().live).toBe(false)
    mail.setForeground(true)
    await vi.waitFor(() => expect(fetchPage.mock.calls.length).toBeGreaterThan(pulls))
    await mail.switchTo(null)
  })
})
