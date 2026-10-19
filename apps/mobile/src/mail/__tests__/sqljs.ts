import initSqlJs from 'sql.js'
import type { Sql, SqlValue } from '../sql'

/** The mail copy's SQL on sql.js, for unit tests. */
export async function memorySql(): Promise<Sql> {
  const SQL = await initSqlJs()
  const database = new SQL.Database()

  const queries: Omit<Sql, 'transaction'> = {
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

  return {
    ...queries,
    transaction: async (task) => {
      database.run('BEGIN')
      try {
        await task({ ...queries, transaction: () => Promise.reject(new Error('Transactions do not nest.')) })
        database.run('COMMIT')
      } catch (error) {
        database.run('ROLLBACK')
        throw error
      }
    },
  }
}
