import { invoke } from '@tauri-apps/api/core'
import type { Sql, SqlValue } from '@tresso/core'

type On = 'reader' | 'writer'

/**
 * A mail copy in the desktop shell's SQLite (see `apps/desktop/src-tauri/src/sql.rs`). Reads outside a transaction
 * go to a connection of their own, so the lists stay responsive while a page of the delta is stored.
 */
export async function openDesktopSql(name: string): Promise<Sql> {
  const handle = await invoke<number>('sql_open', { name })

  function queries(on: On): Omit<Sql, 'transaction' | 'close'> {
    const select = <T>(source: string, params: SqlValue[] = []) => invoke<T[]>('sql_select', { handle, source, params, on })
    return {
      exec: (source) => invoke('sql_exec', { handle, source }),
      run: (source, params = []) => invoke('sql_run', { handle, source, params }),
      all: select,
      first: async <T>(source: string, params: SqlValue[] = []) => (await select<T>(source, params))[0] ?? null,
    }
  }
  const nested = () => Promise.reject(new Error('Transactions do not nest.'))

  return {
    ...queries('reader'),
    // The store queues its writes, so only one transaction is ever open on the writer.
    transaction: async (task) => {
      await invoke('sql_exec', { handle, source: 'BEGIN IMMEDIATE' })
      try {
        await task({ ...queries('writer'), transaction: nested, close: nested })
        await invoke('sql_exec', { handle, source: 'COMMIT' })
      } catch (error) {
        // A failed COMMIT leaves the transaction open too. The original error is what the caller hears.
        await invoke('sql_exec', { handle, source: 'ROLLBACK' }).catch(() => {})
        throw error
      }
    },
    close: () => invoke('sql_close', { handle }),
  }
}

/** Removes a closed copy from the computer. */
export function deleteDesktopSql(name: string): Promise<void> {
  return invoke('sql_delete', { name })
}
