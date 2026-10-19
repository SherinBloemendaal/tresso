import * as SQLite from 'expo-sqlite'

export type SqlValue = string | number | null

/**
 * The part of a SQL database the mail copy uses. The app runs it on expo-sqlite; the unit tests run the same SQL on
 * sql.js, so the storage logic is tested without a device.
 */
export interface Sql {
  exec(source: string): Promise<void>
  run(source: string, params?: SqlValue[]): Promise<void>
  all<T>(source: string, params?: SqlValue[]): Promise<T[]>
  first<T>(source: string, params?: SqlValue[]): Promise<T | null>
  /** Runs the task in one exclusive transaction: all of it is stored, or none. */
  transaction(task: (tx: Sql) => Promise<void>): Promise<void>
}

type Queries = Pick<SQLite.SQLiteDatabase, 'execAsync' | 'runAsync' | 'getAllAsync' | 'getFirstAsync'>

function queries(database: Queries): Omit<Sql, 'transaction'> {
  return {
    exec: (source) => database.execAsync(source),
    run: async (source, params = []) => {
      await database.runAsync(source, params)
    },
    all: (source, params = []) => database.getAllAsync(source, params),
    first: (source, params = []) => database.getFirstAsync(source, params),
  }
}

/** Opens (or creates) a database on the device. */
export async function openSql(name: string): Promise<Sql & { close(): Promise<void> }> {
  const database = await SQLite.openDatabaseAsync(name)
  await database.execAsync('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;')
  return {
    ...queries(database),
    transaction: (task) =>
      database.withExclusiveTransactionAsync(async (txn) => {
        await task({ ...queries(txn), transaction: () => Promise.reject(new Error('Transactions do not nest.')) })
      }),
    close: () => database.closeAsync(),
  }
}

/** Removes the database from the device, e.g. on sign-out. */
export function deleteSql(name: string): Promise<void> {
  return SQLite.deleteDatabaseAsync(name)
}
