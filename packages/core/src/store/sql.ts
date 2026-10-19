export type SqlValue = string | number | null

/**
 * The part of a SQLite database the mail copy uses. Each client brings its own: expo-sqlite on the phone, the desktop
 * shell's commands on the computer, sql.js in the unit tests.
 */
export interface Sql {
  exec(source: string): Promise<void>
  run(source: string, params?: SqlValue[]): Promise<void>
  all<T>(source: string, params?: SqlValue[]): Promise<T[]>
  first<T>(source: string, params?: SqlValue[]): Promise<T | null>
  /** Runs the task in one exclusive transaction: all of it is stored, or none. Transactions do not nest. */
  transaction(task: (tx: Sql) => Promise<void>): Promise<void>
  close(): Promise<void>
}
