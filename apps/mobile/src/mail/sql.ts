import * as SQLite from 'expo-sqlite'
import type { Sql } from '@tresso/core'

export type { Sql, SqlValue } from '@tresso/core'

type Queries = Pick<SQLite.SQLiteDatabase, 'execAsync' | 'runAsync' | 'getAllAsync' | 'getFirstAsync'>

function queries(database: Queries): Omit<Sql, 'transaction' | 'close'> {
  return {
    exec: (source) => database.execAsync(source),
    run: async (source, params = []) => {
      await database.runAsync(source, params)
    },
    all: (source, params = []) => database.getAllAsync(source, params),
    first: (source, params = []) => database.getFirstAsync(source, params),
  }
}

const nested = () => Promise.reject(new Error('Transactions do not nest.'))

/** Opens (or creates) a database on the device. */
export async function openSql(name: string): Promise<Sql> {
  const database = await SQLite.openDatabaseAsync(name)
  await database.execAsync('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;')
  return {
    ...queries(database),
    transaction: (task) =>
      database.withExclusiveTransactionAsync(async (txn) => {
        await task({ ...queries(txn), transaction: nested, close: nested })
      }),
    close: () => database.closeAsync(),
  }
}

/** Removes the database from the device, e.g. on sign-out. */
export function deleteSql(name: string): Promise<void> {
  return SQLite.deleteDatabaseAsync(name)
}
