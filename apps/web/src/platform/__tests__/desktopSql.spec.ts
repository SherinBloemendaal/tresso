import { beforeEach, describe, expect, it, vi } from 'vitest'

const calls: { command: string; args: Record<string, unknown> }[] = []
const failures = new Map<string, Error>()
const rows = vi.hoisted(() => ({ value: [] as unknown[] }))

vi.mock('@tauri-apps/api/core', () => ({
  invoke: async (command: string, args: Record<string, unknown> = {}) => {
    calls.push({ command, args })
    const failure = failures.get(command)
    if (failure !== undefined) {
      throw failure
    }
    if (command === 'sql_open') return 7
    if (command === 'sql_select') return rows.value
    return undefined
  },
}))

const { deleteDesktopSql, openDesktopSql } = await import('../desktopSql')

describe('openDesktopSql', () => {
  beforeEach(() => {
    calls.length = 0
    failures.clear()
    rows.value = []
  })

  it('reads on the reading connection and writes on the writing one', async () => {
    const sql = await openDesktopSql('tresso-mail-kim')
    rows.value = [{ n: 1 }]

    expect(await sql.first('SELECT 1 AS n')).toEqual({ n: 1 })
    rows.value = []
    expect(await sql.first('SELECT 1 AS n')).toBeNull()
    await sql.run('DELETE FROM t WHERE id = ?', ['x'])
    await sql.exec('CREATE TABLE t (id TEXT)')

    expect(calls).toEqual([
      { command: 'sql_open', args: { name: 'tresso-mail-kim', page: expect.any(String) } },
      { command: 'sql_select', args: { handle: 7, source: 'SELECT 1 AS n', params: [], on: 'reader' } },
      { command: 'sql_select', args: { handle: 7, source: 'SELECT 1 AS n', params: [], on: 'reader' } },
      { command: 'sql_run', args: { handle: 7, source: 'DELETE FROM t WHERE id = ?', params: ['x'] } },
      { command: 'sql_exec', args: { handle: 7, source: 'CREATE TABLE t (id TEXT)' } },
    ])
  })

  it('reads inside a transaction on the writing connection and commits', async () => {
    const sql = await openDesktopSql('copy')
    calls.length = 0

    await sql.transaction(async (tx) => {
      await tx.all('SELECT * FROM t')
      await tx.run('INSERT INTO t VALUES (?)', [1])
      await expect(tx.transaction(async () => {})).rejects.toThrow('Transactions do not nest.')
    })

    expect(calls.map((call) => [call.command, call.args.on])).toEqual([
      ['sql_begin', undefined],
      ['sql_select', 'writer'],
      ['sql_run', undefined],
      ['sql_commit', undefined],
    ])
  })

  it('rolls back a failed write, also when COMMIT fails, and reports the original error', async () => {
    const sql = await openDesktopSql('copy')
    calls.length = 0

    await expect(sql.transaction(async () => Promise.reject(new Error('bad row')))).rejects.toThrow('bad row')
    failures.set('sql_commit', new Error('disk full'))
    await expect(sql.transaction(async () => {})).rejects.toThrow('disk full')

    expect(calls.map((call) => call.command)).toEqual(['sql_begin', 'sql_rollback', 'sql_begin', 'sql_commit', 'sql_rollback'])
  })

  it('logs a rollback that fails too, and still reports the original error', async () => {
    const logged = vi.spyOn(console, 'error').mockImplementation(() => {})
    const sql = await openDesktopSql('copy')
    failures.set('sql_rollback', new Error('gone'))

    await expect(sql.transaction(async () => Promise.reject(new Error('bad row')))).rejects.toThrow('bad row')

    expect(logged).toHaveBeenCalledWith('Undoing a failed write failed', new Error('gone'))
    logged.mockRestore()
  })

  it('opens every copy of one page load under the same page id', async () => {
    await openDesktopSql('a')
    await openDesktopSql('b')

    const pages = calls.filter((call) => call.command === 'sql_open').map((call) => call.args.page)
    expect(pages[0]).toBe(pages[1])
  })

  it('closes and deletes copies through the shell', async () => {
    const sql = await openDesktopSql('copy')
    await sql.close()
    await deleteDesktopSql('copy')

    expect(calls.slice(-2)).toEqual([
      { command: 'sql_close', args: { handle: 7 } },
      { command: 'sql_delete', args: { name: 'copy' } },
    ])
  })
})
