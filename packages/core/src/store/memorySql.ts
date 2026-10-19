import initSqlJs from 'sql.js'
import type { Sql, SqlValue } from './sql'

/**
 * A mail copy's SQL on sql.js, for unit tests. Like a device, it refuses a second transaction while one is open, so
 * tests catch writes that overlap.
 */
export async function memorySql(): Promise<Sql & { closed(): boolean }> {
  const SQL = await initSqlJs()
  const database = new SQL.Database()
  let closed = false

  const queries: Omit<Sql, 'transaction' | 'close'> = {
    exec: async (source) => {
      database.exec(source)
    },
    run: async (source, params: SqlValue[] = []) => {
      database.run(source, params)
    },
    all: async <T>(source: string, params: SqlValue[] = []) => {
      const statement = database.prepare(source, params)
      const rows: T[] = []
      while (statement.step()) {
        rows.push(statement.getAsObject() as T)
      }
      statement.free()
      return rows
    },
    first: async <T>(source: string, params: SqlValue[] = []) => {
      const statement = database.prepare(source, params)
      const row = statement.step() ? (statement.getAsObject() as T) : null
      statement.free()
      return row
    },
  }
  const nested = () => Promise.reject(new Error('Transactions do not nest.'))

  return {
    ...queries,
    transaction: async (task) => {
      database.run('BEGIN')
      try {
        await task({ ...queries, transaction: nested, close: nested })
        database.run('COMMIT')
      } catch (error) {
        database.run('ROLLBACK')
        throw error
      }
    },
    // The data stays, so a test can open the same copy again, as after a restart.
    close: async () => {
      closed = true
    },
    closed: () => closed,
  }
}
