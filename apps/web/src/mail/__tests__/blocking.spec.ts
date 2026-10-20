import 'fake-indexeddb/auto'
import { describe, expect, it } from 'vitest'
import { deleteMailDatabase, IndexedDbMailStore } from '@/mail/db'

/** Resolves with what the promise gave, or 'still waiting' when it did not settle within the time. */
function within<T>(promise: Promise<T>, ms: number): Promise<T | 'still waiting'> {
  return Promise.race([promise, new Promise<'still waiting'>((resolve) => setTimeout(() => resolve('still waiting'), ms))])
}

describe('a copy left open', () => {
  it('never blocks removing the copy (a sign-out in another tab), it closes itself instead', async () => {
    const left = await IndexedDbMailStore.open('blocking-user')
    await left.cursor()

    expect(await within(deleteMailDatabase('blocking-user'), 1000)).toBeUndefined()
  })
})
